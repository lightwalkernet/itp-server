<?php

namespace App\Http\Controllers\Api;

use App\Enums\CodeAudience;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\CompleteProfileRequest;
use App\Http\Requests\Api\Auth\SendCodeRequest;
use App\Http\Requests\Api\Auth\VerifyCodeRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Mail\OtpCodeMail;
use App\Models\EmailVerificationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Itp\Core\Models\User;

/**
 * Passwordless email OTP sign-in for both app builds (platform.md §5).
 */
class AuthController extends Controller
{
    /**
     * Mail a code. The answer is the same whether or not the email has an account.
     */
    public function sendCode(SendCodeRequest $request): JsonResponse
    {
        $email = $request->validated('email');

        Mail::to($email)->send(new OtpCodeMail(EmailVerificationCode::issue(CodeAudience::App, $email)));

        return ApiResponse::success('We sent a sign-in code to that email.');
    }

    /**
     * Spend a code and sign in, creating the account on first sign-in. Every sign-in gets a new
     * token; other devices stay signed in.
     *
     * @throws ValidationException when the code is refused
     */
    public function verifyCode(VerifyCodeRequest $request): JsonResponse
    {
        $email = $request->validated('email');

        $signIn = DB::transaction(function () use ($request, $email): ?array {
            if (! EmailVerificationCode::consume(CodeAudience::App, $email, $request->validated('code'))) {
                return null;
            }

            $user = User::firstOrCreate(['email' => $email]);

            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            return [$user, $user->createToken($request->validated('device_name'))->plainTextToken];
        });

        if ($signIn === null) {
            throw ValidationException::withMessages([
                'code' => EmailVerificationCode::recordFailedAttempt(CodeAudience::App, $email)->message(),
            ]);
        }

        [$user, $token] = $signIn;

        return ApiResponse::success('Signed in.', [
            'user' => UserResource::make($user),
            'token' => $token,
            'needs_profile' => $user->first_name === null,
        ]);
    }

    public function completeProfile(CompleteProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update($request->safe()->only(['first_name']));

        return ApiResponse::success('Profile updated.', [
            'user' => UserResource::make($user),
            'needs_profile' => false,
        ]);
    }

    /**
     * Revoke only the token this request was made with.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success('Signed out.');
    }
}
