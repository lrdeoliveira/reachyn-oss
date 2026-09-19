<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Export / portabilidade de dados (LGPD art. 18, V) — Reachyn.
 * Dump em JSON dos dados da ORGANIZATION (conta) e seus tenants/conteúdo.
 *
 * Minimização/segurança: NUNCA exporta segredos (senha, tokens de conexão,
 * search_keys cifradas, recovery codes). Exporta o conteúdo e os metadados do titular.
 */
class DataExportService
{
    /** Chaves sensíveis removidas de qualquer registro exportado (allowlist de remoção). */
    protected array $stripKeys = [
        'password', 'remember_token', 'deletion_token',
        'search_keys', 'access_token', 'refresh_token', 'token', 'secret',
        'app_authentication_secret', 'app_authentication_recovery_codes',
        'pm_type', 'pm_last_four', 'two_factor_secret',
    ];

    /** Tabelas de conteúdo por tenant_id a exportar (se existirem). */
    protected array $tenantTables = [
        'profiles', 'connections', 'drafts', 'publications',
        'approvals', 'usages', 'characters', 'prompts', 'credit_transactions',
    ];

    public function export(Organization $org): array
    {
        $tenants = [];
        foreach (Tenant::where('organization_id', $org->id)->get() as $tenant) {
            $tenants[] = [
                'tenant' => $this->clean($tenant->toArray()),
                'content' => $this->tenantContent($tenant),
            ];
        }

        return [
            'exported_at' => now()->toIso8601String(),
            'organization' => $this->clean($org->toArray()),
            'users' => DB::table('users')->where('organization_id', $org->id)->get()
                ->map(fn ($u) => $this->clean((array) $u))->all(),
            'tenants' => $tenants,
            '_note' => 'Export de portabilidade (LGPD art. 18, V). Segredos e credenciais foram omitidos por segurança.',
        ];
    }

    /** Conteúdo das tabelas dependentes do tenant (somente as que existem no schema). */
    protected function tenantContent(Tenant $tenant): array
    {
        $out = [];
        foreach ($this->tenantTables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }
            $rows = DB::table($table)->where('tenant_id', $tenant->id)->get();
            $out[$table] = $rows->map(fn ($r) => $this->clean((array) $r))->all();
        }

        return $out;
    }

    /** Remove campos sensíveis (case-insensitive, por substring) de um registro. */
    protected function clean(array $row): array
    {
        foreach (array_keys($row) as $k) {
            $kl = strtolower((string) $k);
            foreach ($this->stripKeys as $bad) {
                if ($kl === $bad || str_contains($kl, 'token') || str_contains($kl, 'secret') || str_contains($kl, 'password')) {
                    unset($row[$k]);
                    break;
                }
            }
        }

        return $row;
    }
}
