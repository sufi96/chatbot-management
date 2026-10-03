"""Asking the knowledge base more than one way.

A visitor's question and the passage that answers it are often worded nothing
alike: "can I send it back?" and "Returns are accepted within 30 days". Two
well-known remedies, and a bot chooses which (Behaviour, Knowledge base):

- Multi-query: the question rephrased a few ways. Each phrasing is searched by
  meaning and by keyword, so one of them is likely to use the document's words.
- HyDE (hypothetical document embeddings): a short made-up passage that would
  answer the question. Its vector sits among real answers rather than among
  questions, so it is searched by meaning only. Its facts are never shown to
  anyone and never reach the answer model; only its embedding is used.

"Both" asks for the two in the same call. Every result is folded into the same
rank fusion as the visitor's own question, which still counts as a branch of
its own, so expansion can add passages but cannot push out what the plain
question found.

One model call, by the expand role, before the search. Every failure returns
nothing, and the search runs on the question alone as it did before.
"""
import json
import re
from dataclasses import dataclass, field

import roles
from llm_adapter import LLMAdapter

MODES = ("off", "multi_query", "hyde", "both")

MAX_QUERIES = 3
QUERY_CHARS = 300
PASSAGE_CHARS = 1200

_OBJECT = re.compile(r"\{.*\}", re.DOTALL)

_QUERIES = (
    '"queries": up to three other ways a customer might ask the same question. '
    "Use different words from the original, including words a business document "
    "would use. Keep product names, codes and numbers exactly as written."
)
_PASSAGE = (
    '"passage": two to four sentences that could be the passage in a company '
    "document answering the question, written as that document would write it. "
    "Invent plausible details if you must; it is only used to search."
)


def build_prompt(mode: str) -> str:
    asks = []
    if mode in ("multi_query", "both"):
        asks.append(_QUERIES)
    if mode in ("hyde", "both"):
        asks.append(_PASSAGE)

    example = {}
    if mode in ("multi_query", "both"):
        example["queries"] = ["What is the returns policy?", "How many days do I have to return an item?"]
    if mode in ("hyde", "both"):
        example["passage"] = "Items may be returned within 30 days of delivery for a full refund."

    return ("You help a search engine find the answer to a customer's question in a "
            "company's documents. Write in the language of the question.\n\n"
            + "\n".join(f"- {line}" for line in asks)
            + "\n\nReply with one JSON object and nothing else, for example:\n"
            + json.dumps(example))


@dataclass(frozen=True)
class Expansion:
    queries: list[str] = field(default_factory=list)
    passage: str = ""
    model: str = ""

    @property
    def empty(self) -> bool:
        return not self.queries and not self.passage


def mode_of(bot) -> str:
    value = (getattr(bot, "query_expansion", None) or "off").strip().lower()
    return value if value in MODES else "off"


def parse(raw: str, mode: str, question: str) -> Expansion:
    found = _OBJECT.search(raw or "")
    if not found:
        return Expansion()
    try:
        data = json.loads(found.group(0))
    except ValueError:
        return Expansion()
    if not isinstance(data, dict):
        return Expansion()

    queries = []
    if mode in ("multi_query", "both") and isinstance(data.get("queries"), list):
        seen = {question.strip().lower()}
        for item in data["queries"]:
            if not isinstance(item, str):
                continue
            query = item.strip()[:QUERY_CHARS]
            if query and query.lower() not in seen:
                seen.add(query.lower())
                queries.append(query)
            if len(queries) >= MAX_QUERIES:
                break

    passage = ""
    if mode in ("hyde", "both") and isinstance(data.get("passage"), str):
        passage = data["passage"].strip()[:PASSAGE_CHARS]

    return Expansion(queries=queries, passage=passage)


async def expand(bot, question: str, settings: dict, complete=None) -> Expansion:
    mode = mode_of(bot)
    if mode == "off" or not (question or "").strip():
        return Expansion()

    endpoint = roles.endpoint_for("expand", bot, settings)
    if not endpoint.available:
        return Expansion()

    raw = await (complete or LLMAdapter.complete)(
        base_url=endpoint.base_url, api_key=endpoint.api_key, model_name=endpoint.model,
        system_prompt=build_prompt(mode), user_message=question.strip()[:QUERY_CHARS * 2],
        temperature=0.3, max_tokens=400, response_format={"type": "json_object"},
        merge_system=endpoint.merge_system)

    result = parse(raw, mode, question)
    if result.empty:
        if raw:
            print(f"[Expand] Unusable reply, searching the question alone: {raw[:120]!r}")
        return Expansion()

    return Expansion(result.queries, result.passage, endpoint.model)
