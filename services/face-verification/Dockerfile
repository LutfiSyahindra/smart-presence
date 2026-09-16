FROM python:3.13-slim

ENV DEBIAN_FRONTEND=noninteractive \
    DEEPFACE_HOME=/home/face-service \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

RUN apt-get update \
    && apt-get install --no-install-recommends -y ffmpeg libgl1 libglib2.0-0 libgomp1 libsm6 libxext6 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt ./requirements.txt
RUN pip install --no-cache-dir --upgrade pip \
    && pip install --no-cache-dir -r requirements.txt

RUN useradd --create-home --shell /usr/sbin/nologin face-service \
    && mkdir -p /home/face-service/.deepface/weights \
    && chown -R face-service:face-service /home/face-service /app

COPY --chown=face-service:face-service app ./app

USER face-service

EXPOSE 8001

HEALTHCHECK --interval=30s --timeout=5s --start-period=300s --retries=3 \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8001/api/v1/health/live', timeout=3)"

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001", "--workers", "1"]
