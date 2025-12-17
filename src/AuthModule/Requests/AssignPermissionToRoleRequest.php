<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignPermissionToRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role_id' => 'required|string|exists:roles,id',
            'permissions' => 'sometimes|required|array',
            'permissions.*' => 'integer|exists:permissions,id',
        ];
    }
}
