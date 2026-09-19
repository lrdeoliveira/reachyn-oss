FROM python:3.14-slim
RUN pip install --no-cache-dir flask boto3 gunicorn
WORKDIR /app
COPY app.py /app/app.py
USER nobody
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD python -c "import urllib.request;urllib.request.urlopen('http://127.0.0.1:8080/health',timeout=4)" || exit 1
# --no-control-socket: o gunicorn 26 abriu um "control server" que cria um socket em
# $HOME/.gunicorn. O serviço roda como `nobody` (HOME=/nonexistent), então ele falha no
# boot com "Control server error: [Errno 13] Permission denied: '/nonexistent'" a cada
# start. Não usamos a interface de controle; desligar é melhor que dar diretório
# gravável a um processo não-privilegiado. (Apareceu no bump python 3.12→3.14, 2026-08-28.)
CMD ["gunicorn", "-b", "0.0.0.0:8080", "-w", "2", "--timeout", "60", "--no-control-socket", "app:app"]
