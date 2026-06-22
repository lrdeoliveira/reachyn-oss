<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * AUD-005: trilha de auditoria estruturada (OWASP A09 — Security Logging & Monitoring).
 *
 * Registra ações sensíveis (mudança de plano via webhook, decisões de aprovação,
 * consumo premium, eventos de auth) com contexto fixo: timestamp, user_id, tenant_id,
 * ip e ação. Escreve no canal 'audit' se existir; senão cai no canal padrão.
 *
 * NUNCA logar senha/token/secret/chave em claro — passe apenas identificadores
 * (event_id, customer id, voice_id) e metadados não-sensíveis.
 */
class Audit
{
    /**
     * @param  array<string,mixed>  $context  metadados extras (sem segredos)
     */
    public static function log(string $action, array $context = []): void
    {
        $request = request();
        $user = $request?->user();

        $base = [
            'audit' => true,
            'ts' => now()->toIso8601String(),
            'action' => $action,
            'user_id' => $user?->id,
            'tenant_id' => $user?->tenant_id,
            'ip' => $request?->ip(),
        ];

        // Defesa: remove chaves potencialmente sensíveis caso cheguem por engano.
        foreach (['password', 'token', 'secret', 'api_key', 'apikey', 'key', 'authorization'] as $blocked) {
            unset($context[$blocked]);
        }

        static::channel()->info("[AUDIT] {$action}", array_merge($base, $context));
    }

    private static function channel()
    {
        // Usa o canal dedicado 'audit' quando configurado; senão o stack padrão.
        $channels = (array) config('logging.channels', []);

        return isset($channels['audit']) ? Log::channel('audit') : Log::channel();
    }
}
