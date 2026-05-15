<?php

namespace NahidFerdous\LaravelModuleGenerator\Services;

use Illuminate\Support\Str;

class RelationParserService
{
    public function parse(array $relationsData): array
    {
        $relations = [];

        foreach ($relationsData as $relationType => $relationsList) {
            if (! is_string($relationsList)) {
                continue;
            }

            $relationItems = array_map('trim', explode(',', $relationsList));

            foreach ($relationItems as $relationDefinition) {
                $parts = explode(':', trim($relationDefinition));
                $model = trim($parts[0]);

                if (isset($parts[1])) {
                    $name = trim($parts[1]);
                } else {
                    $name = match ($relationType) {
                        'hasMany', 'belongsToMany' => Str::camel(Str::plural($model)),
                        default => Str::camel($model),
                    };
                }

                $relations[$name] = [
                    'type' => $relationType,
                    'model' => $model,
                ];
            }
        }

        return $relations;
    }
}
