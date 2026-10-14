<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class RecoveryMail extends Mailable
{
    public function __construct(
        public string $nombre,
        public string $modo,          // reset_2fa | reset_password | full_recovery
        public string $loginUrl,
        public ?string $resetLink = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'ProServers: soporte restableció el acceso a tu cuenta');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.recovery');
    }
}
