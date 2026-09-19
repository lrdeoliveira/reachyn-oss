<?php

namespace App\Services;

use App\Models\Organization;

/**
 * Conta redes conectadas da organização (soma as marcas). Sem cobrança.
 */
class PublishingBilling
{
    public function __construct(private ZernioService $zernio) {}

    private function orgProfileIds(Organization $org): array
    {
        $ids = [];
        foreach ($org->tenants()->get() as $t) {
            $pids = $t->profiles()->whereNotNull('zernio_profile_id')->pluck('zernio_profile_id')->all();
            if (! $pids && $t->zernio_profile_id) {
                $pids = [$t->zernio_profile_id];
            }
            $ids = array_merge($ids, $pids);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /** Nº de contas de rede da ORG. -1 = provedor de publicação indeterminado. */
    public function accountCount(Organization $org): int
    {
        $profileIds = $this->orgProfileIds($org);
        if (! $profileIds) {
            return 0;
        }
        try {
            $all = $this->zernio->listAccounts();
        } catch (\Throwable $e) {
            return -1;
        }

        return collect($all)
            ->filter(fn ($a) => in_array($a['profileId']['_id'] ?? null, $profileIds, true))
            ->count();
    }
}
