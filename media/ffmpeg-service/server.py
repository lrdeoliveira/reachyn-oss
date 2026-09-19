#!/usr/bin/env python3
"""Micro serviço HTTP para operações ffmpeg.

Escuta em 0.0.0.0:7788 (precisa ser alcançável pelo engine na rede docker),
MAS todo POST exige o header X-Service-Token == env FFMPEG_SERVICE_TOKEN
(comparação constant-time). GET /health fica livre. Sem o token → 401.
"""
import subprocess, json, os, time, hmac, re, ipaddress, socket, ssl, base64, threading
import http.client
from urllib.parse import urlparse
from http.server import HTTPServer, BaseHTTPRequestHandler
from PIL import Image, ImageDraw, ImageFont  # composição do model sheet (grid + rótulos + paleta)

MEDIA_BASE = "/vps_nexus/media"
PUBLIC_BASE = "https://media.example.com/media"

# ───────── Léxico de pronúncia de marca (TTS) ─────────
# Termos próprios que o TTS PT-BR pronuncia errado. "Nexusyn" tem 's' intervocálico que sai como
# /z/ ("Nexuzyn"); dobrar o 's' (grafia "Nexussyn") força o som /s/. A grafia fonética vai SÓ para
# o TTS — a legenda reverte para a grafia de exibição (brand_caption). Extensível via env
# BRAND_LEXICON (JSON {"Exibição":"Fonetica"}); o default cobre Nexusyn.
def _load_brand_lexicon():
    base = {"Nexusyn": "Nexussyn"}
    try:
        extra = json.loads(os.environ.get("BRAND_LEXICON", "") or "{}")
        if isinstance(extra, dict):
            base.update({str(k): str(v) for k, v in extra.items()})
    except Exception:
        pass
    return base

BRAND_LEXICON = _load_brand_lexicon()  # {grafia_exibicao: grafia_fonetica}
_BRAND_RE = re.compile("|".join(re.escape(k) for k in BRAND_LEXICON), re.IGNORECASE)

def brand_say(text):
    """Troca os termos de marca pela grafia FONÉTICA antes de enviar ao TTS (case-insensitive)."""
    if not text:
        return text
    low = {k.lower(): v for k, v in BRAND_LEXICON.items()}
    return _BRAND_RE.sub(lambda m: low.get(m.group(0).lower(), m.group(0)), text)

def brand_caption(upper_text):
    """Reverte a grafia fonética -> grafia de exibição na legenda (texto já em UPPERCASE)."""
    if not upper_text:
        return upper_text
    for disp, say in BRAND_LEXICON.items():
        upper_text = upper_text.replace(say.upper(), disp.upper())
    return upper_text

# ───────── Estilos de ENTREGA da narração (presets de voice_settings) ─────────
# "neutro" = os valores históricos exatos (stability .5 / similarity .75) — todo body sem
# tts_style (ou com um valor desconhecido) cai nele, retrocompat total.
TTS_STYLE_SETTINGS = {
    "neutro":     {"stability": 0.5,  "similarity_boost": 0.75},
    "dramatico":  {"stability": 0.3,  "similarity_boost": 0.75, "style": 0.6},
    "calmo":      {"stability": 0.85, "similarity_boost": 0.75, "style": 0.0},
    # sussurro ≠ calmo: contenção é volume normal com voz estável; sussurro é a personagem
    # sussurrando de fato. Separados porque só o sussurro vira a audio tag [whispers] no v3 —
    # colapsar os dois fez uma fala de "voz firme e grave" sair sussurrada (projeto 20, cena 2).
    "sussurro":   {"stability": 0.9,  "similarity_boost": 0.75, "style": 0.0},
    "energetico": {"stability": 0.35, "similarity_boost": 0.75, "style": 0.35},
    "locutor":    {"stability": 0.45, "similarity_boost": 0.8,  "style": 0.45},  # voz de comercial (ritmo publicitário)
}

def tts_voice_settings(style):
    """voice_settings do preset de entrega (tts_style); desconhecido/vazio = neutro (histórico)."""
    return TTS_STYLE_SETTINGS.get((style or "").strip() or "neutro", TTS_STYLE_SETTINGS["neutro"])

# ───────── Eleven v3: a emoção vira TAG no texto, não voice_settings ─────────
# O v3 é o modelo mais expressivo da casa, mas responde a um contrato DIFERENTE: a API reporta
# can_use_style=false e can_do_speaker_boost=false, e a direção de atuação entra como audio tag no
# próprio texto ("[whispers] Depois de tantos anos..."). Mandar `style` para ele é ignorado — e
# mandar a tag para o multilingual_v2 é pior: ele LÊ "whispers" em voz alta. Por isso a conversão
# mora aqui, onde o tts_model é conhecido, em vez de no console.
#
# Só tags bem documentadas entram. Tag que o modelo não reconhece vira texto falado, então o custo
# de errar é alto e o de omitir é zero (o v3 já é expressivo sem nenhuma).
# A tag [whispers] é do SUSSURRO, não da contenção: "calmo" é fala estável em volume normal e não
# leva tag nenhuma. Enquanto os dois compartilhavam o preset, uma direção de "voz firme e grave"
# que mencionava "triunfo silencioso" saía sussurrada (projeto 20, cena 2).
V3_STYLE_TAGS = {
    "sussurro": "[whispers]",
    "energetico": "[excited]",
}

# stability do v3: 0.0 (Creative), 0.5 (Natural) e 1.0 (Robust).
V3_STABILITY = {"sussurro": 1.0, "calmo": 1.0, "neutro": 0.5, "locutor": 0.5, "dramatico": 0.0, "energetico": 0.0}

def is_eleven_v3(model):
    return (model or "").strip() == "eleven_v3"

def tts_text_for_model(text, style, model):
    """Prefixa a audio tag da emoção quando o modelo é o v3; nos demais devolve o texto intacto."""
    if not is_eleven_v3(model):
        return text
    tag = V3_STYLE_TAGS.get((style or "").strip())
    return ("%s %s" % (tag, text)) if tag else text

def tts_settings_for_model(style, model):
    """voice_settings compatíveis com o modelo: o v3 não aceita style/speaker_boost e restringe a
    stability a três valores; os demais seguem os presets históricos."""
    if not is_eleven_v3(model):
        return tts_voice_settings(style)
    return {
        "stability": V3_STABILITY.get((style or "").strip() or "neutro", 0.5),
        "similarity_boost": 0.75,
    }

def build_sub_style(body):
    """force_style ASS da legenda a partir do body (MESMA lógica/allowlists do /shortform:
    posição, tamanho, cor, borda, fonte, transparência e caixa) — extraída pro Filme reusar."""
    _spos = str(body.get("subtitle_pos") or "bottom").lower()
    _MV = 32  # margem no canvas virtual do libass (~288px p/ SRT), não em pixels do vídeo
    if _spos == "top":
        _align, marginv = 8, _MV
    elif _spos in ("middle", "center", "meio"):
        _align, marginv = 5, 0
    else:
        _align, marginv = 2, _MV

    def _hex_to_ass(hexc, fallback="&H00FFFFFF", alpha=0):
        try:
            h = str(hexc or "").lstrip("#")
            if len(h) != 6:
                return fallback
            r, g, b = int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16)
            return "&H%02X%02X%02X%02X" % (max(0, min(255, int(alpha))), b, g, r)
        except Exception:
            return fallback
    _ssize = int(body.get("subtitle_size") or 0) or 20
    _ssize = max(10, min(72, _ssize))
    _sborder = body.get("subtitle_border")
    _sborder = 3 if _sborder is None else int(_sborder)
    _sborder = max(0, min(12, _sborder))
    _FONTS = {
        "sans": "Liberation Sans", "serif": "Liberation Serif", "mono": "Liberation Mono",
        "dejavu": "DejaVu Sans", "dejavu-serif": "DejaVu Serif", "noto": "Noto Sans",
    }
    _sfont = _FONTS.get(str(body.get("subtitle_font") or "").strip().lower(), "Liberation Sans")
    _sop = max(0, min(90, int(body.get("subtitle_opacity") or 0)))
    _salpha = int(_sop / 100.0 * 255)
    _scolor = _hex_to_ass(body.get("subtitle_color"), alpha=_salpha)
    _sbcolor = _hex_to_ass(body.get("subtitle_border_color"), fallback="&H00000000", alpha=_salpha)
    if bool(body.get("subtitle_bg")):
        _bgop = max(0, min(100, int(body.get("subtitle_bg_opacity") if body.get("subtitle_bg_opacity") is not None else 60)))
        _bgalpha = int((100 - _bgop) / 100.0 * 255)
        _bgcolor = _hex_to_ass(body.get("subtitle_bg_color"), fallback="&H60000000", alpha=_bgalpha)
        _bstyle, _outc, _backc = 3, _bgcolor, _bgcolor
    else:
        _bstyle, _outc, _backc = 1, _sbcolor, "&H00000000"
    return ("Fontname=%s,FontSize=%d,Bold=1,PrimaryColour=%s,OutlineColour=%s,BackColour=%s,"
            "BorderStyle=%d,Outline=%d,Shadow=1,Alignment=%d,MarginV=%d"
            ) % (_sfont, _ssize, _scolor, _outc, _backc, _bstyle, _sborder, _align, marginv)


def parse_sub_anim(body):
    """Preset da legenda ANIMADA no body, validado pela allowlist. "" = legenda ASS queimada
    de sempre. Extraído do /shortform pro /concat-clips (Filme) usar A MESMA regra."""
    p = str(body.get("subtitle_anim") or "").strip().lower()
    return p if p in ("pop", "karaoke", "bounce", "vox") else ""


def build_sub_anim_style(body):
    """Estilo CRU (hex + chave de fonte, NÃO valores ASS) da legenda animada pro caption-service,
    que revalida tudo lá com a mesma allowlist. Irmão do build_sub_style (que faz o force_style
    da legenda queimada) — mesmos clamps de tamanho/borda/opacidade."""
    def _hex_or(v, fb):
        v = str(v or "")
        return v if re.match(r"^#[0-9A-Fa-f]{6}$", v) else fb
    _spos = str(body.get("subtitle_pos") or "bottom").lower()
    _ssize = max(10, min(72, int(body.get("subtitle_size") or 0) or 20))
    _sborder = body.get("subtitle_border")
    _sborder = max(0, min(12, 3 if _sborder is None else int(_sborder)))
    return {
        "pos": _spos if _spos in ("top", "middle") else "bottom",
        "size": _ssize, "border": _sborder,
        "color": _hex_or(body.get("subtitle_color"), "#FFFFFF"),
        "accentColor": _hex_or(body.get("subtitle_accent_color"), "#FFD700"),
        "borderColor": _hex_or(body.get("subtitle_border_color"), "#000000"),
        "font": str(body.get("subtitle_font") or "sans").strip().lower(),
        "opacity": max(0, min(90, int(body.get("subtitle_opacity") or 0))),
        "bg": bool(body.get("subtitle_bg")),
        "bgColor": _hex_or(body.get("subtitle_bg_color"), "#000000"),
        "bgOpacity": max(0, min(100, int(body.get("subtitle_bg_opacity") if body.get("subtitle_bg_opacity") is not None else 60))),
    }


# ───────── Acabamento "Hollywood" (Sprint B) — grade, grain, letterbox ─────────
# Color grade: presets de ffmpeg eq/curves/colorbalance, ordem "Natural" (sem filtro, grátis)
# até looks estilizados. Aplicados no vídeo final (força re-encode quando != "natural").
GRADE_FILTERS = {
    "natural": "",
    "cinema_quente": "curves=r='0/0 0.5/0.58 1/1':b='0/0 0.5/0.42 1/0.95',eq=saturation=1.08:contrast=1.03",
    "teal_orange": "colorbalance=rs=0.10:gs=-0.02:bs=-0.12:rm=0.05:bm=-0.05:rh=0.12:bh=-0.10,eq=saturation=1.12:contrast=1.05",
    "noir": "eq=saturation=0:contrast=1.25:brightness=-0.02",
    "vintage": "eq=saturation=0.85:contrast=0.92:brightness=0.02,curves=r='0/0.05 1/0.95':b='0/0 1/0.85',vignette=PI/5",
    # F2 (Estúdio de Efeitos): +7 "filtros Instagram" white-label — todos determinísticos
    # (eq/curves/colorbalance/hue), custo zero de API; o preço é só o crédito de efeito.
    "dourado": "colorbalance=rh=0.12:gh=0.05:bh=-0.12:rm=0.06:bm=-0.06,eq=saturation=1.10:brightness=0.02:contrast=1.02",
    "gelo": "colorbalance=bs=0.10:rs=-0.06:bm=0.08:rm=-0.04,eq=saturation=0.95:contrast=1.04",
    "pastel": "curves=all='0/0.06 0.5/0.52 1/0.94',eq=saturation=0.82:contrast=0.90:brightness=0.03",
    "tropical": "vibrance=intensity=0.40,eq=saturation=1.08:contrast=1.05:brightness=0.01",
    "drama": "curves=all='0/0 0.45/0.38 1/1',eq=contrast=1.22:saturation=0.92:brightness=-0.02",
    "pb_suave": "hue=s=0,curves=all='0/0.04 1/0.96',eq=contrast=1.06:brightness=0.03",
    "retro_vhs": "curves=r='0/0.04 1/0.95':g='0/0.03 1/0.97':b='0/0.06 1/0.90',chromashift=cbh=3:crh=-3,eq=saturation=0.88:contrast=0.95",
}

def build_grade_filter(body):
    """Filtro de color grade a partir de body['grade'] — chave desconhecida/ausente cai em
    'natural' (sem filtro, sem custo de re-encode extra). body['grade_strength'] (1-99) faz o
    BLEND do look com o original via split+blend (100/ausente = look cheio; <=0 = desliga).
    O sub-grafo com ';' é válido dentro do join por ',' dos callers: o resultado
    \"[in]split[a][b];[b]LOOK[f];[f][a]blend=...,resto[out]\" é um filtergraph bem formado."""
    g = GRADE_FILTERS.get(str(body.get("grade") or "natural").strip().lower(), "")
    if not g:
        return ""
    try:
        strength = int(body.get("grade_strength", 100))
    except Exception:
        strength = 100
    if strength <= 0:
        return ""
    if strength >= 100:
        return g
    # blend: primeiro input = camada de cima (o look), opacity = intensidade escolhida.
    return "split[__go][__gb];[__gb]%s[__gf];[__gf][__go]blend=all_mode=normal:all_opacity=%.2f" % (g, strength / 100.0)

def build_grain_filter(body):
    """Film grain/halation sutil (Sprint B), opcional — ruído temporal leve, sem custo de API."""
    return "noise=alls=6:allf=t+u" if bool(body.get("grain")) else ""

def avg_luma(path):
    """Luminância MÉDIA (0-255) de um clipe — amostra o frame do meio reduzido a 1x1 em cinza
    (média espacial). Usado no COLOR-MATCH (S3) pra casar a exposição entre clipes do Filme.
    Robusto: qualquer falha → None (o caller pula a correção daquele clipe)."""
    try:
        r = subprocess.run(["ffprobe", "-v", "error", "-count_frames", "-select_streams", "v:0",
                            "-show_entries", "stream=nb_read_frames", "-of",
                            "default=noprint_wrappers=1:nokey=1", path], capture_output=True, text=True)
        nbf = int(r.stdout.strip() or "0")
        sel = ("select=eq(n\\," + str(max(0, nbf // 2)) + "),") if nbf > 1 else ""
        p = subprocess.run(["ffmpeg", "-v", "error", "-i", path, "-vf",
                            sel + "scale=1:1,format=gray", "-frames:v", "1", "-f", "rawvideo", "-"],
                           capture_output=True)
        if p.returncode == 0 and len(p.stdout) >= 1:
            return int(p.stdout[0])
    except Exception:
        pass
    return None

def color_match_eq(ref_luma, clip_luma):
    """Correção de brilho (filtro eq) pra aproximar clip_luma de ref_luma — CLAMP forte (±0.12) pra
    nunca estourar/escurecer demais. Vazio quando não há o que corrigir. É o 'color-match' opt-in:
    reduz a variação de exposição entre trechos do plano-sequência (o drift de cor mais visível)."""
    if ref_luma is None or clip_luma is None:
        return ""
    delta = (ref_luma - clip_luma) / 255.0
    delta = max(-0.12, min(0.12, delta))  # clamp de segurança
    if abs(delta) < 0.008:  # diferença irrelevante → não mexe (evita re-encode à toa)
        return ""
    return "eq=brightness=%.4f," % delta

def build_letterbox_filter(body, out_w, out_h):
    """Letterbox 2.39:1 (Sprint B, Filme 16:9 apenas) — corta pro aspect cinemascope e preenche
    com barras pretas, mantendo o canvas out_w x out_h (não muda a resolução de saída)."""
    if not bool(body.get("letterbox")) or out_w <= out_h:
        return ""
    bar_h = int(round(out_w / 2.39))
    bar_h -= bar_h % 2  # altura par (requisito do libx264)
    if bar_h <= 0 or bar_h >= out_h:
        return ""
    off_y = (out_h - bar_h) // 2
    return "crop=%d:%d:0:%d,pad=%d:%d:0:%d:black" % (out_w, bar_h, off_y, out_w, out_h, off_y)

def build_fps_delay_filter(body):
    """ATRASO DE FPS — a assinatura visual do estilo Vox / documentário explicativo.

    A animação roda a MENOS quadros por segundo do que o container, o que dá o movimento
    levemente travado, de stop-motion, que caracteriza o formato. Não é defeito nem economia:
    é a marca registrada, e sem ela a peça parece um slideshow liso qualquer.

    Implementado como `fps=N` (descarta quadros) seguido de `fps=<container>` (duplica os que
    sobraram): o arquivo continua com o frame rate normal — nenhum player estranha — mas o
    movimento avança em degraus de N por segundo.

    body['fps_delay']: quadros/s efetivos. Clamp 8..30; ausente/0 = sem efeito (nada muda nos
    pipelines existentes). A faixa útil que o formato usa é 12..24 — abaixo de 10 vira defeito.
    """
    try:
        n = int(body.get("fps_delay") or 0)
    except (TypeError, ValueError):
        return ""
    if n <= 0:
        return ""
    n = max(8, min(30, n))

    return "fps=%d" % n


def build_finish_filters(body, out_w, out_h):
    """Junta grade + letterbox + grain + atraso de FPS numa lista de filtros de vídeo (Sprint B)
    — vazia se nada estiver ligado (fast-path: pipelines existentes continuam sem re-encode extra).

    O atraso de FPS vai POR ÚLTIMO de propósito: ele é o look final da peça e tem de decimar o
    resultado já graduado, não antes (decimar e depois graduar não muda a imagem, mas põe os
    filtros caros pra rodar sobre quadros que serão descartados)."""
    parts = [f for f in (build_grade_filter(body), build_letterbox_filter(body, out_w, out_h),
                         build_grain_filter(body), build_fps_delay_filter(body)) if f]
    return parts

LOUDNORM = "loudnorm=I=-14:TP=-1.5:LRA=11"  # Sprint B.2 — padrão de plataformas, sempre que há mix de áudio real

# ───────── Auth do serviço (AUD-008) ─────────
SERVICE_TOKEN = os.environ.get("FFMPEG_SERVICE_TOKEN", "")

# ───────── Sanitização (AUD-005 / AUD-009) ─────────
_SAFE_KEY_RE = re.compile(r"^[a-z0-9_-]+$")           # 'kind'/'ext' do /persist
_YT_HOSTS = ("youtube.com", "youtu.be")               # allowlist do /ingest (AUD-015)

# ───────── Allowlist de hosts de mídia interna (AUD-007 SSRF egress) ─────────
# Os endpoints clip/thumbnail/voiceover/shortform/persist só processam mídia do
# NOSSO storage (Scality/CDN). O vídeo do YouTube já passa por /ingest → Scality antes
# de chegar nesses endpoints, então NÃO há caso legítimo de buscar host externo
# arbitrário aqui. Restringir o fetch a esses hosts corta SSRF de egress sem
# quebrar o fluxo real (clipar/processar mídia nossa).
# Configurável por env MEDIA_ALLOWED_HOSTS (lista separada por vírgula).
_DEFAULT_MEDIA_HOSTS = {"s3.example.com", "media.example.com"}
_MEDIA_HOSTS = {
    h.strip().lower()
    for h in os.environ.get("MEDIA_ALLOWED_HOSTS", "").split(",")
    if h.strip()
} or _DEFAULT_MEDIA_HOSTS

def safe_name(name):
    """Sanitiza um filename recebido como input (AUD-005).
    Rejeita '/', '\\', '..' ou início com '/'; aplica basename e garante que o
    caminho final resolvido permanece sob MEDIA_BASE. Retorna o basename seguro."""
    if not isinstance(name, str) or not name:
        raise ValueError("filename inválido")
    if "/" in name or "\\" in name or ".." in name or name.startswith("/"):
        raise ValueError("filename inválido: %r" % name[:80])
    base = os.path.basename(name)
    if not base or base in (".", ".."):
        raise ValueError("filename inválido: %r" % name[:80])
    media_root = os.path.realpath(MEDIA_BASE)
    # valida contra todas as subpastas usadas (videos/audio) e a própria base
    final = os.path.realpath(os.path.join(MEDIA_BASE, base))
    if not (final == media_root or final.startswith(media_root + os.sep)):
        raise ValueError("path traversal bloqueado: %r" % name[:80])
    return base

def safe_key_part(value, field):
    """Valida 'kind'/'ext' usados na key do S3 (AUD-009): só [a-z0-9_-]."""
    v = (value or "").lower()
    if not _SAFE_KEY_RE.match(v):
        raise ValueError("%s inválido (esperado ^[a-z0-9_-]+$): %r" % (field, str(value)[:40]))
    return v

# ───────── SSRF/LFI guard (AUD-004 / AUD-007 / AUD-023) ─────────
# O download é feito por safe_fetch() com http.client conectado direto ao IP já
# validado (sem 2ª resolução DNS), APENAS http/https, e — quando allow_hosts é
# passado — restrito à allowlist de hosts. Sem FileHandler/FTPHandler em jogo.

def _ip_blocked(ip_str):
    try:
        ip = ipaddress.ip_address(ip_str)
    except ValueError:
        return True
    if ip in ipaddress.ip_network("169.254.0.0/16"):  # link-local + metadata cloud
        return True
    return (ip.is_private or ip.is_loopback or ip.is_link_local
            or ip.is_reserved or ip.is_multicast or ip.is_unspecified)

def validate_url(url, allow_hosts=None):
    """Valida uma URL ANTES de baixar (AUD-004 SSRF/LFI).
    - só http/https;
    - allow_hosts (opcional): exige que o host bata num sufixo da allowlist
      (AUD-007: corta SSRF de egress quando a allowlist é passada);
    - resolve o hostname e rejeita se QUALQUER IP for privado/loopback/link-local/
      reservado/multicast ou estiver em 169.254.0.0/16.
    Retorna (parsed_url, [ips_validados]) — os IPs já resolvidos e aprovados, pra
    o caller pinar a conexão e fechar a janela de DNS-rebinding (AUD-023)."""
    if not isinstance(url, str) or not url:
        raise ValueError("url ausente")
    p = urlparse(url)
    if p.scheme not in ("http", "https"):
        raise ValueError("esquema não permitido: %r" % p.scheme)
    host = p.hostname
    if not host:
        raise ValueError("host ausente na url")
    if allow_hosts is not None:
        h = host.lower()
        if not any(h == d or h.endswith("." + d) for d in allow_hosts):
            raise ValueError("host fora da allowlist: %r" % host)
    try:
        infos = socket.getaddrinfo(host, p.port or (443 if p.scheme == "https" else 80))
    except socket.gaierror as e:
        raise ValueError("falha ao resolver host: %r" % str(e)[:80])
    resolved = []
    for info in infos:
        ip = info[4][0]
        if ip not in resolved:
            resolved.append(ip)
    if not resolved:
        raise ValueError("host não resolveu")
    for ip in resolved:
        if _ip_blocked(ip):
            raise ValueError("ip bloqueado (privado/loopback/link-local/metadata): %s" % ip)
    return p, resolved

def _open_pinned(p, pin_ip, timeout):
    """Abre uma HTTP(S)Connection conectada DIRETO ao IP já validado (AUD-023).
    Pré-conecta o socket no pin_ip e injeta em conn.sock, então o http.client NÃO
    faz nova resolução DNS. Em HTTPS, o TLS é feito com server_hostname=host →
    SNI + verificação de certificado continuam contra o HOSTNAME original."""
    host = p.hostname
    port = p.port or (443 if p.scheme == "https" else 80)
    raw = socket.create_connection((pin_ip, port), timeout=timeout)
    if p.scheme == "https":
        ctx = ssl.create_default_context()
        sock = ctx.wrap_socket(raw, server_hostname=host)  # SNI + cert vs hostname
        conn = http.client.HTTPSConnection(host, port, timeout=timeout, context=ctx)
    else:
        sock = raw
        conn = http.client.HTTPConnection(host, port, timeout=timeout)
    conn.sock = sock  # socket já conectado ao IP validado; sem 2ª resolução DNS
    return conn

def safe_fetch(url, dest, timeout=180, allow_hosts=None, headers=None):
    """Valida (validate_url) e baixa de um IP JÁ VALIDADO (AUD-004 + AUD-023).
    Substitui urlretrieve/urlopen nos pontos de download de URL externa.

    AUD-023 (DNS rebinding / TOCTOU): em vez de validar o host e deixar o opener
    re-resolver o DNS no momento de abrir (2ª resolução = janela de rebind),
    conectamos DIRETO ao IP que acabou de ser validado. Sem 2ª resolução = sem
    janela de troca de IP entre validar e conectar. Redirects são seguidos
    manualmente, revalidando cada destino com a MESMA allowlist."""
    p, ips = validate_url(url, allow_hosts=allow_hosts)
    hdrs = dict(headers or {"User-Agent": "reachyn-fetch/1.0"})
    conn = _open_pinned(p, ips[0], timeout)
    try:
        redirects = 0
        while True:
            path = (p.path or "/") + (("?" + p.query) if p.query else "")
            conn.request("GET", path, headers=hdrs)
            r = conn.getresponse()
            if r.status in (301, 302, 303, 307, 308) and redirects < 5:
                loc = r.getheader("Location")
                if not loc:
                    break
                r.read()  # drena o corpo do redirect antes de reusar/fechar
                conn.close()
                if "://" in loc:
                    nxt = loc
                else:
                    nxt = "%s://%s%s" % (p.scheme, p.hostname, loc if loc.startswith("/") else "/" + loc)
                # revalida o novo alvo com a MESMA allowlist (anti-rebind no redirect)
                p, ips = validate_url(nxt, allow_hosts=allow_hosts)
                conn = _open_pinned(p, ips[0], timeout)
                redirects += 1
                continue
            break
        if r.status >= 400:
            raise ValueError("HTTP %d ao baixar de %s" % (r.status, p.hostname))
        with open(dest, "wb") as f:
            while True:
                chunk = r.read(1 << 16)
                if not chunk:
                    break
                f.write(chunk)
    finally:
        conn.close()
    return dest

# ───────── Scality (fonte única de verdade) — hub de persistência da mídia ─────────
# Toda mídia gerada (fal efêmero, outputs do ffmpeg) sobe pro Scality e passa a ser
# servida por s3.example.com. Fallback gracioso: se falhar, retorna a URL
# original — NUNCA derruba a geração.
# Lê os nomes novos S3_* e cai pros antigos MINIO_* durante a transição do .env (compat).
S3_ENDPOINT = os.environ.get("S3_ENDPOINT") or os.environ.get("MINIO_ENDPOINT", "https://s3.example.com")
S3_KEY = os.environ.get("S3_KEY") or os.environ.get("MINIO_KEY", "")
S3_SECRET = os.environ.get("S3_SECRET") or os.environ.get("MINIO_SECRET", "")
S3_BUCKET = os.environ.get("S3_BUCKET") or os.environ.get("MINIO_BUCKET", "public")
S3_PUBLIC_BASE = os.environ.get("S3_PUBLIC_BASE") or os.environ.get("MINIO_PUBLIC_BASE", "https://s3.example.com/public")
_CT = {"mp4": "video/mp4", "mov": "video/quicktime", "jpg": "image/jpeg", "jpeg": "image/jpeg",
       "png": "image/png", "webp": "image/webp", "gif": "image/gif", "mp3": "audio/mpeg", "wav": "audio/wav"}
_s3 = None
def _s3client():
    global _s3
    if _s3 is None:
        import boto3
        from botocore.config import Config
        _s3 = boto3.client("s3", endpoint_url=S3_ENDPOINT,
                           aws_access_key_id=S3_KEY, aws_secret_access_key=S3_SECRET,
                           region_name="us-east-1", config=Config(s3={"addressing_style": "path"}))
    return _s3

def persist_to_s3(local_path, kind, ext):
    """Sobe um arquivo LOCAL pro Scality; retorna URL pública ou None (caller faz fallback)."""
    if not S3_KEY:
        return None
    try:
        kind = safe_key_part(kind, "kind")   # AUD-009: nunca derivar key de input cru
        ext = safe_key_part(ext, "ext")
        key = "reachyn/%s/%d-%s.%s" % (kind, int(time.time() * 1000), os.urandom(4).hex(), ext)
        # CacheControl: a key carrega timestamp+random, então o objeto é IMUTÁVEL — o conteúdo
        # daquela URL nunca muda (regerar cria outra key). Sem este header o Scality não manda
        # cache nenhum e o browser revalida a cada play; `immutable` corta até o 304, o que pesa
        # num vídeo final de ~10MB. 1 ano é o teto prático do max-age.
        _s3client().upload_file(local_path, S3_BUCKET, key,
                                ExtraArgs={"ContentType": _CT.get(ext, "application/octet-stream"),
                                           "CacheControl": "public, max-age=31536000, immutable"})
        return "%s/%s" % (S3_PUBLIC_BASE, key)
    except Exception as e:
        print("persist_to_s3 falhou:", repr(e)[:200])
        return None

def persist_url(src_url, kind, ext, headers=None):
    """Baixa uma URL (provedor de vídeo/etc) e sobe pro Scality; retorna URL Scality ou a original (fallback).
    headers (opcional): cabeçalhos extras no GET (ex.: auth exigido pela URI do provedor de vídeo premium)."""
    if not S3_KEY or not src_url:
        return src_url
    safe_ext = safe_key_part(ext, "ext")  # AUD-009: ext entra no nome do tmp e na key
    tmp = "/tmp/persist_%d_%s.%s" % (int(time.time() * 1000), os.urandom(3).hex(), safe_ext)
    try:
        fetch_hdrs = {"User-Agent": "reachyn-persist/1.0"}
        if isinstance(headers, dict):
            fetch_hdrs.update({str(k): str(v) for k, v in headers.items()})
        safe_fetch(src_url, tmp, timeout=180,  # AUD-004: valida scheme/host/IP antes de baixar
                   headers=fetch_hdrs)
        return persist_to_s3(tmp, kind, ext) or src_url
    except Exception as e:
        print("persist_url falhou:", repr(e)[:200])
        return src_url
    finally:
        try:
            os.remove(tmp)
        except Exception:
            pass

def _ext_from_url(u, default="bin"):
    base = u.split("?")[0].split("#")[0]
    if "." in base.rsplit("/", 1)[-1]:
        e = base.rsplit(".", 1)[-1].lower()
        if 1 <= len(e) <= 4:
            return e
    return default

def _mk_url(filename, ext="mp4"):
    """URL final de um arquivo em MEDIA_BASE/videos: tenta Scality (durável); fallback = disco local."""
    return persist_to_s3("%s/videos/%s" % (MEDIA_BASE, filename), "videos", ext) or "%s/videos/%s" % (PUBLIC_BASE, filename)

def _dims(path):
    r = subprocess.run(["ffprobe", "-v", "quiet", "-print_format", "json", "-select_streams", "v:0",
                        "-show_entries", "stream=width,height", path], capture_output=True, text=True)
    st = json.loads(r.stdout)["streams"][0]
    return int(st["width"]), int(st["height"])

def _image_even_size(image_url, max_w=1080):
    """Dimensão de saída na PROPORÇÃO da imagem: largura ≤ max_w, ambos os lados PARES.

    Par não é capricho: o libx264 com yuv420p recusa dimensão ímpar ("width not divisible by 2"),
    e a falha só apareceria no encode, depois do download. Imagem que não puder ser lida cai no
    9:16 padrão — melhor um clipe com corte do que nenhum clipe.
    """
    tmp = "/tmp/probe_%d_%s.bin" % (int(time.time() * 1000), os.urandom(3).hex())
    try:
        safe_fetch(image_url, tmp)
        w, h = _dims(tmp)
        if w <= 0 or h <= 0:
            return (1080, 1920)
        if w > max_w:
            h = int(round(h * max_w / w))
            w = max_w
        return (max(2, w - (w % 2)), max(2, h - (h % 2)))
    except Exception:
        return (1080, 1920)
    finally:
        try:
            os.remove(tmp)
        except OSError:
            pass


def _face_center_x(src, start, end, W, H):
    # amostra ~12 frames no trecho, acha o rosto maior, devolve a mediana do x-centro (px). None se nada.
    try:
        import cv2
        cap = cv2.VideoCapture(src)
        casc = cv2.CascadeClassifier(cv2.data.haarcascades + "haarcascade_frontalface_default.xml")
        xs = []; N = 12; span = max(0.001, end - start)
        for k in range(N):
            t = start + span * (k / (N - 1) if N > 1 else 0)
            cap.set(cv2.CAP_PROP_POS_MSEC, t * 1000.0)
            ok, frame = cap.read()
            if not ok:
                continue
            g = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
            faces = casc.detectMultiScale(g, 1.2, 5, minSize=(60, 60))
            if len(faces):
                fx, fy, fw, fh = max(faces, key=lambda f: f[2] * f[3])
                xs.append(fx + fw / 2.0)
        cap.release()
        if not xs:
            return None
        xs.sort()
        return xs[len(xs) // 2]
    except Exception:
        return None

# ───────── Transições entre cenas/trechos (Estúdio de Efeitos, F1) ─────────
# Dois modos, escolhidos pela FÍSICA do fluxo:
#  - "seg" (fadeblack/fadewhite): fade out/in POR SEGMENTO + concat seco → a DURAÇÃO de cada
#    segmento fica INTACTA. É o único modo seguro no /shortform (a narração é por segmento —
#    sobrepor cortaria fala). Custo: re-encode dos segmentos com transição.
#  - "xfade" (fade/slide/wipe/circle/zoom): sobreposição real de D seg por corte via xfade —
#    só no /concat-clips (Filme), onde narração/música/legenda entram DEPOIS da montagem e o
#    encolhimento de (n-1)×D é transparente.
TRANSITIONS = {
    "cut": None,
    "fadeblack": "seg", "fadewhite": "seg",
    "fade": "xfade", "slideleft": "xfade", "slideright": "xfade",
    "wipeleft": "xfade", "wiperight": "xfade", "circleopen": "xfade", "zoomin": "xfade",
}

def parse_transitions(body, n_segs, allow_xfade):
    """Lê body['transitions'] = {default:{kind,dur}, cuts:[kind,...]} → (cuts, dur) validados.
    cuts tem n_segs-1 posições (transição ENTRE i e i+1). Kind inválido → 'cut'. Sem xfade
    permitido, kinds xfade degradam pra 'fadeblack' (não quebra a montagem)."""
    t = body.get("transitions") or {}
    if not isinstance(t, dict) or n_segs < 2:
        return None, 0.5
    default = t.get("default") or {}
    dkind = str(default.get("kind") or "cut")
    try:
        dur = min(1.5, max(0.2, float(default.get("dur") or 0.5)))
    except Exception:
        dur = 0.5
    raw = t.get("cuts") if isinstance(t.get("cuts"), list) else []
    cuts = []
    for i in range(n_segs - 1):
        k = str(raw[i]) if i < len(raw) and raw[i] else dkind
        if k not in TRANSITIONS:
            k = "cut"
        if TRANSITIONS.get(k) == "xfade" and not allow_xfade:
            k = "fadeblack"  # degrade seguro (duração-preservada)
        cuts.append(k)
    if all(k == "cut" for k in cuts):
        return None, dur
    return cuts, dur

def _dur_of(path):
    r = subprocess.run(["ffprobe", "-v", "quiet", "-print_format", "json", "-show_format", path],
                       capture_output=True, text=True)
    try:
        return float(json.loads(r.stdout)["format"]["duration"])
    except Exception:
        return 5.0

def scene_offsets(seg_durs, cuts, tr_dur):
    """Offset (s) do INÍCIO de cada trecho dentro do filme já montado.

    É a soma das durações dos trechos anteriores DESCONTANDO o overlap dos xfades (cada xfade
    encurta o filme em tr_dur). Era código inline do SFX-por-trecho (F4); virou função pura pra
    a locução POR CENA usar exatamente a MESMA régua — se os dois divergirem, o SFX e a fala da
    mesma cena caem em tempos diferentes."""
    offs = []
    for k in range(len(seg_durs)):
        off = sum(seg_durs[:k])
        if cuts:
            off -= tr_dur * sum(1 for c in cuts[:k] if TRANSITIONS.get(c) == "xfade")
        offs.append(max(0.0, off))
    return offs


def film_scene_scripts(body, n):
    """Normaliza body['scripts'] (locução POR CENA) pra EXATAMENTE n itens, ou None.

    ⚠️ Porquê: o console manda `scripts` (uma locução por cena) desde 2026-07-26 — mas nem o
    engine nem este serviço tinham o campo, então ele era DESCARTADO em silêncio e a narração do
    Filme virava um texto corrido colado no segundo 0. Este é o campo que faltava.

    Políticas de borda (as MESMAS do caminho de beats/SFX, por consistência):
      - lista MAIOR que os clipes → sobra ignorada (`[:n]`, igual `sfx_list[:len(segs)]`);
      - lista MENOR → cenas finais mudas (preenche com "", igual `if i < len(beats)`);
      - item vazio no meio → cena SEM fala (nada de TTS, nada de legenda);
      - lista ausente / não-lista / toda vazia → None = cai no `script` único de sempre
        (compatibilidade: sem `scripts`, o comportamento não muda em nada)."""
    raw = body.get("scripts")
    if not isinstance(raw, list):
        return None
    out = [(s.strip() if isinstance(s, str) else "") for s in raw[:n]]
    out += [""] * (n - len(out))
    return out if any(out) else None


def anchor_voice_starts(offsets, voice_durs, gap=0.15):
    """Início REAL de cada locução: ancorada no começo da sua cena, sem sobrepor a anterior.

    Regra: start_i = max(offset_i, fim_da_locução_anterior + gap). Ou seja a fala começa junto
    com a cena (o ponto do bug) e só é empurrada quando a fala ANTERIOR ainda está no ar.

    Locução mais LONGA que o clipe → ela VAZA pro trecho seguinte (não corta, não estica o
    clipe). Motivo: no Filme as durações dos clipes são intocáveis (continuidade do
    plano-sequência — a montagem, os xfades e os offsets de SFX já foram calculados sobre elas), e
    narração é CONTEÚDO, não enfeite: cortar a fala perderia texto. O empurrão da próxima é o que
    evita duas vozes falando por cima uma da outra.

    voice_durs[i] <= 0 = cena sem fala → start None (não entra no mix nem na legenda)."""
    starts = []
    prev_end = 0.0
    for off, d in zip(offsets, voice_durs):
        if d <= 0:
            starts.append(None)
            prev_end = max(prev_end, off)
            continue
        s = max(off, prev_end)
        starts.append(s)
        prev_end = s + d + gap
    return starts


def apply_seg_fades(segs, cuts, dur):
    """Modo 'seg': aplica fade-out no fim do seg i e fade-in no começo do seg i+1 (cor por kind)
    APENAS nos cortes com transição. Duração preservada; áudio intacto. Reescreve in-place."""
    n = len(segs)
    for i, seg in enumerate(segs):
        vf = []
        if i > 0 and TRANSITIONS.get(cuts[i - 1]) == "seg":
            color = "white" if cuts[i - 1] == "fadewhite" else "black"
            vf.append("fade=t=in:st=0:d=%.2f:color=%s" % (dur, color))
        if i < n - 1 and TRANSITIONS.get(cuts[i]) == "seg":
            color = "white" if cuts[i] == "fadewhite" else "black"
            d = _dur_of(seg)
            vf.append("fade=t=out:st=%.2f:d=%.2f:color=%s" % (max(0.0, d - dur), dur, color))
        if not vf:
            continue
        tmp = seg + ".fade.mp4"
        r = subprocess.run(["ffmpeg", "-y", "-i", seg, "-vf", ",".join(vf), "-c:v", "libx264",
                            "-pix_fmt", "yuv420p", "-c:a", "copy", tmp], capture_output=True, text=True)
        if r.returncode == 0:
            os.replace(tmp, seg)

def xfade_join(segs, cuts, dur, out_path, fps=30):
    """Modo 'xfade': junta os segmentos par a par — xfade (vídeo) + acrossfade (áudio) nos cortes
    com transição; concat filter nos cortes secos. Sequencial (n-1 passes) — vídeos curtos, ok."""
    cur = segs[0]
    for i in range(1, len(segs)):
        kind = cuts[i - 1]
        nxt = segs[i]
        step = "/tmp/xf_%d_%d.mp4" % (int(time.time() * 1000) % 100000, i)
        if TRANSITIONS.get(kind) == "xfade":
            off = max(0.0, _dur_of(cur) - dur)
            fc = ("[0:v][1:v]xfade=transition=%s:duration=%.2f:offset=%.2f[v];"
                  "[0:a][1:a]acrossfade=d=%.2f[a]") % (kind, dur, off, dur)
        else:  # corte seco entre segmentos (concat filter, mantém o pipeline homogêneo)
            fc = "[0:v][1:v]concat=n=2:v=1:a=0[v];[0:a][1:a]concat=n=2:v=0:a=1[a]"
        r = subprocess.run(["ffmpeg", "-y", "-i", cur, "-i", nxt, "-filter_complex", fc,
                            "-map", "[v]", "-map", "[a]", "-c:v", "libx264", "-pix_fmt", "yuv420p",
                            "-r", str(int(fps) or 30), "-c:a", "aac", "-ar", "44100", step],
                           capture_output=True, text=True)
        if r.returncode != 0:
            return False, r.stderr[-300:]
        cur = step
    try:
        os.replace(cur, out_path)
    except OSError:
        subprocess.run(["cp", cur, out_path])
    return True, ""

# ───────── 🎇 VFX por cena (F4 — Estúdio de Efeitos) ─────────
# Efeitos visuais DETERMINÍSTICOS (ffmpeg puro, custo zero de API), aplicados POR SEGMENTO
# antes do concat. TODOS preservam a duração do segmento (essencial: no /shortform a narração
# é por segmento e qualquer mudança de duração dessincroniza voz/legenda).
#  - shake: tremida de câmera (crop com offset senoidal por frame sobre um upscale de 5%)
#  - zoom_pulse: zoom pulsando no ritmo (zoompan com z oscilante, d=1 = 1 frame por frame)
#  - punch_in: soco de zoom no INÍCIO da cena (abre em 1.22x e assenta em ~0.8s)
#  - glitch: deslocamento de canais RGB + ruído temporal forte (estética digital quebrada)
#  - vhs: chroma shift + scanlines + ruído + dessaturação leve (fita antiga)
#  - freeze: congela o último ~0.8s da cena (frame hold no fim, duração intacta)
# CAM_MOVES — movimentos que o zoompan reproduz de verdade sobre uma imagem estática. As keys são
# as MESMAS do engine (moveDirectives, internal/content/spec.go) de propósito: vocabulário paralelo
# vira key órfã e some em silêncio. Os 4 que faltam (orbit, crane_up, handheld, dolly) precisam de
# paralaxe/3D real — não dá pra fingir com crop 2D, então o caller cai no i2v pago.
CAM_MOVES = ("static", "push_in", "pull_out", "pan_left", "pan_right", "tilt_up", "tilt_down")


def build_camera_vf(move, out_w, out_h, frames, fps):
    """Câmera PROGRAMADA sobre imagem estática (sem IA) → string -vf do ffmpeg, ou None se o
    movimento não for reproduzível.

    Vantagem sobre o i2v: custo ~zero, instantâneo e ZERO drift — a identidade é a própria arte
    aprovada, pixel a pixel, porque nenhum modelo redesenha nada.
    """
    if move not in CAM_MOVES:
        return None
    n = max(2, int(frames))
    p = "(in/%d)" % (n - 1)                # progresso linear 0→1
    e = "(%s*%s*(3-2*%s))" % (p, p, p)     # smoothstep: acelera e desacelera; linear denuncia "efeito"
    amp = 1.12                             # amplitude do zoom (12%) — acima disso o grão aparece
    cx, cy = "iw/2-(iw/zoom/2)", "ih/2-(ih/zoom/2)"

    if move == "push_in":
        z, x, y = "1+%.2f*%s" % (amp - 1, e), cx, cy
    elif move == "pull_out":
        z, x, y = "%.2f-%.2f*%s" % (amp, amp - 1, e), cx, cy
    elif move == "static":
        # Micro-zoom de 3%: imperceptível como movimento, mas sem ele o plano lê como foto parada.
        z, x, y = "1+0.03*%s" % e, cx, cy
    elif move in ("pan_left", "pan_right"):
        z = "%.2f" % amp
        prog = e if move == "pan_right" else "(1-%s)" % e
        x, y = "(iw-iw/zoom)*%s" % prog, cy
    else:  # tilt_up | tilt_down
        z = "%.2f" % amp
        prog = e if move == "tilt_down" else "(1-%s)" % e
        x, y = cx, "(ih-ih/zoom)*%s" % prog

    # Mesmo encadeamento do Ken Burns dos slides (~l.1314): cobre o frame, corta na proporção e só
    # então faz o upscale grande ANTES do zoompan — é o que mata o jitter clássico do filtro.
    return ("scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,scale=%d:-2,"
            "zoompan=z='%s':d=1:x='%s':y='%s':s=%dx%d:fps=%d,setsar=1"
            ) % (out_w, out_h, out_w, out_h, out_w * 4, z, x, y, out_w, out_h, fps)


# ───────── ✂️ RECORTES DE MONTAGEM (ritmo de corte dentro da cena) ─────────
# O PROBLEMA: uma cena = um clipe = um enquadramento só. Com a narração ditando o tempo, cada
# cena fica em 5-6s de plano ÚNICO, e a peça inteira tem tantos cortes quantas cenas — 6 cortes
# em 36s. Explicativo animado é o oposto disso: o corte é a pontuação, e um plano que dura 6s lê
# como slideshow. Gerar mais cenas resolveria o ritmo pagando linearmente mais no provedor de
# vídeo (foi o que estourou custo em 2026-08-04); não é o caminho.
#
# A SAÍDA: recortar o clipe que JÁ existe. A cena é dividida em K planos que continuam no tempo
# (o mesmo clipe, sem repetir trecho) e cada um entra num enquadramento diferente — geral, fechado,
# detalhe. Corte seco de geral pra fechado sobre a mesma arte é um corte de verdade aos olhos, e
# no Vox a colagem é de câmera travada, então o fechado revela detalhe em vez de denunciar zoom.
# Custo de provedor: ZERO — é crop no ffmpeg. A duração da cena não muda em nenhum caso, o que é
# obrigatório: a narração é POR cena e qualquer desvio dessincroniza voz e legenda.
#
# ⚠️ Entra ANTES da legenda no encadeamento (a legenda é queimada depois): recortar sobre a
# legenda já queimada faria o texto ser ampliado junto e sair de quadro no plano fechado.

# (zoom, âncora x, âncora y) por plano — 0.5/0.5 = centro. O ciclo abre geral, fecha no rosto da
# informação, vai ao detalhe e reabre; repete a partir do 5º (nenhuma peça chega lá hoje).
SHOT_FRAMES = ((1.00, 0.50, 0.50), (1.20, 0.50, 0.42), (1.38, 0.50, 0.56), (1.12, 0.50, 0.50))
SHOT_MIN_SECS = 1.2   # plano mais curto que isto vira piscada, não corte
SHOT_MAX = 4          # teto de planos por cena


def alignment_words(al):
    """Alignment por CARACTERE do TTS → palavras [{word, start, end}] (segundos).

    Mesma conversão usada na legenda word-level da montagem (_film_words) — extraída pra função
    de módulo porque o /tts também passa a devolver isso ao cliente (timestamps=true): quem monta
    fora daqui (editor externo, ComfyUI, agente) precisa do MESMO relógio da fala que a montagem
    interna usa, senão a legenda dele deriva da nossa.
    """
    al = al or {}
    chs = al.get("characters") or []
    stt = al.get("character_start_times_seconds") or []
    ett = al.get("character_end_times_seconds") or []
    out = []
    cur, cs, ce = "", None, None
    for j, ch in enumerate(chs):
        if not str(ch).strip():
            if cur:
                out.append({"word": cur, "start": round(cs, 3), "end": round(ce, 3)})
                cur, cs = "", None
        else:
            if cs is None:
                cs = stt[j] if j < len(stt) else 0.0
            cur += ch
            ce = ett[j] if j < len(ett) else cs
    if cur:
        out.append({"word": cur, "start": round(cs or 0.0, 3), "end": round(ce or 0.0, 3)})
    return out


HEAD_TRIM_MAX = 1.0   # descartar mais que 1s de cabeça já come conteúdo, não só a parada inicial

# 🎬 CUT STING — a "tracking transition" do formato Vox: micro push-in + blur curto nas BORDAS
# do segmento, com pico exatamente no corte. É o que faz os cortes lerem como um fluxo único em
# vez de sequência de slides (breakdown da referência: câmera 3D recuando linear + blur mais
# curto que o movimento, subindo NO corte e caindo frames depois).
#
# Implementado POR SEGMENTO de propósito: cada segmento é encodado sozinho e o concat é -c copy,
# então um xfade entre vizinhos é impossível aqui — mas empurrar a SAÍDA de um e a ENTRADA do
# outro produz o mesmo gesto no corte, sem mudar a duração de nada.
STING_OUT_SECS = 0.15   # rampa do push na saída do segmento
STING_IN_SECS = 0.10    # assentamento na entrada
STING_ZOOM = 0.08       # quanto o quadro fecha no pico (8%)
STING_BLUR_OUT = 0.10   # janela do blur na saída (mais curta que o push, como na referência)
STING_BLUR_IN = 0.07    # janela do blur na entrada
STING_MIN_DUR = 1.2     # segmento mais curto que isto vira só borrão — sem sting


def cut_sting_vf(dur, out_w, out_h, fps, lead, tail):
    """Sufixo de vf do sting: push+blur na entrada (`lead`) e/ou na saída (`tail`) do segmento.

    `lead` = existe corte ANTES deste segmento (falso no primeiro); `tail` = existe corte DEPOIS
    (falso no último). Sem nenhum dos dois, ou segmento curto demais, devolve "" — byte a byte o
    comportamento histórico.

    ⚠️ zoompan e NÃO crop: as expressões de w/h do crop são avaliadas UMA vez na inicialização
    (t indefinido → filtro morre); zoompan avalia z por frame com `it` (timestamp de entrada).
    Mesmo padrão do build_camera_vf: upscale ANTES do zoompan mata o jitter clássico do filtro.
    O gblur é binário (enable) — sigma fixo, janela mais curta que o push, como na referência.
    """
    try:
        D = float(dur or 0)
    except (TypeError, ValueError):
        return ""
    if D < STING_MIN_DUR or not (lead or tail):
        return ""
    zoom = []
    blur = []
    if tail:
        # rampa 0→1 nos últimos STING_OUT_SECS: clip() segura fora da janela.
        zoom.append("%.3f*clip((it-%.3f)/%.3f,0,1)" % (STING_ZOOM, D - STING_OUT_SECS, STING_OUT_SECS))
        blur.append("between(t,%.3f,%.3f)" % (D - STING_BLUR_OUT, D))
    if lead:
        # rampa 1→0 nos primeiros STING_IN_SECS: o segmento entra fechado e assenta.
        zoom.append("%.3f*clip(1-it/%.3f,0,1)" % (STING_ZOOM, STING_IN_SECS))
        blur.append("between(t,0,%.3f)" % STING_BLUR_IN)
    return (",scale=%d:-2,zoompan=z='1+%s':d=1:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=%dx%d:fps=%d,"
            "setsar=1,gblur=sigma=5:enable='%s'"
            % (out_w * 2, "+".join(zoom), out_w, out_h, fps, "+".join(blur)))


def head_trim_secs(v):
    """Segundos a descartar do início do clipe — saneado. Lixo/negativo/ausente ⇒ 0 (intacto)."""
    try:
        ht = float(v or 0)
    except (TypeError, ValueError):
        return 0.0
    if ht <= 0:
        return 0.0

    return min(ht, HEAD_TRIM_MAX)


def build_shot_cuts(dur, shot_secs, out_w, out_h):
    """Sufixo de filter_complex que recorta UMA cena em K planos, ou "" quando não vale recortar.

    Recebe o encadeamento já normalizado para out_w×out_h (é o que o /shortform monta) e devolve
    o trecho que se anexa a ele, terminando SEM label — quem chama fecha com [v], igual ao caso
    de plano único.
    """
    try:
        alvo = float(shot_secs or 0)
        D = float(dur or 0)
    except (TypeError, ValueError):
        return ""
    if alvo <= 0 or D <= 0:
        return ""
    # ARREDONDA, não trunca: com alvo de 2,2s uma cena de 6s trunca em 2 planos de 3s (que é o
    # slideshow que se quer evitar) e arredonda em 3 de 2s — o alvo é um alvo, não um teto.
    k = int(round(D / alvo))
    if k > SHOT_MAX:
        k = SHOT_MAX
    # Um plano só = nada a recortar; e planos curtos demais denunciam o truque (pisca-pisca).
    if k < 2 or D / k < SHOT_MIN_SECS:
        return ""

    passo = D / k
    partes = [",split=%d" % k + "".join("[k%d]" % i for i in range(k))]
    for i in range(k):
        z, ax, ay = SHOT_FRAMES[i % len(SHOT_FRAMES)]
        t0, t1 = i * passo, (i + 1) * passo
        # trim NO TEMPO CONTÍNUO do clipe: o plano seguinte pega onde o anterior parou, então o
        # corte avança a ação em vez de rebobinar.
        ch = "[k%d]trim=%.3f:%.3f,setpts=PTS-STARTPTS" % (i, t0, t1)
        if z > 1.001:
            # Dimensões PARES (libx264/yuv420p exige) e crop ancorado: `ax/ay` são a posição do
            # centro do recorte dentro do quadro ampliado, em fração.
            w, h = int(out_w * z / 2) * 2, int(out_h * z / 2) * 2
            ch += ",scale=%d:%d,crop=%d:%d:%d:%d" % (
                w, h, out_w, out_h,
                max(0, min(w - out_w, int((w - out_w) * ax))),
                max(0, min(h - out_h, int((h - out_h) * ay))))
        partes.append(ch + ",setsar=1[c%d]" % i)
    return ";".join(partes) + ";" + "".join("[c%d]" % i for i in range(k)) + "concat=n=%d:v=1:a=0" % k


def vfx_filter(kind, dur, fps, out_w, out_h):
    k = str(kind or "").strip().lower()
    if k == "shake":
        return ("scale=iw*1.05:ih*1.05,crop=%d:%d:x='(iw-ow)/2+8*sin(n*13.7)':y='(ih-oh)/2+7*cos(n*9.3)'"
                % (out_w, out_h))
    if k == "zoom_pulse":
        return ("zoompan=z='1.04+0.04*sin(in/%.1f)':d=1:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=%dx%d:fps=%d"
                % (max(2.0, fps / 5.0), out_w, out_h, fps))
    if k == "punch_in":
        return ("zoompan=z='max(1.0,1.22-0.28*in/%.1f)':d=1:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=%dx%d:fps=%d"
                % (max(1.0, fps * 0.8), out_w, out_h, fps))
    if k == "glitch":
        return "rgbashift=rh=4:bh=-4:gv=2,noise=alls=16:allf=t"
    if k == "vhs":
        return "chromashift=cbh=4:crh=-4,drawgrid=w=iw:h=3:t=1:c=black@0.22,noise=alls=9:allf=t,eq=saturation=0.85"
    if k == "freeze" and dur and dur > 1.6:
        hold = min(0.8, dur * 0.25)
        return "trim=0:%.2f,tpad=stop_mode=clone:stop_duration=%.2f" % (dur - hold, hold)
    return ""

def apply_vfx(seg, kind, dur, fps, out_w, out_h, overlay_path=None):
    """Re-encoda o segmento COM o VFX (e/ou overlay em blend=screen) in-place. Best-effort:
    falha → segmento original intacto (efeito é acabamento, nunca derruba a montagem).
    overlay_path: vídeo de partícula em FUNDO PRETO (chuva/neve/faísca...) loopado por cima."""
    vf = vfx_filter(kind, dur, fps, out_w, out_h)
    if not vf and not overlay_path:
        return
    tmp = seg + ".vfx.mp4"
    try:
        if overlay_path:
            fc = "[0:v]%s[b]" % (vf or "null")
            fc += (";[1:v]scale=%d:%d,setsar=1,format=yuv420p[o];[b][o]blend=all_mode=screen:shortest=1[v]"
                   % (out_w, out_h))
            cmd = ["ffmpeg", "-y", "-i", seg, "-stream_loop", "-1", "-i", overlay_path,
                   "-filter_complex", fc, "-map", "[v]", "-map", "0:a", "-t", "%.2f" % (dur or _dur_of(seg))]
        else:
            cmd = ["ffmpeg", "-y", "-i", seg, "-filter_complex", "[0:v]" + vf + "[v]",
                   "-map", "[v]", "-map", "0:a"]
        cmd += ["-c:v", "libx264", "-pix_fmt", "yuv420p", "-r", str(int(fps) or 30),
                "-c:a", "copy", tmp]
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=240)
        if r.returncode == 0 and os.path.exists(tmp) and os.path.getsize(tmp) > 0:
            os.replace(tmp, seg)
    except Exception:
        pass

# ───────── 🔊 SFX por cena (F4) — ElevenLabs sound-generation ─────────
# ── Legenda ANIMADA (caption-service / Remotion) ─────────────────────────────────────────
# O caption-service renderiza SÓ a camada de legenda (WebM VP8 com canal alpha) e o composite
# acontece aqui, DEPOIS do segmento pronto. Sem CAPTION_TOKEN configurado o recurso fica
# desligado (secure-by-default) e o chamador cai no fallback (legenda ASS queimada de sempre).
CAPTION_URL = os.environ.get("CAPTION_URL", "http://caption-service:3979")
CAPTION_TOKEN = os.environ.get("CAPTION_TOKEN", "")

def caption_overlay(seg_path, words, dur, fps, out_w, out_h, preset, style, tag):
    """Pede o overlay animado ao caption-service e compõe por cima do segmento (in place).
    True = compôs; False = QUALQUER falha (o chamador decide o fallback — nunca lança)."""
    if not CAPTION_TOKEN or not words:
        return False
    try:
        import urllib.request as _u
        ov = "/tmp/%s.webm" % safe_name(tag)
        payload = json.dumps({"words": words, "duration": dur, "fps": fps, "width": out_w,
                              "height": out_h, "preset": preset, "style": style}).encode()
        req = _u.Request(CAPTION_URL + "/render", data=payload,
                         headers={"Content-Type": "application/json", "X-Service-Token": CAPTION_TOKEN},
                         method="POST")
        # timeout > watchdog do serviço (300s): quem desiste primeiro é o render, não o HTTP.
        with _u.urlopen(req, timeout=360) as r, open(ov, "wb") as f:
            f.write(r.read())
        tmp = seg_path + ".cap.mp4"
        # -c:v libvpx ANTES do -i do overlay: força o decoder libvpx, o único que lê o alpha
        # do WebM (o decoder vp8 nativo do ffmpeg ignora o canal e o overlay sai opaco).
        r2 = subprocess.run(["ffmpeg", "-y", "-i", seg_path, "-c:v", "libvpx", "-i", ov,
                             "-filter_complex", "[0:v][1:v]overlay=0:0:eof_action=pass[v]",
                             "-map", "[v]", "-map", "0:a?", "-c:v", "libx264", "-pix_fmt", "yuv420p",
                             "-r", str(fps), "-c:a", "copy", tmp], capture_output=True, text=True)
        if r2.returncode != 0:
            return False
        os.replace(tmp, seg_path)
        return True
    except Exception:
        return False

def gen_sfx(prompt, dur, el_key, ts, i):
    """Gera um efeito sonoro curto (porta rangendo, passos, trovão...) via ElevenLabs
    sound-generation. Retorna o caminho do mp3 ou None (best-effort)."""
    try:
        import urllib.request as _u
        payload = {"text": str(prompt)[:300], "duration_seconds": max(0.5, min(11.0, float(dur or 3.0)))}
        req = _u.Request("https://api.elevenlabs.io/v1/sound-generation",
                         data=json.dumps(payload).encode(),
                         headers={"xi-api-key": el_key, "Content-Type": "application/json"}, method="POST")
        p = "/tmp/sfx_%d_%d.mp3" % (ts, i)
        with _u.urlopen(req, timeout=120) as r, open(p, "wb") as f:
            f.write(r.read())
        return p if os.path.getsize(p) > 0 else None
    except Exception:
        return None

# ───────── Composição do MODEL SHEET (template fixo, determinístico) ─────────
# Em vez de a IA desenhar a folha inteira (layout+rótulos variavam a cada geração e o texto
# saía alucinado: "Red Coller", "Color Shattch"), a IA gera SÓ os shots individuais limpos
# (i2i, sem texto) e ESTE compositor monta a folha num grid fixo com rótulos corretos (fontes
# instaladas) + swatches de paleta. Mesmo input → mesmo layout, sempre.
_FONT_REG = ["/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
             "/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf"]
_FONT_BOLD = ["/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
              "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf"]

def _font(size, bold=False):
    for p in (_FONT_BOLD if bold else _FONT_REG):
        if os.path.exists(p):
            try:
                return ImageFont.truetype(p, size)
            except Exception:
                pass
    return ImageFont.load_default()

def _wrap(draw, text, font, max_w):
    lines, cur = [], ""
    for w in str(text).split():
        t = (cur + " " + w).strip()
        if not cur or draw.textlength(t, font=font) <= max_w:
            cur = t
        else:
            lines.append(cur); cur = w
    if cur:
        lines.append(cur)
    return lines

def compose_sheet(spec):
    """Monta UMA folha de model sheet num template fixo. spec:
    {title, subtitle?, cols?, shots:[{url,label}], palette?:[{hex,label}], bg?}
    Baixa cada shot do NOSSO S3 (allowlist _MEDIA_HOSTS), cola em grade determinística,
    escreve rótulos com fonte instalada e desenha os swatches. Persiste no S3 e retorna a URL."""
    title = str(spec.get("title", "")).strip()
    subtitle = str(spec.get("subtitle", "")).strip()
    cols = max(1, min(6, int(spec.get("cols", 4) or 4)))
    shots = list(spec.get("shots") or [])
    palette = list(spec.get("palette") or [])
    bg = str(spec.get("bg") or "#ECECEC")

    W, pad, gap = 2400, 48, 28
    cell_w = (W - 2 * pad - (cols - 1) * gap) // cols
    img_h = int(cell_w * 1.15)
    label_h = 66
    cell_h = img_h + label_h
    rows = (len(shots) + cols - 1) // cols if shots else 0
    title_h = 132 if title else 0
    pal_h = 264 if palette else 0
    H = pad + title_h + (rows * cell_h + max(0, rows - 1) * gap) + pal_h + pad
    H = max(H, 420)

    canvas = Image.new("RGB", (W, H), bg)
    d = ImageDraw.Draw(canvas)
    ink, muted, cardc, line = "#1c1c1c", "#666666", "#ffffff", "#d5d5d5"

    y = pad
    if title:
        d.text((pad, y), title, fill=ink, font=_font(56, bold=True))
        if subtitle:
            d.text((pad, y + 68), subtitle, fill=muted, font=_font(30))
        y += title_h

    f_label = _font(26, bold=True)
    for i, sh in enumerate(shots):
        r, c = divmod(i, cols)
        x0 = pad + c * (cell_w + gap)
        y0 = y + r * (cell_h + gap)
        d.rectangle([x0, y0, x0 + cell_w, y0 + img_h], fill=cardc, outline=line, width=2)
        placed = False
        url = (sh.get("url") or "").strip()
        if url:
            tmp = "/tmp/shot_%d_%s.img" % (int(time.time() * 1000), os.urandom(3).hex())
            try:
                safe_fetch(url, tmp, timeout=60, allow_hosts=_MEDIA_HOSTS,
                           headers={"User-Agent": "reachyn-compose/1.0"})
                im = Image.open(tmp).convert("RGB")
                iw, ih = im.size
                bw, bh = cell_w - 24, img_h - 24
                scale = min(bw / iw, bh / ih)
                nw, nh = max(1, int(iw * scale)), max(1, int(ih * scale))
                im = im.resize((nw, nh), Image.LANCZOS)
                canvas.paste(im, (x0 + (cell_w - nw) // 2, y0 + (img_h - nh) // 2))
                placed = True
            except Exception as e:
                print("compose shot falhou:", repr(e)[:150])
            finally:
                try:
                    os.remove(tmp)
                except Exception:
                    pass
        if not placed:
            d.text((x0 + cell_w // 2, y0 + img_h // 2), "—", fill=muted, font=_font(40), anchor="mm")
        label = str(sh.get("label", "")).strip()
        if label:
            # Quebra em até 2 linhas DENTRO da célula (rótulo longo transbordava na célula vizinha).
            lines = _wrap(d, label, f_label, cell_w - 16)[:2]
            if len(lines) == 2 and d.textlength(lines[1], font=f_label) > cell_w - 16:
                while lines[1] and d.textlength(lines[1] + "…", font=f_label) > cell_w - 16:
                    lines[1] = lines[1][:-1]
                lines[1] += "…"
            ly = y0 + img_h + label_h // 2 - (14 if len(lines) == 2 else 0)
            for ln in lines:
                d.text((x0 + cell_w // 2, ly), ln, fill=ink, font=f_label, anchor="mm")
                ly += 28

    if palette:
        py = y + (rows * cell_h + max(0, rows - 1) * gap) + (gap if rows else 0)
        d.text((pad, py), "PALETA DE CORES", fill=ink, font=_font(30, bold=True))
        py += 50
        sw, sgap, f_pl = 150, 22, _font(22)
        for j, item in enumerate(palette[:12]):
            sx = pad + j * (sw + sgap)
            if sx + sw > W - pad:
                break
            hexv = str(item.get("hex", "") or "#888888").strip() or "#888888"
            try:
                d.rectangle([sx, py, sx + sw, py + sw], fill=hexv, outline=line, width=2)
            except Exception:
                d.rectangle([sx, py, sx + sw, py + sw], fill="#888888", outline=line, width=2)
            for k, ln in enumerate(_wrap(d, item.get("label", ""), f_pl, sw)[:2]):
                d.text((sx, py + sw + 8 + k * 26), ln, fill=muted, font=f_pl)

    out = "/tmp/sheet_%d_%s.jpg" % (int(time.time() * 1000), os.urandom(3).hex())
    canvas.save(out, "JPEG", quality=90)
    try:
        return persist_to_s3(out, "image", "jpg")
    finally:
        try:
            os.remove(out)
        except Exception:
            pass


def compose_storyboard(spec):
    """Monta a FOLHA DE STORYBOARD — o documento de produção que se confere ANTES de montar o
    vídeo. spec:
      {title, subtitle?, cols?, footer?:[{k,v}], panels:[{url, index?, time?, shot?, action?, dialogue?}]}
    → {url}

    POR QUE ISTO É COMPOSTO E NÃO GERADO: o método que circula por aí manda um modelo de imagem
    DESENHAR a folha inteira (painéis + numeração + timecode + notas) e torce pra tipografia sair
    certa. Aqui os painéis já existem (são os keyframes que o filme/a animação geraram) e os
    rótulos são DADO nosso — número, tempo, plano, ação e fala saem do JSON do plano. Então a folha
    não custa geração nenhuma e o texto está certo por construção, em vez de por sorte. É o mesmo
    raciocínio do model sheet determinístico (ver compose_sheet): a IA desenha a imagem, o
    template escreve as letras.

    Layout por painel: badge do número (canto sup. esq.) e timecode (canto sup. dir.) SOBRE a
    imagem; abaixo dela o tipo de plano em caixa alta, a ação, e a fala entre aspas.
    """
    title = str(spec.get("title", "")).strip()
    subtitle = str(spec.get("subtitle", "")).strip()
    panels = list(spec.get("panels") or [])
    footer = list(spec.get("footer") or [])
    # 5 colunas = a grade 3x5 de 15 painéis do formato clássico de storyboard. Teto 6 igual ao
    # compose_sheet: acima disso a célula fica menor que o texto que ela precisa carregar.
    cols = max(1, min(6, int(spec.get("cols", 5) or 5)))
    bg = str(spec.get("bg") or "#F2F0EB")

    W, pad, gap = 2400, 48, 24
    cell_w = (W - 2 * pad - (cols - 1) * gap) // cols
    img_h = int(cell_w * 0.5625)  # 16:9 — o quadro do storyboard é o quadro do filme
    note_h = 150                  # plano + ação + fala
    cell_h = img_h + note_h
    rows = (len(panels) + cols - 1) // cols if panels else 0
    title_h = 132 if title else 0
    foot_h = 96 if footer else 0
    H = pad + title_h + (rows * cell_h + max(0, rows - 1) * gap) + foot_h + pad
    H = max(H, 420)

    canvas = Image.new("RGB", (W, H), bg)
    d = ImageDraw.Draw(canvas)
    ink, muted, cardc, line = "#141414", "#5f5f5f", "#ffffff", "#cfcbc4"
    fala = "#6b4b1f"  # a fala destacada da ação — na folha de produção são coisas diferentes

    y = pad
    if title:
        d.text((pad, y), title, fill=ink, font=_font(56, bold=True))
        if subtitle:
            d.text((pad, y + 68), subtitle, fill=muted, font=_font(30))
        y += title_h

    f_shot = _font(22, bold=True)
    f_note = _font(20)
    f_badge = _font(24, bold=True)
    for i, p in enumerate(panels):
        r, c = divmod(i, cols)
        x0 = pad + c * (cell_w + gap)
        y0 = y + r * (cell_h + gap)
        d.rectangle([x0, y0, x0 + cell_w, y0 + img_h], fill=cardc, outline=line, width=2)
        placed = False
        url = (p.get("url") or "").strip()
        if url:
            tmp = "/tmp/sb_%d_%s.img" % (int(time.time() * 1000), os.urandom(3).hex())
            try:
                safe_fetch(url, tmp, timeout=60, allow_hosts=_MEDIA_HOSTS,
                           headers={"User-Agent": "reachyn-compose/1.0"})
                im = Image.open(tmp).convert("RGB")
                iw, ih = im.size
                # PREENCHE a célula (recorta o excedente) em vez de encaixar com tarja: o painel de
                # storyboard é um QUADRO, e quadro com borda vazia não lê como enquadramento.
                scale = max(cell_w / iw, img_h / ih)
                nw, nh = max(1, int(iw * scale)), max(1, int(ih * scale))
                im = im.resize((nw, nh), Image.LANCZOS)
                im = im.crop(((nw - cell_w) // 2, (nh - img_h) // 2,
                              (nw - cell_w) // 2 + cell_w, (nh - img_h) // 2 + img_h))
                canvas.paste(im, (x0, y0))
                placed = True
            except Exception as e:
                print("storyboard painel falhou:", repr(e)[:150])
            finally:
                try:
                    os.remove(tmp)
                except Exception:
                    pass
        if not placed:
            # Célula vazia é resultado HONESTO — a folha serve pra ver o que falta antes de montar.
            d.text((x0 + cell_w // 2, y0 + img_h // 2), "—", fill=muted, font=_font(40), anchor="mm")

        idx = str(p.get("index", i + 1)).strip()
        if idx:
            d.rectangle([x0, y0, x0 + 52, y0 + 40], fill="#141414")
            d.text((x0 + 26, y0 + 20), idx, fill="#ffffff", font=f_badge, anchor="mm")
        tc = str(p.get("time", "") or "").strip()
        if tc:
            tw = d.textlength(tc, font=f_badge) + 20
            d.rectangle([x0 + cell_w - tw, y0, x0 + cell_w, y0 + 40], fill="#141414")
            d.text((x0 + cell_w - tw / 2, y0 + 20), tc, fill="#ffffff", font=f_badge, anchor="mm")

        ty = y0 + img_h + 10
        shot = str(p.get("shot", "") or "").strip().upper()
        if shot:
            for ln in _wrap(d, shot, f_shot, cell_w - 12)[:1]:
                d.text((x0 + 6, ty), ln, fill=ink, font=f_shot)
            ty += 28
        action = str(p.get("action", "") or "").strip()
        if action:
            for ln in _wrap(d, action, f_note, cell_w - 12)[:3]:
                d.text((x0 + 6, ty), ln, fill=muted, font=f_note)
                ty += 24
        dialogue = str(p.get("dialogue", "") or "").strip()
        if dialogue:
            for ln in _wrap(d, '"' + dialogue + '"', f_note, cell_w - 12)[:2]:
                d.text((x0 + 6, ty), ln, fill=fala, font=f_note)
                ty += 24

    if footer:
        fy = y + (rows * cell_h + max(0, rows - 1) * gap) + (gap if rows else 0)
        d.line([pad, fy, W - pad, fy], fill=line, width=2)
        fx, f_k, f_v = pad, _font(20), _font(22, bold=True)
        for item in footer[:6]:
            k = str(item.get("k", "") or "").strip().upper()
            v = str(item.get("v", "") or "").strip()
            d.text((fx, fy + 20), k, fill=muted, font=f_k)
            d.text((fx, fy + 46), v, fill=ink, font=f_v)
            fx += max(d.textlength(k, font=f_k), d.textlength(v, font=f_v)) + 70
            if fx > W - pad:
                break

    out = "/tmp/storyboard_%d_%s.jpg" % (int(time.time() * 1000), os.urandom(3).hex())
    canvas.save(out, "JPEG", quality=90)
    try:
        return persist_to_s3(out, "image", "jpg")
    finally:
        try:
            os.remove(out)
        except Exception:
            pass


# ───────── Estado da chave de voz (alarme do /health) ─────────
# Cache do último veredito: o /health é batido pelo docker a cada poucos segundos e não pode
# martelar o provedor. TTL de 5 min é curto pra flagrar a troca de chave no mesmo turno de
# trabalho e longo pra não virar tráfego.
_VOICE_KEY_CACHE = {"at": 0.0, "status": "desconhecido"}
_VOICE_KEY_TTL = 300


def _voice_key_status():
    """Último veredito conhecido. NUNCA vai à rede — ver _voice_key_loop.

    ⚠️ O healthcheck do container tem timeout de 4s. Consultar o provedor DENTRO do /health faria
    a resposta passar disso sempre que o cache vencesse, e três desses seguidos marcam o serviço
    como unhealthy: o alarme derrubaria justamente o que veio vigiar. Por isso a checagem vive
    numa thread de fundo e aqui só se lê memória.
    """
    return _VOICE_KEY_CACHE["status"]


def _voice_key_probe():
    """'ok' | 'invalida' | 'ausente' | 'indisponivel' — a chave de voz autentica AGORA?

    Usa GET /v1/user (leitura, sem custo). Um 401 por FALTA DE ESCOPO significa chave VÁLIDA (ela
    autenticou; as chaves operacionais não têm user_read) — o mesmo critério do Ping do engine.
    Confundir os dois daria alarme falso todo dia, e alarme falso ensina a ignorar o alarme.
    """
    key = os.environ.get("ELEVENLABS_API_KEY", "")
    if not key.strip():
        status = "ausente"
    else:
        try:
            import urllib.request as _u
            req = _u.Request("https://api.elevenlabs.io/v1/user", headers={"xi-api-key": key})
            with _u.urlopen(req, timeout=10) as r:
                status = "ok" if r.status == 200 else "invalida"
        except Exception as e:
            corpo = ""
            try:
                corpo = e.read().decode("utf-8", "ignore")[:300]
            except Exception:
                pass
            codigo = getattr(e, "code", None)
            if codigo == 401 and "missing_permissions" in corpo:
                status = "ok"           # autenticou; só não tem o escopo de leitura
            elif codigo == 401:
                status = "invalida"
            else:
                status = "indisponivel"  # rede/timeout — não acusa a chave sem prova

    anterior = _VOICE_KEY_CACHE["status"]
    _VOICE_KEY_CACHE.update({"at": time.time(), "status": status})
    # Só grita na MUDANÇA: repetir a cada ciclo viraria ruído, e ruído esconde sinal.
    if status != anterior and status in ("invalida", "ausente"):
        print("ALERTA: chave de voz %s — a narração vai falhar em TODA peça até ser trocada" % status, flush=True)
    elif status == "ok" and anterior in ("invalida", "ausente"):
        print("chave de voz voltou a autenticar", flush=True)

    return status


def _voice_key_loop():
    """Thread de fundo: reconfere a chave de voz a cada _VOICE_KEY_TTL e só atualiza a memória.

    Daemon: não segura o desligamento do processo. Falha na sonda não derruba nada — o pior caso
    é o status ficar 'indisponivel', que é honesto (não sabemos), e não 'ok' (que seria mentira).
    """
    while True:
        try:
            _voice_key_probe()
        except Exception as e:
            print("sonda da chave de voz falhou:", repr(e)[:200], flush=True)
        time.sleep(_VOICE_KEY_TTL)


class Handler(BaseHTTPRequestHandler):
    def log_message(self, format, *args):
        pass

    def _authorized(self):
        """AUD-008: todo POST exige X-Service-Token == FFMPEG_SERVICE_TOKEN (constant-time)."""
        if not SERVICE_TOKEN:
            # serviço sem token configurado = misconfig; falha fechado.
            return False
        provided = self.headers.get("X-Service-Token", "") or ""
        return hmac.compare_digest(provided, SERVICE_TOKEN)

    def do_GET(self):
        # Health check livre (sem token), pro orquestrador/healthcheck do docker.
        #
        # 🔑 A CHAVE DE VOZ ENTRA AQUI (2026-08-07). Este endpoint respondia {"ok": true} sempre —
        # inclusive nas 17 horas de 06/08 em que o serviço rodou com uma chave de voz LEGADA e
        # TODA narração falhou. O container se dizia saudável o tempo inteiro; nada no docker, no
        # log ou no health denunciava. Foi o silêncio, não a falha, que custou o dia.
        #
        # `ok` continua refletindo só o processo: chave morta NÃO pode derrubar o container (o
        # resto — corte, montagem, legenda — funciona sem voz, e reiniciar em laço seria trocar
        # uma feature quebrada por um serviço fora do ar). O estado da chave vai num campo à
        # parte, pra ser visível e monitorável.
        if self.path == "/health":
            self._respond(200, {"ok": True, "voice_key": _voice_key_status()})
            return
        self._respond(404, {"error": "not found"})

    def do_POST(self):
        if not self._authorized():
            self._respond(401, {"error": "unauthorized"})
            return
        length = int(self.headers.get("Content-Length", 0))
        body = json.loads(self.rfile.read(length))

        # /persist — baixa uma URL (ex: fal efêmero) e a torna durável no Scality.
        # body: {url, kind?, ext?} → {url: <minio_url ou original>, persisted: bool}
        if self.path == "/persist":
            try:
                src = body["url"]
                kind = safe_key_part(body.get("kind", "misc"), "kind")  # AUD-009
                ext = safe_key_part((body.get("ext") or _ext_from_url(src, "bin")).lower(), "ext")
                # fetch_headers (opcional): auth p/ baixar a URI do provedor de vídeo premium.
                fetch_headers = body.get("fetch_headers")
                if not isinstance(fetch_headers, dict):
                    fetch_headers = None
                out = persist_url(src, kind, ext, headers=fetch_headers)
                self._respond(200, {"url": out, "persisted": out != src})
            except Exception as e:
                self._respond(500, {"error": str(e)[:200]})
            return

        # /persist-bytes — recebe a mídia EM BASE64 e a torna durável no Scality. Existe pro
        # produtor SEM URL alcançável daqui (ex.: cli-bridge no host — IP privado, que o
        # safe_fetch bloqueia por anti-SSRF de propósito). Aqui não há fetch NENHUM: os bytes
        # já vêm no corpo autenticado (AUD-004 intacto). body: {b64, kind?, ext?} → {url}
        if self.path == "/persist-bytes":
            tmp = None
            try:
                raw = base64.b64decode(body.get("b64") or "", validate=True)
                if not raw:
                    self._respond(400, {"error": "b64 vazio"})
                    return
                kind = safe_key_part(body.get("kind", "misc"), "kind")  # AUD-009
                # TETO POR TIPO. O limite único de 25MB foi dimensionado pra IMAGEM ("bem acima
                # do real, ~300KB") e passou a derrubar coisa legítima quando este endpoint virou
                # a porta de entrada de malha 3D e clipe: em 2026-08-02 uma malha LIMPA estourou
                # e o engine caiu no fallback da malha crua — a limpeza foi feita e jogada fora.
                # Malha e vídeo são grandes por natureza; imagem continua curta de propósito,
                # porque teto frouxo em endpoint autenticado ainda é superfície de abuso.
                #
                # 2026-08-29: imagem subiu de 25 para 80MB. O "~300KB" acima descrevia a imagem
                # de GERAÇÃO; as ferramentas de pós-produção que entraram no catálogo devolvem
                # PNG sem perda em alta — uma reiluminação medida voltou com 29MB e o pedido
                # morria aqui, depois de o crédito já ter sido reservado e a ferramenta ter
                # rodado. 80MB cobre PNG até ~6K com folga e continua longe de irrestrito.
                TETO_MB = {"mesh3d": 200, "clip": 200, "video": 200}.get(kind, 80)
                if len(raw) > TETO_MB * 1024 * 1024:
                    self._respond(413, {"error": "payload excede %dMB" % TETO_MB})
                    return
                ext = safe_key_part((body.get("ext") or "bin").lower(), "ext")
                tmp = "/tmp/pbytes_%d_%s.%s" % (int(time.time() * 1000), os.urandom(3).hex(), ext)
                with open(tmp, "wb") as f:
                    f.write(raw)
                url = persist_to_s3(tmp, kind, ext)
                if not url:
                    self._respond(502, {"error": "scality indisponível"})
                    return
                self._respond(200, {"url": url, "persisted": True})
            except Exception as e:
                self._respond(400, {"error": str(e)[:200]})
            finally:
                if tmp:
                    try:
                        os.remove(tmp)
                    except Exception:
                        pass
            return

        # /strip-audio — remove o áudio de um vídeo (ex: Veo) → vídeo MUDO no Scality.
        # Usado pelo modo "Veo + narração própria": tira o áudio nativo antes da voz.
        if self.path == "/strip-audio":
            tmp_in = "/tmp/strip_in_%d_%s.mp4" % (int(time.time() * 1000), os.urandom(3).hex())
            tmp_out = "/tmp/strip_out_%d_%s.mp4" % (int(time.time() * 1000), os.urandom(3).hex())
            try:
                safe_fetch(body["url"], tmp_in, timeout=180)
                r = subprocess.run(["ffmpeg", "-y", "-i", tmp_in, "-c:v", "copy", "-an", tmp_out],
                                   capture_output=True, text=True)
                if r.returncode != 0:
                    self._respond(500, {"error": r.stderr[-500:]})
                    return
                self._respond(200, {"url": persist_to_s3(tmp_out, "veo", "mp4") or body["url"]})
            except Exception as e:
                self._respond(500, {"error": str(e)[:200]})
            finally:
                for f in (tmp_in, tmp_out):
                    try:
                        os.remove(f)
                    except Exception:
                        pass
            return

        # /gif — converte um vídeo (mp4) em GIF animado otimizado (palettegen+paletteuse p/ qualidade)
        # e persiste no Scality. body: {url, fps?, width?, loop?} → {url}. loop=0 = loop infinito.
        if self.path == "/gif":
            tmp_in = "/tmp/gif_in_%d_%s.mp4" % (int(time.time() * 1000), os.urandom(3).hex())
            tmp_out = "/tmp/gif_out_%d_%s.gif" % (int(time.time() * 1000), os.urandom(3).hex())
            try:
                safe_fetch(body["url"], tmp_in, timeout=180)
                fps = max(5, min(30, int(body.get("fps") or 15)))       # clamp defensivo
                width = max(120, min(1080, int(body.get("width") or 480)))
                loop = int(body.get("loop", 0))                          # 0 = loop infinito
                # 2-pass palette: 1º gera a paleta ótima (stats_mode=diff = foca no que muda),
                # 2º aplica com dither → GIF nítido e leve. scale -1 preserva o aspecto.
                vf = ("fps=%d,scale=%d:-1:flags=lanczos,split[s0][s1];"
                      "[s0]palettegen=stats_mode=diff[p];[s1][p]paletteuse=dither=bayer:bayer_scale=3"
                      % (fps, width))
                r = subprocess.run(["ffmpeg", "-y", "-i", tmp_in, "-vf", vf, "-loop", str(loop), tmp_out],
                                   capture_output=True, text=True)
                if r.returncode != 0:
                    self._respond(500, {"error": r.stderr[-500:]})
                    return
                self._respond(200, {"url": persist_to_s3(tmp_out, "gif", "gif") or ""})
            except Exception as e:
                self._respond(500, {"error": str(e)[:200]})
            finally:
                for f in (tmp_in, tmp_out):
                    try:
                        os.remove(f)
                    except Exception:
                        pass
            return

        if self.path == "/merge":
            try:
                video_file = safe_name(body["video_filename"])  # AUD-005
                audio_file = safe_name(body["audio_filename"])
                video_path = f"{MEDIA_BASE}/videos/{video_file}"
                audio_path = f"{MEDIA_BASE}/audio/{audio_file}"
                out_filename = f"merged_{int(time.time()*1000)}.mp4"
                out_path = f"{MEDIA_BASE}/videos/{out_filename}"

                result = subprocess.run([
                    "ffmpeg", "-y",
                    "-i", video_path,
                    "-i", audio_path,
                    "-c:v", "copy",
                    "-c:a", "aac",
                    "-shortest",
                    out_path
                ], capture_output=True, text=True)

                if result.returncode != 0:
                    self._respond(500, {"error": result.stderr[-500:]})
                    return

                size_mb = round(os.path.getsize(out_path) / 1024 / 1024, 2)
                self._respond(200, {
                    "video_url": _mk_url(out_filename),
                    "filename": out_filename,
                    "size_mb": size_mb,
                    "merged": True
                })
            except Exception as e:
                self._respond(500, {"error": str(e)})
        elif self.path == "/duration":
            try:
                audio_file = safe_name(body["audio_filename"])  # AUD-005
                audio_path = f"{MEDIA_BASE}/audio/{audio_file}"
                result = subprocess.run([
                    "ffprobe", "-v", "quiet", "-print_format", "json",
                    "-show_format", audio_path
                ], capture_output=True, text=True)
                info = json.loads(result.stdout)
                duration = float(info["format"]["duration"])
                import math
                # 1 clip por 9s de áudio: >9s=2, >18s=3, >27s=4, ...
                clips_needed = max(1, math.ceil(duration / 9))
                video_duration = "5" if duration <= 5 else "10"
                self._respond(200, {
                    "duration_seconds": round(duration, 2),
                    "video_duration": video_duration,
                    "clips_needed": clips_needed
                })
            except Exception as e:
                self._respond(500, {"error": str(e)})

        elif self.path == "/concat_merge":
            try:
                video_files = [safe_name(v) for v in body["video_filenames"]]  # AUD-005: cada item
                audio_file  = safe_name(body["audio_filename"])
                audio_path  = f"{MEDIA_BASE}/audio/{audio_file}"
                out_filename = f"merged_{int(time.time()*1000)}.mp4"
                out_path     = f"{MEDIA_BASE}/videos/{out_filename}"

                if len(video_files) == 1:
                    video_path = f"{MEDIA_BASE}/videos/{video_files[0]}"
                else:
                    # Criar arquivo de lista para concat
                    list_path = f"/tmp/concat_{int(time.time())}.txt"
                    with open(list_path, "w") as lf:
                        for vf in video_files:
                            lf.write(f"file '{MEDIA_BASE}/videos/{vf}'\n")
                    concat_path = f"/tmp/concat_out_{int(time.time()*1000)}.mp4"
                    r = subprocess.run([
                        "ffmpeg", "-y", "-f", "concat", "-safe", "0",
                        "-i", list_path, "-c", "copy", concat_path
                    ], capture_output=True, text=True)
                    if r.returncode != 0:
                        self._respond(500, {"error": "concat falhou: " + r.stderr[-300:]})
                        return
                    video_path = concat_path

                # Cortar vídeo em audio_duration + 2s
                dur_result = subprocess.run([
                    "ffprobe", "-v", "quiet", "-print_format", "json", "-show_format", audio_path
                ], capture_output=True, text=True)
                audio_duration = float(json.loads(dur_result.stdout)["format"]["duration"])
                cut_duration = str(round(audio_duration + 2, 3))

                result = subprocess.run([
                    "ffmpeg", "-y",
                    "-i", video_path,
                    "-i", audio_path,
                    "-map", "0:v", "-map", "1:a",
                    "-c:v", "copy", "-c:a", "aac",
                    "-t", cut_duration,
                    out_path
                ], capture_output=True, text=True)

                if result.returncode != 0:
                    self._respond(500, {"error": result.stderr[-500:]})
                    return

                size_mb = round(os.path.getsize(out_path) / 1024 / 1024, 2)
                self._respond(200, {
                    "video_url": _mk_url(out_filename),
                    "filename": out_filename,
                    "size_mb": size_mb,
                    "merged": True,
                    "clips": len(video_files)
                })
            except Exception as e:
                self._respond(500, {"error": str(e)})
        elif self.path == "/voiceover":
            try:
                import urllib.request as _u
                video_url = body["video_url"]
                text = brand_say(body["text"])  # grafia fonética de marca p/ o TTS (ex: Nexusyn→Nexussyn)
                voice_id = body.get("voice_id", "Ey5AWb48tVX1IOcikcht")
                el_key = os.environ.get("ELEVENLABS_API_KEY", "")
                ts = int(time.time() * 1000)
                vpath = f"/tmp/vo_{ts}.mp4"
                apath = f"/tmp/vo_{ts}.mp3"
                safe_fetch(video_url, vpath)  # AUD-004 + AUD-007 (só storage interno)
                tts_req = _u.Request(
                    f"https://api.elevenlabs.io/v1/text-to-speech/{voice_id}",
                    data=json.dumps({"text": text, "model_id": "eleven_multilingual_v2",
                                     "voice_settings": {"stability": 0.5, "similarity_boost": 0.75}}).encode(),
                    headers={"xi-api-key": el_key, "Content-Type": "application/json"}, method="POST")
                with _u.urlopen(tts_req, timeout=90) as r, open(apath, "wb") as f:
                    f.write(r.read())
                out_filename = f"voiceover_{ts}.mp4"
                out_path = f"{MEDIA_BASE}/videos/{out_filename}"
                result = subprocess.run([
                    "ffmpeg", "-y", "-i", vpath, "-i", apath,
                    "-filter_complex", "[0:v]tpad=stop_mode=clone:stop_duration=30[v]",
                    "-map", "[v]", "-map", "1:a:0",
                    "-c:v", "libx264", "-pix_fmt", "yuv420p", "-c:a", "aac", "-shortest", out_path
                ], capture_output=True, text=True)
                if result.returncode != 0:
                    self._respond(500, {"error": result.stderr[-500:]})
                    return
                size_mb = round(os.path.getsize(out_path) / 1024 / 1024, 2)
                self._respond(200, {"video_url": _mk_url(out_filename),
                                    "filename": out_filename, "size_mb": size_mb, "voiceover": True})
            except Exception as e:
                self._respond(500, {"error": str(e)})
        elif self.path == "/shortform":
            try:
                # ⚠️ NÃO importar `base64` aqui: import local (sem alias) torna o nome LOCAL do
                # do_POST inteiro e quebra com UnboundLocalError qualquer rota que use base64
                # ANTES desta linha (caso real: /persist-bytes, 2026-07-19). O módulo já vem do
                # import do topo do arquivo.
                import urllib.request as _u
                beats = body["beats"]
                voice_id = body.get("voice_id", "Ey5AWb48tVX1IOcikcht")
                # FLAGS CONDICIONAIS (orquestrador único). Defaults = True para retrocompat:
                # um body antigo (sem flags) cai no comportamento atual idêntico (narração +
                # legenda + música). Cada flag em False PULA a etapa correspondente, economizando
                # recurso (sem chamada de TTS, sem queima de legenda, sem geração de trilha).
                narration = bool(body.get("narration", True))
                subtitles = bool(body.get("subtitles", True))
                music = bool(body.get("music", True))
                # Formato da montagem: "16:9" (horizontal) ou "9:16" (vertical, default).
                aspect = body.get("aspect", "9:16")
                OUT_W, OUT_H = (1920, 1080) if aspect == "16:9" else (1080, 1920)
                # Qualidade/entrega da narração (tier+preset do catálogo): modelo + formato/bitrate
                # do MP3 + estilo. Vazios = defaults históricos (retrocompat com bodies antigos).
                tts_model = body.get("tts_model") or "eleven_multilingual_v2"
                tts_format = (body.get("tts_format") or "").strip()
                tts_settings = tts_settings_for_model(body.get("tts_style"), tts_model)
                # Sincronismo (ajustes finos do operador): atraso da narração dentro de cada cena
                # (voz+legenda começam DEPOIS do vídeo; o segmento estica junto) + deslocamento
                # extra SÓ da legenda relativo ao áudio. Defaults 0 = comportamento histórico.
                audio_delay = min(max(float(body.get("audio_delay") or 0.0), 0.0), 2.0)
                sub_offset = min(max(float(body.get("subtitle_offset") or 0.0), -1.0), 1.0)
                cue_shift = audio_delay + sub_offset  # deslocamento total dos cues no caminho com narração
                el_key = os.environ.get("ELEVENLABS_API_KEY", "")
                ts = int(time.time() * 1000)
                segs = []
                def _t(s):
                    h = int(s // 3600); m = int((s % 3600) // 60); sec = int(s % 60); ms = int(round((s - int(s)) * 1000))
                    return "%02d:%02d:%02d,%03d" % (h, m, sec, ms)
                # _seg_dur — duração real (segundos) de um clipe via ffprobe. Usada quando NÃO há
                # narração (sem TTS pra ditar a duração): a duração do segmento = duração do clipe.
                def _seg_dur(path, fallback=5.0):
                    try:
                        pj = subprocess.run(["ffprobe", "-v", "quiet", "-print_format", "json", "-show_format", path], capture_output=True, text=True)
                        return float(json.loads(pj.stdout)["format"]["duration"])
                    except Exception:
                        return fallback
                # Estilo da legenda (compartilhado pelos dois caminhos: word-level e timing estimado).
                # Posição da legenda (subtitle_pos): "bottom" (default) | "middle" | "top".
                # Alignment ASS: 2=baixo-centro, 5=meio-centro, 8=topo-centro.
                # ⚠️ MarginV é em unidades do CANVAS virtual do libass (~288px de altura p/ SRT),
                # NÃO em pixels do vídeo. O valor antigo (0.09*OUT_H=172) caía a ~60% = no MEIO.
                # Aqui usamos ~32 (≈11% do canvas) = margem real lá embaixo/em cima.
                _spos = str(body.get("subtitle_pos") or "bottom").lower()
                _MV = 32  # margem da borda no canvas do libass (vale p/ bottom e top)
                if _spos == "top":
                    _align, marginv = 8, _MV
                elif _spos in ("middle", "center", "meio"):
                    _align, marginv = 5, 0
                else:
                    _align, marginv = 2, _MV
                # Estilo configurável: TAMANHO, COR (texto), BORDA (espessura+cor), FONTE, TRANSPARÊNCIA
                # e FUNDO (caixa). Defaults preservam o look histórico. Clamp/allowlist contra valores
                # absurdos ou injeção no force_style (o Fontname entra numa string de filtro do ffmpeg).
                def _hex_to_ass(hexc, fallback="&H00FFFFFF", alpha=0):
                    # #RRGGBB → &HAABBGGRR (ASS = BGR; alpha 00=opaco .. FF=transparente). Inválido → fallback.
                    try:
                        h = str(hexc or "").lstrip("#")
                        if len(h) != 6:
                            return fallback
                        r, g, b = int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16)
                        return "&H%02X%02X%02X%02X" % (max(0, min(255, int(alpha))), b, g, r)
                    except Exception:
                        return fallback
                _ssize = int(body.get("subtitle_size") or 0) or 20
                _ssize = max(10, min(72, _ssize))          # clamp 10..72
                _sborder = body.get("subtitle_border")
                _sborder = 3 if _sborder is None else int(_sborder)
                _sborder = max(0, min(12, _sborder))       # clamp 0..12
                # FONTE: allowlist (só fontes presentes no container; o valor vai pro force_style, então
                # nada de string crua do usuário). Chaves neutras (white-label) → família real.
                _FONTS = {
                    "sans": "Liberation Sans", "serif": "Liberation Serif", "mono": "Liberation Mono",
                    "dejavu": "DejaVu Sans", "dejavu-serif": "DejaVu Serif", "noto": "Noto Sans",
                }
                _sfont = _FONTS.get(str(body.get("subtitle_font") or "").strip().lower(), "Liberation Sans")
                # TRANSPARÊNCIA do texto/borda: 0 (opaco) .. 90 (quase invisível) → alpha ASS.
                _sop = max(0, min(90, int(body.get("subtitle_opacity") or 0)))
                _salpha = int(_sop / 100.0 * 255)
                _scolor = _hex_to_ass(body.get("subtitle_color"), alpha=_salpha)
                _sbcolor = _hex_to_ass(body.get("subtitle_border_color"), fallback="&H00000000", alpha=_salpha)
                # FUNDO (caixa atrás do texto): BorderStyle=3 = opaque box (BackColour). bg_opacity 0..100
                # (100=opaco). Sem fundo → outline normal (BorderStyle=1) com a cor da borda.
                if bool(body.get("subtitle_bg")):
                    _bgop = max(0, min(100, int(body.get("subtitle_bg_opacity") if body.get("subtitle_bg_opacity") is not None else 60)))
                    _bgalpha = int((100 - _bgop) / 100.0 * 255)
                    _bgcolor = _hex_to_ass(body.get("subtitle_bg_color"), fallback="&H60000000", alpha=_bgalpha)
                    _bstyle, _outc, _backc = 3, _bgcolor, _bgcolor
                else:
                    _bstyle, _outc, _backc = 1, _sbcolor, "&H00000000"
                sub_style = ("Fontname=%s,FontSize=%d,Bold=1,PrimaryColour=%s,OutlineColour=%s,BackColour=%s,"
                             "BorderStyle=%d,Outline=%d,Shadow=1,Alignment=%d,MarginV=%d"
                             ) % (_sfont, _ssize, _scolor, _outc, _backc, _bstyle, _sborder, _align, marginv)
                # 🎞️ Legenda ANIMADA (opcional): preset do overlay Remotion (caption-service) na
                # allowlist, senão "" = legenda ASS queimada de sempre. O estilo segue CRU pro
                # serviço (hex + chave de fonte, NÃO os valores ASS) — lá ele é revalidado com a
                # mesma allowlist. accent = cor de realce da palavra ativa (só no modo animado).
                sub_anim = parse_sub_anim(body)
                sub_anim_style = build_sub_anim_style(body)
                MIN_DUR = 0.30   # duração mínima desejada de um cue (segundos)
                MAX_WORDS = 2    # no máximo 2 palavras por cue (legibilidade)
                # image_duration: duração (s) PADRÃO de cada slide quando NÃO há narração (slideshow
                # de imagens estáticas). Com narração, a duração é ditada pelo TTS (este valor é
                # ignorado). Cada beat pode sobrescrever com `duration` (ver o ramo sem narração).
                img_dur = float(body.get("image_duration") or 4.0)
                # fps ALVO da história = o nativo dos CLIPES de vídeo (i2v vêm a 24fps; forçar 30 DUPLICA
                # frames e gera judder). História só de slides (imagens) → 30fps (não há clipe pra casar).
                # Detecta no 1º clipe de vídeo, senão 30. (Mesmo fix do Filme /concat-clips.)
                FPS = 0
                for _b in beats:
                    if _b.get("clip_url"):
                        try:
                            _fp = "/tmp/sffps_%d.mp4" % ts
                            safe_fetch(_b.get("clip_url"), _fp)
                            _pr = subprocess.run(["ffprobe", "-v", "error", "-select_streams", "v:0",
                                                  "-show_entries", "stream=avg_frame_rate",
                                                  "-of", "default=noprint_wrappers=1:nokey=1", _fp],
                                                 capture_output=True, text=True)
                            _num, _, _den = (_pr.stdout.strip() or "30/1").partition("/")
                            _f = float(_num) / float(_den or "1")
                            FPS = int(round(_f)) if 1 < _f <= 60 else 30
                        except Exception:
                            FPS = 30
                        break
                if FPS == 0:
                    FPS = 30
                # F2 (paridade com o Filme): color-match opt-in — casa a exposição de cada cena
                # com a PRIMEIRA (o estilo/modelo varia o brilho cena a cena; isto converge).
                do_cmatch = bool(body.get("color_match"))
                ref_luma = None
                any_real_audio = False  # alguma cena com fala embutida? (muda o modo de concat)
                # F4: offsets acumulados de cada cena (pros SFX entrarem no tempo certo do Short).
                t_acc = 0.0
                sfx_jobs = []  # (offset_s, prompt, dur_s, gain)
                for i, b in enumerate(beats):
                    # Um beat é SLIDE (imagem) quando traz image_url e não clip_url → vira clipe via
                    # Ken Burns (loop + zoompan). Senão é um CLIPE de vídeo (clip_url), como antes.
                    is_image = bool(b.get("image_url")) and not b.get("clip_url")
                    src = "/tmp/sf_%d_%d.%s" % (ts, i, "jpg" if is_image else "mp4")
                    safe_fetch(b.get("image_url") or b.get("clip_url"), src)  # AUD-004 + AUD-007 (só storage interno)
                    clip = src  # nome legado usado adiante (ffprobe de duração quando é clipe de vídeo)
                    ap = None  # caminho do áudio TTS deste beat (None quando narração desligada)
                    srt = None  # caminho da legenda deste beat (None quando legenda desligada)
                    anim_words = None  # palavras com timing pro overlay animado (None = sem/estático)
                    if narration:
                        # ── NARRAÇÃO LIGADA (caminho atual): TTS com timestamps ──
                        # A duração do segmento (D) é ditada pela duração do áudio falado.
                        def _wt_call(fmt, _script=b.get("script", "")):
                            url = "https://api.elevenlabs.io/v1/text-to-speech/%s/with-timestamps" % voice_id
                            if fmt:
                                url += "?output_format=%s" % fmt
                            req = _u.Request(url,
                                data=json.dumps({"text": brand_say(_script), "model_id": tts_model,
                                                 "voice_settings": tts_settings}).encode(),
                                headers={"xi-api-key": el_key, "Content-Type": "application/json"}, method="POST")
                            with _u.urlopen(req, timeout=90) as r:
                                return json.loads(r.read())
                        try:
                            data = _wt_call(tts_format)
                        except Exception:
                            if not tts_format:
                                raise
                            data = _wt_call("")  # formato não liberado no plano → refaz no default
                        ap = "/tmp/sfa_%d_%d.mp3" % (ts, i)
                        open(ap, "wb").write(base64.b64decode(data["audio_base64"]))
                        al = data.get("alignment") or {}
                        chars = al.get("characters", []); st = al.get("character_start_times_seconds", []); et = al.get("character_end_times_seconds", [])
                        # O arquivo decodificado é a fonte de verdade do relógio da cena. Os
                        # timestamps do provedor servem às legendas, mas podem divergir do MP3 por
                        # atraso de encoder/stream. Se D vier deles, cada concat pode iniciar a
                        # próxima fala fora do tempo.
                        audio_dur = _seg_dur(ap, et[-1] if et else 5.0)
                        D = audio_dur + 0.35
                        if D < 2.5: D = 2.5
                        D += audio_delay  # o atraso da narração estica o segmento (vídeo cobre o silêncio inicial)
                        # PISO DE LEITURA: quando o slide traz TEXTO DESENHADO (carrossel editorial),
                        # a fala não pode ser o único relógio. Se a narração termina antes de dar pra
                        # LER o que está escrito na tela, o corte engole o slide — e o espectador não
                        # volta o Reels. `duration` é o tempo de leitura calculado por quem montou;
                        # aqui ele vira PISO, nunca teto: a narração continua mandando quando é mais
                        # longa. Ausente/0 ⇒ comportamento antigo intacto.
                        try:
                            piso = float(b.get("duration") or 0)
                        except (TypeError, ValueError):
                            piso = 0.0
                        if piso > D:
                            D = piso
                        if subtitles:
                            # Legenda WORD-LEVEL: cada palavra no start/end REAIS do alignment do TTS,
                            # batendo com o momento exato da fala (sincronia justa). Agrupa no MÁXIMO 2
                            # palavras e só quando a palavra é muito curta (< MIN_DUR), pra evitar cues
                            # que piscam rápido demais. O grupo herda start da 1ª e end da última palavra.
                            words = []; cur = ""; cs = None; ce = None
                            for j, ch in enumerate(chars):
                                if ch.strip() == "":
                                    if cur: words.append((cur, cs, ce)); cur = ""; cs = None
                                else:
                                    if cs is None: cs = st[j] if j < len(st) else 0
                                    cur += ch; ce = et[j] if j < len(et) else cs
                            if cur: words.append((cur, cs, ce))
                            if sub_anim:
                                # overlay animado: tempos REAIS por palavra (mesmo deslocamento dos cues)
                                anim_words = [{"text": brand_caption(w[0].upper()),
                                               "start": max(0.0, (w[1] or 0.0) + cue_shift),
                                               "end": max((w[1] or 0.0) + cue_shift + 0.05,
                                                          (w[2] or (w[1] or 0.0)) + cue_shift)}
                                              for w in words if w[0]]
                            srt = "/tmp/sfsrt_%d_%d.srt" % (ts, i); cues = []; idx = 1
                            groups = []; g = []
                            for w in words:
                                g.append(w)
                                gstart = g[0][1]; gend = g[-1][2]
                                # fecha o grupo quando já tem duração suficiente OU atingiu o limite de palavras
                                if (gend - gstart) >= MIN_DUR or len(g) >= MAX_WORDS:
                                    groups.append(g); g = []
                            if g:  # sobra (última palavra curta) — junta no grupo anterior se houver, senão vira cue própria
                                if groups:
                                    groups[-1].extend(g)
                                else:
                                    groups.append(g)
                            for gi, grp in enumerate(groups):
                                gstart = grp[0][1]; gend = grp[-1][2]
                                # estica o end até o start do próximo cue (sem ultrapassá-lo) para não piscar entre palavras
                                if gi + 1 < len(groups):
                                    gend = max(gend, groups[gi + 1][0][1])
                                # sincronismo: desloca o cue junto com o áudio (audio_delay) + o ajuste fino (sub_offset)
                                gs = max(0.0, gstart + cue_shift); ge = max(gs + 0.05, gend + cue_shift)
                                cues.append("%d\n%s --> %s\n%s\n" % (idx, _t(gs), _t(ge), brand_caption(" ".join(w[0] for w in grp).upper()))); idx += 1
                            if not cues:
                                cues = ["1\n%s --> %s\n%s\n" % (_t(max(0.0, cue_shift)), _t(D), (b.get("script", "") or "").upper())]
                            open(srt, "w").write("\n".join(cues))
                    else:
                        # ── NARRAÇÃO DESLIGADA: sem TTS (pula ElevenLabs) ──
                        # Duração do segmento: imagem → img_dur (slide estático); clipe de vídeo →
                        # duração REAL do clipe (ffprobe). Sem voz no segmento.
                        # `duration` POR BEAT tem prioridade sobre o image_duration global. Slideshow
                        # de texto não tolera duração uniforme: no carrossel editorial a capa tem ≤12
                        # palavras e um slide interno chega a 32 — com o mesmo tempo, ou a capa se
                        # arrasta ou o interno fica ilegível. Quem monta calcula o tempo de leitura de
                        # cada slide e manda aqui. Ausente ⇒ img_dur (comportamento antigo intacto).
                        D = float(b.get("duration") or 0) or (img_dur if is_image else _seg_dur(clip))
                        if D < 2.5: D = 2.5
                        if subtitles:
                            # Sem alignment do TTS → timing ESTIMADO: distribui as palavras do `script`
                            # uniformemente em [0, D], 1-2 palavras por cue (mesma regra de legibilidade),
                            # com os tempos calculados por divisão igual.
                            raw_words = (b.get("script", "") or "").split()
                            if sub_anim and raw_words:
                                # overlay animado sem narração: cada PALAVRA numa fatia igual de [0, D]
                                stepw = D / len(raw_words)
                                anim_words = [{"text": rw.upper(),
                                               "start": max(0.0, k * stepw + sub_offset),
                                               "end": max(max(0.0, k * stepw + sub_offset) + 0.05,
                                                          (k + 1) * stepw + sub_offset)}
                                              for k, rw in enumerate(raw_words)]
                            srt = "/tmp/sfsrt_%d_%d.srt" % (ts, i); cues = []; idx = 1
                            # agrupa de 2 em 2 palavras (legibilidade: MAX_WORDS)
                            groups = [raw_words[k:k + MAX_WORDS] for k in range(0, len(raw_words), MAX_WORDS)]
                            n_groups = len(groups)
                            if n_groups > 0:
                                step = D / n_groups  # cada grupo ocupa uma fatia igual da duração do clipe
                                for gi, grp in enumerate(groups):
                                    # sem narração só o ajuste fino da legenda se aplica (não há áudio a atrasar)
                                    gstart = max(0.0, gi * step + sub_offset)
                                    gend = max(gstart + 0.05, (gi + 1) * step + sub_offset)
                                    cues.append("%d\n%s --> %s\n%s\n" % (idx, _t(gstart), _t(gend), " ".join(grp).upper())); idx += 1
                            if cues:
                                open(srt, "w").write("\n".join(cues))
                            else:
                                srt = None  # script vazio → nada a legendar
                    # Monta o segmento. O filtro de vídeo é o mesmo (pad+trim+crop 9:16); a legenda
                    # só entra quando há .srt (subtitles ligado e com conteúdo).
                    seg = "/tmp/sfseg_%d_%d.mp4" % (ts, i)
                    # F2 color-match: a 1ª cena define a referência de exposição; as demais ganham
                    # correção de brilho (clamp ±0.12). Falha no probe → sem correção (seguro).
                    cm = ""
                    if do_cmatch:
                        lum = avg_luma(src)
                        if i == 0:
                            ref_luma = lum
                        else:
                            cm = color_match_eq(ref_luma, lum)
                    if is_image and (b.get("still") or b.get("zoom_from")):
                        # REVELADO POR CAMADAS (estilo Vox): um slide vira VÁRIOS beats — o mesmo
                        # fundo com um bloco de texto a mais em cada. Dois cuidados aqui:
                        #
                        # 1. O zoompan padrão REINICIA em 1.0 a cada beat, então o slide dava um
                        #    salto pra trás toda vez que uma camada entrava. Com `zoom_from`/
                        #    `zoom_to` o movimento CONTINUA de onde o beat anterior parou, e o
                        #    slide inteiro vira um único zoom lento e contínuo — o "lente com
                        #    zoom" que o estilo usa por baixo de tudo.
                        # 2. `still` (sem zoom nenhum) fica como escape: fundo já muito fechado,
                        #    ou peça em que o zoom compete com a leitura.
                        z0 = float(b.get("zoom_from") or 0) or 1.0
                        z1 = float(b.get("zoom_to") or 0) or z0
                        if b.get("still") or abs(z1 - z0) < 0.0005:
                            vf = (cm + "scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,setsar=1"
                                  ) % (OUT_W, OUT_H, OUT_W, OUT_H)
                        else:
                            frames = max(2, int(round(D * FPS)))
                            # zoom linear de z0 a z1 ao longo dos frames DESTE beat: 'on' é o índice
                            # do frame de saída, então a rampa é contínua entre beats vizinhos.
                            vf = (cm + "scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,scale=%d:-2,"
                                  "zoompan=z='%.5f+(%.5f)*on/%d':d=%d:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=%dx%d:fps=%d,"
                                  "setsar=1") % (OUT_W, OUT_H, OUT_W, OUT_H, OUT_W * 4,
                                                 z0, z1 - z0, frames, frames, OUT_W, OUT_H, FPS)
                    elif is_image:
                        # SLIDE: imagem estática → clipe de D segundos com Ken Burns (zoom-in suave).
                        # O upscale (scale grande) ANTES do zoompan elimina o jitter clássico do filtro;
                        # o crop garante cobertura 9:16/16:9 antes do zoom.
                        frames = max(1, int(round(D * FPS)))
                        vf = (cm + "scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,scale=%d:-2,"
                              "zoompan=z='min(zoom+0.0012,1.12)':d=%d:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=%dx%d:fps=%d,"
                              "setsar=1") % (OUT_W, OUT_H, OUT_W, OUT_H, OUT_W * 4, frames, OUT_W, OUT_H, FPS)
                    else:
                        # setsar=1 TAMBÉM no clipe: o ramo do slide já normaliza o SAR e o concat -c copy
                        # exige streams homogêneos — clipe i2v sem SAR fixado gera MP4 com descontinuidade
                        # (player congela no 1º segmento com o áudio seguindo) quando há cena slide no meio.
                        # ✂️ CABEÇA DESCARTADA: o clipe i2v abre com a imagem-base PARADA (o motor
                        # leva um instante pra engatar o movimento). Cortar a cabeça faz o plano
                        # entrar já em movimento. O tpad ANTES do trim garante material suficiente
                        # mesmo quando ht+D passa da duração real do clipe (clona o último quadro).
                        # Ausente/0 ⇒ trim=0:D, comportamento histórico intacto.
                        ht = head_trim_secs(b.get("head_trim"))
                        vf = ("tpad=stop_mode=clone:stop_duration=12,trim=%.2f:%.2f,setpts=PTS-STARTPTS," % (ht, ht + D)
                              + cm + "scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,setsar=1" % (OUT_W, OUT_H, OUT_W, OUT_H))
                        # ✂️ RITMO DE CORTE: quem monta pede o tamanho de plano que quer (shot_secs)
                        # e a cena é recortada em K planos do MESMO clipe (ver build_shot_cuts).
                        # Ausente/0 ⇒ plano único, comportamento histórico intacto.
                        vf += build_shot_cuts(D, b.get("shot_secs"), OUT_W, OUT_H)
                    # 🎬 CUT STING (Vox): push+blur nas bordas com pico no corte (ver cut_sting_vf).
                    # ANTES da legenda: o texto fica estável e legível por cima do gesto de câmera.
                    if body.get("cut_sting"):
                        vf += cut_sting_vf(D, OUT_W, OUT_H, FPS, i > 0, i < len(beats) - 1)
                    if srt and not sub_anim:
                        # modo estático: queima no vf. Modo animado: overlay DEPOIS do build/VFX
                        # (o .srt fica guardado como fallback se o caption-service falhar).
                        vf += ",subtitles=%s:force_style='%s'" % (srt, sub_style)
                    # Input de vídeo: imagem precisa de -loop 1 -t D (vira fonte de duração D); clipe é -i direto.
                    vin = ["-loop", "1", "-t", "%.2f" % D, "-i", src] if is_image else ["-i", clip]
                    # CONSISTÊNCIA DE ÁUDIO PARA O CONCAT: o concat -c copy só funde streams com o
                    # MESMO layout. Por isso TODO segmento tem SEMPRE exatamente UMA faixa de áudio:
                    #  - narração ligada  → a voz TTS (mapeada do input de áudio);
                    #  - narração desligada → faixa SILENCIOSA gerada com anullsrc (a música, quando
                    #    ligada, é mixada DEPOIS sobre o vídeo concatenado, não por segmento).
                    # Assim nunca há mistura de "segmento com áudio" + "segmento sem áudio" no concat.
                    if narration and ap:
                        # apad: preenche a voz com silêncio até D, pra a faixa de áudio ter EXATAMENTE
                        # a mesma duração do vídeo (D). Sem isso a voz (≈D-0.35s) fica mais curta que o
                        # vídeo e o concat -c copy faz o áudio/legenda DERIVAREM pra frente, cena a cena
                        # (dessincronia acumulada — a voz começa antes do vídeo nas cenas seguintes).
                        # Com vídeo=áudio=D em todo segmento, voz e legenda começam junto com o vídeo.
                        # audio_delay > 0: adelay empurra o início da voz (o vídeo aparece primeiro).
                        af = "aresample=async=1:first_pts=0,asetpts=N/SR/TB,"
                        af += "adelay=%d:all=1," % int(round(audio_delay * 1000)) if audio_delay > 0 else ""
                        af += "apad,atrim=duration=%.3f" % D
                        cmd = ["ffmpeg", "-y"] + vin + ["-i", ap, "-filter_complex", "[0:v]" + vf + "[v];[1:a]" + af + "[a]",
                               "-map", "[v]", "-map", "[a]", "-t", "%.2f" % D, "-c:v", "libx264",
                               "-pix_fmt", "yuv420p", "-r", str(FPS), "-c:a", "aac", "-ar", "44100", seg]
                    else:
                        # anullsrc = faixa de áudio silenciosa (estéreo, 44.1k) só pra manter o layout
                        # uniforme; o "-shortest" corta o silêncio na duração D do vídeo.
                        cmd = ["ffmpeg", "-y"] + vin + ["-f", "lavfi", "-i", "anullsrc=channel_layout=stereo:sample_rate=44100",
                               "-filter_complex", "[0:v]" + vf + "[v]",
                               "-map", "[v]", "-map", "1:a:0", "-t", "%.2f" % D, "-shortest", "-c:v", "libx264",
                               "-pix_fmt", "yuv420p", "-r", str(FPS), "-c:a", "aac", "-ar", "44100", seg]
                    r = subprocess.run(cmd, capture_output=True, text=True)
                    if r.returncode != 0:
                        self._respond(500, {"error": "beat %d: %s" % (i, r.stderr[-400:])}); return
                    segs.append(seg)
                    # F4 — VFX/overlay POR CENA (best-effort, duração preservada): shake, glitch,
                    # vhs, zoom_pulse, punch_in, freeze e/ou overlay de partículas (fundo preto,
                    # blend=screen). O SFX da cena entra no MIX FINAL no offset acumulado dela.
                    ovp = None
                    if b.get("overlay_url"):
                        try:
                            ovp = "/tmp/sfov_%d_%d.mp4" % (ts, i)
                            safe_fetch(str(b.get("overlay_url")), ovp)  # AUD-004/007 (só storage interno)
                        except Exception:
                            ovp = None
                    if b.get("vfx") or ovp:
                        apply_vfx(seg, b.get("vfx"), D, FPS, OUT_W, OUT_H, overlay_path=ovp)
                    # 🎞️ Legenda ANIMADA: compõe DEPOIS do VFX (legenda estável sobre shake/glitch).
                    # Qualquer falha no caption-service → queima a MESMA legenda ASS de sempre no
                    # segmento pronto (o short NUNCA sai sem legenda por causa do serviço novo).
                    if sub_anim and subtitles:
                        cap_ok = bool(anim_words) and caption_overlay(
                            seg, anim_words, D, FPS, OUT_W, OUT_H, sub_anim, sub_anim_style,
                            "sfcap_%d_%d" % (ts, i))
                        if not cap_ok and srt:
                            tmpb = seg + ".sub.mp4"
                            rb = subprocess.run(["ffmpeg", "-y", "-i", seg,
                                                 "-vf", "subtitles=%s:force_style='%s'" % (srt, sub_style),
                                                 "-c:v", "libx264", "-pix_fmt", "yuv420p", "-c:a", "copy", tmpb],
                                                capture_output=True, text=True)
                            if rb.returncode == 0:
                                os.replace(tmpb, seg)
                    sfx_p = (b.get("sfx") or "").strip() if isinstance(b.get("sfx"), str) else ""
                    if sfx_p:
                        try:
                            sfx_g = float(b.get("sfx_gain") or 0.9)
                        except Exception:
                            sfx_g = 0.9
                        sfx_jobs.append((t_acc, sfx_p, min(D, 8.0), max(0.1, min(1.5, sfx_g))))
                    t_acc += D
                # 🎬 Transições (F1): no shortform SÓ modo duração-preservada (fadeblack/fadewhite)
                # — a narração é por segmento, sobrepor (xfade) cortaria fala. Kinds xfade degradam.
                tr_cuts, tr_dur = parse_transitions(body, len(segs), allow_xfade=False)
                if tr_cuts:
                    apply_seg_fades(segs, tr_cuts, tr_dur)
                listp = "/tmp/sfl_%d.txt" % ts
                with open(listp, "w") as lf:
                    for s in segs:
                        lf.write("file '%s'\n" % s)
                out_filename = "shortform_%d.mp4" % ts
                out_path = "%s/videos/%s" % (MEDIA_BASE, out_filename)
                r = subprocess.run(["ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", listp, "-c", "copy", out_path], capture_output=True, text=True)
                if r.returncode != 0:
                    self._respond(500, {"error": "concat: " + r.stderr[-300:]}); return
                # Áudio musical (best-effort) mixado sob a voz/silêncio:
                #  - sung_narration=True → a voz CANTA o ROTEIRO (letra = script de cada cena) via Eleven
                #    Music composition_plan (uma seção por cena, duração = duração do segmento). Vira o áudio.
                #  - music=True (sem sung_narration) → trilha INSTRUMENTAL de fundo, baixa, sob a narração falada.
                # Quando ambos False, a etapa é PULADA.
                sung_narration = bool(body.get("sung_narration"))
                lang = str(body.get("lang") or "pt-BR")
                # Sprint B (acabamento Hollywood): grade/grain force um re-encode do vídeo mesmo
                # sem música — loudnorm entra SEMPRE que este passo roda (é o único ponto de mix
                # final do /shortform).
                # F2 (paridade com o Filme): + ambience opcional, DUCKING real da trilha/ambiente sob a
                # narração (sidechaincompress; a chave é [0:a] — a voz TTS já está embutida por segmento)
                # e SFX por cena (F4) entrando no offset acumulado de cada uma.
                finish_vf = build_finish_filters(body, OUT_W, OUT_H)
                ambience_prompt = (body.get("ambience_prompt") or "").strip()
                if music or sung_narration or finish_vf or ambience_prompt or sfx_jobs:
                    try:
                        mpath = None
                        mvol = "0.16"
                        if music or sung_narration:
                            if sung_narration:
                                voc = "clear warm female lead vocal singing in Brazilian Portuguese" if lang.lower().startswith("pt") else "clear warm female lead vocal"
                                sections = []
                                for i, sg in enumerate(segs):
                                    script = (beats[i].get("script") or "").strip() if i < len(beats) else ""
                                    # letra = roteiro da cena; <=180 chars/linha (limite 200), <=30 linhas.
                                    lines = [script[k:k + 180] for k in range(0, len(script), 180)][:30] or ["..."]
                                    sections.append({"section_name": "Cena %d" % (i + 1), "positive_local_styles": [], "negative_local_styles": [], "duration_ms": max(3000, int(_seg_dur(sg) * 1000)), "lines": lines})
                                payload = {"model_id": "music_v1", "composition_plan": {"positive_global_styles": [voc, "melodic modern pop", "emotional", "catchy"], "negative_global_styles": ["instrumental", "no vocals", "spoken word", "rap"], "sections": sections}}
                            else:
                                durj = subprocess.run(["ffprobe", "-v", "quiet", "-print_format", "json", "-show_format", out_path], capture_output=True, text=True)
                                dur = float(json.loads(durj.stdout)["format"]["duration"])
                                ms = max(10000, min(300000, int(dur * 1000)))
                                mp = body.get("music_prompt") or "uplifting modern instrumental background music, subtle, social media vibe, energetic, no vocals, no lyrics"
                                payload = {"prompt": mp, "music_length_ms": ms, "model_id": "music_v1"}
                            mreq = _u.Request("https://api.elevenlabs.io/v1/music", data=json.dumps(payload).encode(), headers={"xi-api-key": el_key, "Content-Type": "application/json"}, method="POST")
                            mpath = "/tmp/sfmusic_%d.mp3" % ts
                            with _u.urlopen(mreq, timeout=180) as mr, open(mpath, "wb") as mf:
                                mf.write(mr.read())
                        # 🔉 AMBIENCE (F2, best-effort): camada de som-ambiente sob tudo (chuva, rua...).
                        apath = None
                        if ambience_prompt:
                            try:
                                durj = subprocess.run(["ffprobe", "-v", "quiet", "-print_format", "json", "-show_format", out_path], capture_output=True, text=True)
                                dur = float(json.loads(durj.stdout)["format"]["duration"])
                                ams = max(10000, min(300000, int(dur * 1000)))
                                sp = "ambient background sound and room tone (no music, no melody, no beat): " + ambience_prompt
                                sreq = _u.Request("https://api.elevenlabs.io/v1/music", data=json.dumps({"prompt": sp, "music_length_ms": ams, "model_id": "music_v1"}).encode(), headers={"xi-api-key": el_key, "Content-Type": "application/json"}, method="POST")
                                apath = "/tmp/sfamb_%d.mp3" % ts
                                with _u.urlopen(sreq, timeout=180) as sr, open(apath, "wb") as sf:
                                    sf.write(sr.read())
                            except Exception:
                                apath = None
                        # 🔊 SFX por cena (F4, best-effort): um mp3 curto por cena com SFX pedido.
                        sfx_ready = []  # (offset_s, path, gain)
                        for k, (off, prompt, sdur, gain) in enumerate(sfx_jobs):
                            p = gen_sfx(prompt, sdur, el_key, ts, k)
                            if p:
                                sfx_ready.append((off, p, gain))
                        inputs = ["-i", out_path]
                        fc = []
                        amix_labels = ["[0:a]"]
                        # DUCKING: trilha e ambiente abaixam sob a voz (chave = [0:a], onde a narração
                        # vive). Sem narração (ou narração cantada) → volumes fixos, sem sidechain.
                        duck_layers = ((1 if (mpath and narration and not sung_narration) else 0)
                                       + (1 if (apath and narration) else 0))
                        keys = []
                        if duck_layers > 0:
                            labels = "[abase]" + "".join("[ak%d]" % k for k in range(duck_layers))
                            fc.append("[0:a]asplit=%d%s" % (duck_layers + 1, labels))
                            amix_labels = ["[abase]"]
                            keys = ["[ak%d]" % k for k in range(duck_layers)]
                        if mpath:
                            inputs += ["-i", mpath]
                            mi = len(inputs) // 2 - 1
                            if sung_narration:
                                fc.append("[%d:a]aformat=channel_layouts=stereo,volume=1.0[m]" % mi)
                            elif narration and keys:
                                # base mais alta (0.7): o ducking abaixa dinamicamente sob a voz.
                                fc.append("[%d:a]aformat=channel_layouts=stereo,extrastereo=m=1.4,volume=0.7[mw]" % mi)
                                fc.append("[mw]%ssidechaincompress=threshold=0.02:ratio=8:attack=15:release=320[m]" % keys.pop(0))
                            else:
                                fc.append("[%d:a]aformat=channel_layouts=stereo,volume=%s[m]" % (mi, mvol if narration else "0.9"))
                            amix_labels.append("[m]")
                        if apath:
                            inputs += ["-i", apath]
                            ai = len(inputs) // 2 - 1
                            if narration and keys:
                                fc.append("[%d:a]aformat=channel_layouts=stereo,extrastereo=m=1.5,volume=0.22[aw]" % ai)
                                fc.append("[aw]%ssidechaincompress=threshold=0.02:ratio=6:attack=15:release=320[amb]" % keys.pop(0))
                            else:
                                fc.append("[%d:a]aformat=channel_layouts=stereo,extrastereo=m=1.5,volume=0.12[amb]" % ai)
                            amix_labels.append("[amb]")
                        for k, (off, p, gain) in enumerate(sfx_ready):
                            inputs += ["-i", p]
                            si = len(inputs) // 2 - 1
                            fc.append("[%d:a]adelay=%d:all=1,aformat=channel_layouts=stereo,volume=%.2f[fx%d]" % (si, int(round(off * 1000)), gain, k))
                            amix_labels.append("[fx%d]" % k)
                        fc.append("%samix=inputs=%d:duration=first:dropout_transition=0:normalize=0[am];[am]%s[a]" % ("".join(amix_labels), len(amix_labels), LOUDNORM))
                        vmap, venc = "0:v", ["-c:v", "copy"]
                        if finish_vf:
                            fc.insert(0, "[0:v]" + ",".join(finish_vf) + "[v]")
                            vmap, venc = "[v]", ["-c:v", "libx264", "-pix_fmt", "yuv420p", "-r", str(FPS)]
                        mixed_fn = "shortform_%d_m.mp4" % ts
                        mixed = "%s/videos/%s" % (MEDIA_BASE, mixed_fn)
                        rm = subprocess.run(["ffmpeg", "-y"] + inputs + ["-filter_complex", ";".join(fc), "-map", vmap, "-map", "[a]"] + venc + ["-c:a", "aac", "-ar", "44100", mixed], capture_output=True, text=True)
                        if rm.returncode == 0 and os.path.exists(mixed) and os.path.getsize(mixed) > 0:
                            out_filename = mixed_fn
                    except Exception:
                        pass
                # 🏷️ ENDCARD (F2, paridade com o Filme): cartela final ~2s com o logo/CTA (imagem
                # pronta do cliente). Best-effort: falha → Short sai sem a cartela.
                endcard_url = str(body.get("endcard_url") or "").strip()
                if endcard_url:
                    try:
                        ec_src = "/tmp/sfec_%d.bin" % ts
                        safe_fetch(endcard_url, ec_src)  # AUD-004 + AUD-007 (só storage interno)
                        ec_clip = "/tmp/sfecv_%d.mp4" % ts
                        ec_vf = "scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:black,fps=%d,setsar=1" % (OUT_W, OUT_H, OUT_W, OUT_H, FPS)
                        rec = subprocess.run(["ffmpeg", "-y", "-loop", "1", "-t", "2", "-i", ec_src,
                                              "-f", "lavfi", "-i", "anullsrc=channel_layout=stereo:sample_rate=44100",
                                              "-filter_complex", "[0:v]" + ec_vf + "[v]",
                                              "-map", "[v]", "-map", "1:a:0", "-shortest",
                                              "-c:v", "libx264", "-pix_fmt", "yuv420p", "-r", str(FPS), "-c:a", "aac", "-ar", "44100", ec_clip],
                                             capture_output=True, text=True)
                        if rec.returncode == 0:
                            final_src = "%s/videos/%s" % (MEDIA_BASE, out_filename)
                            ec_out_fn = "shortform_%d_ec.mp4" % ts
                            ec_out = "%s/videos/%s" % (MEDIA_BASE, ec_out_fn)
                            rc = subprocess.run(["ffmpeg", "-y", "-i", final_src, "-i", ec_clip,
                                                 "-filter_complex", "[0:v][0:a][1:v][1:a]concat=n=2:v=1:a=1[v][a]",
                                                 "-map", "[v]", "-map", "[a]", "-c:v", "libx264", "-pix_fmt", "yuv420p", "-r", str(FPS), "-c:a", "aac", "-ar", "44100", ec_out],
                                                capture_output=True, text=True)
                            if rc.returncode == 0 and os.path.exists(ec_out) and os.path.getsize(ec_out) > 0:
                                out_filename = ec_out_fn
                    except Exception:
                        pass
                # 🌊 FLUIDEZ (F2, paridade com o Filme): minterpolate 2× adaptativo por duração
                # (mesmo orçamento/regra do /concat-clips). Best-effort.
                if bool(body.get("smooth")):
                    try:
                        sm_fps = min(FPS * 2, 48)
                        if sm_fps > FPS:
                            sm_src = "%s/videos/%s" % (MEDIA_BASE, out_filename)
                            durj = subprocess.run(["ffprobe", "-v", "quiet", "-print_format", "json", "-show_format", sm_src],
                                                  capture_output=True, text=True)
                            smdur = float(json.loads(durj.stdout)["format"]["duration"])
                            SM_BUDGET = 380
                            mi_f = "minterpolate=fps=%d:mi_mode=mci:mc_mode=obmc:me=epzs" % sm_fps
                            if smdur * 14.5 <= SM_BUDGET:
                                sm_vf = mi_f
                            elif smdur * 7.5 <= SM_BUDGET:
                                sw, sh = (1280, 720) if OUT_W > OUT_H else (720, 1280)
                                sm_vf = "scale=%d:%d,%s,scale=%d:%d" % (sw, sh, mi_f, OUT_W, OUT_H)
                            else:
                                sm_vf = ""
                            if sm_vf:
                                sm_fn = "shortform_%d_s.mp4" % ts
                                sm_out = "%s/videos/%s" % (MEDIA_BASE, sm_fn)
                                rs = subprocess.run(["ffmpeg", "-y", "-i", sm_src, "-vf", sm_vf,
                                                     "-c:v", "libx264", "-pix_fmt", "yuv420p", "-c:a", "copy", sm_out],
                                                    capture_output=True, text=True, timeout=SM_BUDGET + 40)
                                if rs.returncode == 0 and os.path.exists(sm_out) and os.path.getsize(sm_out) > 0:
                                    out_filename = sm_fn
                    except Exception:
                        pass
                self._respond(200, {"video_url": _mk_url(out_filename), "filename": out_filename})
            except Exception as e:
                self._respond(500, {"error": str(e)})

        elif self.path == "/concat-clips":
            # FILME CONTÍNUO (plano-sequência): concatena clipes JÁ contínuos (keyframes
            # compartilhados) na ordem, normalizados (mesma resolução/fps + 1 faixa de áudio
            # uniforme), SEM transição — a continuidade visual vem dos keyframes. music=true →
            # trilha instrumental PROTAGONISTA (o filme não tem narração por default).
            try:
                import urllib.request as _u
                clips = [u for u in (body.get("clip_urls") or []) if isinstance(u, str) and u]
                if not clips:
                    self._respond(400, {"error": "sem clipes"})
                    return
                aspect = body.get("aspect", "9:16")
                OUT_W, OUT_H = (1920, 1080) if aspect == "16:9" else (1080, 1920)
                el_key = os.environ.get("ELEVENLABS_API_KEY", "")
                ts = int(time.time() * 1000)
                segs = []
                # fps NATIVO dos clipes (detectado no 1º; todos vêm do mesmo modelo de vídeo).
                # ⚠️ Normalizar pra um fps DIFERENTE do nativo (ex: forçar 30 sobre um clipe de 24)
                # DUPLICA frames (~6/s no caso 24→30) e gera travadinha/judder CONSTANTE no filme
                # inteiro (não só na junção). Por isso conformamos ao fps real, sem upconversion.
                FPS = 0
                N = len(clips)
                # COLOR-MATCH (S3, opt-in): casa a exposição de cada trecho contra o PRIMEIRO clipe —
                # os modelos i2v re-comprimem e a cor/brilho deriva ao longo do plano-sequência. Só
                # quando body['color_match'] é true (default off = comportamento antigo intacto).
                do_cmatch = bool(body.get("color_match"))
                # 🎬 pad_fit: encaixa o clipe INTEIRO no quadro com barras (contain+pad) em vez de
                # cortar (cover+crop). Usado no lip-sync PER-FALA — os talking-heads são retratos
                # 3:4 e o crop pra 16:9 comia o ROSTO (a boca sincronizada some). Com pad, o rosto
                # sempre aparece. (Padrão false = cover+crop, comportamento do Filme intacto.)
                pad_fit = bool(body.get("pad_fit"))
                ref_luma = None
                # 🎬 Alguma cena com fala embutida? (Estúdio de Animação: clipe de lip-sync/mux traz
                # áudio dentro). Decide o modo de concat lá embaixo. Inicializado ANTES do loop —
                # senão o mix de clipes com/sem áudio quebrava com UnboundLocalError.
                any_real_audio = False
                K = 3  # frames dropados por lado nas JUNÇÕES: os modelos i2v DESACELERAM pra bater o
                       # keyframe compartilhado no fim de cada trecho (e começam devagar no próximo) → o
                       # movimento quase para na junção (o "travadinho"). Cortar os frames de quase-parada
                       # de cada lado mantém a cadência contínua. Não corta o início do 1º nem o fim do último.
                for i, cu in enumerate(clips):
                    src = "/tmp/fc_%d_%d.mp4" % (ts, i)
                    safe_fetch(cu, src)  # AUD-004 + AUD-007 (só storage interno)
                    if FPS == 0:
                        try:
                            pr = subprocess.run(["ffprobe", "-v", "error", "-select_streams", "v:0",
                                                 "-show_entries", "stream=avg_frame_rate",
                                                 "-of", "default=noprint_wrappers=1:nokey=1", src],
                                                capture_output=True, text=True)
                            num, _, den = (pr.stdout.strip() or "30/1").partition("/")
                            f = float(num) / float(den or "1")
                            FPS = int(round(f)) if 1 < f <= 60 else 30
                        except Exception:
                            FPS = 30
                    # nº de frames do clipe (pro trim das junções); 0 = não corta (fallback seguro).
                    try:
                        pf = subprocess.run(["ffprobe", "-v", "error", "-count_frames", "-select_streams", "v:0",
                                             "-show_entries", "stream=nb_read_frames",
                                             "-of", "default=noprint_wrappers=1:nokey=1", src],
                                            capture_output=True, text=True)
                        nbf = int(pf.stdout.strip() or "0")
                    except Exception:
                        nbf = 0
                    # 🎬 Fala embutida? (Estúdio de Animação) — decide o trim de junção E o modo de
                    # concat lá embaixo. Probe ANTES do trim: cortar K frames de um clipe COM fala
                    # comia o começo do diálogo de toda cena a partir da 2ª (dessincronizava).
                    try:
                        pa0 = subprocess.run(["ffprobe", "-v", "error", "-select_streams", "a:0",
                                              "-show_entries", "stream=codec_type",
                                              "-of", "default=noprint_wrappers=1:nokey=1", src],
                                             capture_output=True, text=True)
                        has_audio = "audio" in (pa0.stdout or "")
                    except Exception:
                        has_audio = False
                    any_real_audio = any_real_audio or has_audio
                    # Trim das junções: dropa K frames do lado que encosta em outro trecho. Só se o clipe
                    # é longo o bastante (evita comer clipe curto) e NÃO tem fala embutida (o trim foi
                    # desenhado pro Filme mudo — remove a quase-parada visual; com diálogo, corta fala).
                    tr = ""
                    if N > 1 and nbf > (2 * K + 6) and not has_audio:
                        ds = i > 0           # tem junção ANTES → dropa o início
                        de = i < N - 1       # tem junção DEPOIS → dropa o fim
                        if ds:
                            tr += "trim=start_frame=%d,setpts=PTS-STARTPTS," % K
                        if de:
                            tr += "trim=end_frame=%d,setpts=PTS-STARTPTS," % ((nbf - 2 * K) if ds else (nbf - K))
                    # COLOR-MATCH (S3): o 1º clipe define a referência de exposição; os seguintes
                    # ganham uma correção de brilho (clamp ±0.12) pra convergir. Falha no probe → sem
                    # correção naquele clipe (seguro).
                    cm = ""
                    if do_cmatch:
                        lum = avg_luma(src)
                        if i == 0:
                            ref_luma = lum
                        else:
                            cm = color_match_eq(ref_luma, lum)
                    seg = "/tmp/fcseg_%d_%d.mp4" % (ts, i)
                    # Normaliza pro concat -c copy: (trim das junções) + (color-match) + resolução/pix_fmt
                    # + fps NATIVO (sem duplicar frames) + 1 faixa de áudio uniforme (aac 44100 estéreo).
                    # 🎬 Estúdio de Animação (2026-07-12): o áudio REAL do clipe é PRESERVADO quando
                    # existe (o diálogo por cena vive muxado no clipe — antes era descartado e trocado
                    # por silêncio, e o desenho "saía sem fala"). Clipe mudo (Filme) = anullsrc, como era.
                    if pad_fit:
                        fit = "scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:color=black,fps=%d,setsar=1" % (OUT_W, OUT_H, OUT_W, OUT_H, FPS)
                    else:
                        fit = "scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,fps=%d,setsar=1" % (OUT_W, OUT_H, OUT_W, OUT_H, FPS)
                    vf = tr + cm + fit
                    if has_audio:
                        # atrim espelha o trim de vídeo das junções (mesmos K frames, em segundos).
                        atr = ""
                        if tr:
                            a_s = (float(K) / FPS) if (i > 0) else 0.0
                            a_e = ((float(nbf - K) / FPS)) if (i < N - 1) else None
                            parts_tr = []
                            if a_s > 0:
                                parts_tr.append("start=%.4f" % a_s)
                            if a_e is not None:
                                parts_tr.append("end=%.4f" % a_e)
                            if parts_tr:
                                atr = "atrim=" + ":".join(parts_tr) + ",asetpts=PTS-STARTPTS,"
                        af = "[0:a]aresample=async=1:first_pts=0,asetpts=N/SR/TB," + atr + "aformat=channel_layouts=stereo,aresample=44100,apad[a]"
                        r = subprocess.run(["ffmpeg", "-y", "-i", src,
                                            "-filter_complex", "[0:v]" + vf + "[v];" + af,
                                            "-map", "[v]", "-map", "[a]", "-shortest",
                                            "-c:v", "libx264", "-pix_fmt", "yuv420p", "-r", str(FPS), "-c:a", "aac", "-ar", "44100", seg],
                                           capture_output=True, text=True)
                    else:
                        r = subprocess.run(["ffmpeg", "-y", "-i", src, "-f", "lavfi", "-i", "anullsrc=channel_layout=stereo:sample_rate=44100",
                                            "-filter_complex", "[0:v]" + vf + "[v]",
                                            "-map", "[v]", "-map", "1:a:0", "-shortest",
                                            "-c:v", "libx264", "-pix_fmt", "yuv420p", "-r", str(FPS), "-c:a", "aac", "-ar", "44100", seg],
                                           capture_output=True, text=True)
                    if r.returncode != 0:
                        self._respond(500, {"error": "clipe %d: %s" % (i, r.stderr[-400:])}); return
                    segs.append(seg)
                    # F4 — VFX/overlay POR TRECHO (best-effort, duração preservada), igual às Histórias.
                    vfx_list = body.get("vfx") if isinstance(body.get("vfx"), list) else []
                    ov_list = body.get("overlay_urls") if isinstance(body.get("overlay_urls"), list) else []
                    vk = str(vfx_list[i]) if i < len(vfx_list) and vfx_list[i] else ""
                    ovu = str(ov_list[i]) if i < len(ov_list) and ov_list[i] else ""
                    ovp = None
                    if ovu:
                        try:
                            ovp = "/tmp/fcov_%d_%d.mp4" % (ts, i)
                            safe_fetch(ovu, ovp)  # AUD-004/007 (só storage interno)
                        except Exception:
                            ovp = None
                    if vk or ovp:
                        apply_vfx(seg, vk, _dur_of(seg), FPS, OUT_W, OUT_H, overlay_path=ovp)
                out_filename = "film_%d.mp4" % ts
                out_path = "%s/videos/%s" % (MEDIA_BASE, out_filename)
                # 🎬 Transições (F1): no Filme o xfade COMPLETO é seguro — narração/música/legenda
                # entram DEPOIS da montagem (o encolhimento de (n-1)×D é transparente pro TTS).
                tr_cuts, tr_dur = parse_transitions(body, len(segs), allow_xfade=True)
                if tr_cuts and any(TRANSITIONS.get(k) == "xfade" for k in tr_cuts):
                    ok_x, err_x = xfade_join(segs, tr_cuts, tr_dur, out_path, fps=FPS or 30)
                    if not ok_x:
                        self._respond(500, {"error": "transicao: " + err_x}); return
                else:
                    if tr_cuts:  # só fades duração-preservada
                        apply_seg_fades(segs, tr_cuts, tr_dur)
                    if any_real_audio:
                        # 🎬 Fala embutida (Animação): concat via FILTRO (re-encode, sample-accurate).
                        # O demuxer com -c copy acumula ~46ms de priming AAC por segmento → a fala
                        # dessincronizava progressivamente ao longo do desenho.
                        ins = []
                        for sg in segs:
                            ins += ["-i", sg]
                        pairs = "".join("[%d:v][%d:a]" % (k, k) for k in range(len(segs)))
                        r = subprocess.run(["ffmpeg", "-y", *ins,
                                            "-filter_complex", pairs + "concat=n=%d:v=1:a=1[v][a]" % len(segs),
                                            "-map", "[v]", "-map", "[a]",
                                            "-c:v", "libx264", "-pix_fmt", "yuv420p", "-r", str(FPS or 30),
                                            "-c:a", "aac", "-ar", "44100", out_path], capture_output=True, text=True)
                    else:
                        listp = "/tmp/fcl_%d.txt" % ts
                        with open(listp, "w") as lf:
                            for sg in segs:
                                lf.write("file '%s'\n" % sg)
                        r = subprocess.run(["ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", listp, "-c", "copy", out_path], capture_output=True, text=True)
                    if r.returncode != 0:
                        self._respond(500, {"error": "concat: " + r.stderr[-300:]}); return

                # ── NARRAÇÃO CONTÍNUA (F2): a locução do roteiro INTEIRO entra por cima do filme
                # já concatenado — as durações dos clipes ficam INTACTAS (essencial pra
                # continuidade). with-timestamps dá o alignment pra legenda word-level. Narração
                # pedida e falhou = ERRO (é conteúdo, não enfeite); música continua best-effort.
                script = brand_say((body.get("script") or "").strip())
                # 🎙️ LOCUÇÃO POR CENA (`scripts`): uma fala por clipe, cada uma ANCORADA no início
                # da sua cena. O console monta e manda esse array desde 2026-07-26, mas nem o engine
                # nem este serviço tinham o campo — ele era DESCARTADO em silêncio e a narração do
                # Filme voltava a ser um texto corrido colado no segundo 0 (exatamente o bug que a
                # correção daquela data dizia ter resolvido). Sem `scripts` (ausente, não-lista ou
                # todos vazios) → `script` único de sempre, comportamento intacto.
                scene_scripts = film_scene_scripts(body, len(segs))
                if scene_scripts:
                    scene_scripts = [brand_say(s) if s else "" for s in scene_scripts]
                narration = bool(body.get("narration")) and (script != "" or bool(scene_scripts))
                subtitles = bool(body.get("subtitles")) and narration
                # 🎞️ Legenda ANIMADA no FILME (pop/karaoke/bounce): MESMO overlay do /shortform
                # (caption-service), agora também aqui — o preset chegava do console e este handler
                # o ignorava, então o Filme caía sempre na legenda ASS queimada. "" = queimada.
                sub_anim = parse_sub_anim(body)
                sub_anim_style = build_sub_anim_style(body)
                anim_words = []  # palavras com timing ABSOLUTO no filme (vazio = sem overlay)
                # Sincronismo (igual às Histórias): atraso da locução no início do filme + ajuste
                # fino da legenda relativo ao áudio. Defaults 0 = comportamento atual.
                audio_delay = min(max(float(body.get("audio_delay") or 0.0), 0.0), 2.0)
                sub_offset = min(max(float(body.get("subtitle_offset") or 0.0), -1.0), 1.0)
                cue_shift = audio_delay + sub_offset
                napath = None
                srt_path = None
                if narration:
                    voice_id = body.get("voice_id") or "Ey5AWb48tVX1IOcikcht"
                    tts_model = body.get("tts_model") or "eleven_multilingual_v2"
                    tts_format = (body.get("tts_format") or "").strip()
                    tts_settings = tts_settings_for_model(body.get("tts_style"), tts_model)

                    def _film_wt(fmt, text):
                        url = "https://api.elevenlabs.io/v1/text-to-speech/%s/with-timestamps" % voice_id
                        if fmt:
                            url += "?output_format=%s" % fmt
                        req = _u.Request(url,
                            data=json.dumps({"text": tts_text_for_model(text, body.get("tts_style"), tts_model), "model_id": tts_model,
                                             "voice_settings": tts_settings}).encode(),
                            headers={"xi-api-key": el_key, "Content-Type": "application/json"}, method="POST")
                        with _u.urlopen(req, timeout=180) as r2:
                            return json.loads(r2.read())

                    def _film_tts(text):
                        try:
                            return _film_wt(tts_format, text)
                        except Exception:
                            if not tts_format:
                                raise
                            return _film_wt("", text)  # formato não liberado no plano → default

                    def _film_words(nd):
                        """Palavras (texto, start, end) a partir do alignment por CARACTERE do TTS."""
                        al = nd.get("alignment") or {}
                        chs = al.get("characters", []); stt = al.get("character_start_times_seconds", []); ett = al.get("character_end_times_seconds", [])
                        out = []; cur = ""; cs = None; ce = None
                        for j, ch in enumerate(chs):
                            if ch.strip() == "":
                                if cur: out.append((cur, cs, ce)); cur = ""; cs = None
                            else:
                                if cs is None: cs = stt[j] if j < len(stt) else 0
                                cur += ch; ce = ett[j] if j < len(ett) else cs
                        if cur: out.append((cur, cs, ce))
                        return out

                    def _tt(sx):
                        h = int(sx // 3600); m = int((sx % 3600) // 60); sec = int(sx % 60); msx = int(round((sx - int(sx)) * 1000))
                        return "%02d:%02d:%02d,%03d" % (h, m, sec, msx)

                    def _cues_from(words, base):
                        """Cues word-level (até 2 palavras, mínimo 0.30s) deslocados por `base`.

                        `base` = onde essa locução COMEÇA no filme (0 na narração contínua; o âncora
                        da cena na locução por cena). É o que mantém a legenda batendo com o áudio
                        novo: os tempos do alignment são relativos ao mp3 da cena, então sem o base a
                        legenda da cena 3 apareceria no começo do filme."""
                        groups = []; g = []
                        for wds in words:
                            g.append(wds)
                            if (g[-1][2] - g[0][1]) >= 0.30 or len(g) >= 2:
                                groups.append(g); g = []
                        if g:
                            (groups[-1].extend(g) if groups else groups.append(g))
                        out = []
                        for gi, grp in enumerate(groups):
                            gstart = grp[0][1]; gend = grp[-1][2]
                            if gi + 1 < len(groups):
                                gend = max(gend, groups[gi + 1][0][1])
                            gs = max(0.0, gstart + base + cue_shift); ge = max(gs + 0.05, gend + base + cue_shift)
                            out.append((gs, ge, brand_caption(" ".join(w[0] for w in grp).upper())))
                        return out

                    def _words_abs(words, base):
                        """Palavras do alignment em tempo ABSOLUTO do filme (mesmo deslocamento
                        dos cues: `base` da cena + cue_shift) — entrada do overlay animado."""
                        out = []
                        for w in words:
                            if not w[0]:
                                continue
                            ws = max(0.0, (w[1] or 0.0) + base + cue_shift)
                            we = (w[2] if w[2] is not None else w[1]) or 0.0
                            out.append({"text": brand_caption(w[0].upper()),
                                        "start": ws, "end": max(ws + 0.05, we + base + cue_shift)})
                        return out

                    import base64 as _b64
                    all_cues = []
                    if scene_scripts:
                        # ── LOCUÇÃO POR CENA: um TTS por cena com fala; cena vazia = silêncio.
                        seg_durs_v = [_dur_of(sg) for sg in segs]
                        offs = scene_offsets(seg_durs_v, tr_cuts, tr_dur)  # mesma régua do SFX por trecho
                        parts = []
                        for i_sc, sc in enumerate(scene_scripts):
                            if not sc:
                                parts.append(None)
                                continue
                            nd = _film_tts(sc)
                            p = "/tmp/fcvoice_%d_%d.mp3" % (ts, i_sc)
                            open(p, "wb").write(_b64.b64decode(nd["audio_base64"]))
                            # duração REAL do mp3 decodificado (o relógio da cena) — os timestamps do
                            # provedor servem à legenda, mas divergem do arquivo por priming de encoder.
                            parts.append((p, _dur_of(p), _film_words(nd)))
                        starts = anchor_voice_starts(offs, [(pt[1] if pt else 0.0) for pt in parts])
                        live = [(st, pt) for st, pt in zip(starts, parts) if pt and st is not None]
                        # Junta as falas num ÚNICO mp3, cada uma no seu adelay. Daí pra frente o mix
                        # (ducking pela voz, apad, loudnorm, legenda) é IDÊNTICO ao da narração
                        # contínua — a locução por cena não duplica nem uma linha do mix final.
                        ins = []; fcv = []; labs = []
                        for k, (st, pt) in enumerate(live):
                            ins += ["-i", pt[0]]
                            fcv.append("[%d:a]adelay=%d:all=1,aformat=channel_layouts=stereo[sv%d]" % (k, int(round(st * 1000)), k))
                            labs.append("[sv%d]" % k)
                        napath = "/tmp/fcvoice_%d.mp3" % ts
                        rv = subprocess.run(["ffmpeg", "-y", *ins, "-filter_complex",
                                             ";".join(fcv) + ";" + "".join(labs) +
                                             "amix=inputs=%d:duration=longest:dropout_transition=0:normalize=0[a]" % len(labs),
                                             "-map", "[a]", "-c:a", "libmp3lame", "-ar", "44100", napath],
                                            capture_output=True, text=True)
                        if rv.returncode != 0:
                            self._respond(500, {"error": "locução por cena: " + rv.stderr[-300:]}); return
                        if subtitles:
                            for st, pt in live:
                                all_cues += _cues_from(pt[2], st)
                                if sub_anim:
                                    anim_words += _words_abs(pt[2], st)
                    else:
                        # ── NARRAÇÃO CONTÍNUA (comportamento histórico, sem `scripts`).
                        ndata = _film_tts(script)
                        napath = "/tmp/fcvoice_%d.mp3" % ts
                        open(napath, "wb").write(_b64.b64decode(ndata["audio_base64"]))
                        if subtitles:
                            _fw = _film_words(ndata)
                            all_cues = _cues_from(_fw, 0.0)
                            if sub_anim:
                                anim_words = _words_abs(_fw, 0.0)
                    if all_cues:
                        # Legenda WORD-LEVEL, estilo default do filme (embaixo, branco). Ordenada por
                        # tempo e renumerada — na locução por cena os cues vêm em blocos por cena.
                        all_cues.sort(key=lambda c: c[0])
                        srt_path = "/tmp/fcsrt_%d.srt" % ts
                        open(srt_path, "w").write("\n".join(
                            "%d\n%s --> %s\n%s\n" % (ci + 1, _tt(c[0]), _tt(c[1]), c[2])
                            for ci, c in enumerate(all_cues)))

                # Duração REAL do filme montado — base da trilha, do ambiente e do corte no mix.
                # Uma medição só, reusada: antes cada bloco rodava o próprio ffprobe do mesmo arquivo.
                film_dur = 0.0
                try:
                    _dj = subprocess.run(["ffprobe", "-v", "quiet", "-print_format", "json", "-show_format", out_path], capture_output=True, text=True)
                    film_dur = float(json.loads(_dj.stdout)["format"]["duration"])
                except Exception:
                    film_dur = 0.0

                # ── TRILHA (best-effort): protagonista sem narração (0.9), fundo com (0.16).
                mpath = None
                if bool(body.get("music")):
                    try:
                        dur = film_dur
                        # +3s de FOLGA: o provedor entrega a duração APROXIMADA do que foi pedido, e
                        # quando entrega a menos o filme termina mudo (projeto 19: trilha parou em
                        # 64,5s num filme de 68s — a última cena inteira sem som). Pedir com folga e
                        # cortar na medida (apad+atrim no mix) garante trilha até o último frame.
                        ms = max(10000, min(300000, int(dur * 1000) + 3000))
                        mp = body.get("music_prompt") or "cinematic modern instrumental soundtrack, emotional build-up, premium commercial vibe, warm and inspiring, no vocals, no lyrics"
                        payload = {"prompt": mp, "music_length_ms": ms, "model_id": "music_v1"}
                        mreq = _u.Request("https://api.elevenlabs.io/v1/music", data=json.dumps(payload).encode(), headers={"xi-api-key": el_key, "Content-Type": "application/json"}, method="POST")
                        mpath = "/tmp/fcmusic_%d.mp3" % ts
                        with _u.urlopen(mreq, timeout=180) as mr, open(mpath, "wb") as mf:
                            mf.write(mr.read())
                    except Exception:
                        mpath = None

                # ── 🔉 SFX/AMBIENCE (Sprint D, best-effort): camada de efeitos/ambiente BEM baixa
                # (0.10) sob a trilha/narração — chuva, tráfego, multidão etc. Opcional; texto livre
                # do operador (ambience_prompt). Usa o mesmo Eleven Music (loop instrumental de ambiente).
                spath = None
                ambience_prompt = (body.get("ambience_prompt") or "").strip()
                if ambience_prompt:
                    try:
                        dur = film_dur
                        ms = max(10000, min(300000, int(dur * 1000) + 3000))  # mesma folga da trilha
                        sp = "ambient background sound and room tone (no music, no melody, no beat): " + ambience_prompt
                        spayload = {"prompt": sp, "music_length_ms": ms, "model_id": "music_v1"}
                        sreq = _u.Request("https://api.elevenlabs.io/v1/music", data=json.dumps(spayload).encode(), headers={"xi-api-key": el_key, "Content-Type": "application/json"}, method="POST")
                        spath = "/tmp/fcsfx_%d.mp3" % ts
                        with _u.urlopen(sreq, timeout=180) as sr, open(spath, "wb") as sf:
                            sf.write(sr.read())
                    except Exception:
                        spath = None

                # ── MIX FINAL num passo só: [voz] + [trilha] + [ambiente] sobre o filme +
                # grade/letterbox/grain (Sprint B) + legenda queimada — qualquer um destes força
                # re-encode do vídeo (senão -c:v copy). Loudnorm entra sempre que este passo roda.
                finish_vf = build_finish_filters(body, OUT_W, OUT_H)
                _has_sfx = isinstance(body.get("sfx"), list) and any(isinstance(s, str) and s.strip() for s in body.get("sfx"))
                if napath or mpath or spath or srt_path or finish_vf or _has_sfx:
                    inputs = ["-i", out_path]
                    fc = []; amix = ["[0:a]"]
                    # 🎚️ Profundidade de áudio: a música (e o ambiente) são espacializados em ESTÉREO
                    # e sofrem DUCKING real — abaixam dinamicamente quando a narração fala (via
                    # sidechaincompress usando a voz como chave), no lugar de um volume fixo. A voz
                    # fica centrada/inteligível; o leito volta a subir nos silêncios.
                    duck_layers = (1 if mpath else 0) + (1 if spath else 0)
                    nkeys = duck_layers if napath else 0
                    keys = []
                    # 🎬 Estúdio de Animação: sem narração mas com FALA embutida nos clipes (diálogo
                    # muxado por cena) → o PRÓPRIO áudio do filme vira a chave do ducking da trilha/
                    # ambiente. Clipes mudos = chave silenciosa = compressor não morde (retrocompatível
                    # com o Filme, cujos clipes i2v são mudos). Sem isso a música a 0.9 afogava a fala.
                    #
                    # 🗣️ E a FALA vai tratada, não crua. Medido no projeto 20: o TTS sai em -18 dB e
                    # MONO, enquanto trilha e ambiente recebem alargamento estéreo — a voz ficava
                    # estreita e no meio da mistura em vez de à frente dela. Aqui ela leva corte de
                    # graves (highpass, tira ruído de fundo do clipe), compressão suave (nivela as
                    # sílabas) e ganho. O tratamento vem ANTES do asplit de propósito: a chave do
                    # ducking passa a ser a voz já nivelada, então o compressor morde mais parelho.
                    voz_fx = "aformat=channel_layouts=stereo,highpass=f=90,acompressor=threshold=0.05:ratio=3:attack=10:release=200,volume=1.7"
                    if not napath and duck_layers > 0:
                        labels = "[ca]" + "".join("[cak%d]" % k for k in range(duck_layers))
                        fc.append("[0:a]%s,asplit=%d%s" % (voz_fx, duck_layers + 1, labels))
                        keys = ["[cak%d]" % k for k in range(duck_layers)]
                        amix[0] = "[ca]"
                    elif not napath and (mpath or spath):
                        # Sem camada para "duckar", mas ainda vale nivelar a fala embutida.
                        fc.append("[0:a]%s[ca]" % voz_fx)
                        amix[0] = "[ca]"
                    if napath:
                        inputs += ["-i", napath]
                        ni = len(inputs) // 2 - 1
                        # audio_delay > 0: a locução (e a legenda, via cue_shift) começa depois do vídeo.
                        naf = ("adelay=%d:all=1,apad" % int(round(audio_delay * 1000))) if audio_delay > 0 else "apad"
                        if nkeys > 0:
                            # a voz vai pro mix E vira chave(s) de sidechain — 1 saída + nkeys cópias.
                            labels = "[nv]" + "".join("[nvk%d]" % k for k in range(nkeys))
                            fc.append("[%d:a]%s,aformat=channel_layouts=stereo,asplit=%d%s" % (ni, naf, nkeys + 1, labels))
                            keys = ["[nvk%d]" % k for k in range(nkeys)]
                        else:
                            fc.append("[%d:a]%s[nv]" % (ni, naf))
                        amix.append("[nv]")
                    # 🎚️ COBERTURA ATÉ O ÚLTIMO FRAME: a voz já tinha `apad`; a trilha e o ambiente
                    # não tinham NADA. Faixa mais curta que o filme = amix (duration=longest) segura
                    # o vídeo e o fim sai MUDO — projeto 19, 3,5s finais em silêncio. `apad` cobre o
                    # que faltar, `atrim` impede sobra depois do último frame e o `afade` fecha a
                    # trilha em vez de cortá-la seco. Sem film_dur medido, mantém o comportamento antigo.
                    _tail = (",apad,atrim=0:%.3f,afade=t=out:st=%.3f:d=1.20" % (film_dur, max(0.0, film_dur - 1.20))) if film_dur > 1.5 else ""
                    if mpath:
                        inputs += ["-i", mpath]
                        mi = len(inputs) // 2 - 1
                        if keys:
                            # 0.7 empatava com a voz. Medido no projeto 20: o TTS sai em -18 dB e a
                            # trilha chegava no MESMO nível — o ducking até funcionava (a música subia
                            # de -21 para -12 quando a fala acabava), mas durante a fala as duas
                            # disputavam o mesmo espaço e a voz sumia. Leito mais baixo e compressor
                            # mais fundo (ratio 12, release mais longo): a música vira cama, não rival.
                            fc.append("[%d:a]aformat=channel_layouts=stereo,extrastereo=m=1.4,volume=0.45%s[mw]" % (mi, _tail))
                            fc.append("[mw]%ssidechaincompress=threshold=0.02:ratio=12:attack=15:release=450[m]" % keys.pop(0))
                        else:
                            fc.append("[%d:a]aformat=channel_layouts=stereo,extrastereo=m=1.4,volume=0.9%s[m]" % (mi, _tail))
                        amix.append("[m]")
                    if spath:
                        inputs += ["-i", spath]
                        si = len(inputs) // 2 - 1
                        if keys:
                            # Ambiente é leito de fundo: some ainda mais sob a fala que a trilha.
                            fc.append("[%d:a]aformat=channel_layouts=stereo,extrastereo=m=1.5,volume=0.16%s[sw]" % (si, _tail))
                            fc.append("[sw]%ssidechaincompress=threshold=0.02:ratio=8:attack=15:release=450[sfx]" % keys.pop(0))
                        else:
                            fc.append("[%d:a]aformat=channel_layouts=stereo,extrastereo=m=1.5,volume=0.12%s[sfx]" % (si, _tail))
                        amix.append("[sfx]")
                    # 🔊 SFX POR TRECHO (F4): body['sfx'] = lista alinhada aos clipes (prompt curto ou "").
                    # Offset = soma das durações dos segs anteriores, descontando o overlap dos xfades.
                    sfx_list = body.get("sfx") if isinstance(body.get("sfx"), list) else []
                    if any(isinstance(s, str) and s.strip() for s in sfx_list):
                        seg_durs = [_dur_of(sg) for sg in segs]
                        sfx_offs = scene_offsets(seg_durs, tr_cuts, tr_dur)  # mesma régua da locução por cena
                        for k, sp_txt in enumerate(sfx_list[:len(segs)]):
                            if not (isinstance(sp_txt, str) and sp_txt.strip()):
                                continue
                            off = sfx_offs[k]
                            p = gen_sfx(sp_txt.strip(), min(seg_durs[k], 8.0), el_key, ts, 900 + k)
                            if not p:
                                continue
                            inputs += ["-i", p]
                            si = len(inputs) // 2 - 1
                            fc.append("[%d:a]adelay=%d:all=1,aformat=channel_layouts=stereo,volume=0.9[ffx%d]" % (si, int(round(max(0.0, off) * 1000)), k))
                            amix.append("[ffx%d]" % k)
                    fc.append("%samix=inputs=%d:duration=first:dropout_transition=0:normalize=0[am];[am]%s[a]" % ("".join(amix), len(amix), LOUDNORM))
                    vmap = "0:v"; venc = ["-c:v", "copy"]
                    vf_chain = list(finish_vf)
                    if srt_path and not (sub_anim and anim_words):
                        # Estilo COMPLETO da legenda (posição/fonte/cor/borda/caixa) — mesmo construtor
                        # das Histórias/Mídia (build_sub_style), com allowlists anti-injeção. Vai POR
                        # ÚLTIMO na cadeia (queima em cima do grade/letterbox/grain).
                        # No modo ANIMADO nada é queimado aqui: o overlay entra DEPOIS do mix (e só
                        # se ele falhar é que esta mesma legenda é queimada, como fallback). Sem
                        # palavras com timing (alignment vazio) o overlay nem é tentado — então a
                        # legenda queima aqui mesmo, como sempre: o modo animado NUNCA tira legenda.
                        vf_chain.append("subtitles=%s:force_style='%s'" % (srt_path, build_sub_style(body)))
                    if vf_chain:
                        fc.insert(0, "[0:v]" + ",".join(vf_chain) + "[v]")
                        vmap = "[v]"; venc = ["-c:v", "libx264", "-pix_fmt", "yuv420p", "-r", str(FPS)]
                    mixed_fn = "film_%d_m.mp4" % ts
                    mixed = "%s/videos/%s" % (MEDIA_BASE, mixed_fn)
                    rm = subprocess.run(["ffmpeg", "-y"] + inputs + ["-filter_complex", ";".join(fc),
                                        "-map", vmap, "-map", "[a]"] + venc + ["-c:a", "aac", "-ar", "44100", mixed],
                                       capture_output=True, text=True)
                    if rm.returncode != 0:
                        if narration:
                            self._respond(500, {"error": "mix da narração: " + rm.stderr[-300:]}); return
                    elif os.path.exists(mixed) and os.path.getsize(mixed) > 0:
                        out_filename = mixed_fn

                # ── 🎞️ LEGENDA ANIMADA (opcional): overlay do caption-service por cima do filme já
                # mixado — antes do endcard (a cartela final não leva legenda). MESMA política do
                # /shortform: qualquer falha do serviço cai no fallback de queimar a legenda ASS,
                # pra o filme NUNCA sair sem legenda por causa do recurso novo.
                if sub_anim and subtitles and anim_words:
                    anim_words.sort(key=lambda w: w["start"])
                    cap_src = "%s/videos/%s" % (MEDIA_BASE, out_filename)
                    cap_dur = film_dur if film_dur > 1.5 else _dur_of(cap_src)
                    if not caption_overlay(cap_src, anim_words, cap_dur, FPS, OUT_W, OUT_H,
                                           sub_anim, sub_anim_style, "fccap_%d" % ts) and srt_path:
                        cap_fb_fn = "film_%d_sb.mp4" % ts
                        cap_fb = "%s/videos/%s" % (MEDIA_BASE, cap_fb_fn)
                        rcb = subprocess.run(["ffmpeg", "-y", "-i", cap_src,
                                              "-vf", "subtitles=%s:force_style='%s'" % (srt_path, build_sub_style(body)),
                                              "-c:v", "libx264", "-pix_fmt", "yuv420p", "-r", str(FPS),
                                              "-c:a", "copy", cap_fb], capture_output=True, text=True)
                        if rcb.returncode == 0 and os.path.exists(cap_fb) and os.path.getsize(cap_fb) > 0:
                            out_filename = cap_fb_fn

                # ── 🏷️ ENDCARD (Sprint B.4, opcional): cartela final ~2s com o logo/CTA da marca
                # (já composta como IMAGEM pelo cliente — não desenhamos texto aqui). Best-effort:
                # se falhar, o filme sai igual, sem a cartela (não é conteúdo crítico).
                endcard_url = str(body.get("endcard_url") or "").strip()
                if endcard_url:
                    try:
                        ec_src = "/tmp/ec_%d.bin" % ts
                        safe_fetch(endcard_url, ec_src)  # AUD-004 + AUD-007 (só storage interno)
                        ec_clip = "/tmp/ecv_%d.mp4" % ts
                        ec_vf = "scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:black,fps=%d,setsar=1" % (OUT_W, OUT_H, OUT_W, OUT_H, FPS)
                        rec = subprocess.run(["ffmpeg", "-y", "-loop", "1", "-t", "2", "-i", ec_src,
                                              "-f", "lavfi", "-i", "anullsrc=channel_layout=stereo:sample_rate=44100",
                                              "-filter_complex", "[0:v]" + ec_vf + "[v]",
                                              "-map", "[v]", "-map", "1:a:0", "-shortest",
                                              "-c:v", "libx264", "-pix_fmt", "yuv420p", "-r", str(FPS), "-c:a", "aac", "-ar", "44100", ec_clip],
                                             capture_output=True, text=True)
                        if rec.returncode == 0:
                            final_src = "%s/videos/%s" % (MEDIA_BASE, out_filename)
                            ec_out_fn = "film_%d_ec.mp4" % ts
                            ec_out = "%s/videos/%s" % (MEDIA_BASE, ec_out_fn)
                            rc = subprocess.run(["ffmpeg", "-y", "-i", final_src, "-i", ec_clip,
                                                 "-filter_complex", "[0:v][0:a][1:v][1:a]concat=n=2:v=1:a=1[v][a]",
                                                 "-map", "[v]", "-map", "[a]", "-c:v", "libx264", "-pix_fmt", "yuv420p", "-r", str(FPS), "-c:a", "aac", "-ar", "44100", ec_out],
                                                capture_output=True, text=True)
                            if rc.returncode == 0 and os.path.exists(ec_out) and os.path.getsize(ec_out) > 0:
                                out_filename = ec_out_fn
                    except Exception:
                        pass

                # ── 🌊 FLUIDEZ (opt-in): interpolação de movimento (minterpolate mci) dobrando o
                # fps (24→48). 2× INTEIRO preserva todos os frames originais e insere 1 entre cada
                # par → cadência uniforme, SEM judder (diferente de 24→30). O scd padrão (fbcd) não
                # interpola através de corte (endcard). ADAPTATIVO por duração (bench VPS 8 cores,
                # 2026-07-08, 1080p): mci/obmc/epzs ≈ 14.4×dur · híbrido 720p ≈ 7×dur. Orçamento
                # 380s (o handler inteiro precisa caber nos 600s do engine/console): filme curto =
                # 1080p; médio = interpola em 720p e re-escala; longo demais = pula (best-effort,
                # nunca quebra nem pendura a montagem).
                if bool(body.get("smooth")):
                    try:
                        sm_fps = min(FPS * 2, 48)
                        if sm_fps > FPS:
                            sm_src = "%s/videos/%s" % (MEDIA_BASE, out_filename)
                            durj = subprocess.run(["ffprobe", "-v", "quiet", "-print_format", "json", "-show_format", sm_src],
                                                  capture_output=True, text=True)
                            smdur = float(json.loads(durj.stdout)["format"]["duration"])
                            SM_BUDGET = 380
                            mi = "minterpolate=fps=%d:mi_mode=mci:mc_mode=obmc:me=epzs" % sm_fps
                            if smdur * 14.5 <= SM_BUDGET:
                                sm_vf = mi  # resolução cheia (filmes até ~26s)
                            elif smdur * 7.5 <= SM_BUDGET:
                                # híbrido: interpola em 720p e re-escala (filmes até ~50s) — a
                                # suavidade de movimento se preserva; perda de nitidez mínima.
                                sw, sh = (1280, 720) if OUT_W > OUT_H else (720, 1280)
                                sm_vf = "scale=%d:%d,%s,scale=%d:%d" % (sw, sh, mi, OUT_W, OUT_H)
                            else:
                                sm_vf = ""  # longo demais pro orçamento — sai sem suavização
                            if sm_vf:
                                sm_fn = "film_%d_s.mp4" % ts
                                sm_out = "%s/videos/%s" % (MEDIA_BASE, sm_fn)
                                rs = subprocess.run(["ffmpeg", "-y", "-i", sm_src, "-vf", sm_vf,
                                                     "-c:v", "libx264", "-pix_fmt", "yuv420p", "-c:a", "copy", sm_out],
                                                    capture_output=True, text=True, timeout=SM_BUDGET + 40)
                                if rs.returncode == 0 and os.path.exists(sm_out) and os.path.getsize(sm_out) > 0:
                                    out_filename = sm_fn
                    except Exception:
                        pass  # suavização é acabamento — timeout/falha mantém o filme original

                self._respond(200, {"video_url": _mk_url(out_filename), "filename": out_filename})
            except Exception as e:
                self._respond(500, {"error": str(e)})

        elif self.path == "/last-frame":
            # Último frame REAL de um vídeo → JPG durável (re-âncora de keyframe do filme).
            try:
                video_url = body.get("video_url") or ""
                ts = int(time.time() * 1000)
                src = "/tmp/lf_%d.mp4" % ts
                safe_fetch(video_url, src)  # AUD-004 + AUD-007 (só storage interno)
                fpath = "/tmp/lf_%d.jpg" % ts
                r = subprocess.run(["ffmpeg", "-y", "-sseof", "-0.15", "-i", src, "-frames:v", "1", "-q:v", "2", fpath], capture_output=True, text=True)
                if r.returncode != 0 or not os.path.exists(fpath):
                    self._respond(500, {"error": "extração falhou: " + r.stderr[-200:]}); return
                self._respond(200, {"url": persist_to_s3(fpath, "image", "jpg")})
            except Exception as e:
                self._respond(500, {"error": str(e)[:200]})

        elif self.path == "/frames-at":
            # Extrai frames em VÁRIAS posições (frações 0.0-1.0 da duração) de um vídeo → URLs
            # JPG duráveis, na mesma ordem das frações pedidas. Turnaround de personagem via
            # ÓRBITA DE CÂMERA (vídeo i2v, ver engine content.go GenerateOrbitAngles): o
            # subject_reference de IMAGEM não segue instrução de ângulo grande de forma confiável
            # (achado real 2026-07-17 — perfil/costas sempre saíam de frente), mas um modelo de
            # VÍDEO mantém coerência espacial ao longo de um movimento de câmera contínuo — gera
            # 1 clipe orbitando o personagem e extrai o frame de cada ângulo do turnaround.
            try:
                video_url = body.get("video_url") or ""
                fractions = body.get("fractions") or []
                if not video_url or not isinstance(fractions, list) or not fractions:
                    self._respond(400, {"error": "video_url e fractions (lista) obrigatórios"}); return
                ts = int(time.time() * 1000)
                src = "/tmp/fa_%d.mp4" % ts
                safe_fetch(video_url, src)  # AUD-004 + AUD-007 (só storage interno)
                dur = _dur_of(src)
                urls = []
                for i, frac in enumerate(fractions):
                    try:
                        frac = max(0.0, min(0.999, float(frac)))
                    except (TypeError, ValueError):
                        urls.append(None)
                        continue
                    t = dur * frac
                    fpath = "/tmp/fa_%d_%d.jpg" % (ts, i)
                    r = subprocess.run(
                        ["ffmpeg", "-y", "-ss", "%.3f" % t, "-i", src, "-frames:v", "1", "-q:v", "2", fpath],
                        capture_output=True, text=True,
                    )
                    if r.returncode != 0 or not os.path.exists(fpath):
                        urls.append(None)
                        continue
                    urls.append(persist_to_s3(fpath, "image", "jpg"))
                self._respond(200, {"urls": urls})
            except Exception as e:
                self._respond(500, {"error": str(e)[:200]})

        elif self.path == "/tts":
            # TTS puro (preview de narração por cena): gera o MP3 do texto e o torna durável no
            # Scality. brand_say aplica o léxico fonético de marca. White-label: erro genérico.
            apath = None
            try:
                import urllib.request as _u
                text = brand_say((body.get("text") or "").strip())
                if not text:
                    self._respond(400, {"error": "texto vazio"})
                    return
                voice_id = body.get("voice_id") or "Ey5AWb48tVX1IOcikcht"
                # Qualidade/entrega da narração (tier+preset do catálogo): modelo de síntese +
                # formato/bitrate do MP3 + estilo. Vazios = defaults históricos (retrocompat total).
                tts_model = body.get("tts_model") or "eleven_multilingual_v2"
                tts_format = (body.get("tts_format") or "").strip()
                voice_settings = tts_settings_for_model(body.get("tts_style"), tts_model)
                el_key = os.environ.get("ELEVENLABS_API_KEY", "")
                ts = int(time.time() * 1000)
                apath = "/tmp/tts_%d.mp3" % ts

                # ⏱️ timestamps=true → endpoint with-timestamps: além do MP3, devolve o
                # alinhamento por palavra ([{word,start,end}]) — o MESMO relógio que a montagem
                # interna usa pra legenda word-level, agora disponível pra quem monta FORA daqui.
                quer_ts = bool(body.get("timestamps"))
                words = None

                def _tts_call(fmt):
                    nonlocal words
                    base = "https://api.elevenlabs.io/v1/text-to-speech/%s" % voice_id
                    if quer_ts:
                        base += "/with-timestamps"
                    if fmt:
                        base += "?output_format=%s" % fmt
                    req = _u.Request(base,
                        data=json.dumps({"text": text, "model_id": tts_model,
                                         "voice_settings": voice_settings}).encode(),
                        headers={"xi-api-key": el_key, "Content-Type": "application/json"}, method="POST")
                    with _u.urlopen(req, timeout=90) as r:
                        raw = r.read()
                    if quer_ts:
                        data = json.loads(raw)
                        open(apath, "wb").write(base64.b64decode(data["audio_base64"]))
                        words = alignment_words(data.get("alignment"))
                    else:
                        open(apath, "wb").write(raw)
                try:
                    _tts_call(tts_format)
                except Exception:
                    if not tts_format:
                        raise
                    _tts_call("")  # formato não liberado no plano do provedor → refaz no default
                resp = {"url": persist_to_s3(apath, "tts", "mp3")}
                if words is not None:
                    resp["words"] = words
                    resp["duration"] = words[-1]["end"] if words else 0.0
                self._respond(200, resp)
            except Exception as e:
                self._respond(500, {"error": str(e)[:200]})
            finally:
                if apath:
                    try:
                        os.remove(apath)
                    except Exception:
                        pass
            return

        elif self.path == "/dialogue":
            # 🎬 Estúdio de Animação: DIÁLOGO multi-voz de UMA cena. Cada fala vira um TTS com a
            # VOZ do personagem e as falas são concatenadas com um respiro (gap) entre elas —
            # resultado = 1 MP3 durável no Scality + duração (o chamador escolhe a duração do
            # clipe i2v a partir dela). body: {lines:[{text, voice_id, tts_style?}], tts_model?,
            # tts_format?, gap?} → {url, duration}. White-label: erro genérico.
            tmp_files = []
            try:
                import urllib.request as _u
                lines = body.get("lines") or []
                if not lines or len(lines) > 24:
                    self._respond(400, {"error": "lines vazio ou demais (max 24)"})
                    return
                tts_model = body.get("tts_model") or "eleven_multilingual_v2"
                tts_format = (body.get("tts_format") or "").strip()
                gap = min(max(float(body.get("gap") or 0.35), 0.0), 2.0)
                el_key = os.environ.get("ELEVENLABS_API_KEY", "")
                ts = int(time.time() * 1000)

                def _line_tts(text, voice_id, style, dest, fmt):
                    url = "https://api.elevenlabs.io/v1/text-to-speech/%s" % voice_id
                    if fmt:
                        url += "?output_format=%s" % fmt
                    req = _u.Request(url,
                        data=json.dumps({"text": tts_text_for_model(text, style, tts_model),
                                         "model_id": tts_model,
                                         "voice_settings": tts_settings_for_model(style, tts_model)}).encode(),
                        headers={"xi-api-key": el_key, "Content-Type": "application/json"}, method="POST")
                    with _u.urlopen(req, timeout=90) as r, open(dest, "wb") as f:
                        f.write(r.read())

                for i, ln in enumerate(lines):
                    text = brand_say((ln.get("text") or "").strip())
                    voice_id = (ln.get("voice_id") or "").strip() or "Ey5AWb48tVX1IOcikcht"
                    if not text:
                        continue
                    dest = "/tmp/dlg_%d_%d.mp3" % (ts, i)
                    try:
                        _line_tts(text, voice_id, ln.get("tts_style"), dest, tts_format)
                    except Exception:
                        if not tts_format:
                            raise
                        _line_tts(text, voice_id, ln.get("tts_style"), dest, "")
                    tmp_files.append(dest)
                if not tmp_files:
                    self._respond(400, {"error": "nenhuma fala com texto"})
                    return

                out_path = "/tmp/dlg_%d_out.mp3" % ts
                # apad em cada fala (menos a última) = respiro entre falas; concat de áudio puro +
                # LOUDNORM: o TTS sai baixo (~-28dB mean) e afogava na trilha do mix final — a fala
                # normalizada (-16 LUFS) entra forte no ducking do /concat-clips.
                cmd = ["ffmpeg", "-y"]
                for f in tmp_files:
                    cmd += ["-i", f]
                parts, labels = [], []
                for i in range(len(tmp_files)):
                    if i < len(tmp_files) - 1 and gap > 0:
                        parts.append("[%d:a]apad=pad_dur=%.2f[a%d]" % (i, gap, i))
                        labels.append("[a%d]" % i)
                    else:
                        labels.append("[%d:a]" % i)
                fc = (";".join(parts) + ";" if parts else "") + "".join(labels) + \
                    "concat=n=%d:v=0:a=1[cat];[cat]loudnorm=I=-16:TP=-1.5:LRA=11[out]" % len(tmp_files)
                cmd += ["-filter_complex", fc, "-map", "[out]",
                        "-ar", "44100", "-c:a", "libmp3lame", "-q:a", "2", out_path]
                r = subprocess.run(cmd, capture_output=True, text=True)
                if r.returncode != 0:
                    self._respond(500, {"error": r.stderr[-300:]})
                    return
                tmp_files.append(out_path)
                self._respond(200, {"url": persist_to_s3(out_path, "tts", "mp3"),
                                    "duration": round(_dur_of(out_path), 2)})
            except Exception as e:
                self._respond(500, {"error": str(e)[:200]})
            finally:
                for f in set(tmp_files):
                    try:
                        os.remove(f)
                    except Exception:
                        pass
            return

        elif self.path == "/mux-audio":
            # 🎬 Estúdio de Animação: casa o ÁUDIO da cena (diálogo /dialogue) com o CLIPE i2v
            # (mudo). Vídeo mais curto que o áudio = congela o último frame (tpad clone); áudio
            # mais curto = silêncio (apad). Saída dura max(vídeo, áudio). body: {video_url,
            # audio_url} → {url, duration}. AUD-004/007: só mídia do storage interno.
            tmp_files = []
            try:
                video_url = body["video_url"]
                audio_url = body["audio_url"]
                ts = int(time.time() * 1000)
                vpath = "/tmp/mux_%d.mp4" % ts
                apath = "/tmp/mux_%d.mp3" % ts
                out_path = "/tmp/mux_%d_out.mp4" % ts
                safe_fetch(video_url, vpath)
                safe_fetch(audio_url, apath)
                tmp_files += [vpath, apath, out_path]
                out_t = max(_dur_of(vpath), _dur_of(apath)) + 0.2
                r = subprocess.run([
                    "ffmpeg", "-y", "-i", vpath, "-i", apath,
                    "-filter_complex",
                    "[0:v]tpad=stop_mode=clone:stop_duration=32[v];[1:a]aresample=async=1:first_pts=0,asetpts=N/SR/TB,apad=pad_dur=32[a]",
                    "-map", "[v]", "-map", "[a]",
                    "-c:v", "libx264", "-pix_fmt", "yuv420p", "-c:a", "aac",
                    "-t", "%.3f" % out_t, out_path,
                ], capture_output=True, text=True)
                if r.returncode != 0:
                    self._respond(500, {"error": r.stderr[-300:]})
                    return
                self._respond(200, {"url": persist_to_s3(out_path, "anim", "mp4"),
                                    "duration": round(out_t, 2)})
            except Exception as e:
                self._respond(500, {"error": str(e)[:200]})
            finally:
                for f in set(tmp_files):
                    try:
                        os.remove(f)
                    except Exception:
                        pass
            return

        elif self.path == "/clip":
            # v2: vídeo longo → N shorts 9:16 com REFRAME por rosto (fallback centro) + legenda word-level.
            try:
                import urllib.request as _u
                video_url = body["video_url"]
                clips = body.get("clips", [])
                ts = int(time.time() * 1000)
                def _t(s):
                    if s < 0: s = 0.0
                    h = int(s // 3600); m = int((s % 3600) // 60); sec = int(s % 60); ms = int(round((s - int(s)) * 1000))
                    return "%02d:%02d:%02d,%03d" % (h, m, sec, ms)
                src = "/tmp/clipsrc_%d.mp4" % ts
                safe_fetch(video_url, src)  # AUD-004 + AUD-007 (só storage interno)
                W, H = _dims(src)
                style = "Fontname=Liberation Sans,FontSize=18,Bold=1,PrimaryColour=&H00FFFFFF,OutlineColour=&H00000000,BorderStyle=1,Outline=3,Shadow=1,Alignment=2,MarginV=170"
                out = []
                for i, c in enumerate(clips):
                    s = float(c["start"]); e = float(c["end"]); dur = max(0.5, e - s)
                    if W / float(H) > 9.0 / 16.0:
                        cw = min(W, int(round(H * 9.0 / 16.0))); ch = H; y = 0
                        cx = _face_center_x(src, s, e, W, H)
                        x = (W - cw) // 2 if cx is None else int(max(0, min(W - cw, cx - cw / 2.0)))
                        reframe = "centro" if cx is None else "rosto@%d" % int(cx)
                    else:
                        cw = W; ch = min(H, int(round(W * 16.0 / 9.0))); x = 0; y = (H - ch) // 2
                        reframe = "vertical-centro"
                    rel = [(w["text"], float(w["start"]) - s, float(w["end"]) - s) for w in c.get("words", []) if float(w["end"]) > s and float(w["start"]) < e]
                    cues = []; idx = 1
                    for k in range(0, len(rel), 3):
                        grp = rel[k:k + 3]
                        a = max(0.0, grp[0][1]); b = min(dur, grp[-1][2])
                        if b <= a: b = a + 0.4
                        cues.append("%d\n%s --> %s\n%s\n" % (idx, _t(a), _t(b), " ".join(w[0] for w in grp).upper())); idx += 1
                    vf = "crop=%d:%d:%d:%d,scale=1080:1920" % (cw, ch, x, y)
                    if cues:
                        srt = "/tmp/clipsrt_%d_%d.srt" % (ts, i)
                        open(srt, "w").write("\n".join(cues))
                        vf += ",subtitles=%s:force_style='%s'" % (srt, style)
                    out_filename = "clip_%d_%d.mp4" % (ts, i)
                    out_path = "%s/videos/%s" % (MEDIA_BASE, out_filename)
                    r = subprocess.run(["ffmpeg", "-y", "-ss", "%.3f" % s, "-i", src, "-t", "%.3f" % dur,
                                        "-vf", vf, "-c:v", "libx264", "-pix_fmt", "yuv420p", "-r", "30",
                                        "-c:a", "aac", "-ar", "44100", out_path], capture_output=True, text=True)
                    if r.returncode != 0:
                        out.append({"ok": False, "title": c.get("title", ""), "error": r.stderr[-300:]}); continue
                    size_mb = round(os.path.getsize(out_path) / 1024 / 1024, 2)
                    out.append({"ok": True, "url": _mk_url(out_filename),
                                "title": c.get("title", ""), "score": c.get("score"), "reframe": reframe, "size_mb": size_mb})
                self._respond(200, {"clips": out, "count": sum(1 for x in out if x.get("ok"))})
            except Exception as e:
                self._respond(500, {"error": str(e)})

        elif self.path == "/ingest":
            # Baixa vídeo (YouTube ou URL direta) via yt-dlp → MP4 hospedado público. body: {url}
            # YouTube em IP de datacenter: resolvido por PO token (bgutil) + deno, sem cookie.
            try:
                url = body["url"]
                # AUD-015: /ingest só atende YouTube (único uso real); rejeita o resto.
                validate_url(url, allow_hosts=_YT_HOSTS)
                ts = int(time.time() * 1000)
                out_filename = "ingest_%d.mp4" % ts
                out_path = "%s/videos/%s" % (MEDIA_BASE, out_filename)
                # YouTube em IP de datacenter: PO token (bgutil :4416) + deno resolvem o anti-bot SEM cookie.
                # (cookies de conta logada QUEBRAM a extracao de formatos com PO token -> nao usar.)
                cmd = ["yt-dlp", "-f", "mp4/bestvideo[ext=mp4]+bestaudio/best", "--no-playlist", "--merge-output-format", "mp4",
                       "--extractor-args", "youtube:player_client=default", "-o", out_path, url]
                # No stack containerizado, o bgutil roda como serviço separado (não 127.0.0.1).
                # Aponta o provedor de PO token pro sidecar via env (host fica inalterado se ausente).
                _pot = os.environ.get("BGUTIL_POT_BASE_URL")
                if _pot:
                    cmd[1:1] = ["--extractor-args", "youtubepot-bgutilhttp:base_url=%s" % _pot]
                r = subprocess.run(cmd, capture_output=True, text=True, timeout=600)
                if not os.path.exists(out_path):
                    err = (r.stderr or r.stdout)[-300:]
                    hint = " (YouTube exige cookies — coloque yt-cookies.txt no ffmpeg-service)" if "not a bot" in err or "cookies" in err else ""
                    self._respond(500, {"error": "yt-dlp: " + err + hint}); return
                size_mb = round(os.path.getsize(out_path) / 1024 / 1024, 2)
                self._respond(200, {"video_url": _mk_url(out_filename), "filename": out_filename, "size_mb": size_mb})
            except Exception as e:
                self._respond(500, {"error": str(e)})

        elif self.path == "/thumbnail":
            # Frame do vídeo + título queimado → thumbnail 9:16. body: {video_url, title}
            try:
                import urllib.request as _u, textwrap
                video_url = body["video_url"]
                title = (body.get("title") or "").strip().upper()
                ts = int(time.time() * 1000)
                src = "/tmp/th_%d.mp4" % ts
                safe_fetch(video_url, src)  # AUD-004 + AUD-007 (só storage interno)
                durj = subprocess.run(["ffprobe", "-v", "quiet", "-print_format", "json", "-show_format", src], capture_output=True, text=True)
                try: dur = float(json.loads(durj.stdout)["format"]["duration"])
                except Exception: dur = 3.0
                t = min(max(1.0, dur * 0.3), max(0.0, dur - 0.1))
                out_fn = "thumb_%d.jpg" % ts
                out_path = "%s/videos/%s" % (MEDIA_BASE, out_fn)
                vf = "scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920"
                if title:
                    wrapped = "\n".join(textwrap.wrap(title, 16)) or title
                    tf = "/tmp/thtxt_%d.txt" % ts; open(tf, "w").write(wrapped)
                    font = "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf"
                    vf += (",drawtext=fontfile=%s:textfile=%s:fontcolor=white:fontsize=84:line_spacing=12:"
                           "borderw=8:bordercolor=black:box=1:boxcolor=black@0.45:boxborderw=30:"
                           "x=(w-text_w)/2:y=150") % (font, tf)
                r = subprocess.run(["ffmpeg", "-y", "-ss", "%.2f" % t, "-i", src, "-frames:v", "1", "-vf", vf, "-q:v", "3", out_path], capture_output=True, text=True)
                if r.returncode != 0 or not os.path.exists(out_path):
                    self._respond(500, {"error": "thumb: " + r.stderr[-300:]}); return
                self._respond(200, {"url": _mk_url(out_fn, "jpg"), "filename": out_fn, "size_kb": round(os.path.getsize(out_path) / 1024, 1)})
            except Exception as e:
                self._respond(500, {"error": str(e)})

        # /image-filter (F2) — aplica um filtro Instagram (GRADE_FILTERS + grade_strength) numa
        # FOTO. body: {image_url, grade, grade_strength?} → {url}. Determinístico, custo zero de
        # API — o mesmo motor de color grade dos vídeos, num JPG.
        elif self.path == "/image-filter":
            try:
                image_url = str(body.get("image_url") or "").strip()
                if not image_url:
                    self._respond(400, {"error": "sem image_url"}); return
                gf = build_grade_filter(body)
                if not gf:
                    self._respond(400, {"error": "filtro invalido ou 'natural' (nada a aplicar)"}); return
                ts = int(time.time() * 1000)
                src = "/tmp/if_%d.bin" % ts
                safe_fetch(image_url, src)  # AUD-004 + AUD-007 (só storage interno)
                out_fn = "filter_%d.jpg" % ts
                out_path = "%s/videos/%s" % (MEDIA_BASE, out_fn)
                r = subprocess.run(["ffmpeg", "-y", "-i", src, "-filter_complex", "[0:v]" + gf + "[v]",
                                    "-map", "[v]", "-frames:v", "1", "-q:v", "2", out_path],
                                   capture_output=True, text=True)
                if r.returncode != 0 or not os.path.exists(out_path):
                    self._respond(500, {"error": "filtro: " + r.stderr[-300:]}); return
                self._respond(200, {"url": _mk_url(out_fn, "jpg"), "filename": out_fn})
            except Exception as e:
                self._respond(500, {"error": str(e)[:200]})

        # /camclip — CÂMERA PROGRAMADA: imagem estática + movimento → clipe mp4, SEM IA de vídeo.
        # body: {image_url, move, duration?, aspect?, fps?} → {url, filename, move, duration}
        # Existe pros planos contemplativos (abertura, hero shot, encerramento, detalhe), onde nada
        # na cena se mexe de verdade: o i2v cobra e ainda arrisca drift de identidade, enquanto aqui
        # o resultado é a arte aprovada com a câmera por cima. Movimento não suportado → 400, e o
        # console cai no i2v (a lista está em CAM_MOVES).
        elif self.path == "/camclip":
            try:
                image_url = str(body.get("image_url") or "").strip()
                if not image_url:
                    self._respond(400, {"error": "sem image_url"}); return
                move = str(body.get("move") or "").strip()
                if move not in CAM_MOVES:
                    self._respond(400, {"error": "movimento sem camera programada: %s" % (move or "(vazio)"),
                                        "supported": list(CAM_MOVES)}); return
                dur = float(body.get("duration") or 5)
                dur = max(1.0, min(15.0, dur))          # teto: passou disso, o zoom fica óbvio demais
                fps = int(body.get("fps") or 24)
                fps = max(12, min(60, fps))
                aspect = str(body.get("aspect") or "9:16")
                if aspect == "auto":
                    # 🖼️ AUTO = a proporção da PRÓPRIA imagem. Existe porque o caminho normal
                    # (9:16 ou 16:9 fixos) usa force_original_aspect_ratio=increase + crop, e isso
                    # CORTA a arte — inaceitável quando o clipe é só um invólucro da peça aprovada
                    # (ex.: publicar a imagem no Reddit como videogif nativo). Aqui não há corte
                    # nem barra: o quadro É a imagem. Largura clampada em 1080 e dimensões pares
                    # (o x264 exige par com yuv420p).
                    out_w, out_h = _image_even_size(image_url, 1080)
                else:
                    out_w, out_h = (1920, 1080) if aspect == "16:9" else (1080, 1920)
                frames = max(2, int(round(dur * fps)))
                vf = build_camera_vf(move, out_w, out_h, frames, fps)

                ts = int(time.time() * 1000)
                src = "/tmp/camclip_%d.bin" % ts
                safe_fetch(image_url, src)  # AUD-004 + AUD-007 (só storage interno)
                out_fn = "camclip_%d.mp4" % ts
                out_path = "%s/videos/%s" % (MEDIA_BASE, out_fn)
                r = subprocess.run(["ffmpeg", "-y", "-loop", "1", "-t", "%.2f" % dur, "-i", src,
                                    "-vf", vf, "-c:v", "libx264", "-pix_fmt", "yuv420p",
                                    "-r", str(fps), "-t", "%.2f" % dur, "-an", out_path],
                                   capture_output=True, text=True)
                if r.returncode != 0 or not os.path.exists(out_path):
                    self._respond(500, {"error": "camclip: " + r.stderr[-300:]}); return
                self._respond(200, {"url": _mk_url(out_fn), "filename": out_fn,
                                    "move": move, "duration": round(dur, 2),
                                    "size_mb": round(os.path.getsize(out_path) / 1024 / 1024, 2)})
            except Exception as e:
                self._respond(500, {"error": str(e)[:200]})

        # /compose-sheet — monta uma folha de model sheet num template FIXO (grid + rótulos + paleta)
        # a partir de shots individuais já gerados. body: {title, subtitle?, cols?, shots:[{url,label}],
        # palette?:[{hex,label}]} → {url}. Determinístico: mesmo input → mesmo layout.
        elif self.path == "/compose-sheet":
            try:
                url = compose_sheet(body)
                if not url:
                    self._respond(500, {"error": "compose falhou (S3 indisponível?)"})
                    return
                self._respond(200, {"url": url})
            except Exception as e:
                self._respond(500, {"error": str(e)[:200]})

        # /compose-storyboard — a FOLHA DE STORYBOARD (documento de produção conferido ANTES da
        # montagem), composta dos keyframes que já existem. body: {title, subtitle?, cols?,
        # footer?:[{k,v}], panels:[{url,index?,time?,shot?,action?,dialogue?}]} → {url}.
        # Determinístico e sem geração de imagem: os rótulos são dado nosso, não tipografia gerada.
        elif self.path == "/compose-storyboard":
            try:
                url = compose_storyboard(body)
                if not url:
                    self._respond(500, {"error": "compose storyboard falhou (S3 indisponível?)"})
                    return
                self._respond(200, {"url": url})
            except Exception as e:
                self._respond(500, {"error": str(e)[:200]})

        else:
            self._respond(404, {"error": "not found"})

    def _respond(self, code, data):
        body = json.dumps(data).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", len(body))
        self.end_headers()
        self.wfile.write(body)

if __name__ == "__main__":
    if not SERVICE_TOKEN:
        print("AVISO: FFMPEG_SERVICE_TOKEN não definido — todos os POST retornarão 401.")
    # 🔑 Vigia da chave de voz. Roda na subida (o veredito já sai no log do boot, que é quando
    # uma troca de chave costuma dar errado) e depois a cada _VOICE_KEY_TTL.
    threading.Thread(target=_voice_key_loop, daemon=True, name="voice-key-watch").start()
    server = HTTPServer(("0.0.0.0", 7788), Handler)
    print("ffmpeg-service rodando em 0.0.0.0:7788 "
          "(POST exige header X-Service-Token; GET /health livre)")
    server.serve_forever()
