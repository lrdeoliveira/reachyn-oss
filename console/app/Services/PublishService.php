<?php

namespace App\Services;

use App\Http\Controllers\Api\StudioController;
use App\Models\Approval;
use App\Models\Draft;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Publica uma aprovação nas contas conectadas do tenant (via Zernio).
 * Substitui o passo de publish do flow Windmill — agora nativo no console.
 */
class PublishService
{
    public function __construct(private ZernioService $zernio) {}

    /** Converte uma imagem WEBP do NOSSO storage para JPEG (Instagram só aceita JPG/PNG) e
     *  devolve a URL nova; qualquer outra imagem (ou falha) devolve a URL original. Detecção por
     *  MAGIC BYTES (RIFF….WEBP), não por extensão — o storage tem webp gravado como .jpg (o
     *  Persist usa a extensão do bucket, não o formato real). Requer GD com webp (Dockerfile). */
    public static function imageUrlToJpeg(string $url, bool $alsoGif = false): string
    {
        try {
            if (! StudioController::isOwnMediaUrl($url)) {
                return $url; // só converte mídia NOSSA (anti-SSRF)
            }
            $raw = Http::timeout(30)->get($url)->body();
            $isWebp = $raw !== '' && strlen($raw) >= 16 && substr($raw, 0, 4) === 'RIFF' && substr($raw, 8, 4) === 'WEBP';
            // GIF (magic GIF87a/GIF89a): o Instagram rejeita ("only JPG and PNG") — quando
            // $alsoGif, achata o 1º frame em JPEG (GD lê gif; animação se perde, mas publica).
            $isGif = $alsoGif && $raw !== '' && strlen($raw) >= 6 && substr($raw, 0, 4) === 'GIF8';
            if (! $isWebp && ! $isGif) {
                return $url; // formato já aceito — segue como está
            }
            if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
                return $url; // GD indisponível — melhor publicar a original que quebrar
            }
            $im = @imagecreatefromstring($raw);
            if (! $im) {
                return $url;
            }
            // Achata transparência em fundo branco (JPEG não tem alpha).
            $w = imagesx($im);
            $h = imagesy($im);
            $flat = imagecreatetruecolor($w, $h);
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $im, 0, 0, 0, 0, $w, $h);
            ob_start();
            imagejpeg($flat, null, 90);
            $jpg = (string) ob_get_clean();
            imagedestroy($im);
            imagedestroy($flat);
            if ($jpg === '') {
                return $url;
            }
            $name = 'reachyn/publish/jpg_'.(int) (microtime(true) * 1000).'-'.bin2hex(random_bytes(3)).'.jpg';
            Storage::disk('media')->put($name, $jpg, 'public');

            return Storage::disk('media')->url($name);
        } catch (\Throwable) {
            return $url; // conversão é best-effort — nunca derruba a publicação
        }
    }

    /** Normaliza a MÍDIA de um post pra publicação: imagens webp → JPEG (Instagram/TikTok só
     *  aceitam JPG/PNG). Vídeos passam direto. Usada nos DOIS caminhos (publicar + repostar). */
    public static function normalizeMediaForPublish(array $media): array
    {
        return array_map(function ($m) {
            if (($m['type'] ?? '') === 'image' && ! empty($m['url'])) {
                $m['url'] = self::imageUrlToJpeg((string) $m['url']);
            }

            return $m;
        }, $media);
    }

    /** Ajustes POR REDE na mídia já normalizada. Instagram: GIF → JPEG do 1º frame (a rede só
     *  aceita JPG/PNG; incidente publicação #19, 2026-07-11 — o GIF derrubava a rede inteira e
     *  o post saía "parcial"). Outras redes que aceitam GIF (TikTok) seguem com o original. */
    public static function mediaForPlatform(string $platform, array $media): array
    {
        if ($platform !== 'instagram') {
            return $media;
        }

        return array_map(function ($m) {
            if (($m['type'] ?? '') === 'image' && ! empty($m['url'])) {
                $m['url'] = self::imageUrlToJpeg((string) $m['url'], alsoGif: true);
                $m['url'] = self::imageUrlToInstagramAspect($m['url']);
            }

            return $m;
        }, $media);
    }

    /** Proporções que o feed do Instagram aceita numa IMAGEM: de 4:5 (0,8) a 1.91:1. */
    private const IG_MIN_RATIO = 0.75;

    private const IG_MAX_RATIO = 1.91;

    /**
     * Tela de destino pra encaixar uma imagem w×h no feed do Instagram, ou null se já cabe.
     *
     * A imagem é CENTRALIZADA com barras (letterbox/pillarbox) — nunca cortada. É a mesma escolha
     * do `padFit` da montagem: com barra o enquadramento errado fica VISÍVEL; com corte, o topo da
     * cena some em silêncio. Vertical 9:16 (0,5625) vira 4:5, que é o mais alto que o feed aceita.
     *
     * @return array{0:int,1:int}|null [larguraFinal, alturaFinal]
     */
    public static function instagramCanvas(int $w, int $h): ?array
    {
        if ($w <= 0 || $h <= 0) {
            return null;
        }
        $ratio = $w / $h;
        if ($ratio >= self::IG_MIN_RATIO && $ratio <= self::IG_MAX_RATIO) {
            return null; // já cabe — não mexe
        }
        if ($ratio < self::IG_MIN_RATIO) {
            return [(int) ceil($h * 0.8), $h]; // alta demais → alarga pra 4:5 (barras nas laterais)
        }

        return [$w, (int) ceil($w / self::IG_MAX_RATIO)]; // larga demais → barras em cima/embaixo
    }

    /**
     * Reenquadra a imagem pro feed do Instagram quando a proporção está fora da faixa aceita.
     *
     * 🐛 Sem isso, o publish morria com o 400 cru do provedor — "Aspect ratio 0.56:1 is outside
     * Instagram's allowed range (0.75 to 1.91)" — em TODO vídeo/história vertical (9:16 = 0,5625),
     * que é o formato padrão do produto. O erro chegava ao operador como texto de API.
     *
     * Best-effort igual ao imageUrlToJpeg: qualquer tropeço devolve a URL original (melhor tentar
     * publicar o que veio do que derrubar a rede inteira).
     */
    public static function imageUrlToInstagramAspect(string $url): string
    {
        try {
            if (! StudioController::isOwnMediaUrl($url)) {
                return $url; // só mexe em mídia NOSSA (anti-SSRF)
            }
            if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
                return $url; // GD indisponível — publica a original
            }
            $raw = Http::timeout(30)->get($url)->body();
            if ($raw === '' || ! ($dim = @getimagesizefromstring($raw))) {
                return $url;
            }
            $canvas = self::instagramCanvas((int) $dim[0], (int) $dim[1]);
            if ($canvas === null) {
                return $url; // proporção já aceita
            }
            [$novoW, $novoH] = $canvas;
            $im = @imagecreatefromstring($raw);
            if (! $im) {
                return $url;
            }
            $w = imagesx($im);
            $h = imagesy($im);
            $tela = imagecreatetruecolor($novoW, $novoH);
            imagefill($tela, 0, 0, imagecolorallocate($tela, 0, 0, 0)); // barras pretas (cinema)
            imagecopy($tela, $im, (int) (($novoW - $w) / 2), (int) (($novoH - $h) / 2), 0, 0, $w, $h);
            ob_start();
            imagejpeg($tela, null, 90);
            $jpg = (string) ob_get_clean();
            // sem imagedestroy(): deprecado no PHP 8.5 (sem efeito desde o 8.0 — o GC cuida)
            if ($jpg === '') {
                return $url;
            }
            $name = 'reachyn/publish/ig_'.(int) (microtime(true) * 1000).'-'.bin2hex(random_bytes(3)).'.jpg';
            Storage::disk('media')->put($name, $jpg, 'public');

            return Storage::disk('media')->url($name);
        } catch (\Throwable) {
            return $url;
        }
    }

    /** Título de FOTO-POST do TikTok: a rede usa o texto como título do slideshow, teto 90 chars.
     *  Usa a 1ª linha se couber; senão corta em fronteira de palavra + "…". Nunca estoura. */
    /**
     * FORMATO do post no Reddit. Não é preferência de estilo: no Reddit um post é de UM tipo só,
     * e os dois tipos possíveis aqui perdem coisas diferentes.
     *
     *  - TEXTO (self post): o corpo inteiro é publicado (até 40.000 caracteres), mas a imagem NÃO
     *    entra embutida. O Reddit só renderiza imagem inline em selftext quando ela é hospedada
     *    por ele, via richtext — Markdown `![](url)` para URL externa não vira imagem, vira texto
     *    solto. Por isso a imagem sai como LINK clicável, que é o que o Reddit de fato entrega.
     *  - IMAGEM (post nativo): a foto aparece grande no feed, e o texto vira só o TÍTULO. O corpo
     *    não existe nesse tipo de post — é o que já custou uma peça publicada muda.
     *
     * Não há terceira opção: o provedor não expõe primeiro-comentário para o Reddit (só Facebook
     * e Instagram têm `firstComment`), então "imagem no post + texto no comentário" não é possível
     * por aqui.
     *
     * ⚠️ O DEFAULT é IMAGEM, e isso não é gosto: é o comportamento que a peça já tinha antes de
     * 2026-08-04 — post de imagem nativo, foto aparecendo no feed. Em 04/08 eu troquei o default
     * pra TEXTO tentando salvar o corpo, e o efeito colateral foi tirar do ar a única coisa que
     * funcionava: a imagem virou link. Mudança de default é mudança de produto; quando um dos dois
     * lados já estava entregando, o default fica onde estava e o outro vira opt-in.
     *
     * Peça SEM mídia não passa por aqui de forma relevante: sem imagem o post já é self por
     * natureza e o corpo inteiro é publicado, qualquer que seja o formato escolhido.
     */
    public const REDDIT_TEXTO = 'texto';

    public const REDDIT_IMAGEM = 'imagem';

    /** Formatos aceitos (allowlist — o valor vem do cliente). */
    public static function redditFormato(mixed $valor): string
    {
        return $valor === self::REDDIT_TEXTO ? self::REDDIT_TEXTO : self::REDDIT_IMAGEM;
    }

    /**
     * ALVO do Reddit → o `subreddit` que a API espera.
     *
     * O cliente escolhe entre publicar numa COMUNIDADE (`r/redfoxcode`) ou no PRÓPRIO PERFIL
     * (`u/redfoxcode`). No protocolo do Reddit os dois são o mesmo campo: o perfil é um
     * "subreddit" chamado `u_<usuário>`. Quem digita "u/redfoxcode" quer o perfil; quem digita
     * "redfoxcode" ou "r/redfoxcode" quer a comunidade.
     *
     * Sem prefixo = comunidade, porque é o caso comum e o que a tela sempre significou.
     */
    public static function redditSubreddit(string $alvo): string
    {
        $alvo = ltrim(trim($alvo), '/');
        if (preg_match('#^u/(.+)$#i', $alvo, $m)) {
            return 'u_'.$m[1];
        }

        return preg_match('#^r/(.+)$#i', $alvo, $m) ? $m[1] : $alvo;
    }

    /**
     * `platformSpecificData` do Reddit: onde publicar, com que título e em que FORMATO.
     *
     * A regra que faltava (doc do provedor, conferida em 2026-08-04): num post de IMAGEM o Reddit
     * usa o `content` como TÍTULO e **não existe corpo** — foi por isso que a peça saiu como foto
     * muda, com o texto inteiro descartado em silêncio. `forceSelf` força um post de TEXTO mesmo
     * com mídia anexada, e aí o corpo (até 40.000 caracteres) é publicado.
     *
     * Escolha: quando existe corpo além do título, o TEXTO ganha — é o que o cliente escreveu e o
     * que ele foi conferir depois. A imagem não some junto: vai anexada no fim do corpo em
     * Markdown (ver redditContent). Quando o texto é só uma linha, não há corpo a perder e o post
     * de imagem continua sendo o melhor formato.
     */
    public static function redditPlatformData(string $alvo, string $content, bool $temMidia, string $formato = self::REDDIT_IMAGEM, string $tituloEscolhido = ''): array
    {
        // Título escrito pelo cliente ganha do derivado. No formato IMAGEM o título é o ÚNICO
        // texto que acompanha a foto, e são 300 caracteres — espaço pra mensagem inteira, não só
        // pra chamada. Vazio = a 1ª linha útil do texto da rede, como sempre foi.
        $titulo = trim($tituloEscolhido) !== '' ? self::redditTitle($tituloEscolhido) : self::redditTitle($content);
        $data = ['subreddit' => self::redditSubreddit($alvo), 'title' => $titulo];
        if ($temMidia && $formato === self::REDDIT_TEXTO && self::redditBody($content) !== '') {
            $data['forceSelf'] = true;
        }

        return $data;
    }

    /**
     * 🖼️→🎞️ Converte a IMAGEM da peça num clipe curto pra publicar no Reddit como **videogif**.
     *
     * Por que isto existe: o provedor faz upload NATIVO só de VÍDEO. Imagem — mesmo hospedada no
     * storage dele — vira link externo, e o post fica apontando pro nosso S3 (medido em 4 testes
     * reais, 2026-08-04). Com `videogif: true` o mesmo upload nativo é submetido com o "kind" de
     * GIF: o Reddit exibe em LOOP SILENCIOSO, sem controles de player — cara de imagem,
     * comportamento de GIF, e o arquivo passa a viver no `v.redd.it`.
     *
     * `aspect: auto` é obrigatório aqui: o modo normal do camclip corta a arte pra forçar 9:16.
     * `move: static` aplica um micro-zoom de 3% — sem ele o loop lê como imagem travada.
     *
     * Custo ZERO (ffmpeg local, sem IA) e best-effort: qualquer tropeço devolve null e a peça
     * publica como imagem normal, que é o comportamento de antes.
     */
    public function redditVideogif(string $imageUrl): ?string
    {
        try {
            $r = Http::baseUrl(rtrim((string) config('services.ffmpeg.url'), '/'))
                ->withHeaders(['X-Service-Token' => (string) config('services.ffmpeg.token')])
                ->timeout(180)
                ->post('/camclip', [
                    'image_url' => $imageUrl,
                    'move' => 'static',
                    'duration' => 3,
                    'aspect' => 'auto',
                ]);
            $url = $r->successful() ? (string) $r->json('url') : '';

            return $url !== '' ? $url : null;
        } catch (\Throwable $e) {
            Log::warning('Reddit videogif: conversão falhou — publica como imagem', ['erro' => $e->getMessage()]);

            return null;
        }
    }

    /** Corpo do post (tudo depois da 1ª linha útil, que virou título). Vazio = post só de título. */
    public static function redditBody(string $content): string
    {
        $linhas = preg_split('/\r?\n/', trim($content)) ?: [];
        $achouTitulo = false;
        $corpo = [];
        foreach ($linhas as $l) {
            if (! $achouTitulo) {
                // Mesma varredura do redditTitle: a 1ª linha com texto de verdade é o título.
                $limpa = trim(preg_replace('/[*_`~]+/u', '', (string) preg_replace('/^\s*(?:#{1,6}|>|[-*+]|\d+\.)\s*/u', '', (string) $l)) ?? '');
                if ($limpa !== '') {
                    $achouTitulo = true;
                }

                continue;
            }
            $corpo[] = $l;
        }

        return trim(implode("\n", $corpo));
    }

    /**
     * `content` do Reddit: título + corpo, com a mídia citada ao final quando o post é self.
     *
     * ⚠️ A URL vai CRUA, não em Markdown de imagem. `![Imagem](url)` foi a primeira tentativa e
     * não funciona: o Reddit não embute imagem externa em selftext — o markdown some e o leitor
     * fica sem nada. URL crua o Reddit auto-linka, então vira um link que abre a imagem. É menos
     * do que se queria e é o máximo que o formato permite; quem quer a foto no feed escolhe o
     * formato IMAGEM.
     */
    public static function redditContent(string $content, array $mediaItems, bool $forceSelf): string
    {
        if (! $forceSelf || $mediaItems === []) {
            return $content;
        }
        $links = [];
        foreach ($mediaItems as $m) {
            $url = (string) ($m['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $links[] = (($m['type'] ?? 'image') === 'video' ? '🎬 Vídeo: ' : '🖼️ Imagem: ').$url;
        }

        return $links === [] ? $content : rtrim($content)."\n\n".implode("\n", $links);
    }

    /**
     * TÍTULO do post no Reddit — a linha que o leitor vê na timeline.
     *
     * Por que existe: no Reddit o título é obrigatório e, num post de imagem, é o ÚNICO texto que
     * aparece. Os textos da rede vêm em Markdown do gerador (a publicação 24 começava com
     * "## O impacto da IA…"), então a marcação tem que cair — senão o "##" vai literal pro título.
     * Teto de 300 é o limite duro da API do Reddit; acima disso o post é recusado.
     *
     * O corpo (`content`) continua indo no mesmo pedido: quando o post é de TEXTO, o provedor o
     * usa como selftext; quando é de imagem, o Reddit não tem onde pôr corpo — e aí o título ser
     * a primeira frase de verdade é o que salva a peça de virar uma foto muda.
     */
    public static function redditTitle(string $text, string $fallback = ''): string
    {
        $linha = '';
        foreach (preg_split('/\r?\n/', trim($text)) ?: [] as $l) {
            // Tira marcação de bloco (#, >, -, *, 1.) e ênfase inline (**, __, *, _, `).
            $l = trim(preg_replace('/^\s*(?:#{1,6}|>|[-*+]|\d+\.)\s*/u', '', (string) $l) ?? '');
            $l = trim(preg_replace('/[*_`~]+/u', '', $l) ?? '');
            if ($l !== '') {
                $linha = $l;
                break;
            }
        }
        if ($linha === '') {
            $linha = trim($fallback) ?: 'Publicação';
        }
        if (mb_strlen($linha) <= 300) {
            return $linha;
        }
        $cut = mb_substr($linha, 0, 299);
        if (($sp = mb_strrpos($cut, ' ')) !== false && $sp > 120) {
            $cut = mb_substr($cut, 0, $sp);
        }

        return rtrim($cut, ' ,;:-–—').'…';
    }

    public static function tiktokPhotoTitle(string $text): string
    {
        $line = trim((string) preg_split('/\r?\n/', trim($text))[0]);
        if ($line === '') {
            $line = trim($text);
        }
        if (mb_strlen($line) <= 90) {
            return $line;
        }
        $cut = mb_substr($line, 0, 89);
        if (($sp = mb_strrpos($cut, ' ')) !== false && $sp > 40) {
            $cut = mb_substr($cut, 0, $sp);
        }

        return rtrim($cut, ' ,;:-–—').'…';
    }

    /**
     * @return array<int,array{ok:bool,id?:string,detail?:string}> resultados por conta
     */
    public function publishApproval(Approval $a): array
    {
        $accounts = $this->zernio->listAccounts($a->tenant?->zernio_profile_id);

        $media = [];
        if ($a->image_url) {
            $media[] = ['type' => 'image', 'url' => $a->image_url];
        }
        if ($a->video_url) {
            $media[] = ['type' => 'video', 'url' => $a->video_url];
        }

        $target = $a->meta['platform'] ?? null; // se a peça é de 1 plataforma específica
        // Seleção de REDES escolhida no envio (aba "Subir e publicar"): meta.platforms (array).
        // Presente e não-vazia = publica SÓ nessas redes; ausente = todas (retrocompat).
        $selected = is_array($a->meta['platforms'] ?? null) && ($a->meta['platforms'] ?? []) !== []
            ? $a->meta['platforms'] : null;
        // 👽 Alvo/formato/título do Reddit escolhidos no envio — mesma tradução r/ u/ do fluxo
        // de rascunho (ver redditPlatformData/redditSubreddit).
        $subreddit = trim((string) ($a->meta['reddit_subreddit'] ?? ''));
        $redditFormato = self::redditFormato($a->meta['reddit_formato'] ?? null);
        $redditTitulo = trim((string) ($a->meta['reddit_title'] ?? ''));

        $results = [];
        foreach ($accounts as $acc) {
            $platform = (string) ($acc['platform'] ?? '');
            if ($target && $platform !== $target) {
                continue;
            }
            if ($selected !== null && ! in_array($platform, $selected, true)) {
                continue; // rede fora da seleção do envio
            }
            $accMedia = self::mediaForPlatform($platform, self::normalizeMediaForPublish($media));
            $platformData = [];
            $content = (string) $a->preview_text;
            if ($platform === 'reddit') {
                $platformData = self::redditPlatformData($subreddit, $content, $accMedia !== [], $redditFormato, $redditTitulo);
                // Post self forçado: a mídia não sobe como nativa, então vai no corpo em Markdown.
                $content = self::redditContent($content, $accMedia, (bool) ($platformData['forceSelf'] ?? false));
            }
            $res = $this->zernio->createPost(
                $platform,
                $acc['_id'],
                $content,
                $a->keyword ?: null,
                $accMedia,
                true,
                $platformData,
            );
            // 👽 REDDIT, formato IMAGEM: o corpo vai no PRIMEIRO COMENTÁRIO (post de imagem só
            // tem título) — mesmo costume do fluxo de rascunho, best-effort.
            if ($platform === 'reddit' && ($res['ok'] ?? false) && ! empty($res['id'])) {
                $res = array_merge($res, $this->redditPrimeiroComentario(
                    (string) $res['id'], (string) $acc['_id'], (string) $a->preview_text, $platformData, $accMedia
                ));
            }
            $results[] = $res;
        }

        return $results;
    }

    /**
     * Publica um rascunho nos PERFIS selecionados (publish.profile_ids) — 1..N profiles do Zernio.
     * Vazio = perfil padrão do tenant (retrocompat). Faz fan-out: cada perfil posta nas suas contas;
     * o resultado de cada rede vem anotado com o perfil (`profile`) p/ a UI distinguir.
     *
     * `comment_id`/`comment_error` aparecem só no Reddit em formato imagem (ver redditPrimeiroComentario).
     *
     * @return array<int,array{platform:string,ok:bool,profile?:string,id?:string,detail?:string,comment_id?:string|null,comment_error?:string}>
     */
    public function publishDraft(Draft $d): array
    {
        $ids = is_array($d->publish['profile_ids'] ?? null) ? $d->publish['profile_ids'] : [];
        $profiles = $ids !== [] ? ($d->tenant?->profiles()->whereIn('id', $ids)->get()) : null;
        if ($profiles === null || $profiles->isEmpty()) {
            // Fallback retrocompat: perfil padrão (ou o zernio_profile_id do tenant direto).
            $default = $d->tenant?->profiles()->where('is_default', true)->first();
            $profiles = collect([$default ?: (object) ['name' => $d->tenant?->name ?? 'perfil', 'zernio_profile_id' => $d->tenant?->zernio_profile_id]]);
        }

        $results = [];
        foreach ($profiles as $profile) {
            $results = array_merge($results, $this->publishToProfile($d, $profile->zernio_profile_id ?? null, (string) ($profile->name ?? 'perfil')));
        }

        return $results;
    }

    /**
     * Publica cada rede COM texto do rascunho nas contas de UM perfil (zernioProfileId).
     * Vídeo manda 1 (o Short final, sem `scene`); senão até 4 imagens. Cada resultado é anotado
     * com `profile` (nome do perfil).
     *
     * @return array<int,array{platform:string,ok:bool,profile:string,id?:string,detail?:string}>
     */
    private function publishToProfile(Draft $d, ?string $zernioProfileId, string $profileName): array
    {
        $accounts = $this->zernio->listAccounts($zernioProfileId);
        $accMap = [];
        foreach ($accounts as $acc) {
            $accMap[$acc['platform'] ?? ''] = $acc['_id'] ?? null;
        }

        $gallery = $d->media ?? [];
        // #5: seleção de redes do submit (publish.platforms). null = publica todas com texto (retrocompat).
        $selected = is_array($d->publish['platforms'] ?? null) ? $d->publish['platforms'] : null;
        // Comunidade do Reddit (subreddit) escolhida no submit. Vazio = subreddit padrão da conta.
        $subreddit = trim((string) ($d->publish['reddit_subreddit'] ?? ''));
        // Formato escolhido no submit (texto | imagem). Rascunho antigo, sem o campo, cai em
        // 'texto' — o default do serviço.
        $redditFormato = self::redditFormato($d->publish['reddit_formato'] ?? null);
        $redditTitulo = trim((string) ($d->publish['reddit_title'] ?? ''));

        $results = [];
        foreach (($d->texts ?? []) as $platform => $content) {
            if (trim((string) $content) === '') {
                continue;
            }
            if ($selected !== null && ! in_array($platform, $selected, true)) {
                continue; // rede fora da seleção do submit (#5)
            }
            // Mídia DESTA plataforma: itens marcados p/ ela OU sem marcação (platforms vazio = serve todas, retrocompat).
            $forPlat = array_values(array_filter($gallery, function ($m) use ($platform) {
                $ps = $m['platforms'] ?? [];

                return ! is_array($ps) || $ps === [] || in_array($platform, $ps, true);
            }));
            // SÓ o vídeo FINAL é publicado: os clipes por cena da história têm o campo `scene`
            // (são intermediários) e ficam de fora — publica o Short montado (sem `scene`).
            $videos = array_values(array_filter($forPlat, fn ($m) => ($m['kind'] ?? '') === 'video' && empty($m['scene'])));
            $images = array_values(array_filter($forPlat, fn ($m) => ($m['kind'] ?? '') === 'image'));
            if ($videos !== []) {
                $last = end($videos);
                $media = [['type' => 'video', 'url' => $last['url']]];
            } else {
                // Imagens: webp → JPEG (Instagram/TikTok só aceitam JPG/PNG; detecção por magic bytes).
                $media = self::normalizeMediaForPublish(array_map(fn ($m) => ['type' => 'image', 'url' => $m['url']], array_slice($images, -4)));
            }
            // Regras POR REDE (Instagram: GIF → JPEG 1º frame) — depois do normalize, antes do snapshot.
            $media = self::mediaForPlatform($platform, $media);
            // Snapshot do que foi publicado NESTA rede (texto + mídia) — guardado no arquivo
            // pra o detalhe mostrar o conteúdo por rede (cada rede tem o seu texto). Normaliza
            // a mídia pra {kind,url} (igual ao resto do app).
            $netMedia = array_map(fn ($m) => ['kind' => $m['type'] ?? 'image', 'url' => $m['url']], $media);
            $snap = ['text' => (string) $content, 'media' => $netMedia, 'profile' => $profileName];
            if ($platform === 'blog') {
                continue; // blog descontinuado como rede — ignora rascunhos legados sem publicar/poluir o resultado
            }
            $accountId = $accMap[$platform] ?? null;
            if (! $accountId) {
                $results[] = array_merge(['platform' => $platform, 'ok' => false, 'detail' => 'conta não conectada'], $snap);

                continue;
            }
            $title = $platform === 'youtube' ? mb_substr($d->keyword ?: 'Vídeo', 0, 95) : null;
            // 👽 REDDIT: alvo (comunidade r/ ou perfil u/), título e formato do post. Ver
            // redditPlatformData — é lá que mora o porquê de `forceSelf`. O snapshot guarda o
            // alvo COMO O CLIENTE ESCREVEU, pro repost reusar a mesma escolha.
            $platformData = [];
            if ($platform === 'reddit') {
                $platformData = self::redditPlatformData($subreddit, (string) $content, $media !== [], $redditFormato, $redditTitulo);
                $snap['subreddit'] = $subreddit;
                $snap['reddit_formato'] = $redditFormato; // o repost repete a MESMA escolha
                $snap['reddit_title'] = $platformData['title'];
                // 🎞️ IMAGEM → VIDEOGIF NATIVO: no formato imagem, a arte sobe como clipe em loop
                // silencioso e passa a viver no v.redd.it (ver redditVideogif pro porquê). Só
                // quando a peça é UMA imagem — vídeo já é nativo, e galeria perderia as outras.
                if ($redditFormato === self::REDDIT_IMAGEM && $videos === [] && count($media) === 1
                    && ($gif = $this->redditVideogif((string) $media[0]['url']))) {
                    $media = [['type' => 'video', 'url' => $gif]];
                    $platformData['videogif'] = true;
                    $snap['media'] = [['kind' => 'image', 'url' => $netMedia[0]['url'] ?? $gif]]; // arquivo mostra a ARTE, não o invólucro
                }
            }
            // TikTok FOTO-POST: a rede usa o texto como TÍTULO do slideshow (teto 90 chars) — o
            // texto cheio da rede estouraria (erro 400). Publica a 1ª linha/corte seguro ≤90.
            $postContent = match (true) {
                $platform === 'tiktok' && $videos === [] => self::tiktokPhotoTitle((string) $content),
                // Post self forçado: a mídia não sobe como nativa, então vai no corpo em Markdown.
                $platform === 'reddit' => self::redditContent((string) $content, $media, (bool) ($platformData['forceSelf'] ?? false)),
                default => (string) $content,
            };
            $res = $this->zernio->createPost($platform, $accountId, $postContent, $title, $media, true, $platformData);
            // 👽 REDDIT, formato IMAGEM: o corpo não cabe no post (post de imagem só tem título),
            // então vai no PRIMEIRO COMENTÁRIO — que é o costume da própria rede e como a peça
            // fica completa: foto no feed + texto logo abaixo.
            if ($platform === 'reddit' && ($res['ok'] ?? false) && ! empty($res['id'])) {
                $res = array_merge($res, $this->redditPrimeiroComentario(
                    (string) $res['id'], $accountId, (string) $content, $platformData, $media
                ));
            }
            $results[] = array_merge(['platform' => $platform], $res, $snap);
        }

        return $results;
    }

    /**
     * Publica o CORPO da peça como primeiro comentário do post no Reddit.
     *
     * Só faz sentido no formato IMAGEM: no self post (`forceSelf`) o corpo já foi publicado no
     * próprio post, e repetir viraria eco. Sem corpo (texto de uma linha), não há o que comentar.
     *
     * BEST-EFFORT: o post já está no ar quando isto roda. Se o comentário falhar, a publicação
     * continua válida — anota `comment_error` no resultado pra aparecer no arquivo, e não estorna
     * nada. O que NÃO pode acontecer é o inverso: derrubar uma peça publicada por causa do comentário.
     *
     * @param  array<string,mixed>  $platformData
     * @param  array<int,mixed>  $media
     * @return array{comment_id?:string|null,comment_error?:string}
     */
    private function redditPrimeiroComentario(string $postId, string $accountId, string $content, array $platformData, array $media): array
    {
        if (($platformData['forceSelf'] ?? false) || $media === []) {
            return [];
        }
        $corpo = self::redditBody($content);
        if ($corpo === '') {
            return [];
        }
        $c = $this->zernio->commentOnPost($postId, $accountId, $corpo);

        return $c['ok'] ? ['comment_id' => $c['commentId'] ?? null] : ['comment_error' => $c['detail'] ?? 'falhou'];
    }
}
