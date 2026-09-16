"""What the chat route does with whichever source answered.

The branching these covered used to live in context_for and
sources_payload_for. It lives in the SourceResult now, so these assert on the
result the cascade hands back.
"""
import pytest

import sources
from sources.result import SourceResult

ALL_ON = {"documents": True, "database": True, "web": True}


def hit(kind, **fields):
    async def attempt():
        return SourceResult(kind=kind, has_content=True, **fields)
    return attempt


def miss(kind):
    async def attempt():
        return SourceResult(kind=kind)
    return attempt


def never(kind):
    async def attempt():
        raise AssertionError(f"{kind} should not have been consulted")
    return attempt


@pytest.mark.asyncio
async def test_a_database_answer_supplies_its_rows_as_the_context():
    found = await sources.resolve(
        ["database", "documents", "web"], ALL_ON,
        {"database": hit("database", context_block="| id |\n| 4 |"),
         "documents": never("documents"), "web": never("web")})

    assert found.context_block == "| id |\n| 4 |"


@pytest.mark.asyncio
async def test_a_documents_answer_supplies_the_knowledge_base_block():
    found = await sources.resolve(
        ["documents", "database", "web"], ALL_ON,
        {"documents": hit("documents", context_block="From the handbook."),
         "database": never("database"), "web": never("web")})

    assert found.context_block == "From the handbook."


@pytest.mark.asyncio
async def test_nothing_retrieved_falls_through_to_the_next_source():
    found = await sources.resolve(
        ["documents", "web", "database"], ALL_ON,
        {"documents": miss("documents"),
         "web": hit("web", context_block="From the web."),
         "database": never("database")})

    assert found.kind == "web"


@pytest.mark.asyncio
async def test_no_source_answering_supplies_no_context_at_all():
    found = await sources.resolve(
        ["documents", "database", "web"], ALL_ON,
        {"documents": miss("documents"), "database": miss("database"),
         "web": miss("web")})

    assert found is None


@pytest.mark.asyncio
async def test_a_database_answer_cites_the_connection_by_name():
    found = await sources.resolve(
        ["database"], ALL_ON,
        {"database": hit("database", citations=[{"n": 1, "title": "Shop database"}])})

    assert found.citations == [{"n": 1, "title": "Shop database"}]


@pytest.mark.asyncio
async def test_a_database_citation_carries_no_url_and_no_sql():
    """A visitor on a public site must not learn the table names, let alone
    the statement, however the answer was produced.
    """
    found = await sources.resolve(
        ["database"], ALL_ON,
        {"database": hit("database", sql="SELECT id FROM orders", row_count=1,
                         citations=[{"n": 1, "title": "Shop database"}])})

    assert list(found.citations[0].keys()) == ["n", "title"]


@pytest.mark.asyncio
async def test_the_statement_reaches_the_transcript_but_not_the_citation():
    """An operator auditing a wrong answer needs the statement. chat.py writes
    these two onto the message row, and only the database attempt sets them.
    """
    found = await sources.resolve(
        ["database"], ALL_ON,
        {"database": hit("database", sql="SELECT id FROM orders", row_count=7,
                         citations=[{"n": 1, "title": "Shop database"}])})

    assert found.sql == "SELECT id FROM orders"
    assert found.row_count == 7


@pytest.mark.asyncio
async def test_a_documents_answer_leaves_the_transcript_columns_empty():
    found = await sources.resolve(
        ["documents"], ALL_ON, {"documents": hit("documents", context_block="x")})

    assert found.sql == ""
    assert found.row_count == 0
