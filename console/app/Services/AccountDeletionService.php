<?php

namespace App\Services;

use App\Http\Controllers\Api\StudioController;
use App\Mail\AccountDeletionCompleted;
use App\Mail\AccountDeletionRequested;
use App\Models\Organization;
use App\Models\Profile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Exclusão de conta (LGPD art. 18, VI) — Reachyn.
 *
 * Fluxo de 3 atos (espelha o Nexusyn): request → carência 7 dias (cancelável) → execute (purge).
 * A "conta" é a ORGANIZATION (users + tenants + todo o conteúdo).
 *
 * Diferença vs Nexusyn: o engine do Reachyn é STATELESS — não há purge no engine.
 * O purge é no banco do console (cascade por tenant_id), perfis sociais e mídia no storage.
 *
 * ⚠️ ATENÇÃO: este código apaga dados de forma IRREVERSÍVEL quando executado.
 * TESTAR EM STAGING (migração + exclusão de uma org de teste + dry-run do command)
 * ANTES de qualquer uso em produção.
 */
class AccountDeletionService
{
    /** Dias de carência antes do purge. */
    public const GRACE_DAYS = 7;

    /**
     * Ato 1 — registra o pedido de exclusão. Agenda o purge para +7 dias,
     * gera token de cancelamento (guarda só o hash), grava auditoria e dispara e-mail #1.
     * Retorna o token EM CLARO (só vai no link do e-mail).
     */
    public function request(Organization $org, User $requester, ?string $ip = null): string
    {
        $token = Str::random(48);
        $scheduledFor = now()->addDays(self::GRACE_DAYS);

        $org->forceFill([
            'deletion_requested_at' => now(),
            'deletion_scheduled_for' => $scheduledFor,
            'deletion_token' => hash('sha256', $token),
            'deletion_requested_by' => $requester->id,
        ])->save();

        DB::table('account_deletion_audits')->insert([
            'organization_id' => $org->id,
            'organization_slug' => $org->slug,
            'requester_user_id' => $requester->id,
            'requester_email_hash' => hash('sha256', (string) $requester->email),
            'status' => 'requested',
            'requested_at' => now(),
            'scheduled_for' => $scheduledFor,
            'request_ip' => $ip,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            Mail::to($requester->email)->send(new AccountDeletionRequested(
                organizationName: (string) ($org->name ?? $org->slug),
                scheduledFor: $scheduledFor->toDateTimeString(),
                cancelToken: $token,
            ));
        } catch (\Throwable $e) {
            Log::warning('AccountDeletion: falha ao enviar e-mail de pedido', ['org' => $org->id, 'err' => $e->getMessage()]);
        }

        return $token;
    }

    /** Cancela um pedido pendente (via painel autenticado). */
    public function cancel(Organization $org): void
    {
        if (! $this->isPending($org)) {
            return;
        }
        $org->forceFill([
            'deletion_requested_at' => null,
            'deletion_scheduled_for' => null,
            'deletion_token' => null,
            'deletion_requested_by' => null,
        ])->save();

        DB::table('account_deletion_audits')
            ->where('organization_id', $org->id)
            ->where('status', 'requested')
            ->update(['status' => 'canceled', 'canceled_at' => now(), 'updated_at' => now()]);
    }

    /** Cancela via link do e-mail (público — o token é a credencial). */
    public function cancelByToken(string $token): bool
    {
        $org = Organization::whereNotNull('deletion_token')
            ->where('deletion_token', hash('sha256', $token))
            ->first();

        if (! $org) {
            return false;
        }
        $this->cancel($org);

        return true;
    }

    public function isPending(Organization $org): bool
    {
        return $org->deletion_scheduled_for !== null;
    }

    /**
     * Ato 3 — purge IRREVERSÍVEL. Idempotente: só executa se ainda pendente.
     *
     * Ordem: captura dados → limpa externos por tenant
     * (perfis sociais + mídia, best-effort) → apaga no banco em transação (tenants cascateiam
     * seus dependentes; depois users; depois a organization) → auditoria + e-mail #2.
     */
    public function execute(Organization $org): void
    {
        if (! $this->isPending($org)) {
            return;
        }

        // Snapshot para e-mail/auditoria ANTES de apagar.
        $ownerEmail = optional($org->users()->first())->email;
        if ($org->deletion_requested_by) {
            $ownerEmail = optional(User::find($org->deletion_requested_by))->email ?? $ownerEmail;
        }
        $orgName = (string) ($org->name ?? $org->slug);
        $orgId = $org->id;
        $orgSlug = $org->slug;

        // 1. Limpeza de serviços externos por tenant (best-effort).
        $tenants = Tenant::where('organization_id', $orgId)->get();
        foreach ($tenants as $tenant) {
            $this->cleanupTenantExternals($tenant);
        }

        // 3. Purge no banco (transação). Cascade por tenant_id apaga profiles, connections,
        //    drafts, approvals, usages, characters, prompts, credit_transactions.
        DB::transaction(function () use ($orgId) {
            foreach (Tenant::where('organization_id', $orgId)->get() as $tenant) {
                $tenant->delete(); // cascadeOnDelete nas tabelas dependentes
            }
            User::where('organization_id', $orgId)->delete();
            Organization::where('id', $orgId)->delete();
        });

        // 4. Auditoria + confirmação.
        DB::table('account_deletion_audits')
            ->where('organization_id', $orgId)
            ->where('status', 'requested')
            ->update([
                'status' => 'executed',
                'executed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($ownerEmail) {
            try {
                Mail::to($ownerEmail)->send(new AccountDeletionCompleted(organizationName: $orgName));
            } catch (\Throwable $e) {
                Log::warning('AccountDeletion: falha ao enviar e-mail de conclusão', ['slug' => $orgSlug, 'err' => $e->getMessage()]);
            }
        }

        Log::info('AccountDeletion: org purgada', ['org' => $orgId, 'slug' => $orgSlug]);
    }

    /**
     * Limpa recursos externos de um tenant antes do purge no banco (best-effort).
     * - Perfis sociais no Zernio (profiles com zernio_profile_id).
     * - Mídia no storage 'media' referenciada por drafts/publications.
     * Falhas são logadas mas NÃO abortam a exclusão (o direito à eliminação prevalece;
     * a mídia remanescente sai no ciclo de backups/retenção).
     */
    protected function cleanupTenantExternals(Tenant $tenant): void
    {
        // Zernio — perfis sociais.
        try {
            $zernio = app(ZernioService::class);
            foreach (Profile::where('tenant_id', $tenant->id)->whereNotNull('zernio_profile_id')->get() as $profile) {
                try {
                    $zernio->deleteProfile((string) $profile->zernio_profile_id);
                } catch (\Throwable $e) {
                    Log::warning('AccountDeletion: falha ao apagar perfil Zernio', ['profile' => $profile->id, 'err' => $e->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('AccountDeletion: Zernio indisponível', ['tenant' => $tenant->id, 'err' => $e->getMessage()]);
        }

        // Mídia no storage 'media' (S3/Scality). As chaves são FLAT ("reachyn/uploads/...")
        // — NÃO há segregação por tenant no path. Apagar por prefixo não funciona (e apagar
        // o diretório global apagaria a mídia de TODOS os tenants). Então varremos as URLs de
        // mídia PRÓPRIAS referenciadas nos registros do tenant e derivamos a key, usando o
        // MESMO critério do StudioController (single source of truth).
        $this->deleteTenantMedia($tenant);
    }

    /**
     * Apaga os objetos de mídia (S3/Scality) referenciados pelos registros de conteúdo do
     * tenant. Genérico: varre o JSON de cada registro, coleta URLs do nosso domínio de mídia
     * (StudioController::isOwnMediaUrl) e deleta a key correspondente. Best-effort.
     * DEVE rodar ANTES do delete no banco (precisa dos registros para achar as URLs).
     */
    protected function deleteTenantMedia(Tenant $tenant): void
    {
        $tables = ['drafts', 'publications', 'characters', 'profiles', 'prompts'];
        try {
            $disk = Storage::disk('media');
            $base = rtrim((string) config('filesystems.disks.media.url'), '/');

            foreach ($tables as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                    continue;
                }
                foreach (DB::table($table)->where('tenant_id', $tenant->id)->cursor() as $row) {
                    $json = json_encode((array) $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if (! $json || ! preg_match_all('#https?://[^\s"\'\\\\]+#', $json, $m)) {
                        continue;
                    }
                    foreach (array_unique($m[0]) as $url) {
                        if (! StudioController::isOwnMediaUrl($url)) {
                            continue;
                        }
                        $path = ($base !== '' && str_starts_with($url, $base)) ? substr($url, strlen($base)) : $url;
                        $key = ltrim((string) parse_url($path, PHP_URL_PATH), '/');
                        if ($key === '') {
                            continue;
                        }
                        try {
                            $disk->delete($key);
                        } catch (\Throwable $e) {
                            Log::warning('AccountDeletion: falha ao apagar objeto de mídia', ['key' => $key, 'err' => $e->getMessage()]);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('AccountDeletion: falha na limpeza de mídia do tenant', ['tenant' => $tenant->id, 'err' => $e->getMessage()]);
        }
    }
}
