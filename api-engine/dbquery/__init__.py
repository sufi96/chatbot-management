"""Querying the operator's database, when the order says it is the database's turn.

The shape of this module is one rule repeated: every way it can go wrong reports
that it did not answer. A statement that fails validation twice, a portal that is
down, a query that times out, a question no table can answer. All of them come
back as an answer that did not run, and the cascade moves on to whatever the
operator put next.

It no longer decides whether it should be asked. See
docs/superpowers/specs/2026-09-14-answer-source-order-design.md.
"""
from datetime import date

from sqlalchemy import select

import roles
from dbquery import context as row_context
from dbquery import portal as portal_module
from dbquery import sql as sql_module
from dbquery.result import DbAnswer
from dbquery.schema import (SchemaColumn, SchemaTable, allowed_names,
                            build_schema_block)
from llm_adapter import LLMAdapter

GENERATION_PROMPT = """You write one read-only SQL query for a {driver} database.

{schema}

Rules:
- Reply with the SQL statement and nothing else. No explanation, no markdown fence.
- Only SELECT. Never INSERT, UPDATE, DELETE, or anything that changes data.
- Only the tables listed above may be named.
- Prefer the columns whose descriptions match what was asked for.
- If no table listed above can answer the question, reply exactly NO_QUERY and nothing else."""

DECLINED = "NO_QUERY"

DIALECTS = {"mysql": "MySQL", "pgsql": "PostgreSQL",
            "sqlsrv": "SQL Server", "sqlite": "SQLite"}


async def load_bot_schema(session, bot_id: str) -> tuple[str, str, str, list[SchemaTable]]:
    """The one enabled connection attached to this bot, and its readable tables.

    Both switches have to agree, which is why is_enabled is checked on the
    connection and on the table. A table the database no longer has is
    excluded too: it cannot answer anything and naming it would fail.

    The driver travels with them because the row limit is written differently
    per dialect, and handing SQL Server a LIMIT clause fails every time.
    """
    from database import BotDbConnection, DbColumn, DbConnection, DbTable

    rows = await session.execute(
        select(DbConnection.id, DbConnection.name, DbConnection.driver)
        .join(BotDbConnection, BotDbConnection.connection_id == DbConnection.id)
        .where(BotDbConnection.bot_id == bot_id, DbConnection.is_enabled.is_(True))
        .limit(1))
    found = rows.first()

    if not found:
        return "", "", "", []

    connection_id, connection_name, driver = found

    table_rows = await session.execute(
        select(DbTable)
        .where(DbTable.connection_id == connection_id,
               DbTable.is_enabled.is_(True), DbTable.is_present.is_(True))
        .order_by(DbTable.schema_name, DbTable.table_name))
    stored_tables = list(table_rows.scalars().all())

    if not stored_tables:
        return connection_id, connection_name, driver, []

    column_rows = await session.execute(
        select(DbColumn)
        .where(DbColumn.table_id.in_([t.id for t in stored_tables]),
               DbColumn.is_present.is_(True))
        .order_by(DbColumn.ordinal))
    by_table: dict[int, list] = {}
    for column in column_rows.scalars().all():
        by_table.setdefault(column.table_id, []).append(column)

    tables = []
    for table in stored_tables:
        qualified = (f"{table.schema_name}.{table.table_name}"
                     if table.schema_name else table.table_name)
        tables.append(SchemaTable(
            qualified_name=qualified,
            description=table.description or "",
            columns=[SchemaColumn(
                name=column.column_name,
                data_type=column.data_type or "",
                is_primary_key=bool(column.is_primary_key),
                foreign_key_target=column.foreign_key_target,
                description=column.description or "",
            ) for column in by_table.get(table.id, [])],
        ))

    return connection_id, connection_name, driver, tables


SQL_MAX_TOKENS = 2048


def _sql_endpoint(bot, settings: dict) -> tuple[str, str, str]:
    """Where query work goes. Blank settings mean the bot's own model.

    roles.py owns that rule for every job; this keeps the tuple the generator
    and its tests already use.
    """
    endpoint = roles.endpoint_for("sql", bot, settings)

    return endpoint.base_url, endpoint.api_key, endpoint.model


def _unfence(answer: str) -> str:
    """Models wrap SQL in a markdown fence however firmly you ask them not to."""
    text = (answer or "").strip()
    if text.startswith("```"):
        text = text.split("\n", 1)[-1]
        if "```" in text:
            text = text.rsplit("```", 1)[0]

    return text.strip()


async def answer(session, bot, message: str, settings: dict, complete=None,
                 portal_call=None, load_schema=None) -> DbAnswer:
    """One attempt at the database, and whether it produced an answer.

    It no longer decides whether it should be asked. The bot's source order
    decides that, and this reports back only whether it managed to answer, so
    the cascade knows whether to move on.
    """
    complete = complete or LLMAdapter.complete
    portal_call = portal_call or portal_module.run_query
    loader = load_schema or load_bot_schema

    if not bot.db_query_enabled:
        return DbAnswer()

    connection_id, connection_name, driver, tables = await loader(session, bot.id)
    if not tables:
        # Nothing readable, so there is nothing to ask about.
        return DbAnswer()

    base_url, api_key, model = _sql_endpoint(bot, settings)
    merge_system = roles.endpoint_for("sql", bot, settings).merge_system

    from config import settings as engine_settings

    generation_prompt = GENERATION_PROMPT.format(
        driver=DIALECTS.get(driver, "SQL"), schema=build_schema_block(tables))
    allowed = allowed_names(tables)
    max_rows = int(bot.db_max_rows or 50)

    statement = ""
    complaint = ""
    for attempt in range(2):
        prompt = generation_prompt if attempt == 0 else (
            f"{generation_prompt}\n\nYour last statement was rejected: {complaint}\n"
            "Write a statement that obeys the rules.")

        # Room to think first: a reasoning model that ignores the thinking
        # switch spent the old 512 tokens reasoning and returned no statement.
        raw = _unfence(await complete(
            base_url=base_url, api_key=api_key, model_name=model,
            system_prompt=prompt, user_message=message,
            max_tokens=SQL_MAX_TOKENS, merge_system=merge_system))

        # A decline is not a rejected statement, so it is not argued with.
        if raw.strip().upper() == DECLINED:
            return DbAnswer()

        checked = sql_module.validate(raw, allowed, driver, max_rows)

        if checked.ok:
            statement = checked.sql
            break

        complaint = checked.complaint
        print(f"[DbQuery] Statement rejected: {complaint}")

    if not statement:
        return DbAnswer()

    result, failure = await portal_call(
        base_url=engine_settings.PORTAL_BASE_URL,
        token=engine_settings.PORTAL_INTERNAL_TOKEN,
        connection_id=connection_id, sql=statement,
        max_rows=max_rows, timeout=int(bot.db_query_timeout or 10))

    if result is None:
        print(f"[DbQuery] Query failed, answering without it: {failure}")
        return DbAnswer()

    trimmed = row_context.fit_rows_to_budget(
        result, int(settings.get("context_char_budget", 6000)))

    return DbAnswer(
        ran=True,
        context_block=row_context.build_database_context_block(
            connection_name, trimmed, date.today().isoformat(), question=message),
        sql=statement,
        row_count=result.row_count,
        connection_name=connection_name,
    )
