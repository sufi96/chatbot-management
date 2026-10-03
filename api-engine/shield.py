"""Catching prompt injection by its wording, before any model reads it.

The guard role asks a model, which is slow, optional, and fails open. This asks
a list of patterns, which is instant, always available, and catches the
attempts that are actually made: "ignore your previous instructions", a fake
system tag, a request to print the hidden prompt. It is the first layer, not
the only one; a determined attacker rephrases, which is what the guard model
and the leak watch behind it are for.

Two places are checked. A visitor's message, where the install chooses whether
a match is refused, only flagged for review, or ignored. And every passage a
source returns, from documents and from the web, where a match is dropped
before the answer model ever reads it: an uploaded PDF or a web page can carry
instructions as easily as a visitor can.

Invisible characters are removed from both before anything else. Unicode tag
characters spell out text no reader sees but a model does, and zero-width
characters split a phrase so a pattern misses it.
"""
import re
import unicodedata
from dataclasses import dataclass

MODES = ("off", "flag", "block")
SOURCE_MODES = ("off", "drop")

FLAG = "injection"

# Tag characters (U+E0000 to U+E007F) and the zero-width and direction marks.
_INVISIBLE = re.compile("[\U000E0000-\U000E007F​-‏‪-‮⁠-⁤﻿]")
_TAGS = re.compile("[\U000E0000-\U000E007F]")

_FLAGS = re.IGNORECASE | re.MULTILINE

# Each rule is named, so a flag says which one fired. Written to need a verb of
# overriding and an object that is the assistant's own instructions together,
# so a visitor asking how to "ignore a missed call" is not caught.
RULES: tuple[tuple[str, re.Pattern], ...] = (
    ("override", re.compile(
        r"\b(ignore|disregard|forget|override|bypass|skip|drop)\b[^.\n]{0,40}?"
        r"\b(previous|prior|above|earlier|all|any|your|the|these|those|system|initial|original)\b[^.\n]{0,30}?"
        r"\b(instructions?|prompts?|rules?|directions?|guidelines?|guardrails?|policies|constraints?)\b", _FLAGS)),
    ("reveal_prompt", re.compile(
        r"\b(reveal|show|print|repeat|output|display|leak|dump|give me|tell me|what (is|are|were))\b[^.\n]{0,40}?"
        # "the rules" and "the instructions" are a customer's words too ("the
        # instructions for the X200"), so only the assistant's own count.
        r"\b(system prompt|initial prompt|hidden prompt|original prompt|prompt above|your prompt|"
        r"your (system |initial |original |hidden |secret )?(instructions|directives)|"
        r"(hidden|secret|initial|original|system) (instructions|rules|directives)|developer message)\b", _FLAGS)),
    ("persona", re.compile(
        r"\b(you are now|from now on,? you are|pretend (to be|you are)|act as) (an? )?"
        r"(unrestricted|unfiltered|uncensored|jailbroken|evil|dan)\b|\bdan mode\b|\bdeveloper mode (enabled|on|activated)\b",
        _FLAGS)),
    ("role_tag", re.compile(
        r"<\|?\s*/?\s*(system|im_start|im_end|endoftext)\s*\|?>|\[/?INST\]|<<\s*/?SYS\s*>>|"
        r"</?\s*(system|assistant|context)\s*>|^\s*#{0,3}\s*(system|developer)\s*(prompt|message)?\s*:", _FLAGS)),
    ("new_rules", re.compile(
        r"\b(new|updated|real|actual|revised) (system )?(instructions?|rules|prompt)\s*:", _FLAGS)),
    # Malay, for the installs this was built for: "abaikan semua arahan sebelum ini".
    ("override_ms", re.compile(
        r"\b(abaikan|lupakan|langkau|ketepikan)\b[^.\n]{0,40}?\b(arahan|peraturan|instruksi|prompt)\b", _FLAGS)),
)


@dataclass(frozen=True)
class Detection:
    found: bool = False
    rule: str = ""


def clean(text: str) -> str:
    """The text with invisible characters removed and compatibility forms folded."""
    folded = unicodedata.normalize("NFKC", text or "")
    return _INVISIBLE.sub("", folded)


def detect(text: str) -> Detection:
    """Whether the text reads as an attempt to instruct the model."""
    raw = text or ""
    if _TAGS.search(raw):
        return Detection(True, "hidden_text")

    cleaned = clean(raw)
    for name, pattern in RULES:
        if pattern.search(cleaned):
            return Detection(True, name)

    return Detection()


def mode_for(settings: dict) -> str:
    value = (settings.get("injection_shield") or "block").strip().lower()
    return value if value in MODES else "block"


def source_mode_for(settings: dict) -> str:
    value = (settings.get("injection_shield_sources") or "drop").strip().lower()
    return value if value in SOURCE_MODES else "drop"


def screen_passages(items: list, text_of, settings: dict) -> tuple[list, int]:
    """The passages that carry no instructions, and how many were dropped."""
    if source_mode_for(settings) == "off":
        return items, 0

    kept = [item for item in items if not detect(text_of(item)).found]
    dropped = len(items) - len(kept)
    if dropped:
        print(f"[Shield] Dropped {dropped} passage(s) that read as instructions to the model.")
    return kept, dropped
