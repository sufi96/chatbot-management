"""Walking a bot's source order until one of them answers.

Pure orchestration: the attempts are callables the test supplies, so none of
this needs a model, a database or an HTTP request.
"""
import pytest

import sources
from sources.result import SourceResult


def hit(kind):
    async def attempt():
        return SourceResult(kind=kind, context_block=f"{kind} block", has_content=True)
    return attempt


def miss(kind, log=None):
    async def attempt():
        if log is not None:
            log.append(kind)
        return SourceResult(kind=kind)
    return attempt


def never(kind):
    async def attempt():
        raise AssertionError(f"{kind} should not have been consulted")
    return attempt


ALL_ON = {"documents": True, "database": True, "web": True}


@pytest.mark.asyncio
async def test_the_first_source_with_content_answers():
    result = await sources.resolve(
        ["documents", "database", "web"], ALL_ON,
        {"documents": hit("documents"), "database": never("database"),
         "web": never("web")})

    assert result.kind == "documents"


@pytest.mark.asyncio
async def test_the_configured_order_is_followed_not_the_default():
    result = await sources.resolve(
        ["web", "database", "documents"], ALL_ON,
        {"documents": never("documents"), "database": never("database"),
         "web": hit("web")})

    assert result.kind == "web"


@pytest.mark.asyncio
@pytest.mark.parametrize("order", [
    ["documents", "database", "web"],
    ["documents", "web", "database"],
    ["database", "documents", "web"],
    ["database", "web", "documents"],
    ["web", "documents", "database"],
    ["web", "database", "documents"],
])
async def test_whichever_source_is_first_is_the_one_consulted(order):
    """All six permutations, because "the order is followed" is the whole
    feature and one example of it proves only that one example works.
    """
    first = order[0]
    attempts = {name: (hit(name) if name == first else never(name)) for name in order}

    result = await sources.resolve(order, ALL_ON, attempts)

    assert result.kind == first


@pytest.mark.asyncio
async def test_a_miss_moves_on_to_the_next_source_in_order():
    log = []

    result = await sources.resolve(
        ["documents", "database", "web"], ALL_ON,
        {"documents": miss("documents", log), "database": hit("database"),
         "web": never("web")})

    assert log == ["documents"]
    assert result.kind == "database"


@pytest.mark.asyncio
async def test_a_source_switched_off_is_skipped_without_being_called():
    result = await sources.resolve(
        ["documents", "database", "web"],
        {"documents": False, "database": True, "web": True},
        {"documents": never("documents"), "database": hit("database"),
         "web": never("web")})

    assert result.kind == "database"


@pytest.mark.asyncio
async def test_a_source_that_raises_is_a_miss_and_the_cascade_continues():
    async def explode():
        raise RuntimeError("the database is on fire")

    result = await sources.resolve(
        ["database", "web"], ALL_ON,
        {"database": explode, "web": hit("web")})

    assert result.kind == "web"


@pytest.mark.asyncio
async def test_every_source_missing_answers_with_nothing():
    result = await sources.resolve(
        ["documents", "database", "web"], ALL_ON,
        {"documents": miss("documents"), "database": miss("database"),
         "web": miss("web")})

    assert result is None


@pytest.mark.asyncio
async def test_every_source_switched_off_answers_with_nothing():
    result = await sources.resolve(
        ["documents", "database", "web"],
        {"documents": False, "database": False, "web": False},
        {"documents": never("documents"), "database": never("database"),
         "web": never("web")})

    assert result is None


def test_which_switch_governs_which_source():
    class Bot:
        retrieval_enabled = True
        db_query_enabled = False
        web_search_enabled = True

    assert sources.enabled_for(Bot()) == {
        "documents": True, "database": False, "web": True}


class FallbackBot:
    retrieval_fallback = "say_unknown"


def test_a_question_that_found_nothing_is_told_so():
    assert sources.fallback_for(FallbackBot(), True, {"documents": True}) == "say_unknown"


def test_a_greeting_is_never_told_the_answer_is_missing():
    # A gated message must never be told the answer is missing from the
    # material. A greeting gets a greeting.
    assert sources.fallback_for(FallbackBot(), False, {"documents": True}) == "answer_anyway"


def test_a_bot_with_no_source_at_all_is_never_told_the_answer_is_missing():
    # There was no material to be missing from.
    assert sources.fallback_for(
        FallbackBot(), True,
        {"documents": False, "database": False, "web": False}) == "answer_anyway"


def test_answer_kind_names_the_source_that_answered():
    assert sources.answer_kind(SourceResult(kind="web", has_content=True), True, False) == "web"


def test_answer_kind_tells_a_miss_from_no_search():
    assert sources.answer_kind(None, True, False) == "none"
    assert sources.answer_kind(None, False, False) == "model"


def test_answer_kind_says_refused_before_anything_else():
    assert sources.answer_kind(None, False, True) == "refused"
