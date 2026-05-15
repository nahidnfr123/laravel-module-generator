<?php

namespace NahidFerdous\LaravelModuleGenerator\Concerns;

use Illuminate\Support\Facades\File;

trait HandlesFileGeneration
{
    protected function guardAgainstExisting(string $path, string $label, bool $force): bool
    {
        if (File::exists($path) && ! $force) {
            $this->command->warn("⚠️ {$label} already exists.");

            return false;
        }

        if (File::exists($path)) {
            File::delete($path);
            $this->command->warn("⚠️ Deleted existing {$label}");
        }

        return true;
    }
}
