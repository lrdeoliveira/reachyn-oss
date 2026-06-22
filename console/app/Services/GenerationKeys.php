<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\ProviderKey;
use Illuminate\Support\Facades\Http;

/**
 * Chaves dos provedores de GERAÇÃO (operador). Fonte da verdade = tabela provider_keys (cifrada).
 * Empurra pro engine no save (efeito imediato) e serve o boot-fetch (/internal/gen-keys).
 * NÃO confundir com as chaves de PESQUISA (tenants.search_keys, BYOK por cliente).
 */
class GenerationKeys
{
    /**
     * Provedores geridos, agrupados por função (white-label: SÓ rótulos genéricos na UI).
     * O campo `key` é o CONTRATO DE FIO com o engine (tags JSON de genkeys.Set) — NÃO renomear
     * sem alinhar o engine. Os `label`/`group`/`desc` são genéricos (sem nome de marca).
     */
    public const PROVIDERS = [
        ['key' => 'text', 'label' => 'Texto/Imagem (primário)', 'group' => 'Texto & Imagem', 'desc' => 'LLM (texto dos posts, briefs, prompts) + imagem (primário).', 'testable' => true, 'configurable' => ['base_url', 'model']],
        ['key' => 'text_alt', 'label' => 'Texto (alternativo)', 'group' => 'Texto & Imagem', 'desc' => 'LLM de fallback (texto).', 'testable' => true, 'configurable' => ['base_url', 'model']],
        ['key' => 'media', 'label' => 'Vídeo/Imagem', 'group' => 'Vídeo & Imagem', 'desc' => 'Vídeo (fila) + imagem i2i + fallback de imagem.', 'testable' => true],
        ['key' => 'premium', 'label' => 'Vídeo premium', 'group' => 'Vídeo & Imagem', 'desc' => 'Vídeo premium com áudio nativo.', 'testable' => true],
        ['key' => 'voice', 'label' => 'Voz', 'group' => 'Voz', 'desc' => 'Narração (voz BR), transcrição, clonagem e dublagem.', 'testable' => true],
    ];

    /** Chave única de gen_lines em app_settings. */
    public const GEN_LINES_KEY = 'gen_lines';

    /**
     * Provedores/modelos válidos por função de GERAÇÃO (allowlist de validação).
     * Diferente das chaves (PROVIDERS): aqui são as "opções" escolhíveis por função.
     * Os valores são o CONTRATO DE FIO com o engine (gen_lines primary/fallback) — o engine
     * casa por essas strings; NÃO renomear sem alinhar o engine.
     *
     * @var array<string,array<int,string>>
     */
    public const GEN_PROVIDERS = [
        'text'  => ['text', 'text-alt'],
        'image' => ['image', 'image-alt'],
        'video' => ['video-a', 'video-b', 'video-c'],
        'voice' => ['voice'],
    ];

    /** Defaults recomendados de principal/fallback por função de GERAÇÃO. */
    public const GEN_LINES_DEFAULT = [
        'text'  => ['primary' => 'text',    'fallback' => 'text-alt'],
        'image' => ['primary' => 'image',   'fallback' => 'image-alt'],
        'video' => ['primary' => 'video-a', 'fallback' => 'video-b'],
        'voice' => ['primary' => 'voice',   'fallback' => ''],
    ];

    /**
     * Campos extra (base_url, model) que um provider aceita; [] se não-configurável.
     *
     * @return array<int,string>
     */
    public static function configurableFields(string $provider): array
    {
        foreach (self::PROVIDERS as $p) {
            if ($p['key'] === $provider) {
                return $p['configurable'] ?? [];
            }
        }

        return [];
    }

    /** @return array<string,string> provider => chave em claro (só as não-vazias) */
    public function all(): array
    {
        $out = [];
        foreach (ProviderKey::all() as $pk) {
            if (trim((string) $pk->api_key) !== '') {
                $out[$pk->provider] = $pk->api_key;
            }
        }

        return $out;
    }

    /**
     * provider => ['base_url'=>?, 'model'=>?] em claro (NÃO secretos), só os providers
     * configuráveis e só os campos não-vazios. Pra UI preencher os campos.
     *
     * @return array<string,array<string,string>>
     */
    public function settings(): array
    {
        $out = [];
        foreach (ProviderKey::all() as $pk) {
            $fields = self::configurableFields($pk->provider);
            if ($fields === []) {
                continue;
            }
            $vals = [];
            foreach ($fields as $f) {
                $v = trim((string) ($pk->{$f} ?? ''));
                if ($v !== '') {
                    $vals[$f] = $v;
                }
            }
            if ($vals !== []) {
                $out[$pk->provider] = $vals;
            }
        }

        return $out;
    }

    /**
     * Linhas efetivas de GERAÇÃO (principal/fallback por função): o que o operador salvou
     * em app_settings OU os defaults recomendados (merge por função). GLOBAL (do operador).
     *
     * @return array<string,array{primary:string,fallback:string}>
     */
    public function genLines(): array
    {
        $saved = (array) AppSetting::getValue(self::GEN_LINES_KEY, []);
        $lines = [];
        foreach (self::GEN_LINES_DEFAULT as $fn => $def) {
            $cur = (array) ($saved[$fn] ?? []);
            $lines[$fn] = [
                'primary' => (string) ($cur['primary'] ?? $def['primary']),
                'fallback' => (string) ($cur['fallback'] ?? $def['fallback']),
            ];
        }

        return $lines;
    }

    /**
     * Salva as linhas de GERAÇÃO (já validadas) em app_settings (GLOBAL).
     *
     * @param  array<string,array{primary:string,fallback:string}>  $lines
     */
    public function setGenLines(array $lines): void
    {
        AppSetting::setValue(self::GEN_LINES_KEY, $lines);
    }

    /** Reseta gen_lines aos defaults (apaga o override do operador). */
    public function forgetGenLines(): void
    {
        AppSetting::forget(self::GEN_LINES_KEY);
    }

    /**
     * Payload no formato do engine (genkeys.Set): todas as chaves geridas (vazio = manter .env).
     * Providers de TEXTO configuráveis também mandam <provider>_base_url e <provider>_model
     * (snake_case) quando setados, no formato esperado pelas tags JSON do engine (genkeys.Set).
     * Inclui também gen_lines (principal/fallback por função de geração) — objeto, mesmo shape
     * que genLines(): { "text":{"primary","fallback"}, "image":{...}, "video":{...}, "voice":{...} }.
     */
    public function payload(): array
    {
        $saved = $this->all();
        $settings = $this->settings();
        $out = [];
        foreach (self::PROVIDERS as $p) {
            $out[$p['key']] = $saved[$p['key']] ?? '';
            foreach (self::configurableFields($p['key']) as $f) {
                $v = $settings[$p['key']][$f] ?? '';
                if ($v !== '') {
                    $out[$p['key'].'_'.$f] = $v; // <provider>_base_url / <provider>_model
                }
            }
        }
        $out['gen_lines'] = $this->genLines();

        return $out;
    }

    /** provider => bool (configurada?) — pra UI sem expor a chave. */
    public function configured(): array
    {
        $saved = $this->all();
        $out = [];
        foreach (self::PROVIDERS as $p) {
            $out[$p['key']] = isset($saved[$p['key']]);
        }

        return $out;
    }

    /**
     * Salva a chave e, opcionalmente, base_url/model (texto puro, só providers configuráveis).
     *
     * @param  array<string,string|null>  $settings  base_url/model (null/'' = não toca)
     */
    public function set(string $provider, string $key, array $settings = []): void
    {
        $attrs = [];
        if (trim($key) !== '') {
            $attrs['api_key'] = $key;
        }
        foreach (self::configurableFields($provider) as $f) {
            if (array_key_exists($f, $settings)) {
                $v = trim((string) ($settings[$f] ?? ''));
                $attrs[$f] = $v !== '' ? $v : null;
            }
        }
        if ($attrs === []) {
            return;
        }
        ProviderKey::updateOrCreate(['provider' => $provider], $attrs);
    }

    public function forget(string $provider): void
    {
        ProviderKey::where('provider', $provider)->delete();
    }

    /** Empurra o conjunto atual pro engine (efeito imediato, sem redeploy). */
    public function pushToEngine(): void
    {
        $base = rtrim((string) config('services.engine.url'), '/');
        $token = (string) config('services.engine.admin_token');
        if ($base === '' || $token === '') {
            return;
        }
        Http::withHeaders(['X-Admin-Token' => $token])->timeout(10)
            ->put($base.'/v1/admin/gen-keys', $this->payload())->throw();
    }

    /**
     * Testa uma chave candidata no engine (sem salvar).
     *
     * @return array{ok:bool,latency_ms?:int,error?:string}
     */
    public function test(string $provider, string $key): array
    {
        $base = rtrim((string) config('services.engine.url'), '/');
        $token = (string) config('services.engine.admin_token');
        if ($base === '' || $token === '') {
            return ['ok' => false, 'error' => 'Engine/admin token não configurado.'];
        }
        try {
            return Http::withHeaders(['X-Admin-Token' => $token])->timeout(35)
                ->post($base.'/v1/admin/test-key', compact('provider', 'key'))->throw()->json();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
