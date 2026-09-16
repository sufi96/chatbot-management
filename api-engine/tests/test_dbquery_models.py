"""The engine's view of tables Laravel owns.

Stage one migrated these. Nothing here creates them; these tests only pin
that the engine's picture matches, because a column named wrongly here fails
silently at runtime rather than loudly at import.
"""
from database import (SETTING_DEFAULTS, BotDbConnection, BotProfile,
                      ChatMessage, DbColumn, DbConnection, DbTable)


def test_the_four_tables_are_named_as_laravel_named_them():
    assert DbConnection.__tablename__ == "db_connections"
    assert DbTable.__tablename__ == "db_tables"
    assert DbColumn.__tablename__ == "db_columns"
    assert BotDbConnection.__tablename__ == "bot_db_connection"


def test_a_connection_carries_its_master_switch():
    assert hasattr(DbConnection, "is_enabled")
    assert hasattr(DbConnection, "name")
    assert hasattr(DbConnection, "driver")
    assert hasattr(DbConnection, "system_id")


def test_a_table_carries_its_allowlist_flag_and_annotation():
    for column in ("connection_id", "schema_name", "table_name",
                   "description", "is_enabled", "is_present"):
        assert hasattr(DbTable, column), column


def test_a_column_carries_its_shape_and_annotation():
    for column in ("table_id", "column_name", "data_type", "is_primary_key",
                   "foreign_key_target", "description", "ordinal", "is_present"):
        assert hasattr(DbColumn, column), column


def test_a_bot_carries_its_query_settings():
    assert hasattr(BotProfile, "db_query_enabled")
    assert hasattr(BotProfile, "db_max_rows")
    assert hasattr(BotProfile, "db_query_timeout")


def test_a_message_can_record_the_statement_that_answered_it():
    assert hasattr(ChatMessage, "db_sql")
    assert hasattr(ChatMessage, "db_row_count")


def test_the_sql_model_settings_default_to_blank():
    # Blank means "use the bot's own endpoint and model", which is the
    # supported default and needs no configuration at all.
    assert SETTING_DEFAULTS["sql_model_base_url"] == ""
    assert SETTING_DEFAULTS["sql_model_api_key"] == ""
    assert SETTING_DEFAULTS["sql_model_name"] == ""
