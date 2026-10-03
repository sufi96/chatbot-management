"""Checking that an answer says only what its sources say.

Retrieval puts the right passage in front of the model; it cannot stop the
model adding a figure, a promise or a policy the passage never mentioned. With
the check on (Behaviour, Safety), every answer drawn from a source is put to the
verify role together with the material it was given, and the verdict is kept on
the message: supported, or the first claim the material does not back.

It runs after the answer has streamed, beside the output guard, because holding
every answer back for it would make every bot feel slow. So it flags rather
than prevents: the conversations screen marks the answer for the operator, an
unsupported answer is never put in the answer cache, and the analytics page
counts them, which is the number that says whether the material is good enough.

Answers with no source material, a greeting or a refusal, are not checked;
there is nothing to check them against. Every failure is "not checked".
"""
import json
import re
from dataclasses import dataclass

import roles
from llm_adapter import LLMAdapter

MATERIAL_CHARS = 12000
ANSWER_CHARS = 4000
NOTE_CHARS = 300

_OBJECT = re.compile(r"\{.*\}", re.DOTALL)

PROMPT = """You check whether an assistant's answer is supported by the reference material it was given.

An answer is supported when every factual claim in it (figures, dates, prices, names, conditions, promises, policies) is stated in or follows directly from the material. Greetings, offers to help, and saying that something is not in the material need no support.

Reply with one JSON object and nothing else. When supported:
{"grounded": true, "claim": ""}
When not, quote the first unsupported claim, briefly:
{"grounded": false, "claim": "<the claim>"}"""


@dataclass(frozen=True)
class Verdict:
    grounded: bool | None = None
    claim: str = ""
    model: str = ""

    @property
    def ran(self) -> bool:
        return self.grounded is not None


def enabled(bot) -> bool:
    return bool(getattr(bot, "grounding_check", False))


def build_input(material: str, answer: str) -> str:
    return (f"Reference material:\n{(material or '')[:MATERIAL_CHARS]}\n\n"
            f"Answer to check:\n{(answer or '')[:ANSWER_CHARS]}")


def parse(raw: str) -> Verdict:
    found = _OBJECT.search(raw or "")
    if not found:
        return Verdict()
    try:
        data = json.loads(found.group(0))
    except ValueError:
        return Verdict()
    if not isinstance(data, dict) or not isinstance(data.get("grounded"), bool):
        return Verdict()

    claim = "" if data["grounded"] else str(data.get("claim") or "").strip()[:NOTE_CHARS]
    return Verdict(grounded=data["grounded"], claim=claim)


async def check(bot, material: str, answer: str, settings: dict, complete=None) -> Verdict:
    if not (material or "").strip() or not (answer or "").strip():
        return Verdict()

    endpoint = roles.endpoint_for("verify", bot, settings)
    if not endpoint.available:
        return Verdict()

    raw = await (complete or LLMAdapter.complete)(
        base_url=endpoint.base_url, api_key=endpoint.api_key, model_name=endpoint.model,
        system_prompt=PROMPT, user_message=build_input(material, answer),
        temperature=0.0, max_tokens=150, response_format={"type": "json_object"},
        merge_system=endpoint.merge_system)

    verdict = parse(raw)
    if not verdict.ran:
        if raw:
            print(f"[Grounding] Unusable reply, answer left unchecked: {raw[:120]!r}")
        return Verdict()

    return Verdict(verdict.grounded, verdict.claim, endpoint.model)
