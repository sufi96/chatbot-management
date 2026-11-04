"""A visual block drawn with keyboard characters, for anywhere that cannot draw.

The widget draws chart, diagram, timeline and svg blocks (widget/visuals.js).
A file, a transcript export or a plain-text channel cannot, so the same block
becomes a text drawing here, with the numbers as a table where there are any.
"""
import json
import re

KINDS = ("chart", "diagram", "timeline", "svg")
BAR = 30
FENCE = re.compile(r"^```(chart|diagram|timeline|svg)[ \t]*\n(.*?)\n```[ \t]*$", re.MULTILINE | re.DOTALL)


def _chart(spec: dict) -> str:
    labels = [str(x) for x in spec.get("labels") or []]
    series = [s for s in spec.get("series") or [] if isinstance(s, dict)]
    lines = [str(spec["title"])] if spec.get("title") else []
    values = [float(v) for s in series for v in (s.get("values") or []) if isinstance(v, (int, float))]
    top = max([abs(v) for v in values] or [1]) or 1
    width = max([len(x) for x in labels] or [1])
    for s in series:
        if len(series) > 1:
            lines.append(f"{s.get('name', '')}:")
        for label, value in zip(labels, s.get("values") or []):
            if isinstance(value, (int, float)):
                lines.append(f"{label.ljust(width)} {'█' * max(1, round(abs(value) / top * BAR))} {value:g}")
    header = "| " + " | ".join(["", *[str(s.get("name", "")) for s in series]]) + " |"
    rows = [header, "|" + "---|" * (len(series) + 1)]
    for i, label in enumerate(labels):
        cells = [str((s.get("values") or [None] * (i + 1))[i]) if i < len(s.get("values") or []) else ""
                 for s in series]
        rows.append("| " + " | ".join([label, *cells]) + " |")
    return "\n".join(lines) + "\n\n" + "\n".join(rows)


def _diagram(spec: dict) -> str:
    names = {str(n.get("id")): str(n.get("label") or n.get("id")) for n in spec.get("nodes") or []
             if isinstance(n, dict)}
    lines = []
    for link in spec.get("links") or []:
        a, b = names.get(str(link.get("from")), str(link.get("from"))), names.get(str(link.get("to")), str(link.get("to")))
        lines.append(f"{a} → {b}" + (f" ({link['label']})" if link.get("label") else ""))
    return "\n".join(lines or list(names.values()))


def _timeline(spec: dict) -> str:
    return "\n".join(f"{item.get('when', '')} — {item.get('label', '')}"
                     for item in spec.get("items") or [] if isinstance(item, dict))


def to_text(lang: str, source: str) -> str:
    if lang == "svg":
        title = re.search(r"<title>(.*?)</title>", source, re.DOTALL)
        return f"[drawing: {title.group(1).strip() if title else 'picture'}]"
    try:
        spec = json.loads(source)
        if not isinstance(spec, dict):
            raise ValueError
        return {"chart": _chart, "diagram": _diagram, "timeline": _timeline}[lang](spec)
    except Exception:
        return f"```\n{source}\n```"


def replace_blocks(markdown: str) -> str:
    """Every visual block in an answer, as a plain code block holding its text drawing."""
    return FENCE.sub(lambda m: "```\n" + to_text(m.group(1), m.group(2)) + "\n```", markdown)
