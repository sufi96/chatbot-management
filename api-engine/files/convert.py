"""An answer, written in Markdown, made into a real file.

The model never writes a binary file. It writes what it is good at and this
turns it into txt, md, csv, xlsx, docx or pdf, in a plain layout (templates
come later). Visual blocks become text drawings first (visual_text.py).
"""
import csv
import io
import os
import re
from dataclasses import dataclass

from files.checks import MIMES
from files.visual_text import replace_blocks

HEADING = re.compile(r"^(#{1,6})\s+(.*)$")
ITEM = re.compile(r"^\s*([-*+]|\d+[.)])\s+(.*)$")
FENCE = re.compile(r"^\s*```")
SEPARATOR = re.compile(r"^\s*\|?\s*:?-{1,}:?\s*(\|\s*:?-{1,}:?\s*)*\|?\s*$")
RUN = re.compile(r"(\*\*\*[^*]+\*\*\*|\*\*[^*]+\*\*|\*[^*\n]+\*|`[^`]+`)")

FONTS = (r"C:\Windows\Fonts\arial.ttf", r"C:\Windows\Fonts\segoeui.ttf",
         "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf", "/usr/share/fonts/dejavu/DejaVuSans.ttf",
         "/Library/Fonts/Arial Unicode.ttf")


@dataclass(frozen=True)
class Made:
    data: bytes
    mime: str
    filename: str


def plain(text: str) -> str:
    """Inline Markdown marks removed."""
    text = re.sub(r"\[([^\]]*)\]\(([^)]*)\)", r"\1 (\2)", text)
    return re.sub(r"(\*\*\*|\*\*|\*|`|~~)", "", text)


def _cells(line: str) -> list:
    return [c.strip() for c in line.strip().strip("|").split("|")]


def blocks(markdown: str) -> list:
    """("heading", level, text) | ("para", text) | ("item", ordered, text) | ("table", rows) | ("code", text)."""
    lines, out, i = markdown.replace("\r\n", "\n").split("\n"), [], 0
    while i < len(lines):
        line = lines[i]
        if FENCE.match(line):
            body, i = [], i + 1
            while i < len(lines) and not FENCE.match(lines[i]):
                body.append(lines[i])
                i += 1
            out.append(("code", "\n".join(body)))
        elif "|" in line and i + 1 < len(lines) and SEPARATOR.match(lines[i + 1]):
            rows, i = [_cells(line)], i + 2
            while i < len(lines) and "|" in lines[i] and lines[i].strip():
                rows.append(_cells(lines[i]))
                i += 1
            out.append(("table", rows))
            continue
        elif HEADING.match(line):
            m = HEADING.match(line)
            out.append(("heading", len(m.group(1)), m.group(2).strip()))
        elif ITEM.match(line):
            m = ITEM.match(line)
            out.append(("item", m.group(1)[0].isdigit(), m.group(2).strip()))
        elif line.strip():
            para = [line.strip()]
            while (i + 1 < len(lines) and lines[i + 1].strip() and not HEADING.match(lines[i + 1])
                   and not ITEM.match(lines[i + 1]) and not FENCE.match(lines[i + 1]) and "|" not in lines[i + 1]):
                i += 1
                para.append(lines[i].strip())
            out.append(("para", " ".join(para)))
        i += 1
    return out


def _number(value: str):
    try:
        number = float(value.replace(",", ""))
        return int(number) if number.is_integer() else number
    except ValueError:
        return value


def _slug(title: str) -> str:
    return re.sub(r"[^a-z0-9]+", "-", (title or "").lower()).strip("-")[:60] or "answer"


def _txt(markdown: str) -> bytes:
    lines = []
    for b in blocks(markdown):
        if b[0] == "heading":
            lines += [plain(b[2]).upper() if b[1] == 1 else plain(b[2]), ""]
        elif b[0] == "para":
            lines += [plain(b[1]), ""]
        elif b[0] == "item":
            lines.append(f"- {plain(b[2])}")
        elif b[0] == "table":
            lines += ["\t".join(plain(c) for c in row) for row in b[1]] + [""]
        else:
            lines += [b[1], ""]
    return "\n".join(lines).strip().encode("utf-8") + b"\n"


def _tables(markdown: str) -> list:
    return [b[1] for b in blocks(markdown) if b[0] == "table"]


def _csv(markdown: str) -> bytes:
    tables = _tables(markdown)
    rows = [[plain(c) for c in row] for row in tables[0]] if tables else [
        [line] for line in _txt(markdown).decode().splitlines() if line.strip()]
    out = io.StringIO()
    csv.writer(out).writerows(rows)
    return ("\ufeff" + out.getvalue()).encode("utf-8")


def _xlsx(markdown: str, title: str) -> bytes:
    from openpyxl import Workbook
    from openpyxl.styles import Font

    book = Workbook()
    tables = _tables(markdown)
    if not tables:
        sheet = book.active
        sheet.title = "Answer"
        # A sheet is read row by row, so each written line keeps its own row.
        for line in markdown.splitlines():
            if line.strip() and not FENCE.match(line):
                sheet.append([plain(HEADING.sub(r"\2", ITEM.sub(r"\2", line.strip())))])
    for n, table in enumerate(tables):
        sheet = book.active if n == 0 else book.create_sheet()
        sheet.title = (f"Table {n + 1}" if len(tables) > 1 else (plain(title)[:31] or "Table")).replace("/", "-")
        for r, row in enumerate(table):
            sheet.append([plain(c) if r == 0 else _number(plain(c)) for c in row])
        for cell in sheet[1]:
            cell.font = Font(bold=True)
    out = io.BytesIO()
    book.save(out)
    return out.getvalue()


def _runs(paragraph, text: str) -> None:
    for piece in RUN.split(text):
        if not piece:
            continue
        if piece.startswith("***"):
            run = paragraph.add_run(piece[3:-3]); run.bold = run.italic = True
        elif piece.startswith("**"):
            paragraph.add_run(piece[2:-2]).bold = True
        elif piece.startswith("`"):
            paragraph.add_run(piece[1:-1]).font.name = "Consolas"
        elif piece.startswith("*") and piece.endswith("*") and len(piece) > 2:
            paragraph.add_run(piece[1:-1]).italic = True
        else:
            paragraph.add_run(plain(piece))


def _docx(markdown: str) -> bytes:
    import docx

    document = docx.Document()
    for b in blocks(markdown):
        if b[0] == "heading":
            document.add_heading(plain(b[2]), level=min(b[1], 4))
        elif b[0] == "para":
            _runs(document.add_paragraph(), b[1])
        elif b[0] == "item":
            _runs(document.add_paragraph(style="List Number" if b[1] else "List Bullet"), b[2])
        elif b[0] == "table":
            width = max(len(r) for r in b[1])
            table = document.add_table(rows=len(b[1]), cols=width)
            table.style = "Table Grid"
            for r, row in enumerate(b[1]):
                for c, value in enumerate(row):
                    table.cell(r, c).text = plain(value)
                    if r == 0:
                        for run in table.cell(r, c).paragraphs[0].runs:
                            run.bold = True
        else:
            run = document.add_paragraph().add_run(b[1])
            run.font.name = "Consolas"
    out = io.BytesIO()
    document.save(out)
    return out.getvalue()


def _pdf(markdown: str, title: str) -> bytes:
    from fpdf import FPDF

    pdf = FPDF()
    pdf.set_auto_page_break(True, margin=15)
    pdf.add_page()
    font = next((f for f in FONTS if os.path.exists(f)), None)
    if font:
        pdf.add_font("Body", "", font)
        pdf.add_font("Body", "B", font)
        family, safe = "Body", (lambda s: s)
    else:
        family, safe = "Helvetica", (lambda s: s.encode("latin-1", "replace").decode("latin-1"))

    def write(text, size=11, gap=2):
        pdf.set_font(family, size=size)
        pdf.multi_cell(0, size * 0.5, safe(text), new_x="LMARGIN", new_y="NEXT")
        pdf.ln(gap)

    for b in blocks(markdown):
        if b[0] == "heading":
            write(plain(b[2]), size={1: 18, 2: 15, 3: 13}.get(b[1], 12), gap=3)
        elif b[0] == "para":
            write(plain(b[1]))
        elif b[0] == "item":
            write(("• " if not b[1] else "- ") + plain(b[2]), gap=1)
        elif b[0] == "table":
            pdf.set_font(family, size=10)
            with pdf.table() as table:
                for row in b[1]:
                    cells = table.row()
                    for value in row:
                        cells.cell(safe(plain(value)))
            pdf.ln(3)
        else:
            write(b[1], size=9)
    return bytes(pdf.output())


def to_file(markdown: str, fmt: str, title: str) -> Made:
    text = replace_blocks(markdown or "")
    data = {
        "md": lambda: (markdown or "").encode("utf-8"),
        "txt": lambda: _txt(text),
        "csv": lambda: _csv(text),
        "xlsx": lambda: _xlsx(text, title),
        "docx": lambda: _docx(text),
        "pdf": lambda: _pdf(text, title),
    }[fmt]()
    return Made(data=data, mime=MIMES[fmt], filename=f"{_slug(title)}.{fmt}")
