"""Turn an uploaded document into plain text.

markitdown converts PDF, Word, PowerPoint, Excel, CSV, HTML and Markdown
through one interface, which is why it is the single dependency here rather
than a different parser per format.
"""
from pathlib import Path

from markitdown import MarkItDown

from config import settings

SUPPORTED_UPLOAD_SUFFIXES = {
    ".pdf", ".docx", ".pptx", ".xlsx", ".xls",
    ".csv", ".md", ".markdown", ".txt", ".html", ".htm", ".json", ".xml",
}


def resolve_upload(file_path: str) -> Path:
    """Absolute path of an upload, confined to the upload root."""
    root = Path(settings.UPLOAD_ROOT).resolve()
    candidate = (root / file_path).resolve()
    if root not in candidate.parents and candidate != root:
        raise ValueError(f"upload path escapes the upload root: {file_path}")
    return candidate


def extract_file(path: Path) -> str:
    if not path.exists():
        raise FileNotFoundError(f"upload not found: {path}")

    suffix = path.suffix.lower()
    if suffix not in SUPPORTED_UPLOAD_SUFFIXES:
        raise ValueError(f"File type {suffix or 'unknown'} is not supported.")

    # Plain text needs no conversion, and routing it through a converter only
    # adds a way for it to fail.
    if suffix in (".txt", ".md", ".markdown"):
        return path.read_text(encoding="utf-8", errors="replace").strip()

    result = MarkItDown().convert(str(path))
    return (result.text_content or "").strip()
