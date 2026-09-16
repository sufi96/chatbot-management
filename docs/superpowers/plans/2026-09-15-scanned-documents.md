# Scanned Documents Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When an install configures the `vision` role, a scanned PDF or an uploaded image is transcribed page by page into Markdown and indexed like any other document. With nothing configured, a scan still cannot be read, and the source now says why and what to do.

**Architecture:**
- **Vision module.** A new `api-engine/kb/vision.py` decides whether a PDF's own text is too thin to be its content, renders its pages to PNG with pypdfium2, and sends each page to the vision role as an OpenAI-compatible image message.
- **Indexer.** `kb/indexer.py` reads file sources through `vision.read_file`, so text documents are untouched.
- **Role.** `vision` is a specialist role: ingestion belongs to no bot, and a chat model may not read images, so blank means no stand-in.
- **Portal.** It accepts PNG, JPG and WebP uploads.

**Tech Stack:** Python, httpx, pypdfium2, Pillow, pytest; Laravel 13, PHPUnit, Blade.

**Spec:** `docs/model-stack-review.md` sections 1 to 3 and 6, and the plan 6 row in `docs/superpowers/plans/2026-09-15-spark-readiness-roadmap.md`.

## Global Constraints

- **Role.** `vision` joins `roles.SPECIALIST`. Its three settings default to `''` on both sides, and a blank role is never a client.
- **Scan detection.** A PDF counts as a scan when its stripped extracted text is shorter than `50 × page count`. Only PDFs are ever checked.
- **Rendering.** Pages are rendered at scale 2.0 and sent as PNG. At most 40 pages are read; later pages are skipped with a log line.
- **Request.** `POST {base_url}/chat/completions` with one user message whose content parts are the prompt text and an `image_url` data URI. It sets `temperature` 0, `max_tokens` 4096, no streaming, and `chat_template_kwargs: {"enable_thinking": false}`, dropped and retried once when the endpoint refuses it. A failed transcription raises, so the source records an error instead of indexing a partial document.
- **Output.** Transcriptions lose a wrapping Markdown fence, are joined with a blank line, and empty pages are skipped.
- **Errors.** A scan or image with no vision role raises `ValueError` with the exact messages in Appendix D.
- **Image suffixes.** `.png`, `.jpg`, `.jpeg`, `.webp`.
- **Dependencies.** pypdfium2 and Pillow are already installed through markitdown's PDF extras. They are added to `requirements.txt` explicitly because this code imports them directly.
- **Commands.** Engine tests run from `api-engine/` with `.venv\Scripts\python.exe -m pytest`. Portal tests run from `admin-laravel/` with `php artisan test`.

## File map

| File | Change | Responsibility |
|---|---|---|
| `api-engine/roles.py`, `database.py` | Modify | `vision` role and defaults |
| `api-engine/tests/test_roles.py` | Modify | Vision role tests |
| `admin-laravel/app/Models/AppSetting.php` | Modify | Defaults |
| `admin-laravel/app/Http/Controllers/AdminSettingsController.php` | Modify | `MODEL_ROLES['vision']` |
| `admin-laravel/tests/Feature/ModelRoleSettingsTest.php` | Modify | Five roles |
| `api-engine/kb/vision.py` | Create | Detection, rendering, client, `read_file` |
| `api-engine/tests/test_vision.py` | Create | Vision behaviour |
| `api-engine/requirements.txt` | Modify | pypdfium2, pillow |
| `api-engine/kb/indexer.py` | Modify | `read_source`; `index_source(make_vision=)` |
| `api-engine/tests/test_indexer.py` | Modify | Scans through indexing |
| `admin-laravel/app/Http/Controllers/KnowledgeBaseController.php` | Modify | Image mimes |
| `admin-laravel/resources/views/kb/show.blade.php` | Modify | Accept list and help text |
| `admin-laravel/tests/Feature/KnowledgeBaseUploadTest.php` | Modify | Image upload |
| `docs/architecture.md` | Modify | A section on pages with no text layer |

---

### Task 1: A vision role on both sides

- [ ] **Step 1: Write the failing tests.**
  - Append to `api-engine/tests/test_roles.py`:

```python
def test_a_blank_vision_role_is_unavailable_rather_than_borrowed():
    """Ingestion belongs to no bot, and a chat model may not read images."""
    assert roles.endpoint_for("vision", None, SETTING_DEFAULTS).available is False


def test_a_configured_vision_role_is_available():
    settings = {**SETTING_DEFAULTS,
                "vision_model_base_url": "http://spark-b:8005/v1",
                "vision_model_name": "qwen3-vl-8b"}

    assert roles.endpoint_for("vision", None, settings).available is True
```

  - In `ModelRoleSettingsTest::test_the_roles_match_the_engine`, expect `['intent', 'sql', 'rerank', 'guard', 'vision']`, and change its comment from "the same four" to "the same five".
- [ ] **Step 2: Run them to verify they fail.** Expected: `ValueError: Unknown model role: 'vision'`, and the portal's role-list assertion.
- [ ] **Step 3: Implement.**
  - **`roles.py`:**
    - Change `SPECIALIST = ("rerank",)` to `SPECIALIST = ("rerank", "vision")`.
    - In the docstring, change "A specialist job (rerank)" to "A specialist job (rerank, vision)".
    - In the same bullet, change "because a chat model does not speak the rerank protocol" to "because a chat model does not speak the rerank protocol or may not read images".
  - **`database.py`:** add `vision_model_base_url`, `vision_model_api_key` and `vision_model_name`, all `""`, after the `guard_model_*` defaults.
  - **`AppSetting::DEFAULTS`:** add the same three keys after `guard_model_name`.
  - **`AdminSettingsController::MODEL_ROLES`:** append this entry:

```php
        'vision' => [
            'label' => 'Vision',
            'job' => 'Reads uploaded images, and scanned PDFs with no text layer, page by page up to 40 pages. Needs a model that accepts images. Re-index a source after setting this.',
            'blank' => 'scans and images cannot be read',
            'placeholder' => 'qwen3-vl:8b',
        ],
```

- [ ] **Step 4: Run both suites.** Expected: all pass.
- [ ] **Step 5: Commit** with `feat: a vision role, with no stand-in when blank`.

### Task 2: Reading a page with a vision model

**Interfaces produced:**
- `kb.vision`: constants `IMAGE_SUFFIXES`, `MAX_PAGES = 40`, `RENDER_SCALE = 2.0`, `MIN_CHARS_PER_PAGE = 50`, `PROMPT`, `NO_VISION_FOR_IMAGE`, `NO_VISION_FOR_SCAN`
- `needs_vision(text: str, page_count: int) -> bool`
- `unfence(text: str) -> str`
- `pdf_page_count(path: Path) -> int`
- `render_pdf_pages(path: Path, max_pages=MAX_PAGES, scale=RENDER_SCALE) -> list[bytes]`
- `image_as_png(path: Path) -> bytes`
- `VisionClient(base_url, api_key, model)`, with `.model` and `async .transcribe(png: bytes, transport=None) -> str`
- `client_for(settings: dict) -> VisionClient | None`
- `async read_file(path: Path, settings: dict, make_client=None) -> str`

- [ ] **Step 1:** Create `api-engine/tests/test_vision.py` from Appendix A.
- [ ] **Step 2:** Run it. Expected: `No module named 'kb.vision'`.
- [ ] **Step 3:** Create `api-engine/kb/vision.py` from Appendix B. Add `pypdfium2>=4.0.0` and `pillow>=10.0.0` to `requirements.txt` after `markitdown[all]`.
- [ ] **Step 4:** Run the full engine suite. Expected: all pass.
- [ ] **Step 5:** Commit with `feat: a page with no text layer is transcribed by a vision model`.

### Task 3: Indexing reads files through it

**Interfaces produced:**
- `kb.indexer.read_source(source, settings, make_vision=None) -> str` (async)
- `index_source(session, source_id, embedder=None, make_vision=None)`

- [ ] **Step 1:** Append the tests in Appendix C to `api-engine/tests/test_indexer.py`.
- [ ] **Step 2:** Run that file. Expected: the new tests fail on the unexpected `make_vision` keyword.
- [ ] **Step 3:** Apply Appendix D to `api-engine/kb/indexer.py`.
- [ ] **Step 4:** Run the full engine suite. Expected: all pass, including the existing file and missing-upload tests.
- [ ] **Step 5:** Commit with `feat: scanned uploads are indexed from their transcription`.

### Task 4: The portal accepts images

- [ ] **Step 1: Write the failing test.** Append to `KnowledgeBaseUploadTest.php`:

```php
    public function test_an_image_can_be_uploaded(): void
    {
        // A real one-pixel PNG, so the type is read from the bytes.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=');
        $file = UploadedFile::fake()->createWithContent('menu.png', $png);

        $this->actingAs($this->editor())
            ->post(route('kb.sources.upload', 'kbc_1'), ['file' => $file])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('menu.png', KbSource::first()->title);
    }
```

- [ ] **Step 2: Run it.** Run `php artisan test --filter=KnowledgeBaseUploadTest`. Expected: the new test fails on `file`.
- [ ] **Step 3: Implement.**
  - **`KnowledgeBaseController::uploadSource`:**
    - The mimes rule becomes `'mimes:pdf,docx,pptx,xlsx,xls,csv,md,txt,html,htm,png,jpg,jpeg,webp'`.
    - The message becomes `'That file type is not supported. Use PDF, Word, PowerPoint, Excel, CSV, Markdown, HTML, plain text, or a PNG, JPG or WebP image.'`.
  - **`kb/show.blade.php`:**
    - The card subtitle becomes `PDF, Word, Excel, CSV, Markdown or an image`.
    - The `accept` list gains `,.png,.jpg,.jpeg,.webp`.
    - The help text becomes:

```blade
                                PDF, Word, PowerPoint, Excel, CSV, Markdown, HTML, plain text or an image. Up to 20 MB.
                                Scanned PDFs and images are read by the vision model set in admin settings; without one they cannot be indexed.
```

- [ ] **Step 4: Run both suites.** Expected: all pass. If a test asserts the old subtitle or help text, update it to the new wording.
- [ ] **Step 5: Document it.** Apply Appendix E to `docs/architecture.md`, and set plan 6's roadmap status to `Done`.
- [ ] **Step 6: Commit** with `feat: images and scans can be uploaded to a knowledge base`.

---

## Appendix

### A. `api-engine/tests/test_vision.py`

```python
"""Reading pages that have no text layer.

Real PDFs are made in each test from blank images, which is exactly what a scan
is to a text extractor: pages with nothing to extract. The vision model itself
is faked, or its endpoint is.
"""
import base64
import json

import httpx
import pytest
from PIL import Image

from database import SETTING_DEFAULTS
from kb import vision


def scanned_pdf(path, pages=1):
    images = [Image.new("RGB", (300, 400), "white") for _ in range(pages)]
    images[0].save(path, "PDF", save_all=True, append_images=images[1:])
    return path


class FakeVision:
    model = "fake-vl"

    def __init__(self, replies=None):
        self.replies = list(replies or [])
        self.seen = []

    async def transcribe(self, png, transport=None):
        self.seen.append(png)
        return self.replies.pop(0) if self.replies else "## Warranty\n\nTwo years."


def no_client_expected(settings):
    raise AssertionError("a document with its own text must not need a vision model")


# Deciding

def test_a_page_of_real_text_is_not_a_scan():
    assert vision.needs_vision("x" * 400, 1) is False


def test_nothing_on_a_page_is_a_scan():
    assert vision.needs_vision("", 1) is True


def test_a_stray_page_number_on_each_page_is_still_a_scan():
    assert vision.needs_vision("1 2 3", 3) is True


def test_a_document_with_no_pages_is_not_a_scan():
    assert vision.needs_vision("", 0) is False


def test_a_fenced_transcription_is_unwrapped():
    assert vision.unfence("```markdown\n# Title\n\nBody\n```") == "# Title\n\nBody"


def test_an_unfenced_transcription_is_kept():
    assert vision.unfence("  # Title  ") == "# Title"


# Rendering

def test_every_page_is_rendered_as_png(tmp_path):
    pages = vision.render_pdf_pages(scanned_pdf(tmp_path / "scan.pdf", pages=2))

    assert len(pages) == 2
    assert all(page.startswith(b"\x89PNG") for page in pages)


def test_rendering_stops_at_the_page_limit(tmp_path):
    assert len(vision.render_pdf_pages(scanned_pdf(tmp_path / "long.pdf", pages=3), max_pages=2)) == 2


def test_pages_are_counted(tmp_path):
    assert vision.pdf_page_count(scanned_pdf(tmp_path / "scan.pdf", pages=3)) == 3


def test_an_image_in_any_format_becomes_png(tmp_path):
    path = tmp_path / "photo.jpg"
    Image.new("RGB", (50, 50), "white").save(path, "JPEG")

    assert vision.image_as_png(path).startswith(b"\x89PNG")


# The endpoint

@pytest.mark.asyncio
async def test_a_page_is_sent_as_an_image_message():
    seen = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["url"] = str(request.url)
        seen["auth"] = request.headers.get("authorization")
        seen["body"] = json.loads(request.read().decode())
        return httpx.Response(200, json={"choices": [{"message": {
            "content": "```markdown\n## Warranty\n\nTwo years.\n```"}}]})

    client = vision.VisionClient("http://spark-b:8005/v1", "secret", "qwen3-vl-8b")
    text = await client.transcribe(b"\x89PNGfake", transport=httpx.MockTransport(handler))

    assert seen["url"] == "http://spark-b:8005/v1/chat/completions"
    assert seen["auth"] == "Bearer secret"
    body = seen["body"]
    assert body["model"] == "qwen3-vl-8b"
    assert body["temperature"] == 0.0
    assert body["stream"] is False
    parts = body["messages"][0]["content"]
    assert parts[0] == {"type": "text", "text": vision.PROMPT}
    url = parts[1]["image_url"]["url"]
    assert url.startswith("data:image/png;base64,")
    assert base64.b64decode(url.split(",", 1)[1]) == b"\x89PNGfake"
    assert text == "## Warranty\n\nTwo years."


@pytest.mark.asyncio
async def test_an_endpoint_refusing_the_thinking_switch_is_asked_again():
    bodies = []

    def handler(request):
        bodies.append(json.loads(request.read().decode()))
        if "chat_template_kwargs" in bodies[-1]:
            return httpx.Response(400, text="unknown field chat_template_kwargs")
        return httpx.Response(200, json={"choices": [{"message": {"content": "Text."}}]})

    client = vision.VisionClient("http://x/v1", "", "m")

    assert await client.transcribe(b"png", transport=httpx.MockTransport(handler)) == "Text."
    assert len(bodies) == 2


@pytest.mark.asyncio
async def test_a_failed_transcription_raises():
    """A page silently skipped would index a document with pages missing."""
    def handler(request):
        return httpx.Response(500, text="boom")

    client = vision.VisionClient("http://x/v1", "", "m")

    with pytest.raises(httpx.HTTPStatusError):
        await client.transcribe(b"png", transport=httpx.MockTransport(handler))


def test_a_blank_role_builds_no_client():
    assert vision.client_for(SETTING_DEFAULTS) is None


def test_a_configured_role_builds_a_client():
    client = vision.client_for({**SETTING_DEFAULTS,
                                "vision_model_base_url": "http://spark-b:8005/v1/",
                                "vision_model_name": "qwen3-vl-8b"})

    assert client.model == "qwen3-vl-8b"
    assert client.base_url == "http://spark-b:8005/v1"


# Reading an upload

@pytest.mark.asyncio
async def test_a_scanned_pdf_is_read_page_by_page(tmp_path):
    fake = FakeVision(["# Page one", "# Page two"])

    text = await vision.read_file(scanned_pdf(tmp_path / "scan.pdf", pages=2),
                                  SETTING_DEFAULTS, make_client=lambda settings: fake)

    assert text == "# Page one\n\n# Page two"
    assert len(fake.seen) == 2


@pytest.mark.asyncio
async def test_blank_pages_are_left_out(tmp_path):
    fake = FakeVision(["# Page one", "   "])

    text = await vision.read_file(scanned_pdf(tmp_path / "scan.pdf", pages=2),
                                  SETTING_DEFAULTS, make_client=lambda settings: fake)

    assert text == "# Page one"


@pytest.mark.asyncio
async def test_a_scan_with_no_vision_model_says_what_to_do(tmp_path):
    with pytest.raises(ValueError) as raised:
        await vision.read_file(scanned_pdf(tmp_path / "scan.pdf"), SETTING_DEFAULTS,
                               make_client=lambda settings: None)

    assert str(raised.value) == vision.NO_VISION_FOR_SCAN


@pytest.mark.asyncio
async def test_a_pdf_with_its_own_text_is_not_sent_to_vision(tmp_path, monkeypatch):
    monkeypatch.setattr(vision, "extract_file", lambda path: "Real text on the page. " * 40)

    text = await vision.read_file(scanned_pdf(tmp_path / "doc.pdf"), SETTING_DEFAULTS,
                                  make_client=no_client_expected)

    assert text.startswith("Real text on the page.")


@pytest.mark.asyncio
async def test_an_uploaded_image_is_transcribed(tmp_path):
    path = tmp_path / "menu.png"
    Image.new("RGB", (80, 80), "white").save(path, "PNG")
    fake = FakeVision(["# Menu"])

    text = await vision.read_file(path, SETTING_DEFAULTS, make_client=lambda settings: fake)

    assert text == "# Menu"
    assert fake.seen[0].startswith(b"\x89PNG")


@pytest.mark.asyncio
async def test_an_image_with_no_vision_model_says_what_to_do(tmp_path):
    path = tmp_path / "menu.webp"
    Image.new("RGB", (80, 80), "white").save(path, "WEBP")

    with pytest.raises(ValueError) as raised:
        await vision.read_file(path, SETTING_DEFAULTS, make_client=lambda settings: None)

    assert str(raised.value) == vision.NO_VISION_FOR_IMAGE


@pytest.mark.asyncio
async def test_a_missing_image_is_reported(tmp_path):
    with pytest.raises(FileNotFoundError):
        await vision.read_file(tmp_path / "gone.png", SETTING_DEFAULTS,
                               make_client=lambda settings: FakeVision())


@pytest.mark.asyncio
async def test_other_documents_are_read_as_before(tmp_path):
    path = tmp_path / "policy.txt"
    path.write_text("Refunds within thirty days.", encoding="utf-8")

    text = await vision.read_file(path, SETTING_DEFAULTS, make_client=no_client_expected)

    assert text == "Refunds within thirty days."
```

### B. `api-engine/kb/vision.py`

```python
"""Reading pages that have no text layer, with a vision model.

markitdown reads the text a document carries. A scanned PDF or a photographed
page carries none, so it used to index as nothing. When the install configures
the vision role, each such page is rendered to an image and transcribed into
Markdown, which then goes through the same chunking as any other document:
headings, lists and tables survive as structure.

The role has no stand-in. Ingestion belongs to no bot, and a chat model may not
read images. Blank means a scan still cannot be read, and the source says so in
words an operator can act on.
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
                       "then re-index.")
NO_VISION_FOR_SCAN = ("This PDF has no text layer, so it looks scanned. Set a vision model "
                      "under Models in admin settings, then re-index.")

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
    def __init__(self, base_url: str, api_key: str, model: str):
        self.base_url = base_url.rstrip("/")
        self.api_key = api_key or ""
        self.model = model

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


def client_for(settings: dict) -> VisionClient | None:
    """A client for the install's vision model, or None when none is configured."""
    endpoint = roles.endpoint_for("vision", None, settings)
    if not endpoint.available:
        return None

    return VisionClient(endpoint.base_url, endpoint.api_key, endpoint.model)


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
        return await client.transcribe(image_as_png(path))

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

    transcribed = [await client.transcribe(png) for png in render_pdf_pages(path)]

    return "\n\n".join(part for part in transcribed if part.strip()) or text
```

### C. Tests appended to `api-engine/tests/test_indexer.py`

```python
class FakeVision:
    model = "fake-vl"

    def __init__(self, reply="## Warranty\n\nTwo years on desk lamps."):
        self.reply = reply

    async def transcribe(self, png, transport=None):
        return self.reply


def scanned_upload(tmp_path, monkeypatch, name="scan.pdf"):
    from PIL import Image
    from config import settings

    upload_root = tmp_path / "public"
    (upload_root / "kb" / "sources").mkdir(parents=True)
    target = upload_root / "kb" / "sources" / name
    image = Image.new("RGB", (300, 400), "white")
    image.save(target, "PDF" if name.endswith(".pdf") else "PNG")
    monkeypatch.setattr(settings, "UPLOAD_ROOT", str(upload_root))
    return f"kb/sources/{name}"


@pytest.mark.asyncio
async def test_a_scanned_pdf_is_indexed_from_its_transcription(session, tmp_path, monkeypatch):
    path = scanned_upload(tmp_path, monkeypatch)
    session.add(KbSource(id="scan1", collection_id="col1", type="file",
                         title="Warranty card", file_path=path))
    await session.commit()

    count = await index_source(session, "scan1", embedder=StubEmbedder(),
                               make_vision=lambda settings: FakeVision())

    assert count == 1
    content = (await session.execute(
        text("SELECT content FROM kb_chunks WHERE source_id='scan1'"))).scalar()
    assert "Two years on desk lamps." in content
    assert content.startswith("Section: Warranty card > Warranty")


@pytest.mark.asyncio
async def test_a_scan_with_no_vision_model_explains_itself(session, tmp_path, monkeypatch):
    path = scanned_upload(tmp_path, monkeypatch)
    session.add(KbSource(id="scan2", collection_id="col1", type="file",
                         title="Warranty card", file_path=path))
    await session.commit()

    count = await index_source(session, "scan2", embedder=StubEmbedder(),
                               make_vision=lambda settings: None)

    assert count == 0
    source = await session.get(KbSource, "scan2")
    assert source.status == "error"
    assert "vision model" in source.error_message


@pytest.mark.asyncio
async def test_an_uploaded_image_is_indexed(session, tmp_path, monkeypatch):
    path = scanned_upload(tmp_path, monkeypatch, name="menu.png")
    session.add(KbSource(id="img1", collection_id="col1", type="file",
                         title="Menu", file_path=path))
    await session.commit()

    count = await index_source(session, "img1", embedder=StubEmbedder(),
                               make_vision=lambda settings: FakeVision("Nasi lemak RM 8."))

    assert count == 1
```

### D. Changes to `api-engine/kb/indexer.py`

- Add `from kb import vision` after `from kb.extract import extract_file, resolve_upload`.
- Below `extract_text`, add:

```python
async def read_source(source: KbSource, settings: dict, make_vision=None) -> str:
    """The canonical text for a source, reading scans and images with the vision role.

    Pasted text and question-answer pairs need no reading, so they go through
    extract_text exactly as before.
    """
    if source.type == "file":
        if not source.file_path:
            raise ValueError("Source is a file but has no stored path.")
        return await vision.read_file(resolve_upload(source.file_path), settings,
                                      make_client=make_vision)
    return extract_text(source)
```

- Change `index_source`'s signature to `async def index_source(session, source_id: str, embedder=None, make_vision=None) -> int:`.
- Replace `body = extract_text(source)` with `body = await read_source(source, settings, make_vision)`.

### E. `docs/architecture.md`

Insert after the paragraph ending `Splitting one would return half an answer.`:

```markdown
### Pages with no text layer

A scanned PDF or a photographed page carries no text for markitdown to read.
When the install configures the `vision` role, `kb/vision.py` notices: a PDF
whose extracted text averages under 50 characters a page is treated as a scan.
Each page, up to 40, is rendered at 144 dpi and sent to the vision model, which
transcribes it into Markdown. The transcription is chunked like any other
document, so a table on the page stays a table.

An uploaded PNG, JPG or WebP image is always sent to the vision model. Without
one, an image or a scan is refused with a message naming the setting to change,
instead of the old "no text to index". The role has no stand-in: ingestion
belongs to no bot, and a chat model may not read images.
```
