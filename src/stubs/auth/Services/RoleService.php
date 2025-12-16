<?php

namespace App\Services;

use App\Models\Role;

class RoleService
{
    public function getAll()
    {
        return Role::withCount('permissions')
            ->withCount('users')
            ->orderBy(request('order_by', 'name'), request('order', 'ASC'))
            ->paginate(request('per_page', 10));
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
