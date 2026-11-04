<?php

namespace App\Mail;

use App\Models\EmailVerificationCode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The sign-in code. Sent from the configured `mail.from` address on `itstotallypossible.life`.
 */
class OtpCodeMail extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new message instance.
     */
    public function __construct(public string $code) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "your verification code for it's totally possible",
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
            with: ['lifetimeMinutes' => EmailVerificationCode::LIFETIME_MINUTES],
        );
    }
}
