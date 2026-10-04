# One image, two builds:
#   CPU (VITS; F5 runs, slowly):  docker compose --profile cpu up -d --build
#   NVIDIA GPU (VITS and F5):     docker compose --profile gpu up -d --build
# The GPU build starts from an image that already has CUDA PyTorch. On the DGX
# Sparks (ARM64), set BASE to an NGC PyTorch image, e.g.
# nvcr.io/nvidia/pytorch:25.09-py3.
ARG BASE=python:3.11-slim
FROM ${BASE}

ARG TORCH_INDEX=https://download.pytorch.org/whl/cpu

ENV PYTHONUNBUFFERED=1 PIP_NO_CACHE_DIR=1 \
    HF_HOME=/models/huggingface TTS_DATA_DIR=/data

RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg libsndfile1 curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Keep the base image's PyTorch when it has one, so pip does not swap a CUDA
# build for a CPU one.
RUN python -c "import torch, torchaudio" 2>/dev/null \
    || pip install torch torchaudio --index-url ${TORCH_INDEX}

COPY requirements.txt .
RUN pip install -r requirements.txt

COPY . .

EXPOSE 5051
HEALTHCHECK --interval=30s --timeout=5s CMD curl -fsS http://127.0.0.1:5051/health || exit 1
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "5051"]
