<?php

namespace App\Http\Controllers\v1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\ForgetPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\VerifyForgotPasswordTokenRequest;
use App\Http\Resources\User\MeUserResource;
use App\Http\Resources\User\UserResource;
use App\Mail\PasswordResetEmail;
use App\Models\User;
use App\Services\AuthService;
use App\Services\UserService;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Resend\Laravel\Facades\Resend;

class AuthController extends Controller
{
    use ApiResponseTrait;

    /**
     * @throws ValidationException
     * @throws \Exception
     */
    public function login(LoginRequest $request): \Illuminate\Http\JsonResponse
    {
        try {
            $auth = new AuthService()->login($request->validated());

            return $this->success('Login Successful.', ['token' => $auth->token, 'roles' => $auth->roles ?? []]);
        } catch (\Exception $e) {
            return $this->failure($e->getMessage());
        }
    }

    public function register(UserStoreRequest $request): \Illuminate\Http\JsonResponse
    {
        try {
            new UserService()->store($request);
            $auth = new AuthService()->login($request->only('email', 'password'));

            return $this->success('User created successfully', [
                'user' => new UserResource($auth->user),
                'roles' => $auth->roles ?? []
            ]);
        } catch (\Exception $e) {
            return $this->failure($e->getMessage());
        }
    }

    public function logout(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return $this->success('Successfully logged out.');
    }

    public function getAuthUser(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = User::with('roles')->findOrFail(auth()->id());
        return $this->success('Success.', new MeUserResource($user));
    }

    /**
     * @throws \Exception
     */
    public function forgotPassword(ForgetPasswordRequest $request): \Illuminate\Http\JsonResponse
    {
        try {
            new AuthService()->forgetPassword($request->validated());

            return $this->success(__('Password reset link sent.'));
        } catch (\Exception $e) {
            return $this->failure($e->getMessage());
        }
    }

    public function verifyForgotPasswordToken(VerifyForgotPasswordTokenRequest $request): \Illuminate\Http\JsonResponse
    {
        $password_reset = DB::table('password_reset_tokens')->where('token', $request->token)->first();
        if (!$password_reset) {
            return $this->failure('Password reset token is invalid.', 422);
        }

        if (Carbon::parse($password_reset->created_at)->addMinutes(720)->isPast()) {
            $password_reset->delete();
            return $this->failure('Password reset token has expired.', 422);
        }

        return $this->success('Token is valid.');
    }

    public function resetPassword(ResetPasswordRequest $request): \Illuminate\Http\JsonResponse
    {
        try {
            DB::beginTransaction();
            new AuthService()->resetPassword($request->validated());

            DB::commit();
            return $this->success('Password updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->failure($e->getMessage());
        }
    }

    public function changePassword(ChangePasswordRequest $request): \Illuminate\Http\JsonResponse
    {
        $user = User::where('id', auth()->id())->first();

        if (!Hash::check($request->previous_password, $user->password)) {
            return $this->failure('The provided password does not match your current password.', 422);
        }

        $user->update(['password' => Hash::make($request->password)]);
        return $this->success('Password updated successfully.');
    }
}
