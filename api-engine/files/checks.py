"""The cheap checks every file passes before it is read or stored.

The real type comes from the bytes, never from the name: a program called
report.pdf is a program. Office files are zips, so they are opened and looked
through for macros and for a zip bomb; PDFs are searched for scripts and
embedded files; images are decoded and saved again, which drops hidden
payloads and EXIF data such as GPS location. Every refusal is a sentence a
visitor can read.
"""
import io
import zipfile
from dataclasses import dataclass

from PIL import Image

MIMES = {
    "pdf": "application/pdf",
    "docx": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "xlsx": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "pptx": "application/vnd.openxmlformats-officedocument.presentationml.presentation",
    "csv": "text/csv",
    "txt": "text/plain",
    "md": "text/markdown",
    "png": "image/png",
    "jpg": "image/jpeg",
    "webp": "image/webp",
}

TEXT_KINDS = ("txt", "md", "csv")
OFFICE_FOLDERS = {"word/": "docx", "xl/": "xlsx", "ppt/": "pptx"}
PDF_RISKS = (b"/JavaScript", b"/JS", b"/Launch", b"/EmbeddedFile")

ZIP_MAX_ENTRIES = 1000
ZIP_MAX_BYTES = 100 * 1024 * 1024
ZIP_MAX_RATIO = 100

Image.MAX_IMAGE_PIXELS = 40_000_000


class Refused(ValueError):
    """A file that may not go any further, with the reason a visitor is shown."""


@dataclass(frozen=True)
class Checked:
    ext: str
    mime: str
    data: bytes


def _claimed(filename: str) -> str:
    ext = filename.rsplit(".", 1)[-1].lower() if "." in filename else ""
    return {"jpeg": "jpg", "markdown": "md"}.get(ext, ext)


def _sniff(data: bytes, claimed: str) -> str:
    if data.startswith(b"%PDF-"):
        return "pdf"
    if data.startswith(b"\x89PNG\r\n\x1a\n"):
        return "png"
    if data.startswith(b"\xff\xd8\xff"):
        return "jpg"
    if data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        return "webp"
    if data.startswith(b"PK\x03\x04"):
        return _office(data)
    if claimed in TEXT_KINDS and b"\x00" not in data:
        try:
            data.decode("utf-8")
            return claimed
        except UnicodeDecodeError:
            pass
    raise Refused("This file's contents are not a type this chat accepts.")


def _office(data: bytes) -> str:
    try:
        archive = zipfile.ZipFile(io.BytesIO(data))
        entries = archive.infolist()
    except zipfile.BadZipFile:
        raise Refused("This file is damaged and cannot be opened.")

    total = sum(e.file_size for e in entries)
    packed = sum(e.compress_size for e in entries) or 1
    if len(entries) > ZIP_MAX_ENTRIES or total > ZIP_MAX_BYTES or total / packed > ZIP_MAX_RATIO:
        raise Refused("This file expands to far more than its size and was refused.")

    names = [e.filename for e in entries]
    if any(n.lower().endswith("vbaproject.bin") for n in names):
        raise Refused("Files with macros are not accepted. Save it without macros and try again.")
    if "[Content_Types].xml" in names:
        for folder, kind in OFFICE_FOLDERS.items():
            if any(n.startswith(folder) for n in names):
                return kind
    raise Refused("This file's contents are not a type this chat accepts.")


def _clean_image(data: bytes, ext: str) -> bytes:
    try:
        image = Image.open(io.BytesIO(data))
        image.load()
    except Exception:
        raise Refused("This picture could not be opened.")
    out = io.BytesIO()
    if ext == "jpg":
        image.convert("RGB").save(out, "JPEG", quality=92)
    else:
        image.save(out, {"png": "PNG", "webp": "WEBP"}[ext])
    return out.getvalue()


def inspect(data: bytes, filename: str, allowed: tuple) -> Checked:
    claimed = _claimed(filename)
    real = _sniff(data, claimed)
    if real != claimed:
        raise Refused(f"This file is named .{claimed or '?'} but is really .{real}. Rename it or send another.")
    if real not in allowed:
        raise Refused(f"{real.upper()} files are not accepted here.")
    if real == "pdf" and any(risk in data for risk in PDF_RISKS):
        raise Refused("PDFs with scripts or embedded files are not accepted.")
    if real in ("png", "jpg", "webp"):
        data = _clean_image(data, real)
    return Checked(ext=real, mime=MIMES[real], data=data)
