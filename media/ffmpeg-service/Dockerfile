# ffmpeg-service — micro HTTP de mídia (ffmpeg/ffprobe/yt-dlp + persist Scality).
# Porta do host /vps_nexus/ffmpeg-service/server.py para dentro do stack do Reachyn.
FROM python:3.12-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
      ffmpeg \
      fonts-liberation \
      ca-certificates \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY server.py .

# AUD-017: rodar como usuário não-root.
# O serviço escreve em MEDIA_BASE (/vps_nexus/media/{videos,audio}) e em /tmp (scratch ffmpeg),
# então criamos o usuário e damos posse desses diretórios ANTES do USER.
# CONFERIR: se MEDIA_BASE for um bind mount/volume montado em runtime, o dono no host precisa
# bater com o UID do appuser (1001) OU o mount precisa ser gravável por ele.
RUN useradd -r -u 1001 -m -d /home/appuser appuser \
 && mkdir -p /vps_nexus/media/videos /vps_nexus/media/audio \
 && chown -R appuser:appuser /vps_nexus/media /app
USER appuser

EXPOSE 7788
CMD ["python", "server.py"]
