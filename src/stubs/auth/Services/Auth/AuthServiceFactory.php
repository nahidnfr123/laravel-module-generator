<?php

namespace App\Services\Auth;

class AuthServiceFactory
{
    public static function password(): ShieldPasswordService
    {
        return app(ShieldPasswordService::class);
    }

    public static function verification(): ShieldVerificationService
    {
        return app(ShieldVerificationService::class);
    }
}
