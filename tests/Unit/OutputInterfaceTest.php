<?php

namespace NahidFerdous\LaravelModuleGenerator\Tests\Unit;

use NahidFerdous\LaravelModuleGenerator\Console\Commands\GenerateModuleFromYaml;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\ModuleRollback;
use NahidFerdous\LaravelModuleGenerator\Contracts\OutputInterface;
use NahidFerdous\LaravelModuleGenerator\Tests\TestCase;

class OutputInterfaceTest extends TestCase
{
    public function test_generate_module_from_yaml_implements_output_interface(): void
    {
        $reflection = new \ReflectionClass(GenerateModuleFromYaml::class);

        $this->assertTrue($reflection->implementsInterface(OutputInterface::class));
    }

    public function test_module_rollback_implements_output_interface(): void
    {
        $reflection = new \ReflectionClass(ModuleRollback::class);

        $this->assertTrue($reflection->implementsInterface(OutputInterface::class));
    }

    public function test_output_interface_defines_expected_methods(): void
    {
        $reflection = new \ReflectionClass(OutputInterface::class);

        $expectedMethods = ['info', 'warn', 'error', 'line', 'newLine'];
        foreach ($expectedMethods as $method) {
            $this->assertTrue($reflection->hasMethod($method), "Missing method: {$method}");
        }
    }

    public function test_output_interface_matches_laravel_io_trait_signatures(): void
    {
        // Must match Illuminate\Console\Concerns\InteractsWithIO trait signatures exactly
        // so that Command subclasses can implement this interface without conflict

        $infoMethod = new \ReflectionMethod(OutputInterface::class, 'info');
        $this->assertNull($infoMethod->getReturnType());
        $this->assertCount(2, $infoMethod->getParameters());
        $this->assertNull($infoMethod->getParameters()[0]->getType());
        $this->assertTrue($infoMethod->getParameters()[1]->isOptional());

        $warnMethod = new \ReflectionMethod(OutputInterface::class, 'warn');
        $this->assertNull($warnMethod->getReturnType());
        $this->assertCount(2, $warnMethod->getParameters());

        $errorMethod = new \ReflectionMethod(OutputInterface::class, 'error');
        $this->assertNull($errorMethod->getReturnType());
        $this->assertCount(2, $errorMethod->getParameters());

        $lineMethod = new \ReflectionMethod(OutputInterface::class, 'line');
        $this->assertNull($lineMethod->getReturnType());
        $this->assertCount(3, $lineMethod->getParameters());

        $newLineMethod = new \ReflectionMethod(OutputInterface::class, 'newLine');
        $this->assertNull($newLineMethod->getReturnType());
        $this->assertNull($newLineMethod->getParameters()[0]->getType());
    }
}
