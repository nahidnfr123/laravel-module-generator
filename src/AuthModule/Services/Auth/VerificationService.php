<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\URL;

class VerificationService
{
    /**
     * Verify email using signed URL params
     */
    public function verify(int $userId, string $hash): bool
    {
        $user = User::find($userId);

        if (! $user) {
            return false;
        }
        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return false;
        }
        if ($user->hasVerifiedEmail()) {
            return true;
        }
        $user->markEmailAsVerified();
        event(new Verified($user));

        return true;
    }

    /**
     * Resend verification email
     */
    public function resend(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $this->sendVerificationEmail($user);
    }

    /**
     * Generate API-friendly signed URL
     */
    public function sendVerificationEmail(User $user): void
    {
        $frontendUrl = config('app.frontend_url').'/verify-email';

        $signedUrl = URL::temporarySignedRoute('api.verification.verify', now()->addMinutes(60), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        // Convert API URL → frontend URL
        $redirectUrl = $frontendUrl.'?'.parse_url($signedUrl, PHP_URL_QUERY);

        // Send mail
        \Mail::to($user->email)->send(new \App\Mail\VerifyEmailMail($user, $redirectUrl));
    }
}
