"""Whether a message earns a web search.

Deliberately not a second opinion on what counts as a question. The knowledge
base gate in kb/gating.py already decides that, and two vocabularies would
drift apart. This adds only the part that is specific to the web: it is the
fallback, so it runs when nothing else answered.
"""


def web_search_runs(enabled: bool, message_is_a_question: bool, kb_hits: int) -> bool:
    return bool(enabled) and bool(message_is_a_question) and kb_hits == 0
