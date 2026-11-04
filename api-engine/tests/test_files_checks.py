"""The cheap checks every upload passes before anything reads or stores it."""
import io
import zipfile

import pytest
from PIL import Image

from files import checks
from files.settings import IN_TYPES

DOCX_TYPES = b'<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>'


def office(folder: str, extra: dict | None = None) -> bytes:
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("[Content_Types].xml", DOCX_TYPES)
        z.writestr(f"{folder}/document.xml", b"<doc>hello</doc>")
        for name, data in (extra or {}).items():
            z.writestr(name, data)
    return buf.getvalue()


def jpeg_with_exif() -> bytes:
    image = Image.new("RGB", (40, 30), "red")
    exif = Image.Exif()
    exif[0x010F] = "SpyCam"  # Make
    buf = io.BytesIO()
    image.save(buf, "JPEG", exif=exif)
    return buf.getvalue()


def test_a_program_named_pdf_is_refused():
    with pytest.raises(checks.Refused):
        checks.inspect(b"MZ\x90\x00" + b"\x00" * 200, "report.pdf", IN_TYPES)


def test_a_real_pdf_passes():
    got = checks.inspect(b"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF", "a.pdf", IN_TYPES)

    assert (got.ext, got.mime) == ("pdf", "application/pdf")


def test_a_pdf_with_javascript_is_refused():
    with pytest.raises(checks.Refused, match="script"):
        checks.inspect(b"%PDF-1.4\n<< /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >>", "a.pdf", IN_TYPES)


def test_a_word_file_passes_and_one_with_macros_is_refused():
    assert checks.inspect(office("word"), "letter.docx", IN_TYPES).ext == "docx"

    with pytest.raises(checks.Refused, match="macro"):
        checks.inspect(office("word", {"word/vbaProject.bin": b"\x00" * 10}), "letter.docx", IN_TYPES)


def test_a_spreadsheet_named_docx_is_refused():
    with pytest.raises(checks.Refused):
        checks.inspect(office("xl"), "letter.docx", IN_TYPES)


def test_a_zip_bomb_is_refused():
    with pytest.raises(checks.Refused):
        checks.inspect(office("word", {"word/media/big.bin": b"\x00" * 20_000_000}), "x.docx", IN_TYPES)


def test_a_photo_loses_its_exif():
    got = checks.inspect(jpeg_with_exif(), "me.jpeg", IN_TYPES)

    assert got.ext == "jpg"
    assert "SpyCam" not in got.data.decode("latin-1")
    assert Image.open(io.BytesIO(got.data)).size == (40, 30)


def test_a_type_the_bot_does_not_allow_is_refused():
    with pytest.raises(checks.Refused, match="not accepted"):
        checks.inspect(b"%PDF-1.4\n%%EOF", "a.pdf", ("png",))


def test_plain_csv_passes_and_binary_named_csv_is_refused():
    assert checks.inspect(b"name,qty\napple,3\n", "list.csv", IN_TYPES).ext == "csv"

    with pytest.raises(checks.Refused):
        checks.inspect(b"name\x00qty", "list.csv", IN_TYPES)
