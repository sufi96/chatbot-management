"""Turning an answer (Markdown) into a real file, and visuals into text drawings."""
import csv
import io
import json

import docx
import openpyxl

from files import convert, visual_text

ANSWER = """# Stock report

Here is the **current** stock.

| Item | Qty |
|------|-----|
| Apple | 3 |
| Durian | 12 |

- Reorder durian next week
- Check apples
"""


def test_markdown_is_kept_as_written():
    made = convert.to_file(ANSWER, "md", "Stock report")

    assert made.data.decode() == ANSWER and made.filename == "stock-report.md"


def test_text_loses_the_markdown_marks():
    text = convert.to_file(ANSWER, "txt", "Stock").data.decode()

    assert "**" not in text and "# " not in text and "current" in text and "Durian" in text


def test_csv_is_the_first_table():
    rows = list(csv.reader(io.StringIO(convert.to_file(ANSWER, "csv", "Stock").data.decode("utf-8-sig"))))

    assert rows == [["Item", "Qty"], ["Apple", "3"], ["Durian", "12"]]


def test_excel_holds_the_table_with_numbers_as_numbers():
    made = convert.to_file(ANSWER, "xlsx", "Stock")
    sheet = openpyxl.load_workbook(io.BytesIO(made.data)).active

    assert [c.value for c in sheet[1]] == ["Item", "Qty"]
    assert sheet["B3"].value == 12 and sheet["A1"].font.bold
    assert made.filename == "stock.xlsx"


def test_excel_without_a_table_puts_lines_in_column_a():
    sheet = openpyxl.load_workbook(io.BytesIO(convert.to_file("one\ntwo", "xlsx", "x").data)).active

    assert [sheet["A1"].value, sheet["A2"].value] == ["one", "two"]


def test_word_has_the_heading_text_list_and_table():
    document = docx.Document(io.BytesIO(convert.to_file(ANSWER, "docx", "Stock").data))
    texts = [p.text for p in document.paragraphs]

    assert "Stock report" in texts and "Here is the current stock." in texts
    assert "Reorder durian next week" in texts
    assert document.tables[0].cell(2, 0).text == "Durian"


def test_pdf_is_a_pdf_and_survives_malay_and_symbols():
    made = convert.to_file(ANSWER + "\nHarga: RM5 — teh tarik ✓", "pdf", "Laporan")

    assert made.data.startswith(b"%PDF") and made.mime == "application/pdf"


def test_a_title_with_nothing_usable_is_named_answer():
    assert convert.to_file("hi", "txt", "!!!").filename == "answer.txt"


def test_a_chart_block_becomes_a_text_chart_in_a_document():
    chart = {"type": "bar", "title": "Sales", "labels": ["Jan", "Feb"],
             "series": [{"name": "RM", "values": [10, 20]}]}
    text = convert.to_file("Sales below.\n\n```chart\n" + json.dumps(chart) + "\n```\n", "txt", "s").data.decode()

    assert "█" in text and "Feb" in text and "{" not in text


def test_visual_text_for_each_kind():
    assert "A → B (yes)" in visual_text.to_text("diagram", json.dumps(
        {"nodes": [{"id": "a", "label": "A"}, {"id": "b", "label": "B"}],
         "links": [{"from": "a", "to": "b", "label": "yes"}]}))
    assert "2026 — Launch" in visual_text.to_text("timeline", json.dumps({"items": [{"when": "2026", "label": "Launch"}]}))
    assert visual_text.to_text("svg", "<svg><title>Logo</title></svg>") == "[drawing: Logo]"
    assert "```" in visual_text.to_text("chart", "not json")
