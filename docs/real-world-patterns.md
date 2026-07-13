# Real-World Patterns

> Companion to the [README](../README.md). Production patterns distilled from real deployments of `ubxty/azure-ai`.

---

## 1. Tenant-scoped invocation

For multi-tenant apps, isolate per-tenant rate-limit + rotation:

```php
class TenantAzureAdapter
{
    public function __construct(
        private AzureManager $azure,
        private string $tenantId,
    ) {}

    public function invoke(string $system, string $user): array
    {
        // Per-tenant connection — see §4 below
        return $this->azure->invoke(
            modelId: config('core-ai.azure_ai.defaults.model'),
            systemPrompt: $system,
            userMessage: $user,
            connection: $this->tenantConnection(),
        );
    }

    private function tenantConnection(): string
    {
        return "tenant-{$this->tenantId}";
    }
}
```

Or, more commonly, a single `default` connection with tenant ID flowing through `tags` (Azure doesn't have a tags feature like Bedrock, so use a `user-id` header or store tenant context separately):

```php
$result = Azure::invoke(
    'gpt-4o',
    'You are a careful Q&A bot.',
    $userMessage,
);
Log::info('ai.invoke', ['tenant' => $tenantId, 'model' => 'gpt-4o', 'cost' => $result['cost']]);
```

---

## 2. Cost-cap listener

Hard cap a tenant's monthly spend:

```php
Event::listen(AzureInvoked::class, function ($e) {
    $tenant = request()->tenant?->id ?? 'unknown';

    Cache::increment("tenant.{$tenant}.monthly_spend", $e->cost);

    $monthly = Cache::get("tenant.{$tenant}.monthly_spend");

    if ($monthly > config("tenants.{$tenant}.cost_cap_usd", 1000)) {
        throw new CostLimitExceededException("Tenant {$tenant} over cap.");
    }
});
```

The exception propagates to the framework's exception handler — return `429: Over monthly cap`.

---

## 3. Multi-turn + multimodal

```php
$result = Azure::conversation('gpt-4o')
    ->system('You extract line items from invoices. Output JSON.')
    ->user('Read this invoice.')
    ->userWithImage('Anything I missed?', '/tmp/invoice.jpg')
    ->schema([
        'type' => 'object',
        'properties' => [
            'line_items' => ['type' => 'array', 'items' => ['type' => 'object']],
        ],
    ])
    ->temperature(0.0)
    ->maxTokens(2048)
    ->send();
```

`gpt-4o`, `gpt-4-turbo`, and `gpt-4o-mini` all support JSON-schema constrained output.

---

## 4. Multi-key round-robin across 2 regions

```php
'connections' => [
    'default' => [
        'keys' => [
            ['label' => 'East-Prod',  'endpoint' => 'https://east.openai.azure.com',     'api_key' => env('AZURE_API_EAST'),     'api_version' => '2024-10-21'],
            ['label' => 'West-Prod',  'endpoint' => 'https://west.openai.azure.com',     'api_key' => env('AZURE_API_WEST'),     'api_version' => '2024-10-21'],
            ['label' => 'Foundry-EU', 'endpoint' => 'https://eu-proj.services.ai.azure.com/api/projects/p/openai/v1', 'api_key' => env('AZURE_API_EU_FOUNDRY')],
        ],
    ],
],
```

Two traditional keys (East + West same subscription tier), one Foundry v1 key (different account). The package routes each call correctly based on endpoint shape.

---

## 5. Idempotent worker

```php
class ExtractCaseJob
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 30;

    public function handle(): void
    {
        $result = Azure::invoke(
            config('core-ai.azure_ai.defaults.model'),
            'You extract symptom vectors…',
            file_get_contents(storage_path("cases/{$this->caseId}.txt")),
            temperature: 0.0,
        );

        CaseModel::find($this->caseId)->update(['extraction' => $result['response']]);
    }
}
```

The package automatically attaches `Idempotency-Key = sha256(model|sys|user)` — a network-blip retry does not double-bill.

---

## 6. Cache-bypass loop (prompt engineering)

```php
$base = $system;
$samples = ['v1', 'v2', 'v3', 'v4'];

foreach ($samples as $variant) {
    config(['core-ai.cache.response_ttl' => 0]); // bypass cache
    $out = Azure::invoke('gpt-4o', $base, $variant);
    Storage::append("experiments/p1.log", "[$variant] {$out['response']}\n");
}
```

For evaluation, disable the response cache so each variant produces a fresh sample.

---

## 7. Streaming with Vue / React (SSE)

```php
return Azure::converseStream(
    modelId: 'gpt-4o',
    messages: [['role' => 'user', 'content' => request('q')]],
    onChunk: function (string $chunk) {
        echo $chunk;
        ob_flush();
        flush();
    },
);
```

This emits chunked plain-text (no SSE envelope). For proper `text/event-stream`:

```php
return response()->stream(function () use ($userMessage) {
    Azure::converseStream(
        modelId: 'gpt-4o',
        messages: [['role' => 'user', 'content' => $userMessage]],
        onChunk: function (string $chunk) {
            echo "data: ".json_encode(['chunk' => $chunk])."\n\n";
            ob_flush();
            flush();
        },
    );
    echo "data: [DONE]\n\n";
}, 200, [
    'Content-Type'      => 'text/event-stream',
    'Cache-Control'     => 'no-cache',
    'X-Accel-Buffering' => 'no',
]);
```

Vue / React consume the SSE with a stream reader.

---

## 8. Embedding ingestion with concurrency

For a 100k-row corpus:

```php
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

Bus::batch(
    collect($corpus)->chunk(1000)->map(
        fn ($chunk, $i) => new IngestEmbeddingsJob($chunk->all(), $tenantId, $i),
    )->toArray()
)->name('embeddings-v3')->dispatch();

class IngestEmbeddingsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private array $texts, private string $tenantId, private int $batchIdx)
    {
        $this->onQueue('embeddings');
    }

    public function handle(): void
    {
        $vectors = Azure::embed('text-embedding-3-small', $this->texts, dimensions: 512);

        DB::table("embeddings_{$this->tenantId}")->insert(
            array_map(fn ($v, $t) => ['text' => $t, 'vec' => pack('g*', ...$v)], $vectors, $this->texts)
        );
    }
}
```

Per-text SHA256 caching means re-running a batch (e.g. on a job failure) is free.

---

## 9. Structured-output JSON for ETL

```php
$result = Azure::conversation('gpt-4o')
    ->system('You classify support tickets into one of: ['billing', 'technical', 'feature-request', 'other'].')
    ->user($ticketBody)
    ->schema([
        'type'       => 'object',
        'properties' => [
            'category' => ['type' => 'string', 'enum' => ['billing', 'technical', 'feature-request', 'other']],
            'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
        ],
        'required' => ['category'],
    ])
    ->temperature(0.0)
    ->maxTokens(128)
    ->send();

$decoded = json_decode($result['response'], true);
```

---

## 10. Audit log via `AzureInvoked`

```php
Event::listen(AzureInvoked::class, function ($e) {
    Log::channel('audit')->info('ai.invoke', [
        'model'       => $e->modelId,
        'tenant'      => request()->tenant?->id,
        'cost'        => $e->cost,
        'tokens_in'   => $e->inputTokens,
        'tokens_out'  => $e->outputTokens,
        'key_used'    => $e->keyUsed,
        'latency_ms'  => $e->latencyMs,
        'idempotency' => $e->idempotencyKey,
    ]);
});
```

---

## 11. Replay-safe retries in distributed workers

```php
$job = (new ExtractCaseJob($caseId))->onQueue('cases');
$job->setIdempotencyKey("case-{$caseId}-v2"); // Laravel 11+
dispatch($job);
```

If the same job runs twice in different workers, the underlying Azure call returns the same cached response.

---

## 12. Rate-limit-aware queue throttling

```php
Event::listen(AzureRateLimited::class, function ($e) {
    $secs = $e->retryAfterSeconds ?? 30;
    Cache::put('ai.rate_limited_until', now()->addSeconds($secs));
    Log::warning('rate limited', ['for' => $secs]);
});
```

Workers check this before invoking:

```php
if (Cache::has('ai.rate_limited_until') && now()->lt(Cache::get('ai.rate_limited_until'))) {
    $this->release(now()->diffInSeconds(Cache::get('ai.rate_limited_until')));
}
```

---

## 13. A/B-testing between models

```php
class AbTestService
{
    public function invoke(string $system, string $user, string $bucket): array
    {
        $model = match ($bucket) {
            'gpt-4o'        => 'gpt-4o',
            'gpt-4o-mini'   => 'gpt-4o-mini',
            default         => 'gpt-4o',
        };

        $result = Azure::invoke($model, $system, $user);

        Event::dispatch(new AiAbCompleted($bucket, $model, $result['cost'], $result['latency_ms']));

        return $result;
    }
}
```

The `AiAbCompleted` event is yours to define — not part of the package. The point is that the `cost`, `latency_ms`, and `key_used` fields let you compare models empirically.

---

## 14. Forwarding caller identity with `user` parameter

```php
$vectors = Azure::embed(
    'text-embedding-3-small',
    $corpus,
    dimensions: 512,
    user: auth()->user()?->id ?? 'anonymous',
);
```

The `user` flows through as `x-ms-user-agent` — useful when you have Azure's per-user abuse-detection enabled (PTU with tenant scoping).

---

## 15. Pre-call token estimation

```php
use Ubxty\CoreAi\Support\TokenEstimator;

$estimated = TokenEstimator::estimateTokens($userMessage);
$modelMax  = app(ModelSpecResolver::class)->resolve($modelId)['context_window'] ?? 8192;

if ($estimated > $modelMax - $maxTokensOutput) {
    throw new \OutOfBoundsException("Input too long ({$estimated} tokens). Limit: ".($modelMax - $maxTokensOutput));
}
```

The package's `ConversationBuilder::estimate()` does this automatically and applies a silent-downscale if the value is within `maxTokensOutput` of the model spec.

---

## 16. Token-aware prompt trimming

```php
use Ubxty\CoreAi\Support\TokenEstimator;

$system    = 'You are a summariser.';
$userText  = $caseBody;

$estimated = TokenEstimator::estimateMultimodal([
    ['type' => 'text', 'text' => $system],
    ['type' => 'text', 'text' => $userText],
]);

if ($estimated > 6000) {
    // Trim the case body to fit
    $userText = mb_substr($userText, 0, (int)(mb_strlen($userText) * 6000 / $estimated));
}

Azure::invoke('gpt-4o', $system, $userText, maxTokens: 512);
```

`estimateMultimodal` accounts for image / document blocks alongside text. For pure text input the regular `estimateTokens()` is fine.
