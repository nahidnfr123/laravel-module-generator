<?php

namespace NahidFerdous\LaravelModuleGenerator\Services;

use Illuminate\Support\Str;

class RelationCodeGenerator
{
    private array $allModels;

    public function __construct(array $allModels)
    {
        $this->allModels = $allModels;
    }

    public function generateImports(array $modelData, string $currentModelName = ''): string
    {
        if (! isset($modelData['nested_requests'])) {
            return '';
        }

        $nestedRequests = is_array($modelData['nested_requests'])
            ? $modelData['nested_requests']
            : array_map('trim', explode(',', $modelData['nested_requests']));

        $relations = (new RelationParserService)->parse($modelData['relations'] ?? []);
        $imports = [];

        foreach ($nestedRequests as $relationName) {
            $relationName = trim($relationName);
            if (isset($relations[$relationName])) {
                $relatedModel = $relations[$relationName]['model'];
                if ($relatedModel !== $currentModelName) {
                    $imports[] = 'use '.app()->getNamespace()."Models\\{$relatedModel};";
                }
            }
        }

        if (! empty($imports)) {
            array_unshift($imports, 'use Illuminate\\Support\\Facades\\DB;');

            $hasFileFields = false;
            foreach ($nestedRequests as $relationName) {
                $relationName = trim($relationName);
                if (isset($relations[$relationName])) {
                    $relatedModelData = $this->getRelatedModelData($relations[$relationName]['model']);
                    if ($this->hasFileOrImageFields($relatedModelData)) {
                        $hasFileFields = true;
                        break;
                    }
                }
            }

            if ($hasFileFields) {
                array_unshift($imports, 'use Illuminate\\Support\\Facades\\Storage;');
            }
        }

        return implode("\n", array_unique($imports));
    }

    public function generateWithRelations(array $modelData): string
    {
        if (! isset($modelData['with'])) {
            return '';
        }

        $withList = is_array($modelData['with'])
            ? $modelData['with']
            : array_map('trim', explode(',', $modelData['with']));

        $relations = array_map(fn ($rel) => "'".trim($rel)."'", $withList);

        return implode(', ', $relations);
    }

    public function generateStoreCode(array $modelData, string $modelVariable, string $modelKey): string
    {
        if (! isset($modelData['nested_requests'])) {
            return '';
        }

        $nestedRequests = is_array($modelData['nested_requests'])
            ? $modelData['nested_requests']
            : array_map('trim', explode(',', $modelData['nested_requests']));

        $relations = (new RelationParserService)->parse($modelData['relations'] ?? []);
        $code = "\n        DB::transaction(function () use (\$data, \${$modelVariable}) {";

        foreach ($nestedRequests as $relationName) {
            $relationName = trim($relationName);

            if (! isset($relations[$relationName])) {
                continue;
            }

            $relationConfig = $relations[$relationName];
            $relationKey = Str::snake($relationName);
            $relationType = $relationConfig['type'];

            $relatedModelData = $this->getRelatedModelData($relationConfig['model']);
            $hasFileFields = $this->hasFileOrImageFields($relatedModelData);

            switch ($relationType) {
                case 'hasMany':
                    $code .= "\n            // Handle {$relationName} relation";
                    $code .= "\n            if (!empty(\$data['{$relationKey}']) && is_array(\$data['{$relationKey}'])) {";

                    $nestedRelations = $this->getNestedRelations($relationConfig['model']);

                    if (! empty($nestedRelations) || $hasFileFields) {
                        $code .= "\n                \${$relationKey} = [];";
                        $code .= "\n                foreach (\$data['{$relationKey}'] as \$relationData) {";
                        $code .= "\n                    \${$relationKey}[] = \$relationData;";

                        if ($hasFileFields) {
                            $uploadCode = $this->generateNestedFileUploadCode($relatedModelData, $relationConfig['model']);
                            if ($uploadCode) {
                                $code .= $uploadCode;
                            }
                        }

                        foreach ($nestedRelations as $nestedRelationName => $nestedRelationConfig) {
                            $nestedRelationKey = Str::snake($nestedRelationName);
                            $nestedRelationType = $nestedRelationConfig['type'];

                            if ($nestedRelationType === 'hasMany') {
                                $code .= "\n                    // Handle nested {$nestedRelationName} relation";
                                $code .= "\n                    if (!empty(\$relationData['{$nestedRelationKey}']) && is_array(\$relationData['{$nestedRelationKey}'])) {";
                                $code .= "\n                        \${$relationName}Record->{$nestedRelationName}()->createMany(\$relationData['{$nestedRelationKey}']);";
                                $code .= "\n                    }";
                            } elseif ($nestedRelationType === 'hasOne') {
                                $code .= "\n                    // Handle nested {$nestedRelationName} relation";
                                $code .= "\n                    if (!empty(\$relationData['{$nestedRelationKey}'])) {";
                                $code .= "\n                        \${$relationName}Record->{$nestedRelationName}()->create(\$relationData['{$nestedRelationKey}']);";
                                $code .= "\n                    }";
                            }
                        }

                        $code .= "\n                }";
                        $code .= "\n                    \${$modelVariable}->{$relationName}()->createMany(\${$relationKey});";
                    } else {
                        $code .= "\n                \${$modelVariable}->{$relationName}()->createMany(\$data['{$relationKey}']);";
                    }

                    $code .= "\n            }";
                    break;

                case 'hasOne':
                    $code .= "\n            // Handle {$relationName} relation";
                    $code .= "\n            if (!empty(\$data['{$relationKey}'])) {";

                    if ($hasFileFields) {
                        $uploadCode = $this->generateNestedFileUploadCode($relatedModelData, $relationConfig['model'], true);
                        if ($uploadCode) {
                            $code .= $uploadCode;
                        }
                    }

                    $code .= "\n            \${$modelVariable}->{$relationName}()->create(\$data['{$relationKey}']);";
                    $code .= "\n            }";
                    break;

                case 'belongsToMany':
                    $code .= "\n            // Handle {$relationName} relation";
                    $code .= "\n            if (!empty(\$data['{$relationKey}']) && is_array(\$data['{$relationKey}'])) {";
                    $code .= "\n                \${$modelVariable}->{$relationName}()->sync(\$data['{$relationKey}']);";
                    $code .= "\n            }";
                    break;
            }
        }

        $code .= "\n        });";

        return $code;
    }

    public function generateUpdateCode(array $modelData, string $modelVariable, string $modelKey): string
    {
        if (! isset($modelData['nested_requests'])) {
            return '';
        }

        $nestedRequests = is_array($modelData['nested_requests'])
            ? $modelData['nested_requests']
            : array_map('trim', explode(',', $modelData['nested_requests']));

        $relations = (new RelationParserService)->parse($modelData['relations'] ?? []);
        $code = "\n        DB::transaction(function () use (\$data, \${$modelVariable}) {";

        foreach ($nestedRequests as $relationName) {
            $relationName = trim($relationName);

            if (! isset($relations[$relationName])) {
                continue;
            }

            $relationConfig = $relations[$relationName];
            $relationKey = Str::snake($relationName);
            $relationType = $relationConfig['type'];

            $relatedModelData = $this->getRelatedModelData($relationConfig['model']);
            $hasFileFields = $this->hasFileOrImageFields($relatedModelData);
            $nestedRelations = $this->getNestedRelations($relationConfig['model']);

            switch ($relationType) {
                case 'hasMany':
                    $code .= "\n            // Update {$relationName} relation";
                    $code .= "\n            if (!empty(\$data['{$relationKey}']) && is_array(\$data['{$relationKey}'])) {";
                    $code .= "\n                \$existingIds = \${$modelVariable}->{$relationName}()->pluck('id')->toArray();";
                    $code .= "\n                \$keptIds = [];";

                    $code .= "\n                foreach (\$data['{$relationKey}'] as \$relationData) {";

                    if ($hasFileFields) {
                        $uploadCode = $this->generateNestedFileUploadCode(
                            $relatedModelData,
                            $relationConfig['model']
                        );
                        if ($uploadCode) {
                            $code .= $uploadCode;
                        }
                    }

                    $code .= "\n                    \$record = \${$modelVariable}->{$relationName}()->updateOrCreate(";
                    $code .= "\n                        ['id' => \$relationData['id'] ?? null],";
                    $code .= "\n                        \$relationData";
                    $code .= "\n                    );";

                    $code .= "\n                    \$keptIds[] = \$record->id;";

                    foreach ($nestedRelations as $nestedRelationName => $nestedRelationConfig) {
                        $nestedKey = Str::snake($nestedRelationName);
                        $nestedType = $nestedRelationConfig['type'];

                        if ($nestedType === 'hasMany') {
                            $code .= "\n                    if (!empty(\$relationData['{$nestedKey}'])) {";
                            $code .= "\n                        \$record->{$nestedRelationName}()->delete();";
                            $code .= "\n                        \$record->{$nestedRelationName}()->createMany(\$relationData['{$nestedKey}']);";
                            $code .= "\n                    }";
                        }

                        if ($nestedType === 'hasOne') {
                            $code .= "\n                    if (!empty(\$relationData['{$nestedKey}'])) {";
                            $code .= "\n                        \$record->{$nestedRelationName}()->updateOrCreate([], \$relationData['{$nestedKey}']);";
                            $code .= "\n                    }";
                        }
                    }

                    $code .= "\n                }";

                    $code .= "\n                \${$modelVariable}->{$relationName}()->whereNotIn('id', \$keptIds)->delete();";

                    $code .= "\n                unset(\$data['{$relationKey}']);";
                    $code .= "\n            }";
                    break;

                case 'hasOne':
                    $code .= "\n            // Update {$relationName} relation";
                    $code .= "\n            if (!empty(\$data['{$relationKey}'])) {";

                    if ($hasFileFields) {
                        $deleteCode = $this->generateNestedFileDeleteCode(
                            $relatedModelData,
                            $relationName,
                            $modelVariable,
                            true
                        );
                        if ($deleteCode) {
                            $code .= $deleteCode;
                        }

                        $uploadCode = $this->generateNestedFileUploadCode(
                            $relatedModelData,
                            $relationConfig['model'],
                            true
                        );
                        if ($uploadCode) {
                            $code .= $uploadCode;
                        }
                    }

                    $code .= "\n                \${$modelVariable}->{$relationName}()->updateOrCreate([], \$data['{$relationKey}']);";
                    $code .= "\n                unset(\$data['{$relationKey}']);";
                    $code .= "\n            }";
                    break;

                case 'belongsToMany':
                    $code .= "\n            // Update {$relationName} relation";
                    $code .= "\n            if (!empty(\$data['{$relationKey}'])) {";
                    $code .= "\n                \${$modelVariable}->{$relationName}()->sync(\$data['{$relationKey}']);";
                    $code .= "\n                unset(\$data['{$relationKey}']);";
                    $code .= "\n            }";
                    break;
            }
        }

        $code .= "\n        });";

        return $code;
    }

    private function getNestedRelations(string $relatedModelName): array
    {
        $nestedRelations = [];

        foreach ($this->allModels as $modelKey => $modelData) {
            if (Str::studly($modelKey) !== $relatedModelName) {
                continue;
            }

            if (! isset($modelData['nested_requests'])) {
                break;
            }

            $nestedRequests = is_array($modelData['nested_requests'])
                ? $modelData['nested_requests']
                : array_map('trim', explode(',', $modelData['nested_requests']));

            $relations = (new RelationParserService)->parse($modelData['relations'] ?? []);

            foreach ($nestedRequests as $relationName) {
                $relationName = trim($relationName);
                if (isset($relations[$relationName])) {
                    $nestedRelations[$relationName] = $relations[$relationName];
                }
            }

            break;
        }

        return $nestedRelations;
    }

    private function getRelatedModelData(string $relatedModelName): ?array
    {
        foreach ($this->allModels as $modelKey => $modelData) {
            if (Str::studly($modelKey) === $relatedModelName) {
                return $modelData;
            }
        }

        return null;
    }

    private function hasFileOrImageFields(?array $modelData): bool
    {
        if (! $modelData || ! isset($modelData['fields'])) {
            return false;
        }

        foreach ($modelData['fields'] as $fieldName => $fieldDefinition) {
            $type = explode(':', $fieldDefinition)[0];
            if (in_array($type, ['file', 'image'])) {
                return true;
            }
        }

        return false;
    }

    private function generateNestedFileUploadCode(?array $modelData, string $modelName, bool $isSingle = false): string
    {
        if (! $modelData || ! isset($modelData['fields'])) {
            return '';
        }

        $code = '';
        $varName = $isSingle ? 'data' : 'relationData';
        $camelModelName = Str::camel($modelName);

        foreach ($modelData['fields'] as $fieldName => $fieldDefinition) {
            $type = explode(':', $fieldDefinition)[0];

            if ($type === 'image' || $type === 'file') {
                $code .= "\n                    if (!empty(\${$varName}['{$fieldName}']) && \${$varName}['{$fieldName}'] instanceof \\Illuminate\\Http\\UploadedFile) {";
                $code .= "\n                        \${$varName}['{$fieldName}'] = uploadFile(\${$varName}['{$fieldName}'], '{$camelModelName}', 'public');";
                $code .= "\n                    }";
            }
        }

        return $code;
    }

    private function generateNestedFileDeleteCode(?array $modelData, string $relationName, string $modelVariable, bool $isSingle = false): string
    {
        if (! $modelData || ! isset($modelData['fields'])) {
            return '';
        }

        $fileFields = [];

        foreach ($modelData['fields'] as $fieldName => $fieldDefinition) {
            $type = explode(':', $fieldDefinition)[0];
            if ($type === 'image' || $type === 'file') {
                $fileFields[] = $fieldName;
            }
        }

        if (empty($fileFields)) {
            return '';
        }

        $code = '';

        if ($isSingle) {
            $code .= "\n                if (\${$modelVariable}->{$relationName}) {";
            foreach ($fileFields as $field) {
                $code .= "\n                    if (\${$modelVariable}->{$relationName}->{$field} && Storage::disk('public')->exists(\${$modelVariable}->{$relationName}->{$field})) {";
                $code .= "\n                        Storage::disk('public')->delete(\${$modelVariable}->{$relationName}->{$field});";
                $code .= "\n                    }";
            }
            $code .= "\n                }";
        } else {
            $code .= "\n                foreach (\${$modelVariable}->{$relationName} as \$record) {";
            foreach ($fileFields as $field) {
                $code .= "\n                    if (\$record->{$field} && Storage::disk('public')->exists(\$record->{$field})) {";
                $code .= "\n                        Storage::disk('public')->delete(\$record->{$field});";
                $code .= "\n                    }";
            }
            $code .= "\n                }";
        }

        return $code;
    }
}
