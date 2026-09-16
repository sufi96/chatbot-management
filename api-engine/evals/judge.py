"""Whether an answer says what its reference says, as judged by a model.

Kept apart from the deterministic checks because it is not deterministic: two
runs can score the same answer differently. It is the number to watch across a
model change, not a gate on a single run. A small model judging its own answers
is generous, so point the judge at the strongest model available.
"""
import json
import re
from dataclasses import dataclass

from llm_adapter import LLMAdapter

PROMPT = """You grade a customer service assistant's answer against a reference answer written by the business.

Score how well the answer agrees with the reference:
5 - says what the reference says, and nothing that contradicts it
4 - agrees, with a minor omission
3 - partly agrees, or leaves out something important
2 - mostly misses what the reference says
1 - contradicts the reference, or does not answer

Wording, length and politeness do not matter. Reply with one JSON object and nothing else:
{"score": 4, "reason": "<one sentence>"}"""

REASON_CHARS = 300

_OBJECT = re.compile(r"\{.*\}", re.DOTALL)


@dataclass(frozen=True)
class Judgement:
    score: int | None = None
    reason: str = ""


def parse(raw: str) -> Judgement | None:
    """The judge's reply as a Judgement, or None when it holds no usable score."""
    found = _OBJECT.search(raw or "")
    if not found:
        return None

    try:
        data = json.loads(found.group(0))
    except ValueError:
        return None

    if not isinstance(data, dict):
        return None

    score = data.get("score")
    # JSON has one number type, so 4.0 is a four. 3.5 is not a score on this scale.
    if isinstance(score, float) and score.is_integer():
        score = int(score)
    if isinstance(score, bool) or not isinstance(score, int) or not 1 <= score <= 5:
        return None

    return Judgement(score=score, reason=str(data.get("reason") or "").strip()[:REASON_CHARS])


def grading_input(case, answer: str) -> str:
    return (f"Question:\n{case.message}\n\n"
            f"Reference answer:\n{case.reference}\n\n"
            f"Assistant's answer:\n{answer or '(no answer)'}")


async def judge(case, answer: str, base_url: str, api_key: str, model: str,
                complete=None) -> Judgement:
    if not case.reference:
        return Judgement(reason="No reference, so not judged.")

    raw = await (complete or LLMAdapter.complete)(
        base_url=base_url, api_key=api_key, model_name=model,
        system_prompt=PROMPT, user_message=grading_input(case, answer),
        max_tokens=200, response_format={"type": "json_object"})

    return parse(raw) or Judgement(reason="The judge gave no usable score.")
