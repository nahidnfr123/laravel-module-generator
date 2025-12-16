<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

class PermissionService
{
    public function getAll()
    {
        return Permission::paginate(request('per_page', 50));
    }

    public function assignPermissionToRole($data): void
    {
        $permissions = $data['permissions'] ?? [];
        $role = Role::findOrFail($data['role_id']);
        $permissionNames = Permission::whereIn('id', $permissions)->pluck('name')->toArray();
        $role->syncPermissions($permissionNames);
    }

    public function assignPermissionToUser($data): void
    {
        $userId = $data['user_id'];
        $permissions = $data['permissions'] ?? [];

        $user = User::findOrFail($userId);

        // Fetch only permissions belonging to that guard
        $permissionNames = Permission::whereIn('id', $permissions)
            ->pluck('name')
            ->toArray();
        $user->syncPermissions($permissionNames);
    }
}
