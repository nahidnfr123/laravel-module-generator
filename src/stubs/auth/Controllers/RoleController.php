<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpsertRoleRequest;
use App\Http\Resources\RoleCollection;
use App\Http\Resources\RoleResource;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Services\RoleService;
use App\Traits\ApiResponseTrait;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class RoleController extends Controller implements HasMiddleware
{
    use ApiResponseTrait;

    public function __construct(protected RoleService $roleService) {}

    public static function middleware(): array
    {
        $model = 'role';

        return [
            'auth',
            new Middleware(["permission:view-$model"], only: ['index']),
            new Middleware(["permission:show-$model"], only: ['show']),
            new Middleware(["permission:create-$model"], only: ['store']),
            new Middleware(["permission:update-$model"], only: ['update']),
            new Middleware(["permission:delete-$model"], only: ['destroy']),
        ];
    }

    public function index(): \Illuminate\Http\JsonResponse
    {
        $roles = $this->roleService->getAll();

        return $this->success('Success.', RoleCollection::make($roles));
    }

    public function show(Role $role): \Illuminate\Http\JsonResponse
    {
        return $this->success('Success.', [
            'users' => UserResource::collection($role->users),
            'permissions' => $role->permissions->pluck('name'),
        ]);
    }

    public function store(UpsertRoleRequest $request): \Illuminate\Http\JsonResponse
    {
        $role = $this->roleService->store($request);

        return $this->success('Role created successfully.', new RoleResource($role));
    }

    public function update(UpsertRoleRequest $request, Role $role): \Illuminate\Http\JsonResponse
    {
        $this->roleService->update($request, $role);

        return $this->success('Role updated successfully.', new RoleResource($role));
    }

    public function destroy($id): \Illuminate\Http\JsonResponse
    {
        $role = Role::withTrashed()->findOrFail($id);

        if (! $role) {
            return $this->failure('Role not found.', 404);
        }

        if (in_array($role->name, ['super-admin', 'admin'], true)) {
            return $this->failure('You cannot delete this role.', 403);
        }

        if ($role->users()->exists()) {
            return $this->failure('You cannot delete this role because it is assigned to users.', 403);
        }
        $role->forceDelete();

        // Cache::forget('roles');
        return $this->success('Role deleted successfully.');
    }
}
