"""A fixed set of questions a bot is expected to answer, and what counts as right.

A set is a JSON file, so an operator can write one without touching code. It is
checked when it is loaded, because a typo discovered after a twenty-minute run
is a wasted run.
"""
import json
from dataclasses import dataclass, field
from pathlib import Path

SOURCES = ("none", "documents", "database", "web")


@dataclass(frozen=True)
class Expect:
    # Which source should answer. None accepts any; "none" means no source at all.
    source: str | None = None
    # Titles, or parts of titles, that must appear among the citations.
    cites: tuple[str, ...] = ()
    # At least one of these must appear in the answer, whatever its case.
    must_include_any: tuple[str, ...] = ()
    # None of these may appear in the answer, whatever its case.
    must_not_include: tuple[str, ...] = ()
    max_first_token_seconds: float | None = None
    max_total_seconds: float | None = None


@dataclass(frozen=True)
class Case:
    id: str
    message: str
    history: tuple = ()
    expect: Expect = field(default_factory=Expect)
    # What a correct answer says, for the judge. Blank means it is not judged.
    reference: str = ""


@dataclass(frozen=True)
class EvalSet:
    name: str
    bot_id: str
    cases: tuple[Case, ...]


def _strings(value, where: str) -> tuple[str, ...]:
    if value is None:
        return ()
    if not isinstance(value, list) or not all(isinstance(item, str) for item in value):
        raise ValueError(f"{where} must be a list of strings.")
    return tuple(value)


def _seconds(value, where: str) -> float | None:
    if value is None:
        return None
    if isinstance(value, bool) or not isinstance(value, (int, float)) or value <= 0:
        raise ValueError(f"{where} must be a positive number of seconds.")
    return float(value)


def _history(value, where: str) -> tuple:
    turns = value or []
    valid = isinstance(turns, list) and all(
        isinstance(turn, dict) and turn.get("role") in ("user", "assistant")
        and isinstance(turn.get("content"), str)
        for turn in turns)
    if not valid:
        raise ValueError(f"{where} history must be turns with a role of user or assistant "
                         "and text content.")
    return tuple({"role": turn["role"], "content": turn["content"]} for turn in turns)


def parse_set(data: dict) -> EvalSet:
    if not isinstance(data, dict):
        raise ValueError("An evaluation set must be a JSON object.")

    name = str(data.get("name") or "").strip()
    bot_id = str(data.get("bot_id") or "").strip()
    if not name:
        raise ValueError("The set needs a name.")
    if not bot_id:
        raise ValueError("The set needs a bot_id.")

    raw_cases = data.get("cases")
    if not isinstance(raw_cases, list) or not raw_cases:
        raise ValueError("The set needs at least one case.")

    cases: list[Case] = []
    seen: set[str] = set()
    for position, raw in enumerate(raw_cases, start=1):
        if not isinstance(raw, dict):
            raise ValueError(f"Case {position} must be an object.")

        case_id = str(raw.get("id") or "").strip()
        where = f"Case {case_id or position}"
        if not case_id:
            raise ValueError(f"{where} needs an id.")
        if case_id in seen:
            raise ValueError(f"{where} appears twice.")
        seen.add(case_id)

        message = str(raw.get("message") or "").strip()
        if not message:
            raise ValueError(f"{where} needs a message.")

        expect = raw.get("expect") or {}
        if not isinstance(expect, dict):
            raise ValueError(f"{where} expect must be an object.")

        source = expect.get("source")
        if source is not None and source not in SOURCES:
            raise ValueError(f"{where} expects an unknown source {source!r}; "
                             f"use one of {', '.join(SOURCES)}.")

        cases.append(Case(
            id=case_id,
            message=message,
            history=_history(raw.get("history"), where),
            expect=Expect(
                source=source,
                cites=_strings(expect.get("cites"), f"{where} cites"),
                must_include_any=_strings(expect.get("must_include_any"),
                                          f"{where} must_include_any"),
                must_not_include=_strings(expect.get("must_not_include"),
                                          f"{where} must_not_include"),
                max_first_token_seconds=_seconds(expect.get("max_first_token_seconds"),
                                                 f"{where} max_first_token_seconds"),
                max_total_seconds=_seconds(expect.get("max_total_seconds"),
                                           f"{where} max_total_seconds"),
            ),
            reference=str(raw.get("reference") or "").strip(),
        ))

    return EvalSet(name=name, bot_id=bot_id, cases=tuple(cases))


def load_set(path) -> EvalSet:
    try:
        data = json.loads(Path(path).read_text(encoding="utf-8"))
    except json.JSONDecodeError as error:
        raise ValueError(f"{path} is not valid JSON: {error}") from error

    return parse_set(data)
