"""Reading pages that have no text layer, with a vision model.

markitdown reads the text a document carries. A scanned PDF or a photographed
page carries none, so it used to index as nothing. When the install configures
the vision role, each such page is rendered to an image and transcribed into
Markdown, which then goes through the same chunking as any other document:
headings, lists and tables survive as structure.

Ingestion belongs to no bot, so a blank role borrows the main model of a bot
that reads the collection, which the indexer finds. That model may not read
images; when it fails, the source says whose model it was and what to set. A
collection no bot reads has nothing to borrow, and says that instead.
"""
import base64
import io
import re
from pathlib import Path

import httpx
import pypdfium2 as pdfium
from PIL import Image

import roles
from kb.extract import extract_file

IMAGE_SUFFIXES = {".png", ".jpg", ".jpeg", ".webp"}

# A long scan costs one model call a page, and indexing runs while the operator
# waits for the source to turn ready.
MAX_PAGES = 40

# PDF points are 1/72 inch, so 2.0 renders at 144 dpi: an A4 page is about
# 1190 by 1684 pixels, enough for footnote-sized print.
RENDER_SCALE = 2.0

# A page of real text carries hundreds of characters. A scan carries none, or a
# stray page number.
MIN_CHARS_PER_PAGE = 50

TIMEOUT = httpx.Timeout(180.0, connect=10.0)

PROMPT = ("Transcribe this page into Markdown. Keep headings as headings, lists as lists "
          "and tables as Markdown tables. Write only what is on the page, in its own "
          "language, with no commentary. If the page is blank, reply with nothing.")

NO_VISION_FOR_IMAGE = ("Images need a vision model. Set one under Models in admin settings, "
                       "or connect this collection to a bot whose main model reads images, "
                       "then re-index.")
NO_VISION_FOR_SCAN = ("This PDF has no text layer, so it looks scanned. Set a vision model "
                      "under Models in admin settings, or connect this collection to a bot "
                      "whose main model reads images, then re-index.")

_FENCE = re.compile(r"^```[a-zA-Z]*\n(.*)\n```$", re.DOTALL)


def needs_vision(text: str, page_count: int) -> bool:
    """Whether a PDF's own text is too thin to be its real content."""
    return page_count > 0 and len((text or "").strip()) < MIN_CHARS_PER_PAGE * page_count


def unfence(text: str) -> str:
    """A transcription without the code fence models wrap Markdown in."""
    stripped = (text or "").strip()
    match = _FENCE.match(stripped)
    return match.group(1).strip() if match else stripped


def pdf_page_count(path: Path) -> int:
    document = pdfium.PdfDocument(str(path))
    try:
        return len(document)
    finally:
        document.close()


def render_pdf_pages(path: Path, max_pages: int = MAX_PAGES,
                     scale: float = RENDER_SCALE) -> list[bytes]:
    """Each page as a PNG, up to max_pages."""
    document = pdfium.PdfDocument(str(path))
    try:
        pages = []
        for index in range(min(len(document), max_pages)):
            page = document[index]
            try:
                pages.append(_png(page.render(scale=scale).to_pil()))
            finally:
                page.close()
        return pages
    finally:
        document.close()


def image_as_png(path: Path) -> bytes:
    """An uploaded image as PNG, whatever format it arrived in."""
    with Image.open(path) as image:
        return _png(image.convert("RGB"))


def _png(image) -> bytes:
    buffer = io.BytesIO()
    image.save(buffer, format="PNG")
    return buffer.getvalue()


class VisionClient:
    def __init__(self, base_url: str, api_key: str, model: str,
                 borrowed_from: str | None = None):
        self.base_url = base_url.rstrip("/")
        self.api_key = api_key or ""
        self.model = model
        # The bot whose main model this is, when the vision role was blank.
        self.borrowed_from = borrowed_from

    async def transcribe(self, png: bytes, transport=None) -> str:
        """One page's text as Markdown. Raises when the endpoint fails."""
        headers = {"Content-Type": "application/json"}
        if self.api_key:
            headers["Authorization"] = f"Bearer {self.api_key}"

        image_url = "data:image/png;base64," + base64.b64encode(png).decode("ascii")
        payload = {
            "model": self.model,
            "messages": [{"role": "user", "content": [
                {"type": "text", "text": PROMPT},
                {"type": "image_url", "image_url": {"url": image_url}},
            ]}],
            "stream": False,
            "temperature": 0.0,
            "max_tokens": 4096,
            # A thinking model would spend the page's budget describing it.
            "chat_template_kwargs": {"enable_thinking": False},
        }

        async with httpx.AsyncClient(timeout=TIMEOUT, transport=transport) as client:
            while True:
                response = await client.post(f"{self.base_url}/chat/completions",
                                             headers=headers, json=payload)
                if (response.status_code == 400 and "chat_template_kwargs" in payload
                        and "chat_template_kwargs" in response.text):
                    payload.pop("chat_template_kwargs")
                    continue
                response.raise_for_status()
                break

        return unfence(response.json()["choices"][0]["message"]["content"] or "")


def client_for(settings: dict, bot=None) -> VisionClient | None:
    """A client for the vision role, borrowing the bot's main model when the role
    is blank. None when there is nothing to call."""
    endpoint = roles.endpoint_for("vision", bot, settings)
    if not endpoint.available:
        return None

    return VisionClient(endpoint.base_url, endpoint.api_key, endpoint.model,
                        borrowed_from=None if endpoint.configured else bot.name)


async def _transcribe(client, png: bytes) -> str:
    """One page, with a failure of a borrowed model explained.

    A bot's main model is often a text model. Its endpoint's own complaint says
    little to an operator who never chose it to read images.
    """
    try:
        return await client.transcribe(png)
    except Exception as error:
        if not getattr(client, "borrowed_from", None):
            raise
        raise ValueError(
            f"No Vision model is set, so this was read with the main model of "
            f"{client.borrowed_from} ({client.model}), which failed: {str(error)[:200]}. "
            f"Set a Vision model under Models in admin settings, or give that bot a "
            f"model that reads images, then re-index.") from error


async def read_file(path: Path, settings: dict, make_client=None) -> str:
    """The text of an uploaded file, transcribed where the file carries none."""
    suffix = path.suffix.lower()
    make_client = make_client or client_for

    if suffix in IMAGE_SUFFIXES:
        if not path.exists():
            raise FileNotFoundError(f"upload not found: {path}")
        client = make_client(settings)
        if client is None:
            raise ValueError(NO_VISION_FOR_IMAGE)
        return await _transcribe(client, image_as_png(path))

    text = extract_file(path)
    if suffix != ".pdf":
        return text

    pages = pdf_page_count(path)
    if not needs_vision(text, pages):
        return text

    client = make_client(settings)
    if client is None:
        # Thin text is still some text. Only a PDF with none at all is refused.
        if text.strip():
            return text
        raise ValueError(NO_VISION_FOR_SCAN)

    if pages > MAX_PAGES:
        print(f"[Vision] {path.name} has {pages} pages; reading the first {MAX_PAGES}.")

    transcribed = [await _transcribe(client, png) for png in render_pdf_pages(path)]

    return "\n\n".join(part for part in transcribed if part.strip()) or text
