FROM python:3.12-slim
RUN pip install --no-cache-dir flask boto3 gunicorn
WORKDIR /app
COPY app.py /app/app.py
USER nobody
EXPOSE 8080
CMD ["gunicorn", "-b", "0.0.0.0:8080", "-w", "2", "--timeout", "60", "app:app"]
