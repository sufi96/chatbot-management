"""What the model is told about a database.

It never sees the tables, only these sentences. Everything the feature is
worth rests on them arriving intact and on nothing else arriving at all.
"""
from dbquery.schema import (SchemaColumn, SchemaTable, allowed_names,
                            build_schema_block, build_table_summary)


def column(name, description="", pk=False, fk=None, data_type="integer"):
    return SchemaColumn(name=name, data_type=data_type, is_primary_key=pk,
                        foreign_key_target=fk, description=description)


def orders():
    return SchemaTable(
        qualified_name="orders",
        description="Orders placed through the web shop. One row per order.",
        columns=[
            column("id", pk=True),
            column("customer_id", fk="customers.id", description="Who placed it."),
            column("amt_ttl", data_type="numeric",
                   description="Total charged, including tax."),
        ],
    )


def customers():
    return SchemaTable(
        qualified_name="customers",
        description="Everyone who has ever ordered.",
        columns=[column("id", pk=True), column("full_name", data_type="text")],
    )


def test_the_table_name_and_its_description_both_appear():
    block = build_schema_block([orders()])

    assert "orders" in block
    assert "One row per order" in block


def test_every_column_appears_with_its_type():
    block = build_schema_block([orders()])

    assert "amt_ttl" in block
    assert "numeric" in block


def test_a_column_description_appears():
    # This is the whole point: amt_ttl means nothing without the sentence.
    block = build_schema_block([orders()])

    assert "Total charged, including tax." in block


def test_a_primary_key_is_marked():
    assert "primary key" in build_schema_block([orders()]).lower()


def test_a_foreign_key_is_rendered_as_a_relationship():
    # A model told that orders.customer_id points at customers.id writes the
    # join without being asked for one.
    block = build_schema_block([orders()])

    assert "customers.id" in block


def test_two_tables_both_appear():
    block = build_schema_block([orders(), customers()])

    assert "orders" in block
    assert "customers" in block
    assert "full_name" in block


def test_no_tables_gives_an_empty_block():
    assert build_schema_block([]) == ""


def test_the_router_summary_is_names_and_descriptions_only():
    summary = build_table_summary([orders()])

    assert "orders" in summary
    assert "One row per order" in summary
    # Column detail is generation's business, not the router's.
    assert "amt_ttl" not in summary


def test_a_table_with_no_description_still_lists_in_the_summary():
    bare = SchemaTable(qualified_name="audit_log", description="", columns=[])

    assert "audit_log" in build_table_summary([bare])


def test_allowed_names_are_lowercase_qualified_names():
    names = allowed_names([orders(), customers()])

    assert names == {"orders", "customers"}


def test_allowed_names_lowercase_a_mixed_case_table():
    mixed = SchemaTable(qualified_name="Public.Orders", description="", columns=[])

    assert allowed_names([mixed]) == {"public.orders"}
