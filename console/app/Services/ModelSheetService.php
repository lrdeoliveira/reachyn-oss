<?php

namespace App\Services;

use App\Models\Character;
use App\Models\GenModel;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MODEL SHEET DETERMINÍSTICO — em vez de a IA desenhar a folha inteira (layout + rótulos variavam
 * a cada geração e o texto saía alucinado: "Red Coller", "Color Shattch"), a IA gera SÓ os SHOTS
 * individuais limpos (i2i ancorado na base, sem texto) e o ffmpeg-service COMPÕE cada folha num
 * template FIXO (grid + rótulos corretos + swatches). Mesmo personagem → mesmo layout, sempre.
 *
 * Grupos = folhas (ordem fixa): angles (turnaround) · head (cabeça/expressões) · poses ·
 * outfit (acessórios & vestimenta) · palette (só swatches, sem IA).
 */
class ModelSheetService
{
    // Estilos fotográficos → reforça foto real (anti-3D). Espelha CharacterController.
    private const PHOTO_STYLES = ['realista', 'produto', 'editorial', 'macro', 'arquitetura', 'noir', 'vintage'];

    /**
     * Motor dos shots do model sheet: `img-realista` (seedream/4.5). ESCOLHIDO MEDINDO, não por
     * catálogo — mesma prancha (head, 6 shots), mesmo personagem, mesma base (2026-07-21):
     *
     *   img-personagem  (ideogram/character) → 4/6 · qualidade boa   · 12 créditos  [anterior]
     *   img-ultra       (nano-banana-pro)    → 1/6 · qualidade ótima · 23 créditos
     *   img-realista    (seedream/4.5)       → 4/6 · qualidade ótima ·  9 créditos  ← vencedor
     *
     * O `img-ultra` parecia a escolha óbvia (topo do catálogo, mesmo motor da imagem-base) e foi a
     * PIOR: nano-banana-* é Google, e a política de conteúdo deles recusou 5 dos 6 shots de um
     * dachshund cartoon. Qualidade por shot não serve de nada quando a maioria das células volta
     * vazia. O `ideogram/character`, especialista em personagem, entregava a mesma taxa que o
     * seedream com acabamento inferior e custando mais.
     *
     * Lição embutida: quando um provedor recusa por moderação, a saída é trocar de CASA de
     * moderação (aqui: Google → ByteDance), não subir de tier dentro da mesma família.
     *
     * Shots de PRODUTO (flat-lay de item, sem personagem) seguem no i2i default — o especialista
     * forçaria o personagem dentro da foto do item. Indisponível → cai no default.
     */
    public const SHEET_I2I_MODEL = 'img-realista';

    /** Folhas do BUNDLE PADRÃO (geração completa), na ordem canônica de exibição.
     *  'accessories' (não 'outfit') pra não colidir com os figurinos manuais do addOutfit (kind='outfit'). */
    public const KINDS = ['angles', 'head', 'poses', 'accessories', 'palette'];

    /** Kinds válidos p/ regeneração de UMA folha (bundle + 'shots' e 'lighting' opt-in, fora do bundle padrão). */
    public const REGEN_KINDS = ['angles', 'head', 'poses', 'accessories', 'palette', 'shots', 'lighting'];

    /**
     * Monta as specs dos grupos do model sheet a partir do personagem + bíblia.
     * Cada grupo: {kind, cols, aspect, title, subtitle, shots:[{prompt,label}], palette?}.
     * `$only` limita a 1 grupo (regeneração per-folha). `$tweak` = ajuste livre do usuário
     * (botão Editar da prancha), anexado ao prompt de TODO shot do grupo.
     *
     * @return array<string,array<string,mixed>>
     */
    public function groups(Character $c, ?array $bible, string $lang, ?string $only = null, string $tweak = ''): array
    {
        $pt = $lang !== 'en-US';
        $L = fn (string $p, string $e) => $pt ? $p : $e;
        $isPhoto = in_array($c->style, self::PHOTO_STYLES, true);
        $NAME = mb_strtoupper($c->name ?: 'Personagem');

        // IDENTIDADE travada em TODO shot: espécie/sexo/cores (não vira macho), corpo natural,
        // anatomia neutra (sem genitália) e, p/ estilos foto, anti-3D/boneco.
        $lock = trim((string) $c->lock);
        $desc = trim((string) $c->description);
        $idSrc = $lock !== '' ? $lock : $desc;
        // 🧍 TIPO DE SUJEITO (human | animal | '' desconhecido). Fonte primária = classificação
        // EXPLÍCITA da vision na bíblia (bible.subject, engine /v1/characterbible). O regex é só
        // FALLBACK pra personagens antigos sem subject — e com precedência POSICIONAL: a palavra
        // que aparece PRIMEIRO no lock decide (a linha de espécie abre o lock). Isso mata as
        // alucinações da versão anterior, onde blocklist ganhava sempre: pessoa real com
        // "fur-trimmed coat", "hair or fur or skin", "rabo de cavalo" ou "estampa de leão" caía
        // como ANIMAL e as âncoras de quadrúpede empurravam o difusor a desenhar um bicho.
        $subject = self::subjectKind($c, $bible);
        $isHuman = $subject === 'human';
        $isAnimal = $subject === 'animal';
        $who = trim((string) $c->name) !== '' ? ' named "'.trim((string) $c->name).'"' : '';
        // "if it is female keep it female" em vez de "keep the identical SEX / never change the sex":
        // mesma instrução, sem a palavra isolada que os filtros de conteúdo pontuam como risco (ver a
        // nota de anatomia neutra abaixo — foi o conjunto desses termos que derrubou o model sheet).
        $identity = 'The EXACT SAME character as in the reference image'.$who.' — keep the identical species, '
            .'face, body, proportions, colors and markings; if the reference shows a female keep it '
            .'female, if it shows a male keep it male; never redesign the character. '
            .'Keep the natural body type and posture from the reference'
            .($isHuman
                ? ' — this subject is a HUMAN BEING: keep it a human being with human anatomy, NEVER turn it into an animal, creature or mascot. '
                : ($isAnimal
                    ? ' — this subject is an ANIMAL: keep the exact same species and anatomy from the reference, never humanize or redesign it. '
                    : ' (e.g. if it is a human/humanoid keep it human/humanoid; if it is a four-legged animal keep it four-legged). '));
        if ($idSrc !== '') {
            $identity .= 'Locked identity: '.trim((string) preg_replace('/\s*[\r\n]+\s*/', '; ', $idSrc)).'. ';
        }
        // ANATOMIA NEUTRA — em linguagem POSITIVA. A frase anterior ("do NOT depict any genitalia…")
        // nomeava os termos e com isso ACIONAVA o filtro de conteúdo dos provedores: a MiniMax barrava
        // com "new_sensitive" (2026-07-17) e o KIE passou a devolver "flagged as sensitive" / "violated
        // Google's Generative AI Prohibited Use policy" em TODO shot (2026-07-21) — derrubando o model
        // sheet inteiro. Como o principal recusava, caía no fallback MiniMax subject_reference, que em
        // personagem não-humano ignora a referência e devolve SUCESSO com a imagem ERRADA: a folha da
        // Mel (dachshund) veio com 4 de 6 células mostrando HUMANOS.
        // A intenção continua (a vista de baixo fazia a dachshund FÊMEA "virar macho"), mas descrevendo
        // o que se QUER — que também é mais confiável do que confiar que o modelo ignore a palavra
        // proibida que você acabou de escrever.
        $identity .= 'Clean professional reference shot: keep the belly and underside completely smooth, featureless and toy-like. ';
        $photo = $isPhoto
            ? 'Real photograph of a real-life subject with natural realistic '
                .($isHuman ? 'skin and hair detail' : ($isAnimal ? 'textures and fur/hair detail' : 'surface and hair detail')).' — NOT a '
                .'3D render, NOT CGI, NOT a cartoon, NOT an illustration, NOT a toy figurine. '
            : '';
        // Âncoras anatômicas por tipo de sujeito. IMPORTANTE: as âncoras de bicho (tail/hindquarters/
        // muzzle/paw pads) SÓ entram com animal EXPLÍCITO — sujeito desconhecido usa âncoras NEUTRAS,
        // senão uma pessoa real sem classificação vira animal na prancha (bug 2026-07-13).
        $rear = $isHuman ? 'the BACK and shoulders are' : ($isAnimal ? 'the HINDQUARTERS and tail are' : 'the BACK of the subject is');
        $rearFace = $isAnimal ? 'at most the tip of the muzzle past the cheek' : 'at most the edge of the cheek';
        $backAnat = $isHuman ? 'back of the head, back, legs and heels' : ($isAnimal ? 'back of the head, back, hindquarters and tail' : 'back of the head, back and lower body');
        $profileTrail = $isAnimal ? 'the tail at the opposite edge' : 'the back of the head at the opposite edge';
        $topAnat = $isHuman ? 'the top of the head, hair, shoulders and back' : ($isAnimal ? 'the top of the head, ears, back and tail' : 'the top of the head, shoulders and back');
        $bottomAnat = $isHuman
            ? 'the SOLES of the feet/shoes pressed against the glass are NEAREST the camera, the legs and body foreshortened above them, the head at the far end'
            : ($isAnimal
                ? 'the paw pads pressed against the glass are NEAREST the camera, the belly outline behind them, the head foreshortened at the far end; smooth neutral underbelly'
                : 'the UNDERSIDE pressed against the glass is NEAREST the camera, the body foreshortened above it, the head at the far end');
        $headProfileAnat = $isAnimal ? 'muzzle and ear' : 'nose and ear';
        $bg = 'The background is ONLY a plain seamless flat light-gray studio backdrop — never a room, '
            .'furniture, books, shelves, floorboards, landscape or any scenery. Soft even lighting, no cast '
            .'shadows, sharp focus. The character keeps the EXACT same body with NO wings, NO horns, NO extra '
            .'limbs, tails or appendages, and no accessories that are not part of its described design. A SINGLE '
            .'subject only, NO text, NO labels, NO watermark, NO extra characters, NO props, no border.';
        // Fundo PRIMEIRO (peso máximo no difusor — os shots ainda vinham com cenário quando a
        // instrução de fundo ficava só no fim) + ajuste do usuário (tweak) por último.
        $tw = trim($tweak) !== '' ? ' Requested adjustments: '.trim($tweak).'.' : '';
        $shot = fn (string $view) => $identity.$photo.'Studio catalog reference shot on a plain seamless light-gray studio '
            .'background, no scenery: '.$view.' '.$bg.$tw;

        // Reforço p/ vistas TRASEIRAS: a instrução de identidade ("mesmo rosto") briga com "de
        // costas" e o modelo insistia em mostrar a FRENTE (rosto) + inventar cenário. Deixa
        // explícito que a pessoa está de costas e o rosto NÃO existe nessa vista.
        $backView = ' The person is FACING AWAY from the camera: show ONLY the back of the head and '
            .'the hair/clothing seen from behind. The face, eyes, nose and mouth are COMPLETELY hidden '
            .'and MUST NOT appear anywhere. This is a REAR view — do NOT show the front of the body.';

        $g = [];

        // 1) TURNAROUND — 10 vistas (as direções são explícitas; a base ancora a identidade).
        // 'identity' exposto à parte (SEM o enquadramento por shot) — o engine usa isso sozinho
        // no caminho de contingência via órbita de câmera (AnglesSheet/GenerateOrbitAngles),
        // quando o KIE está indisponível e cada shot isolado de subject_reference não segue
        // instrução de ângulo grande de forma confiável.
        $g['angles'] = ['kind' => 'angles', 'cols' => 4, 'aspect' => '3:4', 'identity' => $identity,
            'title' => $NAME.' — TURNAROUND',
            'subtitle' => $L('Volta 360° · referência de personagem', '360° turnaround · character reference'),
            // Cada vista descreve a POSIÇÃO DA CÂMERA + âncoras anatômicas (o que fica perto da
            // câmera, se o rosto aparece) — só "girado 45°" o modelo de identidade ignorava e
            // repetia a pose frontal da referência (45/315 iguais, traseiras trocadas, base errada).
            'shots' => [
                ['label' => $L('FRENTE (0°)', 'FRONT (0°)'), 'prompt' => $shot('Full-body shot seen from the FRONT (0°), facing the camera, standing in a neutral relaxed pose, the entire body from head to toe visible with margin.')],
                ['label' => $L('3/4 ESQUERDA (45°)', '3/4 LEFT (45°)'), 'prompt' => $shot('Three-quarter FRONT-LEFT view: the camera is at the subject\'s front-left; its LEFT shoulder is NEAREST the camera; both eyes visible; the head and chest point toward the LEFT edge of the image. Full body visible.')],
                ['label' => $L('PERFIL ESQUERDO (90°)', 'LEFT PROFILE (90°)'), 'prompt' => $shot('Full-body LEFT-SIDE profile (90°): perfect side view, nose pointing at the LEFT edge, '.$profileTrail.'; only ONE eye visible.')],
                ['label' => $L('3/4 TRASEIRA ESQ. (135°)', 'REAR 3/4 LEFT (135°)'), 'prompt' => $shot('REAR three-quarter view from the subject\'s LEFT-BACK: the camera is BEHIND and to its left; '.$rear.' NEAREST the camera; the head is FARTHEST, pointing away toward the upper-left; the face is NOT visible ('.$rearFace.').'.$backView)],
                ['label' => $L('COSTAS (180°)', 'BACK (180°)'), 'prompt' => $shot('Full-body shot seen directly from BEHIND (180°): '.$backAnat.' toward the camera; the face is NOT visible.'.$backView)],
                ['label' => $L('3/4 TRASEIRA DIR. (225°)', 'REAR 3/4 RIGHT (225°)'), 'prompt' => $shot('REAR three-quarter view from the subject\'s RIGHT-BACK — the exact MIRROR of the rear-left view: the camera is BEHIND and to its right; '.$rear.' NEAREST the camera; the head is FARTHEST, pointing away toward the upper-right; the face is NOT visible.'.$backView)],
                ['label' => $L('PERFIL DIREITO (270°)', 'RIGHT PROFILE (270°)'), 'prompt' => $shot('Full-body RIGHT-SIDE profile (270°): perfect side view, nose pointing at the RIGHT edge, '.$profileTrail.'; only ONE eye visible — the exact mirror of the left profile.')],
                ['label' => $L('3/4 DIREITA (315°)', '3/4 RIGHT (315°)'), 'prompt' => $shot('Three-quarter FRONT-RIGHT view — the exact MIRROR of the front-left view: the camera is at the subject\'s front-right; its RIGHT shoulder is NEAREST the camera; both eyes visible; the head and chest point toward the RIGHT edge of the image. Full body visible.')],
                ['label' => $L('TOPO', 'TOP'), 'prompt' => $shot('Seen straight DOWN from directly ABOVE (bird\'s-eye, 90° overhead): only '.$topAnat.' are visible, body foreshortened; the subject stands on the floor below the camera.')],
                ['label' => $L('BASE', 'BOTTOM'), 'prompt' => $shot('Seen from DIRECTLY BELOW through a transparent glass floor the subject is standing on (worm\'s-eye, 90° from underneath): '.$bottomAnat.'.')],
            ]];

        // 2) CABEÇA & EXPRESSÕES — 2 detalhes de cabeça + até 4 expressões (da bíblia).
        $exprs = array_values(array_filter(array_slice($bible['expressions'] ?? [], 0, 4), fn ($e) => trim((string) $e) !== ''));
        if ($exprs === []) {
            $exprs = $pt ? ['Neutra', 'Sorriso', 'Surpresa', 'Curiosa'] : ['Neutral', 'Smile', 'Surprised', 'Curious'];
        }
        $headShots = [
            ['label' => $L('CABEÇA FRENTE', 'HEAD FRONT'), 'prompt' => $shot('Head-and-neck close-up from the FRONT, the face shown in sharp detail; no body below the neck.')],
            ['label' => $L('CABEÇA PERFIL', 'HEAD PROFILE'), 'prompt' => $shot('Head-and-neck close-up in a full side PROFILE, showing '.$headProfileAnat.'; no body below the neck.')],
        ];
        foreach ($exprs as $ex) {
            // Rótulo CURTO (a bíblia agora descreve expressões em frases — o texto completo vai no
            // PROMPT; a folha mostra só as primeiras palavras).
            $headShots[] = ['label' => self::shortLabel((string) $ex), 'prompt' => $shot('Head-only close-up face portrait, tightly cropped at the neck with no body, showing a "'.$ex.'" expression; only the facial expression changes.')];
        }
        $g['head'] = ['kind' => 'head', 'cols' => 3, 'aspect' => '1:1',
            'title' => $NAME.$L(' — CABEÇA & EXPRESSÕES', ' — HEAD & EXPRESSIONS'),
            'subtitle' => $L('Detalhe da cabeça e expressões faciais', 'Head detail and facial expressions'),
            'shots' => $headShots];

        // 3) POSES — corpo inteiro, incluindo poses de AÇÃO e ATITUDE (padrão de referência
        //    Burro/Giraffo: não só poses neutras — dançando/confiante/apontando dão vida à folha).
        $g['poses'] = ['kind' => 'poses', 'cols' => 4, 'aspect' => '3:4',
            'title' => $NAME.' — POSES',
            'subtitle' => $L('Poses de corpo inteiro · ação e atitude', 'Full-body poses · action & attitude'),
            'shots' => [
                ['label' => $L('EM PÉ', 'STANDING'), 'prompt' => $shot('Full-body, standing idle in a neutral relaxed pose.')],
                ['label' => $L('SENTADA', 'SITTING'), 'prompt' => $shot('Full-body, sitting calmly.')],
                ['label' => $L('DEITADA', 'LYING DOWN'), 'prompt' => $shot('Full-body, lying down relaxed'.($isHuman ? ' on the side, propped on one elbow' : ($isAnimal ? ' in a sphinx pose' : ' on the side')).', the belly not exposed.')],
                ['label' => $L('ANDANDO', 'WALKING'), 'prompt' => $shot('Full-body, walking mid-stride, side view.')],
                ['label' => $L('CORRENDO', 'RUNNING'), 'prompt' => $shot('Full-body, running at full speed, dynamic action pose, side view.')],
                ['label' => $L('PULANDO', 'JUMPING'), 'prompt' => $shot('Full-body, jumping in mid-air, dynamic action pose.')],
                ['label' => $L('BRINCANDO', 'PLAYFUL'), 'prompt' => $shot('Full-body, in a lively playful alert pose.')],
                ['label' => $L('CONFIANTE', 'CONFIDENT'), 'prompt' => $shot('Full-body, in a proud confident hero stance, chest up, looking slightly upward.')],
                ['label' => $L('CURIOSA', 'CURIOUS'), 'prompt' => $shot('Full-body, in a curious investigating pose, head tilted, leaning forward attentively.')],
                ['label' => $L('DORMINDO', 'SLEEPING'), 'prompt' => $shot('Full-body, sleeping peacefully'.($isAnimal ? ', curled up' : ', lying on the side').', eyes closed.')],
            ]];

        // 4) ACESSÓRIOS & VESTIMENTA — padrão de referência (Burro/Giraffo): cada ITEM em flat-lay
        //    separado (foto de produto, SEM o personagem) + o personagem VESTIDO (frente/costas) com os
        //    itens NOMEADOS explicitamente (dizer só "signature outfit" gerava o personagem SEM a roupa).
        //    Personagem sem NENHUM item (bíblia vazia) → a folha é PULADA (não faz sentido e saía "pelado").
        $acc = array_values(array_filter(
            array_map(fn ($a) => trim((string) $a), array_slice($bible['accessories'] ?? [], 0, 4)),
            fn ($a) => $a !== '',
        ));
        if ($acc !== []) {
            $accList = implode('; ', $acc);
            $accShots = [];
            foreach ($acc as $item) {
                // Flat-lay do ITEM sozinho — foto de produto (catálogo), nunca o personagem junto.
                $accShots[] = ['label' => self::shortLabel($item), 'product' => true,
                    'prompt' => 'Studio product photograph, catalog flat-lay, on a plain seamless light-gray '
                        .'studio background: ONLY the item "'.$item.'" belonging to this character, shown alone, '
                        .'centered, sharp focus. '.$photo.'Do NOT show the character or any body part — only the '
                        .'item itself. NO text, NO labels, NO watermark, no border.'.$tw];
            }
            $accShots[] = ['label' => $L('VESTIDA — FRENTE', 'DRESSED — FRONT'),
                'prompt' => $shot('Full-body FRONT view, WEARING ALL of these exact signature items, every one '
                    .'clearly visible on the body: '.$accList.'. Do not omit any of the items.')];
            $accShots[] = ['label' => $L('VESTIDA — COSTAS', 'DRESSED — BACK'),
                'prompt' => $shot('Full-body BACK view, WEARING ALL of these exact signature items, every one '
                    .'clearly visible on the body: '.$accList.'. Do not omit any of the items.')];
            $g['accessories'] = ['kind' => 'accessories', 'cols' => 3, 'aspect' => '3:4',
                'title' => $NAME.$L(' — ACESSÓRIOS & VESTIMENTA', ' — ACCESSORIES & OUTFIT'),
                'subtitle' => $L('Peças separadas (catálogo) + personagem vestido', 'Item flat-lays (catalog) + dressed character'),
                'shots' => $accShots];
        }

        // 5) PALETA & TEXTURAS — swatches da bíblia (hex + rótulo, sem IA) + close-ups de textura
        //    (padrão da referência Giraffo: "PADRÃO DE PELOS" + "MATERIAIS E TEXTURAS").
        $pal = [];
        foreach (($bible['palette'] ?? []) as $sw) {
            $hex = trim((string) ($sw['hex'] ?? ''));
            if ($hex === '') {
                continue;
            }
            $pal[] = ['hex' => $hex, 'label' => trim((string) ($sw['label'] ?? ''))];
        }
        $palShots = [
            ['label' => $L('PELAGEM (TEXTURA)', 'FUR/SKIN TEXTURE'),
                'prompt' => $shot('Extreme close-up macro of the character\'s '.($isHuman ? 'skin/hair/clothing' : ($isAnimal ? 'fur/skin/coat' : 'skin/hair/surface')).' surface texture and '
                    .'color pattern, filling the frame; no face, no eyes, only the coat texture.')],
        ];
        if ($acc !== []) {
            $palShots[] = ['label' => $L('MATERIAIS', 'MATERIALS'),
                'prompt' => $shot('Extreme close-up macro of the surface material and texture of the character\'s '
                    .'signature item "'.$acc[0].'", filling the frame; only the material texture.')];
        }
        $g['palette'] = ['kind' => 'palette', 'cols' => 4, 'aspect' => '1:1', 'shots' => $palShots,
            'palette' => array_slice($pal, 0, 12),
            'title' => $NAME.$L(' — PALETA & TEXTURAS', ' — PALETTE & TEXTURES'),
            'subtitle' => $L('Cores características · materiais e texturas', 'Signature colors · materials and textures')];

        // OPT-IN — PLANOS DE CÂMERA: enquadramentos cinematográficos do mesmo personagem. Fora do bundle
        // padrão (custo extra); só sai quando pedido explicitamente ($only='shots').
        $g['shots'] = ['kind' => 'shots', 'cols' => 3, 'aspect' => '16:9',
            'title' => $NAME.$L(' — PLANOS DE CÂMERA', ' — CAMERA SHOTS'),
            'subtitle' => $L('Enquadramentos cinematográficos', 'Cinematic framings'),
            'shots' => [
                ['label' => $L('PLANO MÉDIO', 'MEDIUM SHOT'), 'prompt' => $shot('Medium shot, the character from the waist up, looking at camera.')],
                ['label' => $L('PLANO AMERICANO', 'AMERICAN SHOT'), 'prompt' => $shot('American shot, the character from mid-thigh up.')],
                ['label' => $L('CLOSE-UP', 'CLOSE-UP'), 'prompt' => $shot('Tight close-up on the face, showing expression.')],
                ['label' => $L('PERFIL', 'PROFILE'), 'prompt' => $shot('Full side profile shot of the character.')],
                ['label' => $L('CONTRA-PLONGÉE', 'LOW ANGLE'), 'prompt' => $shot('Low-angle shot, camera looking up, making the character look powerful.')],
                ['label' => $L('PLONGÉE', 'HIGH ANGLE'), 'prompt' => $shot('High-angle shot, camera looking down at the character.')],
                ['label' => $L('HERO SHOT', 'HERO SHOT'), 'prompt' => $shot('Hero shot, the character centered in a confident epic pose, low dramatic angle.')],
                ['label' => $L('PLANO DETALHE', 'DETAIL SHOT'), 'prompt' => $shot('Extreme close-up detail on a signature feature of the character.')],
                ['label' => $L('PLANO ABERTO', 'WIDE SHOT'), 'prompt' => $shot('Wide shot, the full character small in the frame, cinematic composition.')],
            ]];

        // OPT-IN — TESTES DE ILUMINAÇÃO: o MESMO personagem sob 4 setups de luz (padrão do character
        // sheet de estúdio). Ajuda a identidade a se segurar quando o filme joga luzes diferentes em
        // cada cena. Aqui a luz VARIA de propósito, então NÃO reusa $shot() (que fixa "soft even
        // lighting, no cast shadows"); um builder próprio mantém identidade+fundo, só troca a luz.
        $lightBg = 'The background is ONLY a plain seamless neutral studio backdrop — never a room, '
            .'furniture or scenery. A SINGLE subject only, medium close-up (head and shoulders), sharp focus, '
            .'NO text, NO labels, NO watermark, NO extra characters, no border.';
        $lightShot = fn (string $light) => $identity.$photo.'Studio portrait of the character, head and shoulders, '
            .'same neutral pose and expression, lit by: '.$light.' '.$lightBg.$tw;
        $g['lighting'] = ['kind' => 'lighting', 'cols' => 4, 'aspect' => '1:1',
            'title' => $NAME.$L(' — TESTES DE ILUMINAÇÃO', ' — LIGHTING TESTS'),
            'subtitle' => $L('A mesma identidade sob luzes diferentes', 'The same identity under different lighting'),
            'shots' => [
                ['label' => $L('LUZ DE DIA (SUAVE)', 'SOFT DAYLIGHT'), 'prompt' => $lightShot('soft natural daylight, gentle and even, cool-neutral white balance.')],
                ['label' => $L('TUNGSTÊNIO (QUENTE)', 'WARM TUNGSTEN'), 'prompt' => $lightShot('warm tungsten interior light, amber tones, cozy indoor mood.')],
                ['label' => $L('NOITE AZUL (FRIA)', 'COOL BLUE NIGHT'), 'prompt' => $lightShot('cool blue nighttime lighting, low-key, moody deep shadows.')],
                ['label' => $L('LUZ LATERAL (DRAMÁTICA)', 'DRAMATIC SIDE LIGHT'), 'prompt' => $lightShot('dramatic hard side light from one side, strong chiaroscuro, high contrast.')],
            ]];

        if ($only !== null) {
            return isset($g[$only]) ? [$only => $g[$only]] : [];
        }
        unset($g['shots']);    // bundle padrão NÃO inclui os planos de câmera (opt-in)
        unset($g['lighting']); // nem os testes de iluminação (opt-in)

        return $g;
    }

    /**
     * Tipo do sujeito: 'human' | 'animal' | '' (desconhecido → âncoras neutras).
     * 1º a classificação EXPLÍCITA da vision (bible.subject — engine /v1/characterbible);
     * fallback = regex sobre lock/descrição/nome com precedência POSICIONAL: o termo que aparece
     * PRIMEIRO decide (a linha de espécie abre o lock). "woman with a fur-trimmed coat" → human
     * ('woman' vem antes de 'fur'); "female dog" → animal (sexo não conta como termo humano).
     * 'object' da vision cai em '' (neutro) — âncoras de bicho nunca por padrão.
     */
    public static function subjectKind(Character $c, ?array $bible): string
    {
        $s = mb_strtolower(trim((string) ($bible['subject'] ?? (is_array($c->bible) ? ($c->bible['subject'] ?? '') : ''))));
        if ($s === 'human' || $s === 'animal') {
            return $s;
        }
        if ($s === 'object') {
            return '';
        }
        $hay = mb_strtolower(trim((string) $c->lock).' '.trim((string) $c->description).' '.(string) $c->name);
        $humanRe = '/\b(human|person|man|woman|boy|girl|guy|lady|pessoa|humano|humana|homem|mulher|menino|menina|garoto|garota|rapaz|moça|senhor|senhora|criança|adolescente|jovem)\b/u';
        $animalRe = '/\b(dog|cat|animal|creature|fox|bear|lion|bird|dragon|rabbit|turtle|giraffe|donkey|horse|monkey|penguin|cachorro|gato|bicho|raposa|urso|leão|leao|pássaro|passaro|dragão|dragao|coelho|tartaruga|girafa|burro|cavalo|macaco|pinguim|four-legged|quadruped|paws|tail|fur)\b/u';
        $hp = preg_match($humanRe, $hay, $hm, PREG_OFFSET_CAPTURE) ? $hm[0][1] : -1;
        $ap = preg_match($animalRe, $hay, $am, PREG_OFFSET_CAPTURE) ? $am[0][1] : -1;
        if ($hp < 0 && $ap < 0) {
            return '';
        }
        if ($hp >= 0 && ($ap < 0 || $hp <= $ap)) {
            return 'human';
        }

        return 'animal';
    }

    /** Total de SHOTS de IA (p/ reservar cota). Paleta não gera imagem, não conta. */
    public function shotCount(array $groups): int
    {
        return array_sum(array_map(fn ($grp) => count($grp['shots'] ?? []), $groups));
    }

    /**
     * Gera os shots de UM grupo (i2i concorrente ancorado na base) e COMPÕE a folha no ffmpeg-service.
     * Retorna ['url'=>?string, 'failed'=>int] — url null se a composição falhar; failed = shots sem imagem.
     *
     * @return array{url: ?string, failed: int}
     */
    public function buildSheet(Character $c, array $group): array
    {
        $shots = $group['shots'] ?? [];
        $urls = [];
        if ($shots) {
            // A prancha de ÂNGULOS (turnaround) precisa de contexto do GRUPO inteiro — não dá pra
            // resolver com 1 chamada por shot (ver AnglesSheet/orbit.go no engine). As outras
            // pranchas seguem o caminho de sempre.
            $urls = ($group['kind'] ?? '') === 'angles'
                ? $this->generateAnglesShots($c, $group, $shots, (string) ($group['aspect'] ?? '3:4'))
                : $this->generateShots($c, $shots, (string) ($group['aspect'] ?? '3:4'));
        }

        $failed = 0;
        $composeShots = [];
        foreach ($shots as $i => $sh) {
            $u = $urls[$i] ?? '';
            if ($u === '') {
                $failed++;
            }
            $composeShots[] = ['label' => (string) ($sh['label'] ?? ''), 'url' => $u];
        }

        // NENHUM shot vingou → não compõe. A folha sairia com o template certo e TODAS as células
        // em branco, e essa folha vazia era gravada como se fosse resultado: o personagem ficava
        // "pronto" com um model sheet que não serve pra nada, e o usuário só descobria olhando.
        // Falha tem que chegar como falha. Parcial ainda compõe — aí a folha tem valor e as
        // lacunas são visíveis.
        if ($shots && $failed === count($shots)) {
            Log::warning('ModelSheet: todos os shots falharam — folha não composta', [
                'character' => $c->id,
                'kind' => (string) ($group['kind'] ?? ''),
                'shots' => count($shots),
            ]);

            return ['url' => '', 'failed' => $failed];
        }

        $url = $this->compose([
            'title' => (string) ($group['title'] ?? ''),
            'subtitle' => (string) ($group['subtitle'] ?? ''),
            'cols' => (int) ($group['cols'] ?? 4),
            'shots' => $composeShots,
            'palette' => $group['palette'] ?? [],
        ]);

        return ['url' => $url, 'failed' => $failed];
    }

    /** Spec do MOTOR (schema-driven, `capabilities.<provider>` do catálogo) pros shots do
     *  model sheet — style
     *  do provedor (REALISTIC/FICTION conforme o estilo) + negative_prompt reforçado (sexo, anti-
     *  cenário, anti-animalização). Extraído de generateShots() pra reusar em AnglesSheet (a
     *  prancha de ângulos manda a spec 1x, não por shot — o backend só varia o prompt). */
    private function motorSpecFor(Character $c, GenModel $gm): array
    {
        $kie = $gm->capabilities[$gm->provider] ?? $gm->capabilities['kie'] ?? [];
        if (! is_array($kie)) {
            return [];
        }
        $kie['extra'] = array_merge($kie['extra'] ?? [], [
            'style' => in_array($c->style, self::PHOTO_STYLES, true) ? 'REALISTIC' : 'FICTION',
        ]);
        $subj = self::subjectKind($c, is_array($c->bible) ? $c->bible : null);
        $noun = $subj === 'human' ? 'person' : ($subj === 'animal' ? 'animal' : 'subject');
        $idText = mb_strtolower($c->lock.' '.$c->description);
        $neg = (string) ($kie['extra']['negative_prompt'] ?? '');
        $neg = trim($neg.', background scenery, room, indoor scene, outdoor scene, furniture, decorations, party flags, bunting, string lights, kitchen, cluttered background, people in the background', ', ');
        if ($subj === 'human') {
            $neg = trim($neg.', animal, animal body, anthropomorphic mascot', ', ');
        }
        if (preg_match('/\b(female|fêmea|femea|mulher|woman|girl)\b/u', $idText)) {
            $neg = trim($neg.', male '.$noun.', male anatomy, male body', ', ');
        } elseif (preg_match('/\b(male|macho|homem|man|boy)\b/u', $idText)) {
            $neg = trim($neg.', female '.$noun.', female anatomy', ', ');
        }
        if ($neg !== '') {
            $kie['extra']['negative_prompt'] = $neg;
        }

        return $kie;
    }

    /**
     * Prancha de ÂNGULOS (turnaround): 1 chamada ao engine (/v1/anglessheet) pro GRUPO inteiro,
     * não 1 por shot — o caminho de contingência (câmera orbitando via vídeo, ver orbit.go) só
     * faz sentido gerando UM clipe pra todos os ângulos de uma vez. O engine decide sozinho se
     * usa o caminho normal (KIE, mesma qualidade de sempre) ou a órbita (só se o KIE cair).
     *
     * @return array<int,string>
     */
    private function generateAnglesShots(Character $c, array $group, array $shots, string $aspect): array
    {
        $base = rtrim((string) config('services.engine.url'), '/');
        $token = (string) config('services.engine.admin_token');
        $gm = GenModel::resolveSelectable(self::SHEET_I2I_MODEL, 'image', $c->tenant?->plan);
        $payload = [
            'identity' => (string) ($group['identity'] ?? ''),
            'baseImageUrl' => (string) $c->base_url,
            'aspect' => $aspect,
            'style' => $c->style,
            'shots' => array_map(fn ($sh) => ['label' => (string) ($sh['label'] ?? ''), 'prompt' => (string) ($sh['prompt'] ?? '')], $shots),
            // Mesma razão do /v1/image: em sujeito não-humano o fallback subject_reference troca o
            // personagem em vez de falhar. Aqui vale para TOPO e BASE, que a órbita não cobre.
            'nonHumanSubject' => self::subjectKind($c, is_array($c->bible) ? $c->bible : null) !== 'human',
        ];
        if ($gm) {
            $payload['provider'] = $gm->provider;
            $payload['model'] = $gm->provider_model_id;
            $payload[$gm->provider] = $this->motorSpecFor($c, $gm) ?: new \stdClass;
        }
        try {
            $r = Http::baseUrl($base)->withHeaders(['X-Admin-Token' => $token])
                ->acceptJson()->timeout(600) // órbita = 1 clipe de vídeo (~1-2min) + extração; folga real
                ->post('/v1/anglessheet', $payload);
            if ($r->successful()) {
                $urls = (array) $r->json('urls');
                if ($r->json('mode') === 'orbit') {
                    Log::info('ModelSheet: prancha de ângulos via ÓRBITA (KIE indisponível)', ['char' => $c->id]);
                }

                return array_map('strval', $urls);
            }
            Log::warning('ModelSheet: anglessheet falhou', ['char' => $c->id, 'status' => $r->status(), 'body' => mb_substr($r->body(), 0, 300)]);
        } catch (\Throwable $e) {
            Log::warning('ModelSheet: anglessheet exception', ['char' => $c->id, 'error' => $e->getMessage()]);
        }

        return []; // todos os shots do grupo viram célula em branco (estornados) — sinal honesto de falha total
    }

    /**
     * Gera N shots i2i em LOTES concorrentes (Http::pool) contra o engine /v1/image, ancorados na base.
     * Batch de 4 pra não estourar rate-limit do provedor. Retorna as URLs na ordem dos shots ('' = falhou).
     *
     * @return array<int,string>
     */
    private function generateShots(Character $c, array $shots, string $aspect): array
    {
        $base = rtrim((string) config('services.engine.url'), '/');
        $token = (string) config('services.engine.admin_token');
        // Modelo ESPECIALISTA de identidade (ideogram/character) pros shots COM personagem; shots de
        // PRODUTO (flat-lay) e indisponibilidade caem no i2i default do engine (nano-banana-2).
        $gm = GenModel::resolveSelectable(self::SHEET_I2I_MODEL, 'image', $c->tenant?->plan);
        $nonHuman = self::subjectKind($c, is_array($c->bible) ? $c->bible : null) !== 'human';
        $payloadFor = function (array $sh) use ($c, $aspect, $gm, $nonHuman): array {
            $payload = [
                'prompt' => (string) $sh['prompt'],
                'aspect' => $aspect,
                'style' => $c->style,
                'imageUrls' => [$c->base_url], // âncora i2i — a identidade vem da base
                // A referência não é apenas inspiração: é a fonte canônica da identidade. Sem
                // este sinal o motor recebe a foto, mas pode reinterpretar o sujeito pelo prompt
                // (por exemplo, uma pessoa real virar um animal na prancha).
                'anchorIdentity' => true,
                // Sujeito não-humano → o engine PULA o fallback subject_reference da reserva
                // pré-paga. Aquele caminho é documentado como "apenas rosto humano" e, num bicho,
                // ignora a referência e devolve SUCESSO com outro personagem: foi o que encheu a
                // folha da Mel (dachshund) de HUMANOS quando o provedor principal recusou. Célula
                // vazia é resultado honesto — e agora nem compõe a folha (ver buildSheet).
                'nonHumanSubject' => $nonHuman,
            ];
            if ($gm && empty($sh['product'])) {
                $payload['provider'] = $gm->provider;       // kie → o engine roteia
                $payload['model'] = $gm->provider_model_id; // ideogram/character
                $payload[$gm->provider] = $this->motorSpecFor($c, $gm) ?: new \stdClass;
            }

            return $payload;
        };
        $out = [];
        $byIdx = []; // idx → shot (p/ o retry saber qual prompt refazer)
        $idx = 0;
        foreach (array_chunk($shots, 4, true) as $batch) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn ($sh) => $pool->as((string) ($sh['_i']))
                    ->baseUrl($base)->withHeaders(['X-Admin-Token' => $token])
                    ->acceptJson()->timeout(200)
                    ->post('/v1/image', $payloadFor($sh)),
                $this->withKeys($batch, $idx),
            ));
            foreach ($batch as $k => $sh) {
                $r = $responses[(string) $idx] ?? null;
                $out[$idx] = ($r && method_exists($r, 'successful') && $r->successful()) ? (string) ($r->json('url') ?? '') : '';
                $byIdx[$idx] = $sh;
                $idx++;
            }
        }
        // 🔁 RETRY dos shots que voltaram VAZIOS: o lote de 4 concorrentes às vezes estrangula o
        // provedor (rate-limit/timeout) e alguns shots vêm sem URL — era o que deixava, ex., os
        // perfis direitos em branco. Refaz cada vazio UMA vez, SEQUENCIAL (sem pool → sem reincidir
        // no mesmo estrangulamento). Só depois deste passe é que um shot conta como falho.
        foreach (array_keys(array_filter($out, fn ($u) => $u === '')) as $i) {
            $sh = $byIdx[$i] ?? null;
            if (! $sh) {
                continue;
            }
            try {
                $r = Http::baseUrl($base)->withHeaders(['X-Admin-Token' => $token])
                    ->acceptJson()->timeout(200)->post('/v1/image', $payloadFor($sh));
                if ($r->successful() && ($u = (string) ($r->json('url') ?? '')) !== '') {
                    $out[$i] = $u;
                }
            } catch (\Throwable $e) {
                // segue vazio (estornado a jusante)
            }
            if ($out[$i] === '') {
                Log::warning('ModelSheet: shot sem URL (após retry)', ['char' => $c->id, 'label' => $sh['label'] ?? '']);
            }
        }
        ksort($out);

        return array_values($out);
    }

    /** Anexa a chave de pool `_i` (índice global) a cada shot do lote, pra casar resposta↔shot. */
    private function withKeys(array $batch, int $start): array
    {
        $i = $start;
        $keyed = [];
        foreach ($batch as $sh) {
            $sh['_i'] = (string) $i;
            $keyed[] = $sh;
            $i++;
        }

        return $keyed;
    }

    /** Rótulo curto de folha: corta em LIMITE DE PALAVRA (truncar no meio saía "…NOME MEL GRA")
     *  e sobe pra caixa alta. A bíblia (Claude) descreve itens/expressões em frases — o texto
     *  completo alimenta o PROMPT; o rótulo impresso fica com as primeiras palavras. */
    private static function shortLabel(string $txt, int $max = 26): string
    {
        $txt = trim($txt);
        if (mb_strlen($txt) > $max) {
            $cut = mb_substr($txt, 0, $max);
            $sp = mb_strrpos($cut, ' ');
            $txt = ($sp !== false && $sp > 10 ? mb_substr($cut, 0, $sp) : $cut).'…';
        }

        return mb_strtoupper($txt);
    }

    /**
     * Chama o ffmpeg-service /compose-sheet (template fixo). Retorna a URL da folha composta ou null.
     * Público: o upload manual de N shots (panel-upload com files[]) também monta a folha por aqui.
     */
    public function compose(array $spec): ?string
    {
        try {
            $res = Http::baseUrl(rtrim((string) config('services.ffmpeg.url'), '/'))
                ->withHeaders(['X-Service-Token' => (string) config('services.ffmpeg.token')])
                ->acceptJson()->timeout(180)
                ->post('/compose-sheet', $spec);
            if ($res->successful() && ($u = trim((string) $res->json('url'))) !== '') {
                return $u;
            }
            Log::warning('ModelSheet: compose falhou', ['status' => $res->status(), 'body' => mb_substr($res->body(), 0, 200)]);
        } catch (\Throwable $e) {
            Log::warning('ModelSheet: compose exception', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Monta a folha a partir de URLs já hospedadas (upload externo / mmx-local). Usa o mesmo
     * template do buildSheet — title/subtitle/cols/rótulos vêm do groups() do kind.
     */
    public function composeFromUrls(Character $c, string $kind, array $urls, string $lang = 'pt-BR'): ?string
    {
        $groups = $this->groups($c, is_array($c->bible) ? $c->bible : null, $lang, $kind);
        $group = $groups[$kind] ?? null;
        $shots = is_array($group['shots'] ?? null) ? $group['shots'] : [];
        $composeShots = [];
        $n = max(count($shots), count($urls));
        for ($i = 0; $i < $n; $i++) {
            $composeShots[] = [
                'label' => (string) ($shots[$i]['label'] ?? ('Shot '.($i + 1))),
                'url' => (string) ($urls[$i] ?? ''),
            ];
        }
        if ($composeShots === []) {
            return null;
        }

        return $this->compose([
            'title' => (string) ($group['title'] ?? ($c->name.' — '.strtoupper($kind))),
            'subtitle' => (string) ($group['subtitle'] ?? ''),
            'cols' => (int) ($group['cols'] ?? 4),
            'shots' => $composeShots,
            'palette' => $group['palette'] ?? [],
        ]);
    }

    /**
     * Ordena uploads de shots pelo nome do arquivo quando dá pra detectar o ângulo
     * (ex.: mel-frente-0.png, mel-perfil-esquerdo-90.png, mel-topo.png). Sem match →
     * mantém a ordem em que o cliente enviou.
     *
     * @param  array<int,UploadedFile>  $files
     * @return array<int,UploadedFile>
     */
    public function orderShotUploads(array $files, string $kind): array
    {
        if ($kind !== 'angles' && $kind !== 'turnaround') {
            return array_values($files);
        }
        // Ordem canônica do turnaround (mesma de groups() angles).
        $rank = [
            'frente' => 0, 'front' => 0, '0' => 0,
            '45' => 1, 'esquerda-45' => 1, 'left-45' => 1,
            '90' => 2, 'perfil-esquerdo' => 2, 'left-profile' => 2, 'perfil-esq' => 2,
            '135' => 3, 'traseira-esq' => 3, 'rear-left' => 3,
            '180' => 4, 'costas' => 4, 'back' => 4,
            '225' => 5, 'traseira-dir' => 5, 'rear-right' => 5,
            '270' => 6, 'perfil-direito' => 6, 'right-profile' => 6, 'perfil-dir' => 6,
            '315' => 7, 'direita-315' => 7, 'right-315' => 7,
            'topo' => 8, 'top' => 8, 'overhead' => 8,
            'base' => 9, 'bottom' => 9, 'underside' => 9, 'inferior' => 9,
        ];
        $scored = [];
        $any = false;
        foreach (array_values($files) as $i => $f) {
            $name = strtolower((string) $f->getClientOriginalName());
            $score = 1000 + $i; // fallback = ordem de envio
            foreach ($rank as $needle => $ord) {
                // Ângulos numéricos pedem boundary (evita "45" casar dentro de "145").
                $pat = ctype_digit((string) $needle)
                    ? '/(?:^|[^0-9])'.preg_quote((string) $needle, '/').'(?:[^0-9]|$)/'
                    : '/'.preg_quote((string) $needle, '/').'/';
                if (preg_match($pat, $name)) {
                    $score = $ord;
                    $any = true;
                    break;
                }
            }
            $scored[] = [$score, $i, $f];
        }
        if (! $any) {
            return array_values($files);
        }
        usort($scored, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        return array_map(fn ($t) => $t[2], $scored);
    }
}
