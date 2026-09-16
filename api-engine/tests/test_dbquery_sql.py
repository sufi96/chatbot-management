"""The statement validator.

A model writes these, and a model can be talked into writing anything. Every
case here is a thing that must never reach a customer's database.
"""
import pytest

from dbquery.sql import Validation, referenced_tables, strip_comments, validate

ALLOWED = {"orders", "customers", "public.orders"}


def check(sql, driver="mysql", max_rows=50, allowed=None):
    return validate(sql, ALLOWED if allowed is None else allowed, driver, max_rows)


def test_a_plain_select_passes():
    assert check("SELECT id FROM orders").ok


def test_a_join_between_allowed_tables_passes():
    result = check("SELECT o.id FROM orders o JOIN customers c ON c.id = o.customer_id")
    assert result.ok, result.complaint


def test_a_cte_reaching_a_select_passes():
    result = check("WITH recent AS (SELECT id FROM orders) SELECT * FROM recent")
    assert result.ok, result.complaint


def test_a_cte_name_counts_as_allowed():
    # The statement defines "recent" itself, so it is not an unknown table.
    result = check("WITH recent AS (SELECT id FROM orders) SELECT * FROM recent")
    assert result.ok


@pytest.mark.parametrize("statement", [
    "INSERT INTO orders (id) VALUES (1)",
    "UPDATE orders SET total = 0",
    "DELETE FROM orders",
    "DROP TABLE orders",
    "ALTER TABLE orders ADD c int",
    "CREATE TABLE t (id int)",
    "TRUNCATE TABLE orders",
    "GRANT ALL ON orders TO bob",
    "REVOKE ALL ON orders FROM bob",
    "MERGE INTO orders USING customers ON 1=1",
    "EXEC sp_who",
    "EXECUTE sp_who",
    "CALL do_something()",
    "ATTACH DATABASE 'x' AS y",
    "PRAGMA table_info(orders)",
    "COPY orders TO '/tmp/x'",
])
def test_every_write_and_ddl_verb_is_refused(statement):
    assert not check(statement).ok


def test_select_into_is_refused():
    # SELECT ... INTO writes a new table on several dialects.
    assert not check("SELECT * INTO backup FROM orders").ok


def test_a_second_statement_is_refused():
    assert not check("SELECT id FROM orders; DROP TABLE orders").ok


def test_a_statement_hidden_in_a_line_comment_is_refused():
    sql = "SELECT id FROM orders -- harmless\n; DROP TABLE orders"
    assert not check(sql).ok


def test_a_write_verb_hidden_in_a_block_comment_does_not_sneak_past():
    # Stripping comments first is what makes this a single harmless select
    # rather than something that reads as two statements later on.
    result = check("SELECT id /* DELETE FROM orders */ FROM orders")
    assert result.ok, result.complaint


def test_a_trailing_semicolon_is_fine():
    assert check("SELECT id FROM orders;").ok


def test_a_table_outside_the_allowlist_is_refused():
    result = check("SELECT id FROM salaries")
    assert not result.ok
    assert "salaries" in result.complaint


def test_a_schema_qualified_allowed_table_passes():
    assert check("SELECT id FROM public.orders").ok


def test_a_quoted_identifier_is_recognised():
    assert check('SELECT id FROM "orders"').ok


def test_a_backticked_identifier_is_recognised():
    assert check("SELECT id FROM `orders`").ok


def test_an_alias_after_the_table_is_not_mistaken_for_a_table():
    assert check("SELECT s.id FROM orders AS s").ok


def test_a_column_named_like_a_keyword_still_passes():
    # "created_at" contains "create". Token boundaries, not substrings.
    assert check("SELECT created_at, updated_at FROM orders").ok


def test_a_column_named_delete_count_still_passes():
    assert check("SELECT delete_count FROM orders").ok


def test_an_empty_statement_is_refused():
    assert not check("").ok
    assert not check("   \n  ").ok


def test_something_that_is_not_a_select_is_refused():
    assert not check("SHOW TABLES").ok


def test_a_limit_is_injected_for_mysql():
    result = check("SELECT id FROM orders", driver="mysql", max_rows=25)
    assert result.sql.rstrip().lower().endswith("limit 25")


def test_a_limit_is_injected_for_postgres():
    result = check("SELECT id FROM orders", driver="pgsql", max_rows=25)
    assert result.sql.rstrip().lower().endswith("limit 25")


def test_a_limit_is_injected_for_sqlite():
    result = check("SELECT id FROM orders", driver="sqlite", max_rows=25)
    assert result.sql.rstrip().lower().endswith("limit 25")


def test_sql_server_gets_a_top_instead():
    result = check("SELECT id FROM orders", driver="sqlsrv", max_rows=25)
    assert result.sql.lower().startswith("select top 25")
    assert "limit" not in result.sql.lower()


def test_an_existing_limit_is_left_alone():
    result = check("SELECT id FROM orders LIMIT 5", driver="mysql", max_rows=50)
    assert result.sql.lower().count("limit") == 1
    assert "limit 5" in result.sql.lower()


def test_an_existing_top_is_left_alone():
    result = check("SELECT TOP 5 id FROM orders", driver="sqlsrv", max_rows=50)
    assert result.sql.lower().count("top") == 1


def test_strip_comments_removes_both_kinds():
    assert "secret" not in strip_comments("SELECT 1 -- secret\nFROM t")
    assert "secret" not in strip_comments("SELECT 1 /* secret */ FROM t")


def test_strip_comments_leaves_a_string_literal_alone():
    # A double dash inside quotes is data, not a comment.
    assert "a--b" in strip_comments("SELECT 'a--b' FROM t")


def test_referenced_tables_finds_from_and_join():
    found = referenced_tables("SELECT 1 FROM orders o JOIN customers c ON 1=1")
    assert found == {"orders", "customers"}


def test_referenced_tables_sees_into_a_subquery():
    found = referenced_tables("SELECT 1 FROM (SELECT id FROM orders) x")
    assert "orders" in found


def test_a_subquery_touching_a_forbidden_table_is_refused():
    assert not check("SELECT 1 FROM (SELECT id FROM salaries) x").ok


def test_a_validation_carries_a_complaint_worth_retrying_on():
    result = check("DELETE FROM orders")
    assert isinstance(result, Validation)
    assert result.complaint
