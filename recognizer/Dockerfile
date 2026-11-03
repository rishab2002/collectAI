# The local car recognizer: OpenCV + Tesseract behind a small HTTP service.
# Talks to no outside API. Built from recognizer/ only:
#
#   docker build --target runtime -t collectai-recognizer recognizer/
#
# `--target runtime` matters: the last stage is the training image (PyTorch,
# ~1 GB more), which the service never needs.
#
# Listens on 8080 inside the compose network; never publish it to the
# internet — it has no authentication of its own.
FROM python:3.13.7-slim-bookworm AS runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends tesseract-ocr tesseract-ocr-eng \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/recognizer
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY collectai_recognizer ./collectai_recognizer
COPY data ./data
COPY tests ./tests
COPY train ./train

RUN useradd --system --uid 10001 recognizer
USER recognizer

ENV RECOGNIZER_INDEX=/data/catalog-index.json \
    RECOGNIZER_MODEL_DIR=/data/model \
    PYTHONUNBUFFERED=1

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8080/health', timeout=4)"

CMD ["python", "-m", "collectai_recognizer", "serve", "--host", "0.0.0.0", "--port", "8080"]

# The training image: the runtime plus CPU PyTorch. Only built when the
# `trainer` service is used (compose profile "training"); the recognizer image
# above never carries PyTorch.
FROM runtime AS train
USER root
HEALTHCHECK NONE
COPY requirements-train.txt .
RUN pip install --no-cache-dir --index-url https://download.pytorch.org/whl/cpu torch==2.6.0 \
    && pip install --no-cache-dir -r requirements-train.txt
ENV TRAINING_DATA=/data/training-data \
    HF_HOME=/data/.huggingface
CMD ["python", "-m", "train"]
