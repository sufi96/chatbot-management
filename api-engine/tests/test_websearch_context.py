from websearch.context import (UNTRUSTED_NOTICE, build_web_context_block,
                               fit_results_to_budget)
from websearch.result import SearchResult


def result(text, n=1):
    return SearchResult(title=f"Title {n}", url=f"https://example.com/{n}", text=text)


def test_results_that_fit_are_all_kept():
    kept = fit_results_to_budget([result("a" * 10, 1), result("b" * 10, 2)], 100)
    assert len(kept) == 2


def test_results_past_the_budget_are_dropped():
    kept = fit_results_to_budget([result("a" * 60, 1), result("b" * 60, 2)], 100)
    assert len(kept) == 1


def test_the_first_result_survives_even_alone_over_budget():
    kept = fit_results_to_budget([result("a" * 500, 1)], 100)
    assert len(kept) == 1


def test_no_results_makes_no_block():
    assert build_web_context_block([]) == ""


def test_the_block_numbers_results_from_one():
    block = build_web_context_block([result("first", 1), result("second", 2)])
    assert "[1] Title 1" in block
    assert "[2] Title 2" in block


def test_the_block_shows_the_url_so_the_model_can_attribute():
    block = build_web_context_block([result("first", 1)])
    assert "https://example.com/1" in block


def test_the_block_says_the_text_is_untrusted_and_not_instructions():
    block = build_web_context_block([result("first", 1)])
    assert UNTRUSTED_NOTICE in block
    assert "instructions" in UNTRUSTED_NOTICE


def test_the_result_text_is_in_the_block():
    assert "the answer body" in build_web_context_block([result("the answer body", 1)])
