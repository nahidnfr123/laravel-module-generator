<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserCollection;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use App\Traits\ApiResponseTrait;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class UserController extends Controller implements HasMiddleware
{
    use ApiResponseTrait;

    public function __construct(protected UserService $userService) {}

    public static function middleware(): array
    {
        $model = 'user';

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
        $users = $this->userService->getAll();

        return $this->success('Success.', UserCollection::make($users));
    }

    public function show(User $user): \Illuminate\Http\JsonResponse
    {
        return $this->success('Success.', new UserResource($user));
    }

    public function store(StoreUserRequest $request): \Illuminate\Http\JsonResponse
    {
        $user = $this->userService->store($request);

        return $this->success('Role created successfully.', new UserResource($user));
    }

    public function update(UpdateUserRequest $request, User $user): \Illuminate\Http\JsonResponse
    {
        $this->userService->update($request, $user);

        return $this->success('Role updated successfully.', new UserResource($user));
    }

    public function destroy(User $user): \Illuminate\Http\JsonResponse
    {
        $this->userService->delete($user);

        return $this->success('Role deleted successfully.');
    }

    public function changePassword(ChangePasswordRequest $request): \Illuminate\Http\JsonResponse
    {
        $user = User::findOrFail(auth()->id());
        $user = $this->userService->changePassword($request, $user);

        return $this->success('Role updated successfully.', new UserResource($user));
    }
}
