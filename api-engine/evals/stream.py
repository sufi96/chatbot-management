"""Asking the running engine one question, the way the widget does, and timing it.

Over HTTP rather than by calling the route in-process, because an evaluation is
meant to see what a visitor sees: the real models, the real database and the
real network between them.
"""
import json
import time
from dataclasses import dataclass, field

import httpx

from reasoning import TranscriptCollector

# A cold model on a small machine can take tens of seconds before its first
# token. A timeout here would record a slow answer as no answer.
TIMEOUT = httpx.Timeout(300.0, connect=10.0)


@dataclass
class Observed:
    status: int = 0
    answer: str = ""
    # The kind of source that answered, or "none" when no sources event came.
    source: str = "none"
    citations: list = field(default_factory=list)
    model: str = ""
    tokens_in: int | None = None
    tokens_out: int | None = None
    first_token_seconds: float | None = None
    total_seconds: float = 0.0
    error: str = ""


async def ask(engine_url: str, bot_id: str, case, session_id: str,
              transport=None, clock=time.perf_counter) -> Observed:
    """One case, sent and read exactly as the widget sends and reads it.

    The widget puts the message being asked at the end of its history as well,
    so this does too: an evaluation that sent less would measure a request no
    visitor ever makes.
    """
    observed = Observed()
    transcript = TranscriptCollector()
    body = {
        "bot_id": bot_id,
        "session_id": session_id,
        "message": case.message,
        "history": [dict(turn) for turn in case.history]
                   + [{"role": "user", "content": case.message}],
    }

    started = clock()
    try:
        async with httpx.AsyncClient(timeout=TIMEOUT, transport=transport) as client:
            async with client.stream("POST", f"{engine_url.rstrip('/')}/api/v1/chat/stream",
                                     json=body) as response:
                observed.status = response.status_code
                if response.status_code != 200:
                    detail = (await response.aread()).decode(errors="replace")[:200]
                    observed.error = f"HTTP {response.status_code}: {detail}"
                    return _finish(observed, transcript, started, clock)

                async for line in response.aiter_lines():
                    if not line.startswith("data: "):
                        continue
                    raw = line[6:].strip()
                    if raw == "[DONE]":
                        break
                    try:
                        payload = json.loads(raw)
                    except ValueError:
                        continue

                    if payload.get("type") == "sources":
                        observed.source = payload.get("kind") or "none"
                        observed.citations = list(payload.get("sources") or [])
                        continue
                    if payload.get("error"):
                        observed.error = str(payload["error"])
                    if payload.get("meta"):
                        meta = payload["meta"]
                        observed.model = meta.get("model", "")
                        observed.tokens_in = meta.get("tokens_in")
                        observed.tokens_out = meta.get("tokens_out")
                    if payload.get("content") and observed.first_token_seconds is None:
                        observed.first_token_seconds = clock() - started

                    transcript.observe(payload)
    except httpx.HTTPError as error:
        observed.error = f"{type(error).__name__}: {error}"

    return _finish(observed, transcript, started, clock)


def _finish(observed: Observed, transcript: TranscriptCollector, started: float, clock) -> Observed:
    observed.answer = transcript.answer
    observed.total_seconds = clock() - started
    return observed
