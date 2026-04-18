<?php

namespace Ubxty\AzureAi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ubxty\AzureAi\AzureManager;

class AzureManagerInstantiationTest extends TestCase
{
    public function test_manager_can_be_instantiated_with_empty_config(): void
    {
        $manager = new AzureManager([]);

        $this->assertInstanceOf(AzureManager::class, $manager);
    }

    public function test_platform_name_returns_azure(): void
    {
        $manager = new AzureManager([]);

        $this->assertStringContainsStringIgnoringCase('azure', $manager->platformName());
    }
}
