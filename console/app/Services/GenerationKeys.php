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
    /** Provedores geridos, agrupados por função (white-label: nomes só no admin). */
    public const PROVIDERS = [
        ['key' => 'minimax', 'label' => 'MiniMax', 'group' => 'Texto & Imagem', 'desc' => 'LLM (texto dos posts, briefs, prompts) + imagem (image-01, primário).', 'testable' => true, 'configurable' => ['base_url', 'model']],
        ['key' => 'ollama', 'label' => 'Ollama', 'group' => 'Texto & Imagem', 'desc' => 'LLM de fallback (texto).', 'testable' => true, 'configurable' => ['base_url', 'model']],
        ['key' => 'google', 'label' => 'Google (Veo)', 'group' => 'Vídeo', 'desc' => 'Vídeo premium com áudio nativo (Veo 3.1).', 'testable' => true],
        ['key' => 'elevenlabs', 'label' => 'ElevenLabs', 'group' => 'Voz', 'desc' => 'Narração (voz BR), transcrição, clonagem e dublagem.', 'testable' => true],
        // ⚠️ O slot do agregador (key 'kie') saiu em 2026-08-04. Ele continuava na tela de Chaves
        // de geração quase um ano depois de o motor sair do ar (2026-08-03): pedia ao operador a
        // chave de um provedor que nenhum caminho chama mais, e ainda estampava o nome dele na
        // interface (guideline #6, white-label). Campo de chave para motor morto não é resíduo
        // inofensivo — é um convite a cadastrar credencial viva num lugar que ninguém audita.
        // 🐛 FALTAVA (2026-08-02): o engine já lia `genkeys.Set.Spriterrific` e, sem chave,
        // respondia "Chave do motor de sprites não configurada — salve em Chaves de geração".
        // Só que esta lista — que É a tela de Chaves de geração — não tinha o campo. A mensagem
        // mandava o operador a um lugar que não existia, e a aba Sprites ficava morta sem
        // qualquer caminho de conserto. O motor LOCAL de sprites não depende desta chave.
        ['key' => 'spriterrific', 'label' => 'Spriterrific', 'group' => 'Sprites', 'desc' => 'Motor hospedado de sprites de jogo (aba Sprites): personagem → âncora direcional + spritesheets de walk/idle/attack. Créditos debitados no provedor. O motor LOCAL da mesma aba não usa esta chave.', 'testable' => true],
    ];

    /** Chave única de gen_lines em app_settings. */
    public const GEN_LINES_KEY = 'gen_lines';

    /**
     * Provedores/modelos válidos por função de GERAÇÃO (allowlist de validação).
     * Diferente das chaves (PROVIDERS): aqui são as "opções" escolhíveis por função.
     *
     * @var array<string,array<int,string>>
     */
    public const GEN_PROVIDERS = [
        'text' => ['minimax', 'ollama'],
        'image' => ['minimax'],
        // ⚠️ 2026-08-04: eram 'hailuo-fast', 'hailuo', 'seedance' e 'kling' — os quatro rodavam
        // pelo agregador e saíram do ar com ele. A escolha de motor de vídeo mudou de lugar: hoje
        // é POR MODELO no catálogo (gen_models → gen_lines montadas em GenPayload), não neste
        // ajuste global. Sobra o Hailuo direto, que tem chave própria e o engine roteia
        // ('minimax' em clipModelOrdered).
        'video' => ['minimax'],
        'voice' => ['elevenlabs'],
    ];

    /** Defaults recomendados de principal/fallback por função de GERAÇÃO. */
    public const GEN_LINES_DEFAULT = [
        'text' => ['primary' => 'minimax',  'fallback' => 'ollama'],
        'image' => ['primary' => 'minimax',  'fallback' => ''],
        // 'hailuo' + 'seedance' eram do agregador (ver GEN_PROVIDERS): recomendar um default que
        // a própria allowlist recusa deixaria a tela salvando 422 no botão "usar recomendado".
        'video' => ['primary' => 'minimax', 'fallback' => ''],
        'voice' => ['primary' => 'elevenlabs', 'fallback' => ''],
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
     * (snake_case) quando setados. Ex.: minimax_base_url, minimax_model, ollama_base_url, ollama_model.
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
