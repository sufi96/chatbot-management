"""The files a visitor attached, as material for the answer.

Every readable file of the conversation goes in, newest first. A file that fits
the budget goes whole; a longer one sends its passages that share the most
words with the question, the keyword half of what the knowledge base does,
with no embedding call in the visitor's wait. A file that could not be read
is named with a line telling the model not to guess what it says.
"""
import re

import spotlight

LEAD = "Files the visitor attached in this conversation:"
PASSAGE = 1200
WORD = re.compile(r"[\w']{3,}", re.UNICODE)


def _words(text: str) -> set:
    return {w.lower() for w in WORD.findall(text)}


def _passages(text: str, query: str, budget: int) -> str:
    pieces = [text[i:i + PASSAGE] for i in range(0, len(text), PASSAGE)]
    wanted = _words(query)
    ranked = sorted(range(len(pieces)), key=lambda i: (-len(wanted & _words(pieces[i])), i))
    chosen, used = [], 0
    for i in ranked:
        if used + len(pieces[i]) > budget:
            break
        chosen.append(i)
        used += len(pieces[i])
    return "\n[…]\n".join(pieces[i].strip() for i in sorted(chosen))


def block(files: list, query: str, budget: int) -> str:
    if not files:
        return ""
    parts, left = [], max(budget, PASSAGE)
    for f in files:
        if not f.text:
            parts.append(f"File: {f.name}\n(This file could not be read. Tell the visitor so; "
                         f"do not guess what it says.)")
            continue
        if left <= 0:
            parts.append(f"File: {f.name}\n(Left out: no room for more files in this reply.)")
            continue
        body = f.text if len(f.text) <= left else _passages(f.text, query, left)
        left -= len(body)
        parts.append(f"File: {f.name}\n{body}")
    return spotlight.wrap(LEAD, "\n\n".join(parts))
