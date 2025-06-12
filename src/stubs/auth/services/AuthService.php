<?php

namespace App\Services;

use App\Mail\PasswordResetEmail;
use App\Mail\UserAccountCreateMail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * @throws ValidationException
     */
    public function login($credentials): object
    {
        if (!Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Incorrect credentials.'],
            ]);
        }
        $user = Auth::user();
        if (!$user || !$user->status) {
            throw ValidationException::withMessages([
                'email' => ['Your account is inactive. Please contact the administrator.'],
            ]);
        }

        $roles = $user->getRoleNames();
        $token = $user->createToken('user' . 'Token', ['check-' . (implode(',', $roles->toArray()) ?? 'user')]);

        return (object)[
            'user' => $user,
            'token' => $token->plainTextToken,
            'roles' => $roles,
        ];
    }

    public function forgetPassword($data): void
    {
        // Set the frontend URL if present in the request
        if (isset($data['redirect_url'])) {
            config(['app.frontend_url' => $data['redirect_url']]);
        }

        $user = User::where('email', $data['email'])->firstOrFail();

        // $token = Password::createToken($user);
        $resetLink = new AuthService()->createPasswordResetLink($user);

        Mail::to($user->email)->queue(new PasswordResetEmail($user, $resetLink));

//        $status = Password::sendResetLink($request->only('email'));
//
//        if ($status === Password::RESET_LINK_SENT) {
//            return $this->success(__($status));
//        }
//
//        return $this->failure(__($status), 422);
    }

    /**
     * @throws ValidationException
     */
    public function resetPassword($request)
    {
        $passwordReset = DB::table('password_reset_tokens')->where('token', $request->token)->first();
        if (!$passwordReset) {
            abort(422, 'Password reset token is invalid.');
        }

        if (Carbon::parse($passwordReset->created_at)->addMinutes(720)->isPast()) {
            DB::table('password_reset_tokens')->where('token', $request->token)->delete(); // Corrected deletion
            abort(422, 'Password reset token has expired.');
        }

        $user = User::where('email', $request->email)->first();
        $user->update(['password' => Hash::make($request->password)]);
        DB::table('password_reset_tokens')->where('token', $request->token)->delete(); // Corrected deletion

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();
                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return $this->success(__($status));
        }
        return $this->failure(__($status), 422);
    }

    public function createPasswordResetLink($user): string
    {
        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->delete();
        $token = Str::random(60);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => $token,
            'created_at' => now(),
        ]);

        // Build the reset link
        return config('app.frontend_url') . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);
    }

    public
    function sendAccountCreateMail($user, $password): void
    {
        $resetPasswordLink = $this->createPasswordResetLink($user);
        Mail::to($user->email)->queue(new UserAccountCreateMail($user, $password, $resetPasswordLink));
    }
}
