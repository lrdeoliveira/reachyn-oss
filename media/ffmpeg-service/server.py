#!/usr/bin/env python3
"""Micro serviço HTTP para operações ffmpeg.

Escuta em 0.0.0.0:7788 (precisa ser alcançável pelo engine na rede docker),
MAS todo POST exige o header X-Service-Token == env FFMPEG_SERVICE_TOKEN
(comparação constant-time). GET /health fica livre. Sem o token → 401.
"""
import subprocess, json, os, time, hmac, re, ipaddress, socket, ssl
import http.client
from urllib.parse import urlparse
from http.server import HTTPServer, BaseHTTPRequestHandler

MEDIA_BASE = os.environ.get("MEDIA_BASE", "/vps_nexus/media")
# Base pública de leitura da mídia (sem default revelador — vem do .env em produção).
PUBLIC_BASE = os.environ.get("PUBLIC_BASE", "https://media-host.example/media")
# Base da API do provedor de voz (TTS/música/SFX). Sem default revelador.
SPEECH_API_BASE = os.environ.get("SPEECH_API_BASE", "").rstrip("/")
# Modelos / header / voz default do provedor de voz — todos via env, sem valor revelador.
SPEECH_MODEL = os.environ.get("SPEECH_MODEL", "")              # modelo de TTS
SPEECH_MUSIC_MODEL = os.environ.get("SPEECH_MUSIC_MODEL", "")  # modelo de geração de música
SPEECH_API_KEY_HEADER = os.environ.get("SPEECH_API_KEY_HEADER", "x-api-key")
DEFAULT_VOICE_ID = os.environ.get("DEFAULT_VOICE_ID", "")      # voz default (vazio = sem default no código)

# ───────── Auth do serviço (AUD-008) ─────────
SERVICE_TOKEN = os.environ.get("FFMPEG_SERVICE_TOKEN", "")

# ───────── Sanitização (AUD-005 / AUD-009) ─────────
_SAFE_KEY_RE = re.compile(r"^[a-z0-9_-]+$")           # 'kind'/'ext' do /persist
_YT_HOSTS = ("youtube.com", "youtu.be")               # allowlist do /ingest (AUD-015)

# ───────── Allowlist de hosts de mídia interna (AUD-007 SSRF egress) ─────────
# Os endpoints clip/thumbnail/voiceover/shortform/persist só processam mídia do
# NOSSO storage (S3/CDN). O vídeo do YouTube já passa por /ingest → storage S3 antes
# de chegar nesses endpoints, então NÃO há caso legítimo de buscar host externo
# arbitrário aqui. Restringir o fetch a esses hosts corta SSRF de egress sem
# quebrar o fluxo real (clipar/processar mídia nossa).
# Configurável por env MEDIA_ALLOWED_HOSTS (lista separada por vírgula). O default deriva
# dos hosts de PUBLIC_BASE / S3_PUBLIC_BASE (sem domínio hardcoded no código).
def _host_of(u):
    try:
        h = urlparse(u).hostname
        return h.lower() if h else None
    except Exception:
        return None
_DEFAULT_MEDIA_HOSTS = {
    h for h in (
        _host_of(PUBLIC_BASE),
        _host_of(os.environ.get("S3_PUBLIC_BASE", "")),
        _host_of(os.environ.get("MINIO_PUBLIC_BASE", "")),
    ) if h
}
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

# ───────── Object storage S3 (fonte única de verdade) — hub de persistência da mídia ─────────
# Toda mídia gerada (link efêmero de provedor, outputs do ffmpeg) sobe pro storage S3 e
# passa a ser servida pelo host público (via env). Fallback gracioso: se falhar, retorna a
# URL original — NUNCA derruba a geração.
# Endpoint/base SEM default revelador (vêm do .env em produção). Compat: nomes antigos MINIO_*.
S3_ENDPOINT = os.environ.get("S3_ENDPOINT") or os.environ.get("MINIO_ENDPOINT", "")
S3_KEY = os.environ.get("S3_KEY") or os.environ.get("MINIO_KEY", "")
S3_SECRET = os.environ.get("S3_SECRET") or os.environ.get("MINIO_SECRET", "")
S3_BUCKET = os.environ.get("S3_BUCKET") or os.environ.get("MINIO_BUCKET", "public")
S3_PUBLIC_BASE = os.environ.get("S3_PUBLIC_BASE") or os.environ.get("MINIO_PUBLIC_BASE", "")
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
        _s3client().upload_file(local_path, S3_BUCKET, key,
                                ExtraArgs={"ContentType": _CT.get(ext, "application/octet-stream")})
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
        if self.path == "/health":
            self._respond(200, {"ok": True})
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

        # /strip-audio — remove o áudio de um vídeo premium → vídeo MUDO no storage S3.
        # Usado pelo modo "vídeo premium + narração própria": tira o áudio nativo antes da voz.
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
                self._respond(200, {"url": persist_to_s3(tmp_out, "premium-video", "mp4") or body["url"]})
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
                text = body["text"]
                voice_id = body.get("voice_id", DEFAULT_VOICE_ID)
                el_key = os.environ.get("SPEECH_API_KEY", "")
                ts = int(time.time() * 1000)
                vpath = f"/tmp/vo_{ts}.mp4"
                apath = f"/tmp/vo_{ts}.mp3"
                safe_fetch(video_url, vpath)  # AUD-004 + AUD-007 (só storage interno)
                tts_req = _u.Request(
                    f"{SPEECH_API_BASE}/v1/text-to-speech/{voice_id}",
                    data=json.dumps({"text": text, "model_id": SPEECH_MODEL,
                                     "voice_settings": {"stability": 0.5, "similarity_boost": 0.75}}).encode(),
                    headers={SPEECH_API_KEY_HEADER: el_key, "Content-Type": "application/json"}, method="POST")
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
                import urllib.request as _u, base64
                beats = body["beats"]
                voice_id = body.get("voice_id", DEFAULT_VOICE_ID)
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
                el_key = os.environ.get("SPEECH_API_KEY", "")
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
                marginv = int(OUT_H * 0.09)  # 9:16→~172; 16:9→~97 (legenda perto do rodapé, proporcional à altura)
                sub_style = "Fontname=Liberation Sans,FontSize=20,Bold=1,PrimaryColour=&H00FFFFFF,OutlineColour=&H00000000,BorderStyle=1,Outline=3,Shadow=1,Alignment=2,MarginV=%d" % marginv
                MIN_DUR = 0.30   # duração mínima desejada de um cue (segundos)
                MAX_WORDS = 2    # no máximo 2 palavras por cue (legibilidade)
                for i, b in enumerate(beats):
                    clip = "/tmp/sf_%d_%d.mp4" % (ts, i)
                    safe_fetch(b["clip_url"], clip)  # AUD-004 + AUD-007 (só storage interno)
                    ap = None  # caminho do áudio TTS deste beat (None quando narração desligada)
                    srt = None  # caminho da legenda deste beat (None quando legenda desligada)
                    if narration:
                        # ── NARRAÇÃO LIGADA (caminho atual): TTS com timestamps ──
                        # A duração do segmento (D) é ditada pela duração do áudio falado.
                        req = _u.Request("%s/v1/text-to-speech/%s/with-timestamps" % (SPEECH_API_BASE, voice_id),
                            data=json.dumps({"text": b.get("script", ""), "model_id": SPEECH_MODEL,
                                             "voice_settings": {"stability": 0.5, "similarity_boost": 0.75}}).encode(),
                            headers={SPEECH_API_KEY_HEADER: el_key, "Content-Type": "application/json"}, method="POST")
                        with _u.urlopen(req, timeout=90) as r:
                            data = json.loads(r.read())
                        ap = "/tmp/sfa_%d_%d.mp3" % (ts, i)
                        open(ap, "wb").write(base64.b64decode(data["audio_base64"]))
                        al = data.get("alignment") or {}
                        chars = al.get("characters", []); st = al.get("character_start_times_seconds", []); et = al.get("character_end_times_seconds", [])
                        D = (et[-1] if et else 5.0) + 0.35
                        if D < 2.5: D = 2.5
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
                                cues.append("%d\n%s --> %s\n%s\n" % (idx, _t(gstart), _t(gend), " ".join(w[0] for w in grp).upper())); idx += 1
                            if not cues:
                                cues = ["1\n%s --> %s\n%s\n" % (_t(0), _t(D), (b.get("script", "") or "").upper())]
                            open(srt, "w").write("\n".join(cues))
                    else:
                        # ── NARRAÇÃO DESLIGADA: sem TTS (pula o provedor de voz) ──
                        # A duração do segmento = duração REAL do clipe (ffprobe). Sem voz no segmento.
                        D = _seg_dur(clip)
                        if D < 2.5: D = 2.5
                        if subtitles:
                            # Sem alignment do TTS → timing ESTIMADO: distribui as palavras do `script`
                            # uniformemente em [0, D], 1-2 palavras por cue (mesma regra de legibilidade),
                            # com os tempos calculados por divisão igual.
                            raw_words = (b.get("script", "") or "").split()
                            srt = "/tmp/sfsrt_%d_%d.srt" % (ts, i); cues = []; idx = 1
                            # agrupa de 2 em 2 palavras (legibilidade: MAX_WORDS)
                            groups = [raw_words[k:k + MAX_WORDS] for k in range(0, len(raw_words), MAX_WORDS)]
                            n_groups = len(groups)
                            if n_groups > 0:
                                step = D / n_groups  # cada grupo ocupa uma fatia igual da duração do clipe
                                for gi, grp in enumerate(groups):
                                    gstart = gi * step
                                    gend = (gi + 1) * step
                                    cues.append("%d\n%s --> %s\n%s\n" % (idx, _t(gstart), _t(gend), " ".join(grp).upper())); idx += 1
                            if cues:
                                open(srt, "w").write("\n".join(cues))
                            else:
                                srt = None  # script vazio → nada a legendar
                    # Monta o segmento. O filtro de vídeo é o mesmo (pad+trim+crop 9:16); a legenda
                    # só entra quando há .srt (subtitles ligado e com conteúdo).
                    seg = "/tmp/sfseg_%d_%d.mp4" % (ts, i)
                    vf = ("tpad=stop_mode=clone:stop_duration=12,trim=0:%.2f,setpts=PTS-STARTPTS,"
                          "scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d") % (D, OUT_W, OUT_H, OUT_W, OUT_H)
                    if srt:
                        vf += ",subtitles=%s:force_style='%s'" % (srt, sub_style)
                    # CONSISTÊNCIA DE ÁUDIO PARA O CONCAT: o concat -c copy só funde streams com o
                    # MESMO layout. Por isso TODO segmento tem SEMPRE exatamente UMA faixa de áudio:
                    #  - narração ligada  → a voz TTS (mapeada do input de áudio);
                    #  - narração desligada → faixa SILENCIOSA gerada com anullsrc (a música, quando
                    #    ligada, é mixada DEPOIS sobre o vídeo concatenado, não por segmento).
                    # Assim nunca há mistura de "segmento com áudio" + "segmento sem áudio" no concat.
                    if narration and ap:
                        cmd = ["ffmpeg", "-y", "-i", clip, "-i", ap, "-filter_complex", "[0:v]" + vf + "[v]",
                               "-map", "[v]", "-map", "1:a:0", "-t", "%.2f" % D, "-c:v", "libx264",
                               "-pix_fmt", "yuv420p", "-r", "30", "-c:a", "aac", "-ar", "44100", seg]
                    else:
                        # anullsrc = faixa de áudio silenciosa (estéreo, 44.1k) só pra manter o layout
                        # uniforme; o "-shortest" corta o silêncio na duração D do vídeo.
                        cmd = ["ffmpeg", "-y", "-i", clip, "-f", "lavfi", "-i", "anullsrc=channel_layout=stereo:sample_rate=44100",
                               "-filter_complex", "[0:v]" + vf + "[v]",
                               "-map", "[v]", "-map", "1:a:0", "-t", "%.2f" % D, "-shortest", "-c:v", "libx264",
                               "-pix_fmt", "yuv420p", "-r", "30", "-c:a", "aac", "-ar", "44100", seg]
                    r = subprocess.run(cmd, capture_output=True, text=True)
                    if r.returncode != 0:
                        self._respond(500, {"error": "beat %d: %s" % (i, r.stderr[-400:])}); return
                    segs.append(seg)
                listp = "/tmp/sfl_%d.txt" % ts
                with open(listp, "w") as lf:
                    for s in segs:
                        lf.write("file '%s'\n" % s)
                out_filename = "shortform_%d.mp4" % ts
                out_path = "%s/videos/%s" % (MEDIA_BASE, out_filename)
                r = subprocess.run(["ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", listp, "-c", "copy", out_path], capture_output=True, text=True)
                if r.returncode != 0:
                    self._respond(500, {"error": "concat: " + r.stderr[-300:]}); return
                # Música de fundo + SFX de transição (best-effort) — mixados sob a voz/silêncio.
                # Quando music=False a etapa inteira é PULADA (sem geração de trilha/whoosh).
                if music:
                    try:
                        durj = subprocess.run(["ffprobe", "-v", "quiet", "-print_format", "json", "-show_format", out_path], capture_output=True, text=True)
                        dur = float(json.loads(durj.stdout)["format"]["duration"])
                        ms = max(10000, min(300000, int(dur * 1000)))
                        mp = body.get("music_prompt") or "uplifting modern instrumental background music, subtle, social media vibe, energetic, no vocals, no lyrics"
                        mreq = _u.Request("%s/v1/music" % SPEECH_API_BASE, data=json.dumps({"prompt": mp, "music_length_ms": ms, "model_id": SPEECH_MUSIC_MODEL}).encode(), headers={SPEECH_API_KEY_HEADER: el_key, "Content-Type": "application/json"}, method="POST")
                        mpath = "/tmp/sfmusic_%d.mp3" % ts
                        with _u.urlopen(mreq, timeout=150) as mr, open(mpath, "wb") as mf:
                            mf.write(mr.read())
                        inputs = ["-i", out_path, "-i", mpath]
                        fc = ["[1:a]volume=0.16[m]"]
                        mixn = ["[0:a]", "[m]"]
                        try:
                            wreq = _u.Request("%s/v1/sound-generation" % SPEECH_API_BASE, data=json.dumps({"text": "fast cinematic whoosh transition, short", "duration_seconds": 1}).encode(), headers={SPEECH_API_KEY_HEADER: el_key, "Content-Type": "application/json"}, method="POST")
                            wpath = "/tmp/sfwh_%d.mp3" % ts
                            with _u.urlopen(wreq, timeout=60) as wr, open(wpath, "wb") as wf:
                                wf.write(wr.read())
                            bounds = []; acc = 0.0
                            for sg in segs[:-1]:
                                pj = subprocess.run(["ffprobe", "-v", "quiet", "-print_format", "json", "-show_format", sg], capture_output=True, text=True)
                                acc += float(json.loads(pj.stdout)["format"]["duration"]); bounds.append(int(acc * 1000))
                            if bounds:
                                inputs += ["-i", wpath]
                                fc.append("[2:a]volume=0.5,asplit=%d%s" % (len(bounds), "".join("[w%d]" % i for i in range(len(bounds)))))
                                for i, b in enumerate(bounds):
                                    fc.append("[w%d]adelay=%d|%d[d%d]" % (i, b, b, i)); mixn.append("[d%d]" % i)
                        except Exception:
                            pass
                        fc.append("%samix=inputs=%d:duration=first:dropout_transition=0:normalize=0[a]" % ("".join(mixn), len(mixn)))
                        mixed_fn = "shortform_%d_m.mp4" % ts
                        mixed = "%s/videos/%s" % (MEDIA_BASE, mixed_fn)
                        rm = subprocess.run(["ffmpeg", "-y"] + inputs + ["-filter_complex", ";".join(fc), "-map", "0:v", "-map", "[a]", "-c:v", "copy", "-c:a", "aac", "-ar", "44100", mixed], capture_output=True, text=True)
                        if rm.returncode == 0 and os.path.exists(mixed) and os.path.getsize(mixed) > 0:
                            out_filename = mixed_fn
                    except Exception:
                        pass
                self._respond(200, {"video_url": _mk_url(out_filename), "filename": out_filename})
            except Exception as e:
                self._respond(500, {"error": str(e)})

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
    server = HTTPServer(("0.0.0.0", 7788), Handler)
    print("ffmpeg-service rodando em 0.0.0.0:7788 "
          "(POST exige header X-Service-Token; GET /health livre)")
    server.serve_forever()
