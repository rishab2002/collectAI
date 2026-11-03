# The local car recognizer: OpenCV + Tesseract behind a small HTTP service.
# Talks to no outside API. Built from recognizer/ only:
#
#   docker build -t collectai-recognizer recognizer/
#
# Listens on 8080 inside the compose network; never publish it to the
# internet — it has no authentication of its own.
FROM python:3.13.7-slim-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends tesseract-ocr tesseract-ocr-eng \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/recognizer
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY collectai_recognizer ./collectai_recognizer
COPY data ./data
COPY tests ./tests

RUN useradd --system --uid 10001 recognizer
USER recognizer

ENV RECOGNIZER_INDEX=/data/catalog-index.json \
    PYTHONUNBUFFERED=1

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8080/health', timeout=4)"

CMD ["python", "-m", "collectai_recognizer", "serve", "--host", "0.0.0.0", "--port", "8080"]
