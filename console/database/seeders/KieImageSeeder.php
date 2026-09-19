<?php

namespace Database\Seeders;

use App\Models\GenModel;
use Illuminate\Database\Seeder;

/**
 * Modelos de IMAGEM (text-to-image) servidos pela KIE.ai — Fase 2, SCHEMA-DRIVEN.
 *
 * provider = 'kie'; provider_model_id = o MODEL STRING EXATO do market KIE (confirmado na doc). A KIE
 * não padroniza nada entre modelos, então cada um carrega seu SPEC de input em capabilities.kie:
 *   - aspect_field: nome do campo de proporção (aspect_ratio | image_size)
 *   - refs_field:   campo das refs i2i (image_input | image_urls | input_urls)
 *   - extra:        campos OBRIGATÓRIOS fixos do modelo (ex Seedream quality, FLUX resolution)
 * O engine monta o input do createTask a partir desse spec (zero hardcode; modelo novo = só uma linha).
 * O spec NUNCA vai pro cliente (GenModelResource remove capabilities.kie).
 *
 * Idempotente e NÃO-DESTRUTIVO: identidade/roteamento (display_name, provider_model_id, capabilities)
 * sempre atualizados (seeder = fonte); a CALIBRAÇÃO do operador (cost_credits, is_active, sort_order)
 * só no 1º insert. Custos são ESTIMATIVA (operador calibra no Filament). Slugs/nomes white-label (#6).
 */
class KieImageSeeder extends Seeder
{
    public function run(): void
    {
        // slug, display_name, provider_model_id (t2i), spec kie, custo estimado, basis, sort, ativo-no-create
        $models = [
            ['slug' => 'img-pro', 'name' => 'Pro', 'pmid' => 'nano-banana-2',
                'kie' => ['aspect_field' => 'aspect_ratio', 'refs_field' => 'image_input'],
                'cost' => 6, 'basis' => 30000, 'sort' => 2, 'active' => true],
            ['slug' => 'img-ultra', 'name' => 'Ultra', 'pmid' => 'nano-banana-pro',
                'kie' => ['aspect_field' => 'aspect_ratio', 'refs_field' => 'image_input'],
                'cost' => 16, 'basis' => 80000, 'sort' => 3, 'active' => true],
            ['slug' => 'img-criativo', 'name' => 'Criativo', 'pmid' => 'gpt-image-2-text-to-image',
                'kie' => ['aspect_field' => 'aspect_ratio', 'refs_field' => 'input_urls'],
                'cost' => 12, 'basis' => 60000, 'sort' => 4, 'active' => true],
            ['slug' => 'img-realista', 'name' => 'Realista', 'pmid' => 'seedream/4.5-text-to-image',
                'kie' => ['aspect_field' => 'aspect_ratio', 'refs_field' => 'image_urls', 'extra' => ['quality' => 'basic']],
                'cost' => 6, 'basis' => 30000, 'sort' => 5, 'active' => true],
            // Realista Pro — Seedream 5.0 Pro ($0,035 1K/$0,07 2K): t2i de alta qualidade com
            // renderização de TEXTO precisa e multilíngue — ideal p/ Storyboard-Sheet e Compositor
            // "Vestir com a marca" (título/CTA na imagem). ⚠️ t2i PURO: a API 5-pro NÃO aceita refs
            // (sem refs_field) — não serve p/ i2i de keyframe/personagem; o img-realista (4.5) cobre i2i.
            // quality: basic=1K, high=2K. VALIDADO com geração real 2026-07-16 (HTTP 200 → PNG).
            ['slug' => 'img-realista-pro', 'name' => 'Realista Pro', 'pmid' => 'seedream/5-pro-text-to-image',
                'kie' => ['aspect_field' => 'aspect_ratio', 'extra' => ['quality' => 'basic']],
                'cost' => 7, 'basis' => 35000, 'sort' => 8, 'active' => true],
            ['slug' => 'img-artistico', 'name' => 'Artístico', 'pmid' => 'flux-2/pro-text-to-image',
                'kie' => ['aspect_field' => 'aspect_ratio', 'refs_field' => 'input_urls', 'extra' => ['resolution' => '1K']],
                'cost' => 8, 'basis' => 40000, 'sort' => 6, 'active' => true],
            // Extras validados no smoke (geram de verdade na conta). Adicionados sem rebuild do engine.
            ['slug' => 'img-veloz', 'name' => 'Veloz', 'pmid' => 'z-image',
                'kie' => ['aspect_field' => 'aspect_ratio'],
                'cost' => 4, 'basis' => 20000, 'sort' => 9, 'active' => true],
            ['slug' => 'img-versatil', 'name' => 'Versátil', 'pmid' => 'qwen2/text-to-image',
                'kie' => ['aspect_field' => 'image_size'],
                'cost' => 5, 'basis' => 25000, 'sort' => 10, 'active' => true],
            ['slug' => 'img-realista-lite', 'name' => 'Realista Lite', 'pmid' => 'seedream/5-lite-text-to-image',
                'kie' => ['aspect_field' => 'aspect_ratio', 'extra' => ['quality' => 'basic']],
                'cost' => 4, 'basis' => 20000, 'sort' => 12, 'active' => true],
            // Lote 2026-07-09 (análise kie-pricing.json). Nascem INATIVOS — validar 1 geração e ativar.
            // Pro Lite — nano-banana-2-lite ($0,02/img): qualidade Google ~metade do custo do Pro.
            // ⚠️ refs = image_urls (o nano-banana-2 usa image_input — a família NÃO padroniza).
            ['slug' => 'img-pro-lite', 'name' => 'Pro Lite', 'pmid' => 'nano-banana-2-lite',
                'kie' => ['aspect_field' => 'aspect_ratio', 'refs_field' => 'image_urls'],
                'cost' => 4, 'basis' => 20000, 'sort' => 7, 'active' => false],
            // Personagem — ideogram/character (i2i-ONLY, $0,06 TURBO): especialista em MANTER a
            // identidade do personagem a partir de refs (reference_image_urls) — casa com o model
            // sheet/lock do Reachyn. image_size é ENUM próprio → aspect_map traduz (engine mapAspect).
            ['slug' => 'img-personagem', 'name' => 'Personagem', 'pmid' => 'ideogram/character',
                'kie' => ['aspect_field' => 'image_size', 'refs_field' => 'reference_image_urls',
                    'aspect_map' => ['1:1' => 'square_hd', '3:4' => 'portrait_4_3', '9:16' => 'portrait_16_9',
                        '4:3' => 'landscape_4_3', '16:9' => 'landscape_16_9', '4:5' => 'portrait_4_3', '*' => 'square_hd'],
                    // negative_prompt DEDICADO (o modelo ignora negativa em texto no prompt — a Mel fêmea
                    // saía com anatomia de macho no perfil): anatomia NEUTRA em toda geração de personagem.
                    'extra' => ['rendering_speed' => 'TURBO', 'style' => 'AUTO', 'num_images' => '1', 'expand_prompt' => false,
                        'negative_prompt' => 'genitalia, genitals, sheath, prepuce, penis, testicles, scrotum, vulva, anus, nipples, udder, exposed underbelly anatomy']],
                'cost' => 12, 'basis' => 60000, 'sort' => 14, 'active' => false,
                'subtype' => 'image_to_image', 'tasks' => ['Image to Image']],
        ];

        foreach ($models as $m) {
            $gm = GenModel::firstOrNew(['slug' => $m['slug']]);
            $gm->fill([
                'display_name' => $m['name'],
                'kind' => 'image',
                'subtype' => $m['subtype'] ?? 'text_to_image',
                'provider' => 'kie',
                'provider_model_id' => $m['pmid'],
                'capabilities' => ['task_types' => $m['tasks'] ?? ['Text to Image'], 'kie' => $m['kie']],
            ]);
            if (! $gm->exists) {
                $gm->cost_credits = $m['cost'];
                $gm->cost_basis_micro = $m['basis'];
                $gm->is_active = $m['active'];
                $gm->sort_order = $m['sort'];
            }
            $gm->save();
        }
    }
}
