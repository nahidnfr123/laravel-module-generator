<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignPermissionToRoleRequest;
use App\Http\Requests\AssignPermissionToUserRequest;
use App\Http\Resources\PermissionCollection;
use App\Services\PermissionService;
use App\Traits\ApiResponseTrait;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PermissionController extends Controller implements HasMiddleware
{
    use ApiResponseTrait;

    public function __construct(protected PermissionService $permissionService) {}

    public static function middleware(): array
    {
        $model = 'permission';

        return [
            'auth',
            new Middleware(["permission:view-$model"], only: ['index']),
            new Middleware(["permission:update-$model"], only: ['assignPermissionToRole', 'assignPermissionToUser']),
        ];
    }

    public function index(): \Illuminate\Http\JsonResponse
    {
        $permissions = $this->permissionService->getAll();

        return $this->success('Success.', PermissionCollection::make($permissions));
    }

    public function assignPermissionToRole(AssignPermissionToRoleRequest $request): \Illuminate\Http\JsonResponse
    {
        try {
            $this->permissionService->assignPermissionToRole($request->validated());

            return $this->success('Permissions assigned to role successfully.');
        } catch (\Exception $e) {
            return $this->failure($e->getMessage(), $e->getCode());
        }
    }

    public function assignPermissionToUser(AssignPermissionToUserRequest $request): \Illuminate\Http\JsonResponse
    {
        try {
            $this->permissionService->assignPermissionToUser($request->validated());

            return $this->success('Permissions assigned to user successfully.');
        } catch (\Exception $e) {
            return $this->failure($e->getMessage(), $e->getCode());
        }
    }
}
