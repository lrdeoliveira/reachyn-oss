<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E-mail #2 do fluxo de exclusão de conta: confirma que o purge foi executado (transparência).
 */
class AccountDeletionCompleted extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $organizationName) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Sua conta no Reachyn foi excluída — '.$this->organizationName,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.account-deletion-completed',
            with: ['organizationName' => $this->organizationName],
        );
    }
}
