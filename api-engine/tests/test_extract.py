import pytest

from kb.extract import SUPPORTED_UPLOAD_SUFFIXES, extract_file, resolve_upload


def test_supported_suffixes_cover_the_documented_types():
    for suffix in (".pdf", ".docx", ".pptx", ".xlsx", ".csv", ".md", ".txt", ".html"):
        assert suffix in SUPPORTED_UPLOAD_SUFFIXES


def test_resolve_upload_joins_onto_the_laravel_public_disk():
    resolved = resolve_upload("kb/sources/abc.pdf")
    assert resolved.as_posix().endswith("admin-laravel/storage/app/public/kb/sources/abc.pdf")


def test_resolve_upload_refuses_to_escape_the_upload_root():
    # A stored path is not user input today, but a traversal must never resolve.
    with pytest.raises(ValueError):
        resolve_upload("../../../../etc/passwd")


def test_extract_reads_a_plain_text_file(tmp_path):
    target = tmp_path / "note.txt"
    target.write_text("Refunds within thirty days.", encoding="utf-8")

    assert "thirty days" in extract_file(target)


def test_extract_reads_a_csv(tmp_path):
    target = tmp_path / "table.csv"
    target.write_text("question,answer\nrefund window,thirty days\n", encoding="utf-8")

    assert "thirty days" in extract_file(target)


def test_extract_rejects_an_unsupported_type(tmp_path):
    target = tmp_path / "thing.exe"
    target.write_bytes(b"MZ")

    with pytest.raises(ValueError) as excinfo:
        extract_file(target)
    assert "not supported" in str(excinfo.value).lower()


def test_extract_reports_a_missing_file(tmp_path):
    with pytest.raises(FileNotFoundError):
        extract_file(tmp_path / "gone.pdf")
