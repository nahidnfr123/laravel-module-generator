<?php

namespace NahidFerdous\LaravelModuleGenerator\Services;

use Illuminate\Support\Facades\File;

class StubPathResolverService
{
    /**
     * Resolve the path to a stub file
     */
    public function resolveStubPath(string $stubKey): string
    {
        $config = config('module-generator');
        if (!isset($config['stubs'], $config['base_path']) || !$config) {
            throw new \RuntimeException('Module generator stubs configuration not found.');
        }

        // Map new stub keys to files
        $stubMap = [
            'controller-without-service-relations' => 'controller-without-service-relations.stub',
            'controller-without-service' => 'controller-without-service.stub',
            'controller-with-relations' => 'controller-with-relations.stub',

            'service-repository-relations' => 'service-repository-relations.stub',
            'service-relations' => 'service-relations.stub',
            'service-repository' => 'service-repository.stub',
        ];

        $stubFile = $stubMap[$stubKey] ?? ($config['stubs'][$stubKey] ?? null);

        if (!$stubFile) {
            throw new \InvalidArgumentException("Stub not defined for key: {$stubKey}");
        }

        $publishedPath = $config['base_path'] . "/stubs/{$stubFile}";

        if (file_exists($publishedPath)) {
            return $publishedPath;
        }

        // $this->warn($publishedPath . ' stub path not found, using fallback path.');

        $fallbackPath = __DIR__ . '/../stubs/' . $stubFile;

        if (!file_exists($fallbackPath)) {
            throw new \RuntimeException("Stub file not found at fallback path: {$fallbackPath}");
        }

        return $fallbackPath;
    }
}
