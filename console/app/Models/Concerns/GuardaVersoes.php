<?php

namespace App\Models\Concerns;

use App\Models\AssetVersion;

/**
 * Versiona automaticamente as imagens do model: antes de uma URL ser TROCADA, a antiga vira uma
 * `AssetVersion`.
 *
 * POR QUE NO EVENTO, e não em cada controller: a URL de um personagem é escrita em pelo menos
 * quatro lugares (upload manual, job da base, model sheet, restauração de versão) e a de um cenário
 * em três. Versionar em cada um significaria esquecer de um — e o esquecido seria justamente o que
 * apaga sem volta. Aqui é um gancho só, e ele pega todo caminho que passe pelo Eloquent.
 *
 * Só grava quando havia algo ANTES e o valor mudou de verdade: gerar a primeira imagem não cria
 * versão de nada.
 */
trait GuardaVersoes
{
    /** Campos de URL que este model versiona. */
    abstract public function camposVersionados(): array;

    /** Chave do dono na API ('character' | 'scenario' | 'element'). */
    abstract public function tipoDeAsset(): string;

    protected static function bootGuardaVersoes(): void
    {
        static::updating(function ($model) {
            foreach ($model->camposVersionados() as $campo) {
                $antes = (string) $model->getOriginal($campo);
                $depois = (string) $model->{$campo};
                if ($antes === '' || $antes === $depois) {
                    continue;
                }
                AssetVersion::create([
                    'tenant_id' => $model->tenant_id,
                    'owner_type' => $model->tipoDeAsset(),
                    'owner_id' => $model->id,
                    'campo' => $campo,
                    'url' => mb_substr($antes, 0, 500),
                    // O motor da versão ANTIGA é o que estava gravado antes desta escrita.
                    'model' => $model->getOriginal('image_model') ?: null,
                ]);
            }
        });
    }
}
