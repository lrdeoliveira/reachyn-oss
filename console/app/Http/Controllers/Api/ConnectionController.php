<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Profile;
use App\Services\ZernioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conexões do dashboard: PERFIS de redes sociais (cada perfil = 1 profile Zernio = 1 conjunto de
 * contas). Uma conta tem N perfis → publica em 1..N de uma vez.
 * Porta do /api/connections do reachyn-os, agora multi-perfil.
 *
 * A conexão por CHAVE MANUAL (WordPress/blog) saiu em 2026-07-29: o produto não publica mais em
 * blog e não havia nenhuma conexão salva em produção (`SELECT ... WHERE platform='wordpress'` =
 * 0 linhas). Além de morta, era insegura — o `store()` gravava o `secret` inteiro sem validar
 * campo nenhum e marcava `status='connected'` sem testar a credencial, então reenviar o form com
 * a senha em branco apagava a credencial salva e ainda mentia que estava conectado. Toda conexão
 * hoje é OAuth via Zernio (`addProfile`/`destroy`).
 */
class ConnectionController extends Controller
{
    private function tenant(Request $r)
    {
        $t = $r->user()->tenant;
        abort_unless($t, 404, 'Usuário sem tenant.');

        return $t;
    }

    /** Garante que o tenant tenha o perfil PADRÃO (espelha tenants.zernio_profile_id). Lazy-backfill
     *  pra tenants cujo profile foi criado fora da migração. */
    private function ensureDefaultProfile($t): void
    {
        if ($t->zernio_profile_id && ! $t->profiles()->where('is_default', true)->exists()) {
            Profile::create([
                'tenant_id' => $t->id,
                'name' => $t->name ?: 'Principal',
                'zernio_profile_id' => $t->zernio_profile_id,
                'is_default' => true,
            ]);
        }
    }

    /** Contas conectadas de um profile Zernio, normalizadas {id,platform,name}. Tolerante a falha. */
    private function accountsOf(ZernioService $zernio, ?string $zernioProfileId): array
    {
        if (! $zernioProfileId) {
            return [];
        }
        try {
            return array_map(fn ($a) => [
                'id' => $a['_id'] ?? '',
                'platform' => $a['platform'] ?? '',
                'name' => $a['name'] ?? $a['username'] ?? 'conta',
            ], $zernio->listAccounts($zernioProfileId));
        } catch (\Throwable $e) {
            return []; // Zernio indisponível → sem contas, não quebra a página
        }
    }

    public function index(Request $r, ZernioService $zernio): JsonResponse
    {
        $t = $this->tenant($r);
        $this->ensureDefaultProfile($t);

        $profiles = $t->profiles()->orderByDesc('is_default')->orderBy('id')->get()->map(fn (Profile $p) => [
            'id' => (string) $p->id,
            'name' => $p->name,
            'is_default' => (bool) $p->is_default,
            'zernio_profile_id' => $p->zernio_profile_id,
            'accounts' => $this->accountsOf($zernio, $p->zernio_profile_id),
        ])->values();

        // Retrocompat: `accounts` (flat) = contas do perfil PADRÃO (telas antigas ainda leem isso).
        $defaultAccounts = $profiles->firstWhere('is_default', true)['accounts'] ?? ($profiles[0]['accounts'] ?? []);

        $connections = Connection::where('tenant_id', $t->id)->get()
            ->map(fn (Connection $c) => ['id' => (string) $c->id, 'platform' => $c->platform, 'label' => $c->label, 'status' => $c->status, 'detail' => $c->detail]);

        return response()->json([
            'ok' => true,
            'connections' => $connections,
            'networks' => ZernioService::NETWORKS,
            'profiles' => $profiles,
            'accounts' => $defaultAccounts,
        ]);
    }

    /**
     * POST /api/connections/profile { name, zernio_profile_id? } → cria um PERFIL novo.
     * Sem `zernio_profile_id` → cria um profile NOVO no Zernio (createProfile). Com `zernio_profile_id`
     * → registra um profile JÁ existente no Zernio (ex.: trazer um profile criado direto no painel).
     */
    public function addProfile(Request $r, ZernioService $zernio): JsonResponse
    {
        $t = $this->tenant($r);
        $name = trim((string) $r->input('name'));
        if ($name === '') {
            return response()->json(['ok' => false, 'error' => 'informe um nome para o perfil'], 422);
        }
        $existing = trim((string) $r->input('zernio_profile_id', ''));
        try {
            $zpid = $existing !== '' ? $existing : $zernio->createProfile($name);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'não foi possível criar o perfil no provedor'], 502);
        }

        $p = Profile::create([
            'tenant_id' => $t->id,
            'name' => $name,
            'zernio_profile_id' => $zpid,
            'is_default' => ! $t->profiles()->exists(), // 1º perfil do tenant vira o padrão
        ]);

        return response()->json(['ok' => true, 'profile' => [
            'id' => (string) $p->id, 'name' => $p->name, 'is_default' => (bool) $p->is_default,
            'zernio_profile_id' => $p->zernio_profile_id, 'accounts' => $this->accountsOf($zernio, $p->zernio_profile_id),
        ]]);
    }

    /** Desconecta uma conta social (Zernio). */
    public function destroy(Request $r, ZernioService $zernio): JsonResponse
    {
        $t = $this->tenant($r);
        $accountId = (string) $r->input('accountId');

        // AUD-003: o token Zernio é global (1 p/ todos os profiles). Sem validar ownership,
        // qualquer tenant desconectaria a conta de outro. Restringe às contas de QUALQUER perfil
        // deste tenant (multi-perfil).
        $owned = $t->profiles->flatMap(fn (Profile $p) => $p->zernio_profile_id
            ? collect($zernio->listAccounts($p->zernio_profile_id))->pluck('_id')
            : collect())->filter()->all();
        abort_unless(in_array($accountId, $owned, true), 403, 'Conta de outro tenant.');

        try {
            $zernio->disconnectAccount($accountId);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'falha ao desconectar'], 502);
        }
    }
}
