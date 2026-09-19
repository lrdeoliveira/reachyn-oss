<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E-mail #1 do fluxo de exclusão de conta: confirma o agendamento e oferece o link de cancelar.
 */
class AccountDeletionRequested extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $organizationName,
        public string $scheduledFor,
        public string $cancelToken,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '⚠️ Exclusão de conta agendada — '.$this->organizationName,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.account-deletion-requested',
            with: [
                'organizationName' => $this->organizationName,
                'scheduledFor' => $this->scheduledFor,
                'cancelUrl' => url('/account/deletion/cancel/'.$this->cancelToken),
            ],
        );
    }
}
