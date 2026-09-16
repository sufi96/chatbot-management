"""One attempt at the database, with the model and the portal swapped for fakes.

The rule being tested throughout: the step reports whether it answered, and
every way of not answering reports the same thing, so the cascade can move on
to whatever the operator put next.
"""
import pytest

import dbquery
from dbquery.result import DbAnswer, QueryResult
from dbquery.schema import SchemaColumn, SchemaTable


class FakeProvider:
    """The endpoint the bot points at. A bot no longer carries its own."""
    base_url = "http://localhost:11434/v1"
    api_key = ""


class FakeBot:
    def __init__(self, db_query_enabled=True):
        self.id = "bot_1"
        self.db_query_enabled = db_query_enabled
        self.db_max_rows = 50
        self.db_query_timeout = 10
        self.provider = FakeProvider()
        self.model_name = "llama3.2"


ORDERS = SchemaTable(
    qualified_name="orders",
    description="Orders placed through the web shop.",
    columns=[SchemaColumn("id", "integer", True, None, ""),
             SchemaColumn("status", "text", False, None, "shipped or pending")],
)

SETTINGS = {"context_char_budget": "6000", "sql_model_base_url": "",
            "sql_model_api_key": "", "sql_model_name": ""}


def answers(*replies):
    """A fake complete() that returns each reply in turn."""
    queue = list(replies)

    async def complete(**kwargs):
        return queue.pop(0) if queue else ""

    return complete


def portal_returning(result, complaint=""):
    async def call(**kwargs):
        return result, complaint

    return call


def portal_that_must_not_be_called():
    async def call(**kwargs):
        raise AssertionError("a question that produced no statement must not "
                             "reach the portal")

    return call


def schema_loader(connection_id="dbc_1", name="Shop database",
                  driver="mysql", tables=None):
    async def load(session, bot_id):
        return connection_id, name, driver, [ORDERS] if tables is None else tables

    return load


ROWS = QueryResult(columns=["id", "status"], rows=[[1, "shipped"]],
                   row_count=1, elapsed_ms=8)

EMPTY = QueryResult(columns=["id"], rows=[], row_count=0, elapsed_ms=2)


async def run(bot=None, replies=("SELECT id, status FROM orders",),
              portal=None, tables=None):
    return await dbquery.answer(
        None,
        bot or FakeBot(),
        "has order 1 shipped?",
        SETTINGS,
        complete=answers(*replies),
        portal_call=portal or portal_returning(ROWS),
        load_schema=schema_loader(tables=tables),
    )


@pytest.mark.asyncio
async def test_a_question_the_tables_can_answer_is_queried():
    result = await run()

    assert result.ran is True
    assert "shipped" in result.context_block
    assert result.row_count == 1
    assert result.connection_name == "Shop database"


@pytest.mark.asyncio
async def test_the_generated_statement_is_kept_for_the_transcript():
    result = await run()

    assert "select" in result.sql.lower()


@pytest.mark.asyncio
async def test_a_question_no_table_can_answer_is_declined():
    """Ordering the database first would otherwise make every policy question
    generate SQL, find no rows, and stop before reaching the documents.
    """
    result = await run(replies=("NO_QUERY",), portal=portal_that_must_not_be_called())

    assert result.ran is False
    assert result.sql == ""


@pytest.mark.asyncio
async def test_a_decline_is_not_argued_with():
    """A rejected statement is retried once. A decline is not a rejection, and
    asking again would spend a second call to be told the same thing.
    """
    calls = []

    async def counting(**kwargs):
        calls.append(kwargs)
        return "NO_QUERY"

    result = await dbquery.answer(
        None, FakeBot(), "what is your refund policy?", SETTINGS,
        complete=counting, portal_call=portal_that_must_not_be_called(),
        load_schema=schema_loader())

    assert result.ran is False
    assert len(calls) == 1


@pytest.mark.asyncio
async def test_a_decline_is_recognised_through_a_markdown_fence():
    result = await run(replies=("```\nNO_QUERY\n```",),
                       portal=portal_that_must_not_be_called())

    assert result.ran is False


@pytest.mark.asyncio
async def test_a_bot_without_the_switch_never_calls_the_model():
    called = {"n": 0}

    async def counting(**kwargs):
        called["n"] += 1
        return "SELECT id FROM orders"

    result = await dbquery.answer(
        None, FakeBot(db_query_enabled=False), "hello", SETTINGS,
        complete=counting, portal_call=portal_returning(ROWS),
        load_schema=schema_loader())

    assert called["n"] == 0
    assert result.ran is False


@pytest.mark.asyncio
async def test_a_bot_with_no_enabled_tables_never_calls_the_model():
    called = {"n": 0}

    async def counting(**kwargs):
        called["n"] += 1
        return "SELECT id FROM orders"

    result = await dbquery.answer(
        None, FakeBot(), "hi", SETTINGS, complete=counting,
        portal_call=portal_returning(ROWS),
        load_schema=schema_loader(tables=[]))

    assert called["n"] == 0
    assert result.ran is False


@pytest.mark.asyncio
async def test_a_rejected_statement_is_retried_once_then_gives_up():
    result = await run(replies=("DELETE FROM orders", "DROP TABLE orders"),
                       portal=portal_that_must_not_be_called())

    assert result.ran is False
    assert result.context_block == ""


@pytest.mark.asyncio
async def test_a_retry_that_produces_a_good_statement_is_used():
    result = await run(replies=("DELETE FROM orders", "SELECT id, status FROM orders"))

    assert result.ran is True
    assert "shipped" in result.context_block


@pytest.mark.asyncio
async def test_a_statement_naming_a_forbidden_table_gives_up():
    result = await run(replies=("SELECT * FROM salaries", "SELECT * FROM salaries"),
                       portal=portal_that_must_not_be_called())

    assert result.ran is False


@pytest.mark.asyncio
async def test_an_unreachable_portal_is_not_an_answer():
    result = await run(portal=portal_returning(None, "Could not reach the portal"))

    assert result.ran is False
    assert result.context_block == ""


@pytest.mark.asyncio
async def test_a_query_that_ran_with_no_rows_is_still_an_answer():
    # "There is no order 88421" is the right answer, and it comes from here.
    # Falling through to a web search for it would replace an authoritative
    # answer with a stranger's guess.
    result = await run(portal=portal_returning(EMPTY))

    assert result.ran is True
    assert result.context_block
    assert result.row_count == 0


@pytest.mark.asyncio
async def test_the_rows_reach_the_prompt_with_the_question_they_answer():
    result = await run()

    assert "has order 1 shipped?" in result.context_block


@pytest.mark.asyncio
async def test_the_answer_is_the_documented_shape():
    result = await run()

    assert isinstance(result, DbAnswer)
