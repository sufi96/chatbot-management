"""What an attached file says, as text the answer model can read.

Documents go through the same reader the knowledge base uses (kb/extract.py).
Images go through the vision role (kb/vision.py), so even a text-only chat
model can answer about a photo once some model can see. A file that cannot be
read is kept, with a reason, and the answer says it could not read it rather
than guessing. Text that reads as instructions to the model is withheld.
"""
import tempfile
from dataclasses import dataclass
from pathlib import Path

import shield
from files.settings import IMAGE_TYPES

MAX_CHARS = 200_000

NO_IMAGE_READER = "No model here can read pictures, so this image could not be read."
UNREADABLE = "This file could not be read."
WITHHELD = "This file's text reads as instructions to the assistant, so it was not passed on."


@dataclass(frozen=True)
class Read:
    text: str | None
    # Shown to the visitor and the model when text is None.
    note: str = ""
    # For the operator: what actually went wrong.
    error: str = ""
    flag: str | None = None


async def read(data: bytes, ext: str, settings: dict, bot) -> Read:
    from kb import vision

    with tempfile.TemporaryDirectory() as folder:
        path = Path(folder) / f"upload.{ext}"
        path.write_bytes(data)
        try:
            if ext in IMAGE_TYPES:
                client = vision.client_for(settings, bot)
                if client is None:
                    return Read(None, NO_IMAGE_READER, "no vision model")
                text = await vision.read_file(path, settings, make_client=lambda s: client)
            else:
                text = await vision.read_file(path, settings, make_client=lambda s: vision.client_for(s, bot))
        except Exception as error:
            note = NO_IMAGE_READER if ext in IMAGE_TYPES else UNREADABLE
            return Read(None, note, str(error)[:500])

    text = (text or "").strip()[:MAX_CHARS]
    if not text:
        return Read(None, UNREADABLE, "no text found")
    if shield.detect(text).found:
        return Read(None, WITHHELD, "withheld by the shield", flag=shield.FLAG)
    return Read(text)
