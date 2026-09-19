<?php

namespace App\Console\Commands;

use App\Models\GenModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Traz o catálogo da conta Higgsfield para o `gen_models`.
 *
 * POR QUE EXISTE: a conta oferece dezenas de modelos de geração e o Reachyn expunha três, porque
 * cada um exigia um adapter escrito à mão no bridge. Com o bridge lendo o schema da própria CLI
 * (tools/cli-bridge/higgsmodels.go), qualquer modelo passa a ser endereçável por
 * `provider_model_id = higgsfield:<job_type>` — e o que faltava era o catálogo saber que eles
 * existem. Este comando pergunta ao bridge e cadastra o que apareceu.
 *
 * SECURE-BY-DEFAULT, igual ao KieModelsSeeder: tudo entra **inativo e sem preço**. Ativar é
 * decisão do operador no Filament, depois de definir os créditos — modelo ativo sem preço é
 * denial-of-wallet, e modelo ativo sem teste é prometer o que não se sabe entregar.
 *
 * WHITE-LABEL (#6): o `display_name` que a CLI devolve nomeia empresas ("Google Veo 3.1",
 * "Kling 3.0"). Esse nome NÃO pode chegar ao cliente, então entra prefixado como rascunho de
 * operador; a versão pública é escrita à mão no Filament antes de ativar.
 *
 * Idempotente: roda quantas vezes quiser. Nunca reescreve preço, nome público ou is_active de
 * uma linha que já existe — a calibração do operador é a fonte da verdade.
 */
class SyncHiggsfieldModels extends Command
{
    protected $signature = 'reachyn:sync-higgsfield {--deep : pergunta o schema de cada modelo (mais lento; diz quem aceita referência)}';

    protected $description = 'Cadastra os modelos da conta Higgsfield no catálogo (inativos, sem preço)';

    /** Tipos que viram `kind` no catálogo. 3d/data/text ficam de fora: não há caminho pra eles. */
    private const KINDS = ['image' => 'image', 'video' => 'video', 'audio' => 'audio'];

    /**
     * SUBTYPE por tipo — o campo que diz em QUAL seletor o modelo aparece.
     *
     * 🐛 Sem isto (até 2026-08-30) o comando gravava `subtype = null` e os seletores de imagem
     * filtram `subtype === 'text_to_image'`: 21 modelos Higgsfield entraram no catálogo, o
     * operador nomeou e precificou cada um no Filament, ativou — e NENHUM aparecia na aba
     * Imagem. Trabalho feito e produto sem o modelo.
     *
     * `refs` não muda o subtype: nos modelos de imagem do Higgsfield a referência é OPCIONAL
     * (nano banana, seedream, flux e grok geram do zero e aceitam âncora), então marcá-los como
     * image_to_image só os esconderia de novo, no outro seletor.
     */
    private const SUBTYPES = ['image' => 'text_to_image', 'video' => 'text_to_video', 'audio' => 'text_to_speech'];

    public function handle(): int
    {
        $url = rtrim((string) config('services.cli_bridge.url'), '/');
        if ($url === '') {
            $this->error('CLI_BRIDGE_URL não configurado.');

            return self::FAILURE;
        }

        try {
            // X-Bridge-Token, NÃO Authorization: é o header que o bridge confere (authorized()).
            $resp = Http::withHeaders(['X-Bridge-Token' => (string) config('services.cli_bridge.token')])
                ->timeout($this->option('deep') ? 300 : 60)
                ->get($url.'/v1/models', $this->option('deep') ? ['deep' => 1] : []);
        } catch (\Throwable $e) {
            $this->error('Bridge inacessível: '.$e->getMessage());

            return self::FAILURE;
        }
        if (! $resp->successful()) {
            $this->error('Bridge respondeu '.$resp->status());

            return self::FAILURE;
        }

        $models = (array) $resp->json('models', []);
        if ($models === []) {
            $this->warn('O bridge não devolveu modelo nenhum.');

            return self::FAILURE;
        }

        $novos = $existentes = 0;
        foreach ($models as $m) {
            $job = trim((string) ($m['job_type'] ?? ''));
            $kind = self::KINDS[$m['type'] ?? ''] ?? null;
            if ($job === '' || $kind === null) {
                continue;
            }
            $slug = 'hf-'.Str::slug(str_replace('_', '-', $job));
            if (GenModel::where('slug', $slug)->exists()) {
                $existentes++;

                continue;
            }
            GenModel::create([
                'slug' => $slug,
                // Rascunho de operador — o nome público é escrito no Filament antes de ativar.
                'display_name' => '[rascunho] '.trim((string) ($m['display_name'] ?? $job)),
                'kind' => $kind,
                // Vídeo com referência é image_to_video — é o subtype que o resto do produto usa
                // pra saber que o modelo parte de um quadro.
                'subtype' => ($kind === 'video' && ($m['refs'] ?? false)) ? 'image_to_video' : self::SUBTYPES[$kind],
                'provider' => 'cli-bridge',
                // O prefixo é o contrato com o bridge: "higgsfield:<job_type>" cai no catálogo
                // dinâmico, que lê o schema do modelo e monta o argv sozinho.
                'provider_model_id' => 'higgsfield:'.$job,
                'capabilities' => array_filter([
                    'upstream' => 'Higgsfield',
                    'async' => true,
                    'refs' => $m['refs'] ?? null,
                    'aspects' => $m['aspects'] ?? null,
                ], fn ($v) => $v !== null),
                'cost_credits' => null,   // o operador precifica antes de ativar
                'is_active' => false,     // secure-by-default
                'sort_order' => 900,      // no fim da lista até alguém curar
            ]);
            $novos++;
        }

        $this->info("Higgsfield: {$novos} modelo(s) novo(s) cadastrado(s), {$existentes} já existiam.");
        if ($novos > 0) {
            $this->line('Entraram INATIVOS e SEM PREÇO. No Filament: defina o nome público (white-label), o custo em créditos e só então ative.');
        }

        return self::SUCCESS;
    }
}
