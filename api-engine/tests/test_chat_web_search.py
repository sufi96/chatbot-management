"""The decisions the chat route makes about web search.

The route itself needs a running model, so the logic it applies is asserted
here directly, the way test_chat_gating.py does for retrieval.
"""
from kb.gating import should_retrieve
from routers.chat import key_for
from websearch.context import build_web_context_block
from websearch.gating import web_search_runs
from websearch.result import SearchResult


def test_duckduckgo_needs_no_key():
    assert key_for({"web_search_provider": "duckduckgo"}) == ""


def test_the_key_of_the_chosen_provider_is_the_one_used():
    settings = {
        "web_search_provider": "tavily",
        "web_search_tavily_key": "tv",
        "web_search_brave_key": "br",
    }
    assert key_for(settings) == "tv"

    settings["web_search_provider"] = "brave"
    assert key_for(settings) == "br"


def test_a_greeting_never_reaches_the_web():
    assert web_search_runs(True, should_retrieve("hello"), 0) is False


def test_a_question_with_no_knowledge_base_hits_reaches_the_web():
    assert web_search_runs(True, should_retrieve("what is the population?"), 0) is True


def test_a_question_the_knowledge_base_answered_does_not():
    assert web_search_runs(True, should_retrieve("what is the warranty?"), 3) is False


def test_web_results_become_the_context_when_the_knowledge_base_was_empty():
    kb_block = ""
    web = [SearchResult("Title", "https://example.com/", "The population is 34.1 million.")]

    context = kb_block or build_web_context_block(web)

    assert "34.1 million" in context
    assert "https://example.com/" in context


def test_a_knowledge_base_block_is_never_replaced_by_web_results():
    kb_block = "[1] Warranty policy\nThirty six months."
    web = [SearchResult("Title", "https://example.com/", "Something else.")]

    context = kb_block or build_web_context_block(web)

    assert context == kb_block
