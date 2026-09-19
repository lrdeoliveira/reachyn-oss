<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * S2: aviso diário genérico (aprovações pendentes / créditos acabando / trial expirando).
 * Um Mailable só, parametrizado — evita 3 classes quase idênticas.
 */
class DailyNotice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $headline,
        public string $body,
        public string $url,
        public string $cta,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.daily-notice', with: [
            'headline' => $this->headline,
            'body' => $this->body,
            'url' => $this->url,
            'cta' => $this->cta,
        ]);
    }
}
