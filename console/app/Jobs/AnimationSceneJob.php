<?php

namespace App\Jobs;

use App\Jobs\Concerns\RetriesTransientEngineErrors;
use App\Models\AnimationProject;
use App\Models\Tenant;
use App\Services\AnimationFlow;
use App\Services\UsageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 🎬 Estúdio de Animação — passo 4: ANIMA uma cena. Sequência no worker:
 *  1) diálogo multi-voz (/v1/dialogueaudio — 1 TTS por fala, voz do personagem) quando a
 *     cena tem falas; a DURAÇÃO do áudio dimensiona o clipe (5s ou 10s);
 *  2) cena COM fala + modelo de lip-sync → /v1/lipsync do KEYFRAME com o diálogo inteiro
 *     (boca sincronizada NA cena); sem fala ou sem modelo → i2v do keyframe (/v1/filmclip, mudo);
 *  3) mux (/v1/muxaudio) no caminho i2v: o áudio casa com o clipe (vídeo curto congela o frame).
 * Cotas (video + audio por fala) reservadas no dispatch; estornadas aqui se falhar.
 */
class AnimationSceneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RetriesTransientEngineErrors, SerializesModels;

    // O lip-sync (OmniHuman ~2-3min) com retry no pico de concorrência do KIE passa fácil de 10min.
    // Teto abaixo do queue:work --timeout=1300.
    public int $timeout = 1250; // dialogueaudio + lip-sync do keyframe, ou i2v + mux

    // Com tries=1, um restart de worker (deploy/crash/OOM) ou um blip de rede no meio do i2v jogava
    // fora 8-14min de trabalho JÁ FEITO: o crédito era estornado (nenhum caminho aqui deixa de
    // estornar), mas o TTS e o upscale que já tinham saído iam junto, e a cena voltava como erro.
    // tries=2 dá uma segunda chance ao que é transitório; o handle() RETOMA do storyboard em vez de
    // refazer (ver $resume). Mesmo padrão do GenerateModelSheetJob, pelo mesmo motivo.
    //
    // Agora TAMBÉM retenta o i2v transitório (5xx / 200-sem-url): o engineUrlOrRetry lança
    // TransientEngineException enquanto houver tentativa, e a RETOMADA ($resume) reusa o TTS/upscale
    // já gerados — sem re-cobrar. Só desiste (marca erro + advance + estorna) no 4xx permanente ou na
    // última tentativa. ConnectionException (timeout/DNS/reset) sempre relança e cai no failed().
    // retry_after do driver (1360s) > timeout (1250s): worker concorrente não rouba job em execução.
    public int $tries = 3;

    /**
     * ⏱️ PISO DE DURAÇÃO DA CENA COM FALA.
     *
     * O lip-sync devolve um clipe do TAMANHO EXATO da fala. Uma linha curta vira uma cena curta:
     * "Quem está aí?" (1,15s), "Só o vento... continue." (1,49s) e "Finalmente... está aqui."
     * (1,67s) viraram três cenas de ~1,2s no meio de cenas de 6s — piscam e somem antes de a
     * boca sequer aparecer (projeto 19, medido em prod 2026-07-22).
     *
     * Abaixo do piso o lip-sync não paga o que custa: a cena vai por i2v normal (clipe cheio) com
     * o áudio muxado por cima — a cena respira e a fala entra no começo. Acima do piso a boca
     * aparece tempo suficiente para o talking-head valer a pena.
     */
    private const MIN_LIPSYNC_SECONDS = 3.0;

    public function __construct(
        public int $projectId,
        public int $tenantId,
        public int $index,
        public ?array $lines,     // falas [{text, voice_id, tts_style}] | null (cena sem diálogo)
        public array $clip,       // body base do /v1/filmclip (prompt+imageUrl+duration+aspect+gen_lines)
        public int $weight,
        public ?int $costCredits = null,
        public int $audioCount = 0,
        public string $ttsModel = '',
        public ?array $upscale = null, // ✨ "melhorar antes de animar": {payload /v1/enhance, weight, cost}
        public ?array $lipLine = null, // 🎬 gen_lines do modelo de LIP SYNC (talking-head) | null = i2v mudo + mux
    ) {
        // Fila 'animation': o desenho despacha N cenas DE UMA VEZ (dispatchAllScenes), cada uma
        // segurando um worker por 8-14min. Na fila única isso empurrava TODO o resto pra trás —
        // publicar um post (que também é lento: upload sequencial por rede) podia esperar a
        // animação inteira. Aqui o fan-out fica numa fila própria, com workers próprios.
        $this->onQueue('animation');
    }

    public function handle(UsageService $usage): void
    {
        $p = AnimationProject::find($this->projectId);
        if (! $p) {
            $this->refund($usage);

            return;
        }
        $flow = app(AnimationFlow::class);
        $engine = fn () => Http::baseUrl(rtrim((string) config('services.engine.url'), '/'))
            ->withHeaders(['X-Admin-Token' => (string) config('services.engine.admin_token')])
            ->acceptJson();

        // ⏭ RETOMADA (mesmo padrão do GenerateModelSheetJob): numa RE-tentativa, o que a tentativa
        // anterior já produziu é reaproveitado do storyboard em vez de refeito. É o que torna o
        // tries=2 seguro: sem isto, um blip no i2v refaria o TTS e o upscale — re-cobrando áudio e
        // imagem que já tínhamos. Cada etapa PERSISTE seu resultado assim que sai, justamente para
        // sobreviver ao restart do worker.
        $resume = $this->attempts() > 1;
        $sc = (array) (array_values((array) $p->storyboard)[$this->index] ?? []);

        // 1) Diálogo multi-voz (quando há falas).
        $audioUrl = '';
        $audioDur = 0.0;
        $lipFalhou = false; // marca o caminho alternativo, para a tela não perder o aviso
        if (is_array($this->lines) && $this->lines !== []) {
            if ($resume && ($sc['audio_url'] ?? '') !== '') {
                $audioUrl = (string) $sc['audio_url'];
                $audioDur = (float) ($sc['duration'] ?? 0);
                Log::info('AnimationSceneJob: retomada — reusa o diálogo já gerado', ['project' => $this->projectId, 'i' => $this->index]);
            } else {
                $res = $engine()->timeout(300)->post('/v1/dialogueaudio', [
                    'lines' => $this->lines,
                    'ttsModel' => $this->ttsModel,
                ]);
                $audioUrl = $res->successful() ? (string) $res->json('url') : '';
                $audioDur = (float) ($res->json('duration') ?? 0);
                if ($audioUrl === '') {
                    Log::warning('AnimationSceneJob: diálogo sem URL — segue mudo', ['project' => $this->projectId, 'i' => $this->index, 'status' => $res->status()]);
                    // Só o áudio; o clipe segue. O estorno é idempotente por MARCA persistida
                    // (audio_refunded), não por nº de tentativa: o áudio falhar não interrompe a
                    // cena, então o job pode até terminar com sucesso — "última tentativa" não diria
                    // nada aqui. A marca é o que impede devolver 2× se o i2v blipar e houver retry.
                    $this->refundAudioOnce($usage, $p, $flow);
                } else {
                    // Persiste JÁ: se o i2v blipar, a retomada não refaz (nem re-cobra) o TTS.
                    $flow->patchScene($p, $this->index, ['audio_url' => $audioUrl, 'duration' => $audioDur]);
                }
            }
        }

        // 1.5) ✨ upscale do keyframe antes do i2v (opcional): vídeo mais nítido. Falhou → estorna
        // só o upscale e anima com a imagem original (o clipe segue).
        $clip = $this->clip;
        if ($resume && ($sc['keyframe_up_url'] ?? '') !== '') {
            $clip['imageUrl'] = (string) $sc['keyframe_up_url'];
        } elseif (is_array($this->upscale)) {
            $res = $engine()->timeout(300)->post('/v1/enhance', $this->upscale['payload']);
            $up = $res->successful() ? (string) $res->json('url') : '';
            if ($up !== '') {
                $clip['imageUrl'] = $up;
                $flow->patchScene($p, $this->index, ['keyframe_up_url' => $up]); // idem: sobrevive ao retry
            } else {
                Log::warning('AnimationSceneJob: upscale falhou — anima com a imagem original', ['project' => $this->projectId, 'i' => $this->index]);
                $t = ($sc['upscale_refunded'] ?? false) ? null : Tenant::find($this->tenantId); // idem: marca, não tentativa
                if ($t) {
                    $usage->refund($t, 'image', (int) $this->upscale['weight'], $this->upscale['cost'] ?? null);
                    $flow->patchScene($p, $this->index, ['upscale_refunded' => true]);
                }
            }
        }

        // 2) 🎬 LIP-SYNC do KEYFRAME: cena COM diálogo → o talking-head anima a PRÓPRIA CENA (a toca, a
        // floresta) com a boca sincronizada, e o áudio já vem embutido no clipe (não passa pelo mux).
        //
        // ⚠️ Isto substitui o lip-sync PER-FALA (1 talking-head por fala, a partir do RETRATO de quem
        // falava). Aquele desenho tinha 3 defeitos medidos em prod no projeto 12 (2026-07-15): (a) o
        // keyframe da cena era DESCARTADO — 5 das 6 cenas viraram a ficha do personagem em fundo cinza
        // de estúdio; (b) o retrato é 3:4 e a montagem final faz cover+crop pro 16:9 → decapitava o
        // personagem; (c) fala cujo TTS/lip-sync falhava era pulada em silêncio (`continue` sem log),
        // e cena de 3 falas saía com 1 (1,8s). O aspecto aqui vem da IMAGEM — o modelo devolveu 832x1120
        // pro retrato 1792x2400 (3:4), então keyframe 16:9 → clipe 16:9, sem crop na montagem.
        //
        // Falhou → cai no i2v + mux abaixo (nunca quebra a cena).
        //
        // Fala curta demais (< MIN_LIPSYNC_SECONDS) TAMBÉM cai no i2v + mux: ver a constante.
        if (is_array($this->lipLine) && $audioUrl !== '' && $audioDur > 0 && $audioDur < self::MIN_LIPSYNC_SECONDS) {
            Log::info('AnimationSceneJob: fala curta — i2v + mux no lugar do lip-sync (piso de duração da cena)', [
                'project' => $this->projectId, 'i' => $this->index, 'audio_dur' => $audioDur, 'piso' => self::MIN_LIPSYNC_SECONDS,
            ]);
        }
        if (is_array($this->lipLine) && $audioUrl !== '' && $audioDur >= self::MIN_LIPSYNC_SECONDS) {
            $flow->patchScene($p, $this->index, ['video_stage' => 'lipsync']);
            $res = $engine()->timeout(600)->post('/v1/lipsync', [
                'imageUrl' => (string) ($clip['imageUrl'] ?? ''),
                'audioUrl' => $audioUrl,
                'gen_lines' => $this->lipLine,
            ]);
            $lipUrl = $res->successful() ? (string) $res->json('url') : '';
            if ($lipUrl !== '') {
                $flow->patchScene($p, $this->index, [
                    'video_url' => $lipUrl,
                    'audio_url' => $audioUrl,
                    'duration' => $audioDur,
                    'video_status' => 'ready',
                ]);
                $flow->advance($p);

                return;
            }
            Log::warning('AnimationSceneJob: lip-sync do keyframe falhou — cai no i2v + mux', ['project' => $this->projectId, 'i' => $this->index, 'status' => $res->status()]);
            // A tela precisa DIZER que trocou de caminho: o lip-sync falhando e a cena recomeçando
            // pelo i2v é a diferença entre "demorando" e "travado" aos olhos de quem espera.
            $lipFalhou = true;
            $flow->patchScene($p, $this->index, ['video_stage' => 'lipsync_falhou']);
        }

        // 3) i2v do keyframe (mudo). Diálogo longo pede o clipe de 10s (menos freeze-frame no mux).
        if ($audioDur > 5.5) {
            $clip['duration'] = '10';
        }
        // Só sobrescreve o stage quando NÃO veio do fallback: 'lipsync_falhou' é informação que a
        // tela precisa manter à vista até a cena terminar.
        if (! $lipFalhou) {
            $flow->patchScene($p, $this->index, ['video_stage' => 'clipe']);
        }
        $res = $engine()->timeout(600)->post('/v1/filmclip', $clip);
        // Transitório → lança e retenta com $resume (reusa TTS/upscale); só cai aqui quando é pra
        // DESISTIR (4xx ou última tentativa) → marca erro + advance (não deixa o projeto travar).
        $videoUrl = $this->engineUrlOrRetry($res, 'AnimationSceneJob:i2v', ['project' => $this->projectId, 'i' => $this->index]);
        if ($videoUrl === '') {
            $this->refund($usage);
            // 💬 O MOTIVO acompanha a cena. Sem isto a UI só mostrava o keyframe parado e o usuário
            // não tinha como saber por que a cena não animou (incidente 2026-07-22): o erro morria
            // no log do worker. Mensagem do engine quando ela é pro cliente (4xx: modelo incompatível,
            // sem saldo); nas demais, um texto genérico — nunca o corpo cru de um 5xx.
            $flow->patchScene($p, $this->index, [
                'video_status' => 'error',
                'video_error' => $res->clientError()
                    ? (string) ($res->json('error') ?: 'não foi possível animar esta cena')
                    : 'a IA está indisponível no momento — tente animar de novo',
            ]);
            $flow->advance($p);

            return;
        }

        // 4) Mux do diálogo por cima do clipe (max(vídeo, áudio); vídeo curto congela o frame).
        if ($audioUrl !== '') {
            $flow->patchScene($p, $this->index, ['video_stage' => 'mux']);
            $res = $engine()->timeout(300)->post('/v1/muxaudio', ['videoUrl' => $videoUrl, 'audioUrl' => $audioUrl]);
            $muxed = $res->successful() ? (string) $res->json('url') : '';
            if ($muxed !== '') {
                $videoUrl = $muxed;
            } else {
                Log::warning('AnimationSceneJob: mux falhou — cena fica com o clipe mudo', ['project' => $this->projectId, 'i' => $this->index]);
            }
        }

        $flow->patchScene($p, $this->index, [
            'video_url' => $videoUrl,
            'audio_url' => $audioUrl,
            'duration' => $audioDur,
            'video_status' => 'ready',
            'video_error' => '', // limpa o motivo da tentativa anterior — a cena animou
            'video_stage' => '', // some o rótulo de etapa: a cena não está mais em curso
        ]);
        $flow->advance($p);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('AnimationSceneJob falhou', ['project' => $this->projectId, 'error' => $e->getMessage()]);
        $this->refund(app(UsageService::class));
        $p = AnimationProject::find($this->projectId);
        if ($p) {
            $flow = app(AnimationFlow::class);
            $flow->patchScene($p, $this->index, [
                'video_status' => 'error',
                'video_error' => 'a cena não animou depois de várias tentativas — tente de novo',
            ]);
            $flow->advance($p); // não deixa o projeto travar esperando uma cena que esgotou as tentativas
        }
    }

    /**
     * Devolve o que foi reservado no dispatch e NÃO foi entregue. A regra do áudio não é um
     * booleano de quem chama — é o estado da cena:
     *   - áudio gerado (audio_url na cena) → NÃO estorna: o TTS rodou, o arquivo existe e custou.
     *   - áudio já estornado (audio_refunded) → NÃO estorna: seria a 2ª vez.
     *   - resto → estorna.
     *
     * 🐛 Era um parâmetro `refundAudio`, e o i2v passava `$audioUrl === ''` — isto é: quando o
     * áudio FALHAVA (e o passo do diálogo já o tinha estornado), mandava estornar de novo. Áudio
     * e vídeo falhando juntos devolviam 1 crédito por fala a mais, do nada. Com a regra aqui
     * dentro, os 3 caminhos de estorno (áudio falho, i2v sem URL, failed()) não têm como divergir.
     */
    private function refund(UsageService $usage): void
    {
        $t = Tenant::find($this->tenantId);
        if (! $t) {
            return;
        }
        $usage->refund($t, 'video', $this->weight, $this->costCredits);

        $p = AnimationProject::find($this->projectId);
        if ($p) {
            $this->refundAudioOnce($usage, $p, app(AnimationFlow::class));
        }
    }

    /** Estorna o áudio no máximo 1×, e só se ele NÃO foi gerado. Ver refund(). */
    private function refundAudioOnce(UsageService $usage, AnimationProject $p, AnimationFlow $flow): void
    {
        if ($this->audioCount <= 0) {
            return;
        }
        $sc = (array) (array_values((array) $p->fresh()?->storyboard)[$this->index] ?? []);
        if (($sc['audio_refunded'] ?? false) || ($sc['audio_url'] ?? '') !== '') {
            return;
        }
        $t = Tenant::find($this->tenantId);
        if (! $t) {
            return;
        }
        $usage->refund($t, 'audio', $this->audioCount, 1);
        $flow->patchScene($p, $this->index, ['audio_refunded' => true]);
    }
}
