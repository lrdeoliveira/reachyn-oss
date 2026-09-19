<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * S2 (PLANO-UX-INTERFACE): "sua geração ficou pronta" — enviado SÓ para jobs LONGOS
 * (desenho/história/quadrinho completo, model sheet), nunca para mídia avulsa rápida.
 * Throttle é responsabilidade de quem dispara (1 e-mail por projeto concluído).
 */
class GenerationReady extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $what,      // ex.: 'Seu desenho "A raposa astuta"' / 'O model sheet de "Rex"'
        public string $url,       // link direto pro resultado no Studio
        public string $cta = 'Ver o resultado',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '🎉 Pronto! '.mb_substr($this->what, 0, 80));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.generation-ready', with: [
            'what' => $this->what,
            'url' => $this->url,
            'cta' => $this->cta,
        ]);
    }
}
