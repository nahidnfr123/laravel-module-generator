<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;

Route::middleware(['api', 'cors'])->group(function () {
    // Authentication routes
    Route::post('login', [AuthController::class, 'login'])->middleware('guest', 'throttle:6,1');
    Route::post('register', [AuthController::class, 'register'])->middleware('guest', 'throttle:6,1');

    // password reset routes
    Route::prefix('password')->group(function () {
        Route::post('forgot', [AuthController::class, 'forgotPassword'])->middleware('guest', 'throttle:6,1');
        Route::post('reset', [AuthController::class, 'resetPassword']);
    });

    // Email verification routes
    Route::get('email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware(['signed', 'throttle:6,1'])->name('api.verification.verify');
    Route::post('email/resend-verification-link', [AuthController::class, 'resendEmailVerificationLink'])
        ->middleware(['auth:sanctum', 'throttle:6,1']);

    // Protected routes
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('profile', [AuthController::class, 'userProfile']);
        Route::post('logout', [AuthController::class, 'logout']);

        Route::post('user/password-change', [UserController::class, 'changePassword']);
    });
});
