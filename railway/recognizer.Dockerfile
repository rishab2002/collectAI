# The recognizer for Railway (README §8.6). Built from the repository root —
# railway/recognizer.json points here — because it bakes in the trained model
# from training/, which recognizer/Dockerfile's context (recognizer/ only)
# can't reach. Under docker compose use recognizer/Dockerfile: there the model
# arrives on a volume from model-publisher.
#
# Keep the `runtime` stage in step with recognizer/Dockerfile's (same base,
# packages and pins).
#
# Why bake the model: Railway services don't share disks, so there is no
# volume for model-publisher to fill. A model committed to training/ on main
# rebuilds and redeploys this service, which is the same "commit the model,
# deploy picks it up" flow ./collectai deploy gives the compose setup.
#
# The catalog index is not baked: it changes as cars are approved, so the
# recognizer fetches it from the app (RECOGNIZER_INDEX_URL; index_sync.py).
#
# railway/recognizer.Dockerfile.dockerignore replaces the root .dockerignore
# for this build — the root one leaves training/ out on purpose.

FROM python:3.13.7-slim-bookworm AS model
WORKDIR /src
COPY recognizer/train ./train
COPY training ./training
# Unpacks and checksum-verifies only the model group (the app's model, not
# the training data) into /out/model.
RUN python -m train.package publish /src/training /out

FROM python:3.13.7-slim-bookworm AS runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends tesseract-ocr tesseract-ocr-eng \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/recognizer
COPY recognizer/requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY recognizer/collectai_recognizer ./collectai_recognizer
COPY recognizer/data ./data

RUN useradd --system --uid 10001 recognizer \
    && mkdir -p /data \
    && chown recognizer /data
COPY --from=model /out/model /data/model
USER recognizer

ENV RECOGNIZER_INDEX=/data/catalog-index.json \
    RECOGNIZER_MODEL_DIR=/data/model \
    PYTHONUNBUFFERED=1

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8080/health', timeout=4)"

# "::" listens on IPv6 and IPv4: Railway's private network is IPv6. The
# service has no authentication of its own — never give it a public domain.
CMD ["python", "-m", "collectai_recognizer", "serve", "--host", "::", "--port", "8080"]
