<?php

namespace App\Http\Controllers\Api;

use App\Actions\Account\DeleteAccountAction;
use App\Enums\CodeAudience;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Account\DeleteAccountRequest;
use App\Http\Requests\Api\Account\SendDeletionCodeRequest;
use App\Http\Responses\ApiResponse;
use App\Mail\OtpCodeMail;
use App\Models\EmailVerificationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Itp\Core\Models\User;

/**
 * Account deletion for the web build's deletion page (platform.md §5). No bearer token: a fresh
 * deletion code is the identity check, and only a deletion code can delete.
 */
class AccountController extends Controller
{
    /**
     * Mail a deletion code if the email has an account. The answer is the same either way.
     */
    public function sendDeletionCode(SendDeletionCodeRequest $request): JsonResponse
    {
        $email = $request->validated('email');

        if (User::query()->withTrashed()->where('email', $email)->exists()) {
            Mail::to($email)->send(new OtpCodeMail(
                EmailVerificationCode::issue(CodeAudience::AccountDeletion, $email),
                CodeAudience::AccountDeletion,
            ));
        }

        return ApiResponse::success('If that email has an account, we sent it a code to delete the account.');
    }

    /**
     * Spend a deletion code and hard-delete the account.
     *
     * @throws ValidationException when the code is refused
     */
    public function destroy(DeleteAccountRequest $request, DeleteAccountAction $deleteAccount): JsonResponse
    {
        $email = $request->validated('email');

        $deleted = DB::transaction(function () use ($request, $email, $deleteAccount): bool {
            if (! EmailVerificationCode::consume(CodeAudience::AccountDeletion, $email, $request->validated('code'))) {
                return false;
            }

            $user = User::query()->withTrashed()->where('email', $email)->first();

            if ($user !== null) {
                $deleteAccount->execute($user);
            }

            return true;
        });

        if (! $deleted) {
            throw ValidationException::withMessages([
                'code' => EmailVerificationCode::recordFailedAttempt(CodeAudience::AccountDeletion, $email)->message(),
            ]);
        }

        return ApiResponse::success('Your account is deleted.');
    }
}
