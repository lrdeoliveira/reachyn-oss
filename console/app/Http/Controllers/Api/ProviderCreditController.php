<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Saldo das contas nos PROVEDORES de IA. Duas rotas, o MESMO payload:
 *   - GET /api/provider-credit         → contador da sidebar do operador (histórico)
 *   - GET /api/admin/provider-balances → painel da página Chaves & API (nome explícito)
 *
 * POR QUE EXISTE: o saldo do provedor é o que quebra a geração paga quando zera, e não havia
 * onde ver isso sem entrar no painel de cada um. Em 2026-07-20 o saldo do KIE acabou e o sintoma
 * chegou como "geração falhando", não como "acabou o crédito" — este painel encurta esse caminho.
 * 2026-08-01: mesma lógica pro Higgsfield (bridge no host, conta de assinatura Plus).
 * 2026-08-02: ElevenLabs entra medido em CARACTERES (não em créditos) — a conta lá é uma cota
 * mensal que reseta, então o que interessa é usado/limite + a data do reset, não um saldo solto.
 *
 * NÃO CONFUNDIR com /api/usage, que é a cota do PLANO do cliente (créditos internos). Aqui é
 * dinheiro/crédito real na conta do provedor, e por isso:
 *   - só OPERADOR vê (403 pro resto): é informação de bastidor e cita o provedor pelo nome,
 *     o que fere o white-label (guideline #6) se vazar pra cliente;
 *   - a chave/token NUNCA vai pro navegador nem pro log — quem fala com o provedor é o servidor;
 *   - cache curto (60s no painel completo, 5min por provedor): saldo muda devagar e um refresh
 *     de tela não pode virar rajada de requisição no provedor.
 *
 * FONTES (todas verificadas em produção, não deduzidas):
 *   (o agregador saiu daqui em 2026-08-04, junto com o motor: consultar saldo de um provedor
 *   que ninguém mais chama só mantinha viva uma credencial sem dono.)
 *   - Higgsfield: NÃO tem API REST nossa. O saldo vem do bridge (tools/cli-bridge) em
 *     GET {CLI_BRIDGE_URL}/health → providers.higgsfield.credits.
 *   - ElevenLabs: GET https://api.elevenlabs.io/v1/user/subscription (header xi-api-key) →
 *     character_count / character_limit / next_character_count_reset_unix / tier. Essa chave
 *     precisa do escopo `user_read`; sem ele o provedor devolve 401 missing_permissions, que é
 *     um problema de PERMISSÃO e não "saldo zerado" — por isso tem mensagem própria.
 *   - MiniMax e Google: sem endpoint de saldo verificado. Aparecem como `ok:false` com aviso
 *     explícito. Exibir número inventado aqui seria pior que admitir que não sabemos.
 *
 * Falha em QUALQUER provedor não derruba os outros: cada item carrega seu próprio ok/erro.
 */
class ProviderCreditController extends Controller
{
    /** Provedores sem endpoint de saldo conhecido — declarados, não silenciados. */
    private const SEM_SALDO = [
        ['id' => 'minimax', 'label' => 'MiniMax'],
        ['id' => 'google', 'label' => 'Google'],
    ];

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isOperator(), 403);

        $provedores = Cache::remember('provider:balances:v2', 60, fn () => array_merge(
            [$this->higgsfield(), $this->elevenlabs()],
            array_map(fn ($p) => $p + [
                'tipo' => null,
                'saldo' => null,
                'unidade' => null,
                'ok' => false,
                'erro' => 'Este provedor não expõe consulta de saldo — confira direto no painel dele.',
            ], self::SEM_SALDO),
        ));

        // Retrocompat com o contador da sidebar (<ProviderCredit/>), que lê `saldos` como
        // [{provider,balance}] e assume que todo item TEM número: aqui só entram os que deram
        // certo e são contáveis em crédito.
        $saldos = array_values(array_map(
            fn ($p) => ['provider' => $p['label'], 'balance' => (float) $p['saldo']],
            array_filter($provedores, fn ($p) => $p['ok'] && $p['tipo'] === 'creditos' && $p['saldo'] !== null),
        ));
        $primeiro = $saldos[0] ?? null;

        return response()->json([
            'ok' => $primeiro !== null,
            'provider' => $primeiro['provider'] ?? null,
            'balance' => $primeiro['balance'] ?? null,
            'saldos' => $saldos,
            'provedores' => $provedores,
        ]);
    }

    private function higgsfield(): array
    {
        $base = ['id' => 'higgsfield', 'label' => 'Higgsfield', 'tipo' => 'creditos', 'unidade' => 'créditos'];

        $url = (string) config('services.cli_bridge.url', '');
        if ($url === '') {
            return $base + ['saldo' => null, 'ok' => false, 'erro' => 'Bridge de CLI não configurado.'];
        }

        $r = Cache::remember('provider:higgsfield:credit:v2', 300, function () use ($url) {
            try {
                $req = Http::timeout(5)->acceptJson();
                if ($token = (string) config('services.cli_bridge.token', '')) {
                    $req = $req->withToken($token);
                }
                $resp = $req->get(rtrim($url, '/').'/health');
                if (! $resp->successful()) {
                    return ['saldo' => null, 'erro' => 'Bridge indisponível (HTTP '.$resp->status().').'];
                }
                $v = $resp->json('providers.higgsfield.credits');

                return is_numeric($v)
                    ? ['saldo' => (float) $v, 'erro' => null]
                    : ['saldo' => null, 'erro' => 'Bridge no ar, mas sem saldo do Higgsfield (CLI deslogada?).'];
            } catch (\Throwable $e) {
                Log::debug('saldo Higgsfield indisponível', ['erro' => $e->getMessage()]);

                return ['saldo' => null, 'erro' => 'Não foi possível falar com o bridge.'];
            }
        });

        return $base + ['saldo' => $r['saldo'], 'ok' => $r['saldo'] !== null, 'erro' => $r['erro']];
    }

    /**
     * ElevenLabs é cota de CARACTERES que reseta todo ciclo — o número útil é o que RESTA
     * (limite - usado) mais a data do reset, não um "saldo" abstrato.
     */
    private function elevenlabs(): array
    {
        $base = ['id' => 'elevenlabs', 'label' => 'ElevenLabs', 'tipo' => 'caracteres', 'unidade' => 'caracteres'];

        $key = (string) config('services.elevenlabs.key', '');
        if ($key === '') {
            return $base + ['saldo' => null, 'ok' => false, 'erro' => 'Chave não configurada.'];
        }

        $r = Cache::remember('provider:elevenlabs:credit:v2', 300, function () use ($key) {
            try {
                $resp = Http::withHeaders(['xi-api-key' => $key])->acceptJson()->timeout(5)
                    ->get('https://api.elevenlabs.io/v1/user/subscription');
                if ($resp->status() === 401) {
                    // 401 aqui quase sempre é escopo faltando (`user_read`), não chave inválida —
                    // dizer "sem saldo" mandaria o operador caçar o problema no lugar errado.
                    return ['erro' => 'A chave não tem permissão de leitura de conta (escopo user_read). Habilite no painel do provedor.'];
                }
                if (! $resp->successful()) {
                    return ['erro' => 'Provedor indisponível (HTTP '.$resp->status().').'];
                }
                $limite = $resp->json('character_limit');
                $usado = $resp->json('character_count');
                if (! is_numeric($limite) || ! is_numeric($usado)) {
                    return ['erro' => 'Resposta do provedor sem a cota de caracteres.'];
                }
                $reset = $resp->json('next_character_count_reset_unix');

                return [
                    'limite' => (int) $limite,
                    'usado' => (int) $usado,
                    'saldo' => max(0, (int) $limite - (int) $usado),
                    'plano' => is_string($resp->json('tier')) ? $resp->json('tier') : null,
                    'reset_em' => is_numeric($reset) ? gmdate('c', (int) $reset) : null,
                    'erro' => null,
                ];
            } catch (\Throwable $e) {
                Log::debug('saldo ElevenLabs indisponível', ['erro' => $e->getMessage()]);

                return ['erro' => 'Não foi possível falar com o provedor.'];
            }
        });

        return $base + [
            'saldo' => $r['saldo'] ?? null,
            'limite' => $r['limite'] ?? null,
            'usado' => $r['usado'] ?? null,
            'plano' => $r['plano'] ?? null,
            'reset_em' => $r['reset_em'] ?? null,
            'ok' => ($r['saldo'] ?? null) !== null,
            'erro' => $r['erro'],
        ];
    }
}
