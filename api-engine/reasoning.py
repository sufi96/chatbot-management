"""Separates a model's visible thinking from the answer it finally gives.

Three shapes of stream arrive here. A server running a reasoning parser puts
thinking in its own ``reasoning_content`` delta field. Without that parser the
thinking arrives inline in ``content``, wrapped in ``<think>`` tags and split
across chunk boundaries wherever the tokeniser happened to break.

The third shape is the awkward one. A Qwen3 chat template pre-fills the
opening tag into the prompt, so the model emits only the closing tag. Nothing
marks the start of that thinking, and it is indistinguishable from an answer
until ``</think>`` arrives. So it streams out as answer text and is corrected
the moment the tag appears, with a ``reclassify`` event telling whoever is
reading that everything so far belongs to the thinking instead.

A stream that ends before any closing tag stays an answer. Without the tag
there is nothing to tell thinking and answer apart, and guessing would put
real answers behind a disclosure.
"""

OPEN_TAG = "<think>"
CLOSE_TAG = "</think>"


def _prefix_held_back(buffer: str, tag: str) -> int:
    """Length of the buffer tail that could still grow into ``tag``."""
    for n in range(min(len(buffer), len(tag) - 1), 0, -1):
        if buffer.endswith(tag[:n]):
            return n
    return 0


class ReasoningSplitter:
    """Turns raw streaming deltas into ``(kind, text)`` events.

    ``kind`` is ``"reasoning"``, ``"content"``, or ``"reclassify"``, the last
    of which carries ``"reasoning"`` and means every piece of content emitted
    so far was thinking after all.

    With ``enabled`` false the thinking is still parsed out, so no tag ever
    reaches the visitor, but it is discarded rather than reported and no
    reclassification happens. An answer is never taken away.
    """

    def __init__(self, enabled: bool = True):
        self.enabled = enabled
        self._in_think = False
        self._buffer = ""
        # True until we learn how this server marks thinking. A closing tag
        # with no opening one is only meaningful while this holds.
        self._orphan_possible = True

    def feed(self, delta: dict) -> list:
        events = []

        separate = delta.get("reasoning_content")
        if separate:
            # This server speaks the structured protocol, so a tag in the
            # answer text is the visitor's own subject matter, not a marker.
            self._orphan_possible = False
            if self.enabled:
                events.append(("reasoning", separate))

        content = delta.get("content")
        if content:
            self._buffer += content
            events.extend(self._drain())

        return events

    def flush(self) -> list:
        """Release whatever is held back once the stream is over."""
        events = self._emit(self._buffer)
        self._buffer = ""
        return events

    def _drain(self) -> list:
        events = []
        while True:
            if self._in_think:
                closes_at = self._buffer.find(CLOSE_TAG)
                if closes_at >= 0:
                    events.extend(self._emit(self._buffer[:closes_at]))
                    self._buffer = self._buffer[closes_at + len(CLOSE_TAG):]
                    self._in_think = False
                    continue
                watched = (CLOSE_TAG,)
            else:
                opens_at = self._buffer.find(OPEN_TAG)
                closes_at = self._buffer.find(CLOSE_TAG) if self._orphan_possible else -1

                if opens_at >= 0 and (closes_at < 0 or opens_at <= closes_at):
                    events.extend(self._emit(self._buffer[:opens_at]))
                    self._buffer = self._buffer[opens_at + len(OPEN_TAG):]
                    self._in_think = True
                    self._orphan_possible = False
                    continue

                if closes_at >= 0:
                    # The block was opened in the prompt rather than by the
                    # model, so everything up to here was thinking.
                    events.extend(self._emit(self._buffer[:closes_at]))
                    self._buffer = self._buffer[closes_at + len(CLOSE_TAG):]
                    self._orphan_possible = False
                    if self.enabled:
                        events.append(("reclassify", "reasoning"))
                    continue

                watched = (OPEN_TAG, CLOSE_TAG) if self._orphan_possible else (OPEN_TAG,)

            # A partial tag at the tail waits for the next chunk. Everything
            # before it is already unambiguous and can go out now.
            held = max(_prefix_held_back(self._buffer, tag) for tag in watched)
            cut = len(self._buffer) - held
            events.extend(self._emit(self._buffer[:cut]))
            self._buffer = self._buffer[cut:]
            return events

    def _emit(self, text: str) -> list:
        if not text:
            return []
        if not self._in_think:
            return [("content", text)]
        return [("reasoning", text)] if self.enabled else []


class TranscriptCollector:
    """Gathers what to store once a stream has finished.

    The answer and the thinking are kept apart so the saved transcript holds
    what the visitor actually read, with the narration beside it rather than
    inside it. Events that are neither, such as sources and errors, pass by.
    """

    def __init__(self):
        self._content = []
        self._reasoning = []
        self._tokens = 0

    def observe(self, payload: dict) -> None:
        meta = payload.get("meta")
        if meta:
            self._tokens = int(meta.get("tokens_in", 0) or 0) + int(meta.get("tokens_out", 0) or 0)
            return

        if payload.get("reclassify") == "reasoning":
            # A late closing tag revealed the answer so far to be thinking.
            self._reasoning.extend(self._content)
            self._content = []
            return

        content = payload.get("content")
        if content:
            self._content.append(content)

        reasoning = payload.get("reasoning")
        if reasoning:
            self._reasoning.append(reasoning)

    @property
    def tokens(self) -> int:
        """Prompt plus completion tokens, or zero when none were reported."""
        return self._tokens

    @property
    def answer(self) -> str:
        return "".join(self._content).strip()

    @property
    def thinking(self):
        """The narration, or None so the column stays empty when there was none."""
        return "".join(self._reasoning).strip() or None
