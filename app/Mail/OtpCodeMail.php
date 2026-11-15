<?php

namespace App\Mail;

use App\Enums\CodeAudience;
use App\Models\EmailVerificationCode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A sign-in code, or a code to delete the account. Sent from the configured `mail.from` address
 * on `itstotallypossible.life`.
 */
class OtpCodeMail extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new message instance.
     */
    public function __construct(public string $code, public CodeAudience $audience = CodeAudience::App) {}

    public function isForAccountDeletion(): bool
    {
        return $this->audience === CodeAudience::AccountDeletion;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isForAccountDeletion()
                ? "your code to delete your it's totally possible account"
                : "your verification code for it's totally possible",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.otp-code',
            text: 'mail.otp-code-text',
            with: ['lifetimeMinutes' => EmailVerificationCode::LIFETIME_MINUTES, ...$this->copy()],
        );
    }

    /**
     * The lines that differ between a sign-in code and a deletion code.
     *
     * @return array{title: string, heading: string, intro: string, warning: ?string, ignore: string}
     */
    protected function copy(): array
    {
        if ($this->isForAccountDeletion()) {
            return [
                'title' => 'Delete your account',
                'heading' => 'Here is your code to delete your account:',
                'intro' => "Your code to delete your it's totally possible account is:",
                'warning' => 'Entering this code deletes your account and everything in it. This cannot be undone.',
                'ignore' => 'If you did not ask to delete your account, ignore this email. Your account stays as it is.',
            ];
        }

        return [
            'title' => 'Verification Code',
            'heading' => 'Here is your verification code:',
            'intro' => "Your verification code for it's totally possible is:",
            'warning' => null,
            'ignore' => 'If you did not request this code, no further action is required.',
        ];
    }
}
