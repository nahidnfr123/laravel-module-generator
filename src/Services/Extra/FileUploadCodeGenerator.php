<?php

namespace NahidFerdous\LaravelModuleGenerator\Services\Extra;

use Illuminate\Support\Str;

class FileUploadCodeGenerator
{
    /**
     * Generate file upload logic for store method
     */
    public static function generateStoreUploadLogic(array $fields, string $modelName, string $modelVar): string
    {
        $uploadFields = self::getUploadFields($fields);

        if (empty($uploadFields)) {
            return '';
        }

        $code = [];
        $folderName = Str::snake(Str::plural($modelName));

        // Generate upload code for each field
        foreach ($uploadFields as $field => $type) {
            $code[] = "        if (isset(\$data['{$field}']) && \$data['{$field}']) {";
            $code[] = "            \$data['{$field}'] = uploadFile(\$data['{$field}'], '{$folderName}', '_{$field}');";
            $code[] = '        }';
        }

        return implode("\n", $code);
    }

    /**
     * Generate file upload logic for update method
     */
    public static function generateUpdateUploadLogic(array $fields, string $modelName, string $modelVar): string
    {
        $uploadFields = self::getUploadFields($fields);

        if (empty($uploadFields)) {
            return '';
        }

        $code = [];
        $folderName = Str::snake(Str::plural($modelName));

        // Generate upload and delete code for each field
        foreach ($uploadFields as $field => $type) {
            $code[] = "        if (isset(\$data['{$field}']) && \$data['{$field}']) {";
            $code[] = "            \$data['{$field}'] = uploadFile(\$data['{$field}'], '{$folderName}', '_{$field}');";
            $code[] = "            if (\$data['{$field}']) {";
            $code[] = "                deleteFile(\${$modelVar}->{$field});";
            $code[] = '            }';
            $code[] = '        }';
        }

        return implode("\n", $code);
    }

    /**
     * Generate file deletion logic for delete method
     */
    public static function generateDeleteFileLogic(array $fields, string $modelVar): string
    {
        $uploadFields = self::getUploadFields($fields);

        if (empty($uploadFields)) {
            return '';
        }

        $code = [];

        foreach ($uploadFields as $field => $type) {
            $code[] = "        if (\${$modelVar}->{$field}) {";
            $code[] = "            deleteFile(\${$modelVar}->{$field});";
            $code[] = '        }';
        }

        return implode("\n", $code);
    }

    /**
     * Get fields that require file uploads
     */
    private static function getUploadFields(array $fields): array
    {
        $uploadFields = [];

        foreach ($fields as $name => $definition) {
            $parts = explode(':', $definition);
            $type = $parts[0];

            if (in_array($type, ['image', 'file'])) {
                $uploadFields[$name] = $type;
            }
        }

        return $uploadFields;
    }
}
