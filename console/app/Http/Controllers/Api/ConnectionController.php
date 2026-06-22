<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Services\ZernioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conexões do dashboard: redes sociais (OAuth white-label Zernio) + blog/WordPress (chave manual).
 * Porta do /api/connections do reachyn-os.
 */
class ConnectionController extends Controller
{
    /** Blog/manual (chave) — redes sociais vão por OAuth (ZernioService::NETWORKS). */
    private const MANUAL = [
        [
            'key' => 'wordpress',
            'label' => 'WordPress (Blog)',
            'auth' => 'key',
            'fields' => [
                ['name' => 'site_url', 'label' => 'URL do site (https://...)', 'type' => 'text'],
                ['name' => 'username', 'label' => 'Usuário', 'type' => 'text'],
                ['name' => 'app_password', 'label' => 'Application Password', 'type' => 'password'],
            ],
        ],
    ];

    private function tenant(Request $r)
    {
        $t = $r->user()->tenant;
        abort_unless($t, 404, 'Usuário sem tenant.');

        return $t;
    }

    public function index(Request $r, ZernioService $zernio): JsonResponse
    {
        $t = $this->tenant($r);

        $accounts = [];
        try {
            if ($t->zernio_profile_id) {
                $accounts = array_map(fn ($a) => [
                    'id' => $a['_id'] ?? '',
                    'platform' => $a['platform'] ?? '',
                    'name' => $a['name'] ?? $a['username'] ?? 'conta',
                ], $zernio->listAccounts($t->zernio_profile_id));
            }
        } catch (\Throwable $e) {
            // Zernio indisponível → sem contas, não quebra a página
        }

        $connections = Connection::where('tenant_id', $t->id)->get()
            ->map(fn (Connection $c) => ['id' => (string) $c->id, 'platform' => $c->platform, 'label' => $c->label, 'status' => $c->status, 'detail' => $c->detail]);

        return response()->json([
            'ok' => true,
            'platforms' => self::MANUAL,
            'connections' => $connections,
            'networks' => ZernioService::NETWORKS,
            'accounts' => $accounts,
        ]);
    }

    /** Salva conexão por chave (WordPress). */
    public function store(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $platform = (string) $r->input('platform');
        $creds = (array) $r->input('credentials', []);

        $conn = Connection::updateOrCreate(
            ['tenant_id' => $t->id, 'platform' => $platform, 'label' => $creds['site_url'] ?? ''],
            ['secret' => json_encode($creds), 'status' => 'connected', 'detail' => $creds['site_url'] ?? 'conectado'],
        );

        return response()->json(['ok' => true, 'detail' => 'Conexão salva.', 'id' => $conn->id]);
    }

    /** Desconecta uma conta social (Zernio). */
    public function destroy(Request $r, ZernioService $zernio): JsonResponse
    {
        $t = $this->tenant($r);
        $accountId = (string) $r->input('accountId');

        // AUD-003: o token Zernio é global (1 p/ todos os profiles). Sem validar ownership,
        // qualquer tenant desconectaria a conta de outro. Restringe ao profile do chamador.
        $owned = collect($zernio->listAccounts($t->zernio_profile_id))
            ->pluck('_id')->filter()->all();
        abort_unless(in_array($accountId, $owned, true), 403, 'Conta de outro tenant.');

        try {
            $zernio->disconnectAccount($accountId);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'falha ao desconectar'], 502);
        }
    }
}
