<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\DraftFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Tipos pós-cast que o Larastan não infere do casts() (colunas json/jsonb/timestamp).
 *
 * @property array<array-key,mixed>|null $research
 * @property array<array-key,mixed>|null $texts
 * @property array<array-key,mixed>|null $texts_meta
 * @property array<array-key,mixed>|null $media
 * @property array<array-key,mixed>|null $publish
 * @property array<array-key,mixed>|null $story
 * @property array<array-key,mixed>|null $film
 * @property array<array-key,mixed>|null $carousel
 */
class Draft extends Model
{
    // AUD-020: global scope tenant_id (defesa em profundidade) + relação tenant().
    use BelongsToTenant;

    /** @use HasFactory<DraftFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'keyword', 'research', 'texts', 'texts_meta', 'media',
        'image_url', 'video_url', 'image_prompt', 'status', 'publish', 'story', 'film', 'carousel',
        'scheduled_at',
    ];

    protected function casts(): array
    {
        return [
            'research' => 'array',
            'texts' => 'array',
            'texts_meta' => 'array',
            'media' => 'array',
            'publish' => 'array',
            'story' => 'array',
            'film' => 'array',
            'carousel' => 'array',
            'scheduled_at' => 'datetime',
        ];
    }

    /** ID único de um item de mídia da galeria (campo `id` do array `media`). Antes era só
     *  `(int)(microtime*1000)`, que colidia quando dois jobs/requests escreviam mídia no MESMO
     *  milissegundo (o `id` identifica o item pra excluir/fixar → o errado seria afetado). Agora
     *  = timestamp-ms (mantém a ordenabilidade por tempo) + sufixo aleatório. */
    public static function mediaId(): string
    {
        return (int) (microtime(true) * 1000).'-'.bin2hex(random_bytes(4));
    }

    /** Metadados da imagem para a galeria: largura, altura e peso.
     *
     * POR QUE EXISTE: o item de mídia guardava só {id,kind,url,style,platforms}. Sem dimensão
     * nem motor, não dava pra saber o que era o quê — o Luciano gostou de umas imagens e teve
     * que PERGUNTAR qual motor as tinha gerado. Com os motores diferindo muito (mmx 2048x1152,
     * cursor 1536x1024, KIE 2752x1536), isso é o que permite organizar o trabalho.
     *
     * Lê do NOSSO storage, não por fopen remoto: a URL é sempre nossa (isOwnMediaUrl barra o
     * resto), então buscar por HTTP seria dar a volta no mundo pra ler um arquivo local.
     * Best-effort — metadado é enfeite, nunca pode derrubar a geração que já deu certo.
     *
     * @return array{w?:int,h?:int,bytes?:int}
     */
    public static function imageMeta(?string $url): array
    {
        $url = trim((string) $url);
        if ($url === '') {
            return [];
        }
        try {
            // https://s3…/public/reachyn/image/x.jpg → reachyn/image/x.jpg (o disco já é o bucket)
            $path = (string) parse_url($url, PHP_URL_PATH);
            $key = ltrim((string) preg_replace('#^/[^/]+/#', '', $path), '/');
            $disk = Storage::disk('media');
            if ($key === '' || ! $disk->exists($key)) {
                return [];
            }
            $bytes = (int) $disk->size($key);
            // 48MB: acima disso não é imagem de galeria, e ler inteiro pra medir sairia caro.
            if ($bytes <= 0 || $bytes > 48 << 20) {
                return ['bytes' => $bytes ?: null];
            }
            $info = @getimagesizefromstring($disk->get($key));

            return $info
                ? ['w' => (int) $info[0], 'h' => (int) $info[1], 'bytes' => $bytes]
                : ['bytes' => $bytes];
        } catch (\Throwable $e) {
            Log::debug('imageMeta falhou', ['url' => $url, 'erro' => $e->getMessage()]);

            return [];
        }
    }

    /** Carimbo do MOTOR que gerou a mídia: rótulo + slug do catálogo.
     *
     * POR QUE OS DOIS: até 2026-07-25 a galeria gravava só o `display_name` ("Instantâneo",
     * "Econômico") — rótulo curado que NÃO diz qual modelo rodou. Olhando o acervo depois não
     * dava pra saber que "Instantâneo" era um motor e "Econômico" outro, nem responder "qual
     * modelo gerou este clipe" sem cruzar horário com o catálogo na mão.
     *
     * O `model_slug` é a chave estável (`vid-kling3`) — o front resolve contra /gen-models e
     * mostra o NOME REAL do modelo quando quem olha é o operador (o `real_name` do
     * GenModelResource). Assim a ficha técnica ganha o nome real sem gravar o provedor no
     * banco: white-label (guideline #6) continua decidido na hora de exibir, não no dado.
     *
     * @return array{model?:string,model_slug?:string}
     */
    public static function modelStamp(?GenModel $gm): array
    {
        if (! $gm) {
            return [];
        }

        return ['model' => $gm->display_name ?: $gm->slug, 'model_slug' => $gm->slug];
    }

    /** Metadados de VÍDEO/ÁUDIO para a galeria: dimensão, duração, fps e peso.
     *
     * Irmão do imageMeta(), para o que o getimagesize não lê. A medição roda no ffmpeg-service
     * (/probe → ffprobe), que já é o dono do ffmpeg nesta stack e resolve a URL com anti-SSRF.
     *
     * POR QUE IMPORTA: sem isto todo clipe entra no acervo sem propriedade nenhuma — nove
     * vídeos iguais na grade, sem saber qual motor gerou, quanto tempo tem ou em que resolução
     * saiu. É justamente o que permite comparar motores e repetir o caminho que deu certo.
     *
     * Best-effort igual ao imageMeta: serviço fora do ar → mídia entra sem ficha, e nunca o
     * contrário (a geração já deu certo, não pode falhar por causa de enfeite).
     *
     * @return array{w?:int,h?:int,bytes?:int,dur?:float,fps?:float}
     */
    public static function probeMeta(?string $url): array
    {
        $url = trim((string) $url);
        $base = rtrim((string) config('filesystems.disks.media.url'), '/');
        if ($url === '' || $base === '') {
            return [];
        }
        // Só mede mídia NOSSA. O /probe já barra IP privado por conta própria; este filtro
        // evita a viagem pra URL de terceiro que nem deveria estar no acervo.
        //
        // O host gravado pode estar velho (o storage já passou por quick tunnel efêmero, túnel
        // nomeado e localhost). Quem manda é o CAMINHO: se ele é do nosso bucket, medimos pelo
        // host ATUAL. Sem isto, arquivo vivo com URL antiga fica eternamente sem ficha.
        if (! str_starts_with($url, $base)) {
            $prefixo = (string) parse_url($base, PHP_URL_PATH);      // ex.: /public
            $caminho = (string) parse_url($url, PHP_URL_PATH);
            if ($prefixo === '' || ! str_starts_with($caminho, $prefixo)) {
                return [];
            }
            $url = $base.substr($caminho, strlen($prefixo));
        }
        try {
            $res = Http::baseUrl(rtrim((string) config('services.ffmpeg.url'), '/'))
                ->withHeaders(['X-Service-Token' => (string) config('services.ffmpeg.token')])
                ->timeout(90)
                ->post('/probe', ['url' => $url]);
            if (! $res->successful() || ! $res->json('ok')) {
                return [];
            }
            $out = [];
            // `duration` no serviço → `dur` no item de mídia (nome curto que a galeria lê).
            foreach (['w' => 'int', 'h' => 'int', 'bytes' => 'int', 'duration' => 'float', 'fps' => 'float'] as $campo => $tipo) {
                $v = $res->json($campo);
                if ($v !== null) {
                    $out[$campo === 'duration' ? 'dur' : $campo] = $tipo === 'int' ? (int) $v : round((float) $v, 2);
                }
            }

            return $out;
        } catch (\Throwable $e) {
            Log::debug('probeMeta falhou', ['url' => $url, 'erro' => $e->getMessage()]);

            return [];
        }
    }
}
