<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function login($request): object
    {
        if (! Auth::attempt($request)) {
            throw ValidationException::withMessages(['email' => ['Incorrect credentials.']]);
        }
        $user = Auth::user();
        if (! $user) {
            throw ValidationException::withMessages(['email' => ['Incorrect credentials.']]);
        }
        if (isset($user->status)) {
            $this->checkUserStatus($user);
        }

        $roles = $user->getRoleNames();
        $token = $user->createToken('user'.'Token', ['check-'.(implode(',', $roles->toArray()) ?? 'user')]);

        return (object) [
            'user' => $user,
            'token' => $token->plainTextToken,
            'roles' => $roles,
        ];
    }

    private function checkUserStatus($user): void
    {
        if (! $user || ! $user->status) {
            Auth::logout();
            throw ValidationException::withMessages(['email' => ['User is inactive.']]);
        }
    }

    public function register($request): object
    {
        (new UserService)->store($request);

        return $this->login($request->validated());
    }
}
