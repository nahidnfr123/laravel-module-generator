<?php

namespace App\Http\Controllers;

use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Services\Auth\AuthServiceFactory;
use App\Services\AuthService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use ApiResponseTrait;

    public AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Handle user login
     */
    public function login(LoginRequest $request): ?\Illuminate\Http\JsonResponse
    {
        try {
            return $this->success('Login successful', $this->authService->login($request->validated()));
        } catch (\Exception $e) {
            return $this->failure($e->getMessage(), $e->getCode() ?? 400);
        }
    }

    public function register(RegisterRequest $request): ?\Illuminate\Http\JsonResponse
    {
        try {
            return $this->success('Register successful', $this->authService->register($request->validated()));
        } catch (\Exception $e) {
            return $this->failure($e->getMessage(), $e->getCode() ?? 400);
        }
    }

    public function logout(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success('Successfully logged out.');
    }

    public function me(Request $request): \Illuminate\Http\JsonResponse
    {
        return $this->success('User details', $request->user());
    }

    public function verifyEmail(string $token): \Illuminate\Http\JsonResponse
    {
        $verified = AuthServiceFactory::verification()->verifyEmail($token);

        return $verified
            ? $this->success('Email verified successfully')
            : $this->failure('Invalid or expired verification token', 400);
    }

    public function resendEmailVerificationLink(Request $request): \Illuminate\Http\JsonResponse {}

    public function forgotPassword(ForgotPasswordRequest $request): \Illuminate\Http\JsonResponse
    {
        try {
            AuthServiceFactory::password()->sendResetLink($request);

            return $this->success('If the email exists, a password reset link has been sent');
        } catch (\Exception $e) {
            return $this->failure($e->getMessage(), $e->getCode() ?? 400);
        }
    }

    public function resetPassword(ResetPasswordRequest $request): \Illuminate\Http\JsonResponse
    {
        $status = AuthServiceFactory::password()->resetPassword($request);

        return $status === \Password::PASSWORD_RESET
            ? $this->success('Password reset successfully')
            : $this->failure(__($status), 400);
    }
}
