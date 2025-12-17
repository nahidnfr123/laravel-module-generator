<?php

namespace App\Services\Auth;

use App\Mail\VerifyEmailMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use NahidFerdous\Shield\Models\EmailVerificationToken;

class AuthServiceFactory
{
    public static function password(): PasswordService
    {
        return app(PasswordService::class);
    }

    public static function verification(): VerificationService
    {
        return app(VerificationService::class);
    }

    public function sendVerificationEmail($user): void
    {
        // Delete any existing tokens for this user
        EmailVerificationToken::where('user_id', $user->id)->delete();

        // Generate new token
        $token = Str::random(64);
        $expiresAt = now()->addHours(2);

        EmailVerificationToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => $expiresAt,
        ]);

        // Generate verification URL
        $url = (string) config('shield.emails.verify_email.redirect_url', url(config('shield.route_prefix').'/verify-email'));
        $redirectUrl = $url.'?token='.$token;

        // Send email
        Mail::to($user->email)->send(new VerifyEmailMail($user, $redirectUrl));
    }
}
