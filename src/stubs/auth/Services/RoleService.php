<?php

namespace App\Services;

use App\Models\Role;

class RoleService
{
    public function getAll()
    {
        return Role::whereNotIn('slug', ['developer', 'customer'])
            ->latest()
            ->withCount('permissions')
            ->withCount('users')
            ->orderBy(request('order_by', 'name'), request('order', 'ASC'))
            ->get();
    }

    public function store($request): \Spatie\Permission\Contracts\Role|\Spatie\Permission\Models\Role
    {
        $data = $request->validated();
        $data['guard_name'] = $data['guard_name'] ?? 'web';

        // Cache::forget('roles');
        return Role::create($data);
    }

    public function update($request, $role)
    {
        $data = $request->validated();
        $data['guard_name'] = $data['guard_name'] ?? 'web';
        // Cache::forget('roles');
        $role->update($data);

        return $role;
    }
}
