<?php

namespace Database\Seeders;

use App\Models\CreationTemplate;
use Illuminate\Database\Seeder;

/**
 * Templates GLOBAIS de criação (aba Rápido). tenant_id = NULL → visíveis a todas as marcas.
 * Idempotente (updateOrCreate por slug+tenant_id NULL). Roda no CLI, onde app.current_tenant é
 * vazio → o RLS de creation_templates fica fail-open e aceita inserir linhas globais (tenant_id NULL).
 *
 * Cada `payload` é o estado inicial que o apply injeta num AnimationProject (target=animation) ou
 * Draft (target=draft): mode/style/aspect/scenes/quality/sequence_mode + script_seed (roteiro/ideia
 * pré-preenchida) + platforms + easyapps_suggested (dicas de pós-produção pra UI).
 */
class CreationTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $sort => $t) {
            CreationTemplate::updateOrCreate(
                ['tenant_id' => null, 'slug' => $t['slug']],
                [
                    'title' => $t['title'],
                    'description' => $t['description'],
                    'category' => $t['category'],
                    'preview_url' => $t['preview_url'] ?? null,
                    'payload' => $t['payload'],
                    'sort' => $sort,
                    'active' => true,
                ],
            );
        }
    }

    private function templates(): array
    {
        return [
            [
                'slug' => 'reels-historia-narrada',
                'title' => 'Reels história narrada (9:16)',
                'description' => 'Uma história curta com narração única, 6 cenas encadeadas, pronta pro Reels/TikTok.',
                'category' => 'reels',
                'payload' => [
                    'target' => 'animation',
                    'mode' => 'historia',
                    'style' => '3d',
                    'aspect' => '9:16',
                    'scenes' => 6,
                    'quality' => 'padrao',
                    'sequence_mode' => 'encadeado',
                    'script_seed' => 'Uma micro-história com começo, virada e desfecho em 30–45s, narrada em primeira pessoa, com um gancho forte nos 2 primeiros segundos.',
                    'character_required' => false,
                    'platforms' => ['instagram', 'tiktok'],
                    'easyapps_suggested' => ['upscale', 'relight'],
                ],
            ],
            [
                'slug' => 'desenho-animado-dialogo',
                'title' => 'Desenho animado com diálogo (9:16)',
                'description' => 'Cena animada com dois personagens conversando — lip-sync e vozes distintas.',
                'category' => 'historia',
                'payload' => [
                    'target' => 'animation',
                    'mode' => 'animacao',
                    'style' => 'cartoon',
                    'aspect' => '9:16',
                    'scenes' => 6,
                    'quality' => 'padrao',
                    'sequence_mode' => 'encadeado',
                    'script_seed' => 'Um diálogo curto e engraçado entre dois personagens que termina numa punchline. Marque quem fala cada linha.',
                    'character_required' => false,
                    'platforms' => ['instagram', 'tiktok', 'youtube'],
                    'easyapps_suggested' => ['upscale'],
                ],
            ],
            [
                'slug' => 'ugc-produto',
                'title' => 'UGC de produto',
                'description' => 'Post estilo UGC: imagem do produto 1:1 + legenda pronta pro Instagram.',
                'category' => 'ugc_produto',
                'payload' => [
                    'target' => 'draft',
                    'aspect' => '1:1',
                    'quality' => 'padrao',
                    'script_seed' => 'Depoimento autêntico em primeira pessoa sobre o produto: problema → como resolveu → resultado. Tom de amigo indicando, sem parecer anúncio.',
                    'character_required' => false,
                    'platforms' => ['instagram'],
                    'easyapps_suggested' => ['product_bg', 'relight', 'upscale'],
                ],
            ],
            [
                'slug' => 'carrossel-linkedin',
                'title' => 'Carrossel para LinkedIn',
                'description' => 'Post de autoridade com estrutura de carrossel e texto pronto pro LinkedIn.',
                'category' => 'outro',
                'payload' => [
                    'target' => 'draft',
                    'aspect' => '4:5',
                    'quality' => 'padrao',
                    'script_seed' => 'Um insight de autoridade dividido em 6–8 slides: capa com promessa → 1 ideia por slide → CTA final. Tom profissional e direto.',
                    'character_required' => false,
                    'platforms' => ['linkedin'],
                    'easyapps_suggested' => ['upscale'],
                ],
            ],
            [
                'slug' => 'quadrinhos-3-paginas',
                'title' => 'Quadrinhos (3 páginas)',
                'description' => 'Uma mini-HQ em 3 páginas de slides com narração — estilo história em quadrinhos.',
                'category' => 'comic',
                'payload' => [
                    'target' => 'animation',
                    'mode' => 'quadrinhos',
                    'style' => 'anime',
                    'aspect' => '9:16',
                    'scenes' => 9,
                    'quality' => 'padrao',
                    'sequence_mode' => 'encadeado',
                    'script_seed' => 'Uma história em 3 atos (uma página por ato), com balões de fala curtos e um clímax visual na página final.',
                    'character_required' => false,
                    'platforms' => ['instagram', 'tiktok'],
                    'easyapps_suggested' => ['upscale'],
                ],
            ],
            [
                'slug' => 'faceless-top-n',
                'title' => 'Faceless "Top N"',
                'description' => 'Vídeo faceless de ranking (Top 5/Top 10) — puxa a estrutura do formato da Fábrica.',
                'category' => 'faceless',
                'payload' => [
                    'target' => 'draft',
                    'aspect' => '9:16',
                    'quality' => 'padrao',
                    'format_slug' => 'top-n',
                    'script_seed' => 'Um ranking "Top 5" sobre o tema, com contagem regressiva, um gancho na abertura e um item surpresa no topo. Narração faceless.',
                    'character_required' => false,
                    'platforms' => ['tiktok', 'youtube', 'instagram'],
                    'easyapps_suggested' => ['upscale'],
                ],
            ],
            [
                'slug' => 'antes-depois-produto',
                'title' => 'Antes/Depois de produto',
                'description' => 'Comparativo antes/depois pra mostrar transformação — imagem tratada e legenda.',
                'category' => 'ugc_produto',
                'payload' => [
                    'target' => 'draft',
                    'aspect' => '1:1',
                    'quality' => 'padrao',
                    'script_seed' => 'Mostre a transformação antes → depois do produto em uso, com uma legenda que ancora o resultado num benefício concreto.',
                    'character_required' => false,
                    'platforms' => ['instagram', 'tiktok'],
                    'easyapps_suggested' => ['product_bg', 'relight', 'upscale'],
                ],
            ],
            [
                'slug' => 'storyboard-1-click',
                'title' => 'Storyboard em 1 clique',
                'description' => 'Prévia barata: gera só os quadros (sem vídeo) de uma cena encadeada de 4 takes.',
                'category' => 'reels',
                'payload' => [
                    'target' => 'animation',
                    'mode' => 'historia',
                    'style' => '3d',
                    'aspect' => '9:16',
                    'scenes' => 4,
                    'quality' => 'economico',
                    'sequence_mode' => 'encadeado',
                    'storyboard_only' => true,
                    'script_seed' => 'Quatro quadros-chave que contam uma ideia visual do início ao fim — pense em thumbnails de storyboard, não em cenas completas.',
                    'character_required' => false,
                    'platforms' => ['instagram', 'tiktok'],
                    'easyapps_suggested' => ['upscale'],
                ],
            ],
        ];
    }
}
