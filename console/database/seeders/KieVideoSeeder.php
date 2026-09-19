<?php

namespace Database\Seeders;

use App\Models\GenModel;
use Illuminate\Database\Seeder;

/**
 * Modelos de VÍDEO servidos pela KIE.ai — Fase 3, SCHEMA-DRIVEN (espelha KieImageSeeder).
 *
 * provider = 'kie'; provider_model_id = o MODEL STRING EXATO do createTask (confirmado na doc oficial
 * docs.kie.ai). A KIE não padroniza NADA entre famílias, então cada modelo carrega seu SPEC completo
 * em capabilities.kie (lido pelo engine video.KieVideoSpec):
 *   - model_i2v:       variante i2v quando é um model string SEPARADO (Kling Turbo, Wan); usada se há imagem
 *   - aspect_field:    campo do aspecto (aspect_ratio); ausente = i2v puro herda da imagem
 *   - refs_field:      campo da imagem-base i2v (image_urls | first_frame_url | image_url)
 *   - refs_single:     refs como STRING singular (Seedance/Wan/Hailuo) vs ARRAY (Kling)
 *   - duration_field:  campo da duração
 *   - duration_string: duração como STRING ('6'/'10', Kling/Hailuo) vs INT (5/8, Seedance/Wan)
 *   - extra:           campos fixos obrigatórios (resolution, mode...)
 * O spec NUNCA vai pro cliente (GenModelResource remove capabilities.kie).
 *
 * ⚠️ NASCEM INATIVOS (is_active=false): os strings vêm da doc, mas não foram validados com geração
 * real (vídeo custa $ e leva minutos) e nem todo modelo está habilitado na conta KIE — o operador
 * valida 1 geração e ATIVA no Filament. Custos são ESTIMATIVA (calibrar). Slugs/nomes white-label (#6).
 *
 * Idempotente e NÃO-DESTRUTIVO: identidade/roteamento (display_name, provider_model_id, capabilities)
 * sempre atualizados (seeder = fonte); a CALIBRAÇÃO do operador (cost_credits, is_active, sort_order)
 * só no 1º insert (firstOrNew).
 */
class KieVideoSeeder extends Seeder
{
    public function run(): void
    {
        $models = [
            // Seedance 2.0 — 1 model string serve t2v+i2v (resolve pela presença de refs). Áudio nativo.
            ['slug' => 'vid-realista', 'name' => 'Realista', 'pmid' => 'bytedance/seedance-2',
                'kie' => ['aspect_field' => 'aspect_ratio', 'refs_field' => 'first_frame_url', 'refs_single' => true,
                    'duration_field' => 'duration', 'duration_string' => false],
                'cost' => 80, 'basis' => 400000, 'sort' => 10],
            // Kling 3.0 Turbo — t2v e i2v são model strings SEPARADOS (model_i2v). Rápido, cinematográfico.
            ['slug' => 'vid-turbo', 'name' => 'Turbo', 'pmid' => 'kling/v3-turbo-text-to-video',
                // multi_prompt_field: liga o modo multi_shots (Filme rápido + Storyboard-sheet) — o
                // Kling faz vários cortes numa geração lendo o board. Validado com geração real
                // 2026-07-16 (smoke: board 6 painéis → vídeo 15s h264). Sem isto a flag some no reseed
                // (a original, presa a 'kling-3.0/video', virou órfã quando o catálogo mudou).
                'kie' => ['model_i2v' => 'kling/v3-turbo-image-to-video', 'multi_prompt_field' => 'multi_prompt',
                    'aspect_field' => 'aspect_ratio',
                    'refs_field' => 'image_urls', 'refs_single' => false, 'duration_field' => 'duration',
                    'duration_string' => true, 'extra' => ['resolution' => '720p']],
                'cost' => 70, 'basis' => 350000, 'sort' => 11],
            // Wan 2.7 — t2v e i2v separados (model_i2v). Versátil.
            // ⚠️ no_aspect_i2v: o t2v EXIGE aspect_ratio, a variante i2v o REJEITA (422 "ratio is not
            // supported for image-to-video" — o i2v deriva o ratio do first_frame_url). Sem este flag
            // TODA cena de animação premium falha (prod 2026-07-15: 6h30 de re-despacho em loop).
            ['slug' => 'vid-fluido', 'name' => 'Fluido', 'pmid' => 'wan/2-7-text-to-video',
                'kie' => ['model_i2v' => 'wan/2-7-image-to-video', 'aspect_field' => 'aspect_ratio',
                    'no_aspect_i2v' => true,
                    'refs_field' => 'first_frame_url', 'refs_single' => true, 'duration_field' => 'duration',
                    'duration_string' => false, 'extra' => ['resolution' => '720p']],
                'cost' => 80, 'basis' => 400000, 'sort' => 12],
            // Hailuo 2.3 Pro — SÓ i2v na KIE (ótimo p/ animar as cenas das Histórias). image_url singular.
            ['slug' => 'vid-natural', 'name' => 'Natural', 'pmid' => 'hailuo/2-3-image-to-video-pro',
                'kie' => ['refs_field' => 'image_url', 'refs_single' => true, 'duration_field' => 'duration',
                    'duration_string' => true, 'extra' => ['resolution' => '768P']],
                'cost' => 60, 'basis' => 300000, 'sort' => 13, 'subtype' => 'image_to_video',
                'tasks' => ['Image to Video']], // i2v-only: a capability não anuncia t2v

            // ── Lote 2026-07-09 (análise kie-pricing.json) — nascem INATIVOS; validar 1 geração e ativar ──
            // Instantâneo — Grok Imagine (xAI): o vídeo MAIS BARATO do market ($0,008-0,015/s ≈ 10× menos
            // que o resto). t2v e i2v são model strings separados; i2v refs em image_urls (ARRAY, até 7).
            // duration_string=TRUE porque a doc oficial (docs.kie.ai/market/grok-imagine/image-to-video)
            // tipa duration como STRING e o exemplo manda "6". Estava INT aqui — mas o modelo NÃO estava
            // quebrado: testei os dois em prod (2026-07-15) e a API coage, devolvendo vídeo idêntico
            // (736x400, 6,04s) nos dois casos. Alinhar com a doc é higiene (se a KIE apertar a validação,
            // já estamos certos), não conserto. Validado com geração real: HTTP 200.
            ['slug' => 'vid-instantaneo', 'name' => 'Instantâneo', 'pmid' => 'grok-imagine/text-to-video',
                'kie' => ['model_i2v' => 'grok-imagine/image-to-video', 'aspect_field' => 'aspect_ratio',
                    'refs_field' => 'image_urls', 'refs_single' => false, 'duration_field' => 'duration',
                    'duration_string' => true, 'extra' => ['mode' => 'normal'],
                    'qualities' => [
                        ['key' => '480p', 'label' => 'SD (480p)', 'p5' => 10, 'p10' => 20, 'extra' => ['resolution' => '480p']],
                        ['key' => '720p', 'label' => 'HD (720p)', 'p5' => 18, 'p10' => 35, 'extra' => ['resolution' => '720p']],
                    ], 'default_quality' => '720p'],
                'cost' => 18, 'basis' => 90000, 'sort' => 14],
            // Compacto — Seedance 2.0 Mini (ByteDance): ~metade do custo do Seedance 2.0 cheio; mesma
            // família/schema do vid-realista (first_frame_url singular, duration INT, aspect_ratio).
            ['slug' => 'vid-compacto', 'name' => 'Compacto', 'pmid' => 'bytedance/seedance-2-mini',
                'kie' => ['aspect_field' => 'aspect_ratio', 'refs_field' => 'first_frame_url', 'refs_single' => true,
                    'duration_field' => 'duration', 'duration_string' => false,
                    'qualities' => [
                        ['key' => '480p', 'label' => 'SD (480p)', 'p5' => 50, 'p10' => 95, 'extra' => ['resolution' => '480p', 'generate_audio' => false]],
                        ['key' => '720p', 'label' => 'HD (720p)', 'p5' => 105, 'p10' => 205, 'extra' => ['resolution' => '720p', 'generate_audio' => false]],
                    ], 'default_quality' => '720p'],
                'cost' => 105, 'basis' => 525000, 'sort' => 15],
            // Dinâmico — Seedance 2.0 Fast: geração mais rápida que o cheio, qualidade próxima. Mesmo schema.
            ['slug' => 'vid-dinamico', 'name' => 'Dinâmico', 'pmid' => 'bytedance/seedance-2-fast',
                'kie' => ['aspect_field' => 'aspect_ratio', 'refs_field' => 'first_frame_url', 'refs_single' => true,
                    'duration_field' => 'duration', 'duration_string' => false,
                    'qualities' => [
                        ['key' => '480p', 'label' => 'SD (480p)', 'p5' => 80, 'p10' => 155, 'extra' => ['resolution' => '480p', 'generate_audio' => false]],
                        ['key' => '720p', 'label' => 'HD (720p)', 'p5' => 165, 'p10' => 330, 'extra' => ['resolution' => '720p', 'generate_audio' => false]],
                    ], 'default_quality' => '720p'],
                'cost' => 165, 'basis' => 825000, 'sort' => 16],
            // Natural Lite — Hailuo 02 Standard (i2v-only): preço POR VÍDEO ($0,06-0,25) — barato p/ animar
            // cenas. duration STRING '6'/'10'; resolution 512P/768P; image_url singular.
            ['slug' => 'vid-natural-lite', 'name' => 'Natural Lite', 'pmid' => 'hailuo/02-image-to-video-standard',
                'kie' => ['refs_field' => 'image_url', 'refs_single' => true, 'duration_field' => 'duration',
                    'duration_string' => true,
                    'qualities' => [
                        ['key' => '512p', 'label' => 'SD (512p)', 'p5' => 12, 'p10' => 20, 'extra' => ['resolution' => '512P']],
                        ['key' => '768p', 'label' => 'HD (768p)', 'p5' => 30, 'p10' => 50, 'extra' => ['resolution' => '768P']],
                    ], 'default_quality' => '768p'],
                'cost' => 30, 'basis' => 150000, 'sort' => 17, 'subtype' => 'image_to_video',
                'tasks' => ['Image to Video']], // i2v-only
            // HappyHorse-1.1 (Alibaba) — t2v. Contrato confirmado na doc (happyhorse-1-1/text-to-video):
            // duration INT 3-15s, resolution 720p/1080p, aspect_ratio. ⚠️ t2v-ONLY neste endpoint (o
            // fluxo do Reachyn é quase todo i2v) e MAIS CARO que o grok-imagine (vid-instantaneo) —
            // nasce INATIVO; só ativar se a qualidade justificar o custo num uso t2v real.
            ['slug' => 'vid-happyhorse', 'name' => 'HappyHorse', 'pmid' => 'happyhorse-1-1/text-to-video',
                'kie' => ['aspect_field' => 'aspect_ratio', 'duration_field' => 'duration', 'duration_string' => false,
                    'extra' => ['resolution' => '720p']],
                'cost' => 115, 'basis' => 575000, 'sort' => 18, 'subtype' => 'text_to_video',
                'tasks' => ['Text to Video']], // t2v-only
        ];

        foreach ($models as $m) {
            $gm = GenModel::firstOrNew(['slug' => $m['slug']]);
            $gm->fill([
                'display_name' => $m['name'],
                'kind' => 'video',
                'subtype' => $m['subtype'] ?? 'image_to_video',
                'provider' => 'kie',
                'provider_model_id' => $m['pmid'],
                'capabilities' => ['task_types' => $m['tasks'] ?? ['Text to Video', 'Image to Video'], 'durations' => [6, 10], 'kie' => $m['kie']],
            ]);
            if (! $gm->exists) {
                $gm->cost_credits = $m['cost'];
                $gm->cost_basis_micro = $m['basis'];
                $gm->is_active = false; // operador valida 1 geração e ativa no Filament
                $gm->sort_order = $m['sort'];
            }
            $gm->save();
        }
    }
}
