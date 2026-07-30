<?php

namespace App\Team\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email a newly-invited user receives. Carries a single-use link to the
 * accept page where they set their own password for the first time.
 */
class UserInvitationMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public User $user,
        public string $acceptUrl,
        public ?string $inviterName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You’ve been invited to '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invitation');
    }
}
