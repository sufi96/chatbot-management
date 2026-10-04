"""Encoding a waveform as the response_format an OpenAI audio request asked for."""
import io
import wave

import numpy as np

MEDIA_TYPES = {"mp3": "audio/mpeg", "wav": "audio/wav", "pcm": "audio/pcm"}


def pcm16(samples: np.ndarray) -> bytes:
    samples = np.clip(np.asarray(samples, dtype=np.float32).reshape(-1), -1.0, 1.0)
    return (samples * 32767).astype("<i2").tobytes()


def encode(samples: np.ndarray, rate: int, fmt: str) -> tuple[bytes, str]:
    """Bytes and media type. mp3 needs the lameenc package; without it the answer is wav."""
    raw = pcm16(samples)
    if fmt == "pcm":
        return raw, MEDIA_TYPES["pcm"]
    if fmt == "mp3":
        try:
            import lameenc
        except ImportError:
            fmt = "wav"
        else:
            encoder = lameenc.Encoder()
            encoder.set_bit_rate(64)
            encoder.set_in_sample_rate(rate)
            encoder.set_channels(1)
            encoder.set_quality(2)
            return bytes(encoder.encode(raw) + encoder.flush()), MEDIA_TYPES["mp3"]
    buffer = io.BytesIO()
    with wave.open(buffer, "wb") as out:
        out.setnchannels(1)
        out.setsampwidth(2)
        out.setframerate(rate)
        out.writeframes(raw)
    return buffer.getvalue(), MEDIA_TYPES["wav"]
