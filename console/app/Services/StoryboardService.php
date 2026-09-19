<?php

namespace App\Services;

use App\Support\Plano;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * FOLHA DE STORYBOARD — o documento de produção que se confere ANTES de montar o vídeo.
 *
 * POR QUE COMPOSTA E NÃO GERADA: o método que circula por aí manda um modelo de imagem DESENHAR a
 * folha inteira (painéis + numeração + timecode + notas) e torce pra tipografia sair certa. Aqui
 * os painéis JÁ EXISTEM — são os keyframes que o filme/a animação geraram — e os rótulos são DADO
 * nosso: número, tempo, plano, ação e fala saem do JSON do plano. Então a folha não custa geração
 * nenhuma e o texto está certo por construção, em vez de por sorte. Mesmo raciocínio do model
 * sheet determinístico (ModelSheetService): a IA desenha a imagem, o template escreve as letras.
 *
 * POR QUE UM SERVIÇO SÓ: a folha tem de fazer parte de TODA geração de vídeo multi-cena, e as
 * fontes de painel são três e diferentes entre si — `drafts.film` (beats + keyframes),
 * `animation_projects.storyboard` (cenas com keyframe_url) e `shots`/`scenes` (decupagem). Cada
 * fonte tem seu adaptador aqui e todas caem no MESMO formato de painel, num único ponto de
 * composição. Plugar isso três vezes garantiria três layouts divergindo no primeiro ajuste.
 *
 * Formato do painel (o contrato com /compose-storyboard):
 *   ['url'=>string, 'index'=>int|string, 'time'=>string, 'shot'=>string, 'action'=>string, 'dialogue'=>string]
 */
class StoryboardService
{
    /**
     * Rótulo PT de cada key de enquadramento. ESPELHA `SHOT_OPTIONS` (web/lib/shots.ts), que por
     * sua vez espelha `shotKeysList` do engine (internal/content/spec.go) — mesma ordem, mesmas
     * keys. Vive aqui porque a folha é composta no SERVIDOR e o mapa do front não alcança.
     * Mudou lá, muda aqui: key sem rótulo cai no fallback legível, nunca em célula vazia.
     */
    private const SHOT_PT = [
        'medio' => 'Plano médio',
        'americano' => 'Plano americano',
        'closeup' => 'Close-up',
        'perfil' => 'Plano perfil',
        'contraplongee' => 'Contra-plongée',
        'plongee' => 'Plongée',
        'overshoulder' => 'Over shoulder',
        'panoramico' => 'Plano panorâmico',
        'heroshot' => 'Hero shot',
        'overshoulder_aberto' => 'Over shoulder aberto',
        'overshoulder_fechado' => 'Over shoulder fechado',
        'zenital' => 'Plano zenital',
        'extreme_closeup' => 'Extreme close-up',
        'holandes' => 'Plano holandês',
        'aberto_final' => 'Plano aberto (final)',
    ];

    /** Key de enquadramento → rótulo legível. Key desconhecida vira texto humano, não some. */
    public static function rotuloShot(?string $key): string
    {
        $k = trim((string) $key);
        if ($k === '') {
            return '';
        }

        return self::SHOT_PT[$k] ?? ucfirst(str_replace('_', ' ', $k));
    }

    /** Segundos → "M:SS", o timecode que vai no canto do painel. */
    public static function timecode(float $seg): string
    {
        $seg = max(0, (int) round($seg));

        return sprintf('%d:%02d', intdiv($seg, 60), $seg % 60);
    }

    /**
     * FILME CONTÍNUO (`drafts.film`): beats + keyframes na mesma ordem. O keyframe i é o quadro em
     * que o beat i COMEÇA; o movimento (move_prompt) é o que leva ao próximo — por isso ele entra
     * como ação do painel, e não o frame_prompt: numa folha de storyboard interessa o que ACONTECE
     * naquele quadro, não a redescrição da imagem que já está ali à vista.
     */
    public static function painelsDeFilme(array $film, float $clipDur = 5.0): array
    {
        $beats = is_array($film['beats'] ?? null) ? $film['beats'] : [];
        $keys = is_array($film['keyframes'] ?? null) ? array_values($film['keyframes']) : [];
        $paineis = [];
        foreach ($beats as $i => $b) {
            $spec = is_array($b['spec'] ?? null) ? $b['spec'] : [];
            $paineis[] = [
                'url' => (string) ($keys[$i] ?? ''),
                'index' => $i + 1,
                'time' => self::timecode($i * $clipDur),
                'shot' => self::rotuloShot($spec['shot'] ?? null),
                'action' => trim((string) ($b['move_prompt'] ?? $b['title'] ?? '')),
                'dialogue' => trim((string) ($b['voiceover'] ?? '')),
            ];
        }
        // O keyframe FINAL é um quadro de verdade (o hero shot em que o filme termina) e precisa
        // aparecer: é justamente o quadro que se quer conferir antes de montar.
        $final = trim((string) ($film['final_url'] ?? ($keys[count($beats)] ?? '')));
        if ($final !== '') {
            $paineis[] = [
                'url' => $final,
                'index' => count($beats) + 1,
                'time' => self::timecode(count($beats) * $clipDur),
                'shot' => 'Quadro final',
                'action' => trim((string) ($film['final_frame_prompt'] ?? '')),
                'dialogue' => '',
            ];
        }

        return $paineis;
    }

    /**
     * ESTÚDIO DE ANIMAÇÃO (`animation_projects.storyboard`): a coluna já guarda cena a cena com
     * keyframe_url, spec e dialogue — é a fonte mais direta das três.
     */
    public static function painelsDeAnimacao(array $storyboard, float $cenaDur = 5.0): array
    {
        $paineis = [];
        foreach (array_values($storyboard) as $i => $cena) {
            if (! is_array($cena)) {
                continue;
            }
            $spec = is_array($cena['spec'] ?? null) ? $cena['spec'] : [];
            // dialogue vem como lista [{character, line}]; na folha vale a PRIMEIRA fala — o painel
            // é um resumo de conferência, não o roteiro inteiro.
            $fala = '';
            if (is_array($cena['dialogue'] ?? null)) {
                foreach ($cena['dialogue'] as $d) {
                    if (is_array($d) && trim((string) ($d['line'] ?? '')) !== '') {
                        $quem = trim((string) ($d['character'] ?? ''));
                        $fala = ($quem !== '' ? $quem.': ' : '').trim((string) $d['line']);
                        break;
                    }
                }
            } elseif (is_string($cena['dialogue'] ?? null)) {
                $fala = trim($cena['dialogue']);
            }
            if ($fala === '') {
                $fala = trim((string) ($cena['narration'] ?? ''));
            }
            $paineis[] = [
                'url' => (string) ($cena['keyframe_url'] ?? ''),
                'index' => $i + 1,
                'time' => self::timecode($i * $cenaDur),
                'shot' => self::rotuloShot($spec['shot'] ?? null),
                'action' => trim((string) ($cena['action'] ?? $cena['title'] ?? '')),
                'dialogue' => $fala,
            ];
        }

        return $paineis;
    }

    /**
     * DECUPAGEM (`shots` de uma cena): aqui o enquadramento já é vocabulário fechado, então o
     * rótulo sai do próprio `Plano` — que é a fonte canônica da decupagem, não este serviço.
     *
     * A imagem vem de `shots.quadro_url` (o quadro já gerado do plano). `$quadros` sobrescreve por
     * id quando o chamador tem uma URL melhor em mãos. Plano sem quadro vira célula "—", que é
     * resultado honesto: a folha serve justamente pra ver o que ainda falta antes de montar.
     *
     * O relógio anda pela DURAÇÃO de cada plano (`shots.duracao`), não por um passo fixo — na
     * decupagem os planos têm tempos diferentes e um timecode uniforme mentiria.
     */
    public static function painelsDePlanos(iterable $shots, array $quadros = []): array
    {
        $paineis = [];
        $t = 0.0;
        $i = 0;
        foreach ($shots as $s) {
            $plano = [
                'enquadramento' => $s->enquadramento ?? null,
                'angulo' => $s->angulo ?? null,
                'altura' => $s->altura ?? null,
                'movimento' => $s->movimento ?? null,
            ];
            $paineis[] = [
                'url' => (string) ($quadros[$s->id] ?? $s->quadro_url ?? ''),
                'index' => ++$i,
                'time' => self::timecode($t),
                'shot' => Plano::rotulo($plano),
                'action' => trim((string) ($s->acao ?? '')),
                'dialogue' => '',
            ];
            $t += max(1, (int) ($s->duracao ?? 5));
        }

        return $paineis;
    }

    /**
     * Compõe a folha no ffmpeg-service e devolve a URL — ou null se a composição falhar (a folha é
     * conferência, não pode derrubar a geração de quem a pediu).
     *
     * `$cols`: 5 é a grade clássica de storyboard; com poucos painéis encolhe sozinho pra não
     * deixar meia folha vazia.
     */
    public function compor(array $paineis, string $titulo, string $subtitulo = '', array $rodape = [], ?int $cols = null): ?string
    {
        $paineis = array_values(array_filter($paineis, fn ($p) => is_array($p)));
        if ($paineis === []) {
            return null;
        }
        $cols ??= max(1, min(5, count($paineis)));

        try {
            $res = Http::baseUrl(rtrim((string) config('services.ffmpeg.url'), '/'))
                ->withHeaders(['X-Service-Token' => (string) config('services.ffmpeg.token')])
                ->acceptJson()->timeout(180)
                ->post('/compose-storyboard', [
                    'title' => $titulo,
                    'subtitle' => $subtitulo,
                    'cols' => $cols,
                    'footer' => array_values($rodape),
                    'panels' => $paineis,
                ]);
            if ($res->successful() && ($u = trim((string) $res->json('url'))) !== '') {
                return $u;
            }
            Log::warning('Storyboard: compose falhou', [
                'status' => $res->status(),
                'body' => mb_substr($res->body(), 0, 200),
                'paineis' => count($paineis),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Storyboard: compose exception', ['error' => $e->getMessage()]);
        }

        return null;
    }
}
