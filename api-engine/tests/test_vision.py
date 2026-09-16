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


class FakeProvider:
    base_url = "http://laptop:11434/v1"
    api_key = ""


class FakeBot:
    name = "Helpdesk"
    model_name = "qwen3-vl:8b"
    provider = FakeProvider()


def test_a_blank_role_borrows_the_main_model_of_the_bot_given():
    client = vision.client_for(SETTING_DEFAULTS, FakeBot())

    assert client.model == "qwen3-vl:8b"
    assert client.base_url == "http://laptop:11434/v1"
    assert client.borrowed_from == "Helpdesk"


def test_a_configured_role_is_not_marked_as_borrowed():
    client = vision.client_for({**SETTING_DEFAULTS,
                                "vision_model_base_url": "http://spark-b:8005/v1",
                                "vision_model_name": "qwen3-vl-8b"}, FakeBot())

    assert client.model == "qwen3-vl-8b"
    assert client.borrowed_from is None


class FailingVision:
    model = "llama3.2"
    base_url = "http://laptop:11434/v1"

    def __init__(self, borrowed_from):
        self.borrowed_from = borrowed_from

    async def transcribe(self, png, transport=None):
        raise RuntimeError("image input is not supported")


@pytest.mark.asyncio
async def test_a_borrowed_model_that_cannot_read_images_says_what_to_do(tmp_path):
    path = tmp_path / "menu.png"
    Image.new("RGB", (80, 80), "white").save(path, "PNG")

    with pytest.raises(ValueError) as raised:
        await vision.read_file(path, SETTING_DEFAULTS,
                               make_client=lambda settings: FailingVision("Helpdesk"))

    message = str(raised.value)
    assert "Helpdesk" in message and "llama3.2" in message
    assert "image input is not supported" in message
    assert "Vision model" in message


@pytest.mark.asyncio
async def test_a_configured_model_failing_raises_as_before(tmp_path):
    with pytest.raises(RuntimeError):
        await vision.read_file(scanned_pdf(tmp_path / "scan.pdf"), SETTING_DEFAULTS,
                               make_client=lambda settings: FailingVision(None))
