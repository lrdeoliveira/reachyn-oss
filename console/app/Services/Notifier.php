<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * S2 (PLANO-UX-INTERFACE): envio de avisos por e-mail com OPT-OUT por usuário e THROTTLE
 * por chave — defaults conservadores pra nunca virar spam (job longo pronto = 1 por projeto;
 * créditos/trial = 1 por semana via cache).
 *
 * Chaves de aviso (users.notify_prefs JSON; ausente = ligado):
 *   generation_ready · approvals_digest · credits_low · trial_ending
 */
class Notifier
{
    /**
     * Envia o mailable pra todos os usuários da organização que não desligaram a chave.
     * $throttleKey (opcional) suprime reenvio pelo TTL — ex.: 'credits_low:org:2' por 7 dias.
     */
    public function send(Organization $org, string $key, Mailable $mailable, ?string $throttleKey = null, int $ttlSeconds = 0): void
    {
        if ($throttleKey !== null && $ttlSeconds > 0 && Cache::has('notify:'.$throttleKey)) {
            return; // já avisado dentro da janela
        }
        $queued = 0;
        foreach ($org->users as $u) {
            $prefs = (array) ($u->notify_prefs ?? []);
            if (($prefs[$key] ?? true) === false) {
                continue; // opt-out explícito
            }
            if (trim((string) $u->email) === '') {
                continue;
            }
            try {
                // ⚠️ clone OBRIGATÓRIO: PendingMail::fill() MUTA o mailable e setAddress() APPENDA —
                // sem clone, o 2º usuário da org receberia To:[u1,u2], o 3º To:[u1,u2,u3]
                // (duplicatas + vazamento de e-mail entre colegas).
                Mail::to($u->email)->queue(clone $mailable);
                $queued++;
            } catch (\Throwable $e) {
                Log::warning('Notifier: falha ao enfileirar e-mail', ['user' => $u->id, 'key' => $key, 'err' => $e->getMessage()]);
            }
        }
        // Throttle marcado SÓ depois de enfileirar de verdade — falha total não "queima" a janela.
        if ($queued > 0 && $throttleKey !== null && $ttlSeconds > 0) {
            Cache::put('notify:'.$throttleKey, 1, $ttlSeconds);
        }
    }
}
