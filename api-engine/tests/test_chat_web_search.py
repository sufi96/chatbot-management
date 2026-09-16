"""The decisions the chat route makes about web search.

The route itself needs a running model, so the logic it applies is asserted
here directly, the way test_chat_gating.py does for retrieval.

Whether the web runs at all is no longer a rule of its own. It is one position
in the operator's order, and test_source_cascade.py covers ordering.
"""
from kb.gating import should_retrieve
from sources.attempts import key_for
from websearch.context import build_web_context_block
from websearch.result import SearchResult


def test_duckduckgo_needs_no_key():
    assert key_for({"web_search_provider": "duckduckgo"}) == ""


def test_the_key_of_the_chosen_provider_is_the_one_used():
    settings = {"web_search_provider": "tavily", "web_search_tavily_key": "tvly-1",
                "web_search_brave_key": "brave-2"}

    assert key_for(settings) == "tvly-1"


def test_a_greeting_never_reaches_the_web():
    # The cascade is not entered at all for a message that is not a question.
    assert should_retrieve("hello there") is False


def test_web_results_become_a_context_block():
    results = [SearchResult(title="Weather today", url="https://example.test/w",
                            text="Sunny.")]

    block = build_web_context_block(results)

    assert "Weather today" in block
    assert "Sunny." in block
