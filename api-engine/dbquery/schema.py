"""The database, as the model is told about it.

The model never sees a table, only these sentences. A column called amt_ttl
is worth nothing until somebody writes down that it is the total charged
including tax, which is why the schema editor exists at all.
"""
from dataclasses import dataclass


@dataclass
class SchemaColumn:
    name: str
    data_type: str
    is_primary_key: bool
    foreign_key_target: str | None
    description: str


@dataclass
class SchemaTable:
    qualified_name: str
    description: str
    columns: list[SchemaColumn]


def allowed_names(tables: list[SchemaTable]) -> set[str]:
    """The allowlist the validator checks a statement against."""
    return {table.qualified_name.lower() for table in tables}


def build_table_summary(tables: list[SchemaTable]) -> str:
    """Names and descriptions only, for the router.

    The router is deciding whether this database could answer at all. Column
    detail would triple the prompt without improving that decision.
    """
    lines = []
    for table in tables:
        description = table.description.strip() if table.description else ""
        lines.append(f"- {table.qualified_name}: {description}" if description
                     else f"- {table.qualified_name}")

    return "\n".join(lines)


def build_schema_block(tables: list[SchemaTable]) -> str:
    """Everything generation needs: shape, keys, and the human explanation."""
    if not tables:
        return ""

    parts = []
    for table in tables:
        parts.append(f"TABLE {table.qualified_name}")
        if table.description and table.description.strip():
            parts.append(f"  {table.description.strip()}")

        for col in table.columns:
            notes = [col.data_type or "unknown type"]
            if col.is_primary_key:
                notes.append("primary key")
            if col.foreign_key_target:
                notes.append(f"references {col.foreign_key_target}")

            line = f"  - {col.name} ({', '.join(notes)})"
            if col.description and col.description.strip():
                line += f": {col.description.strip()}"
            parts.append(line)

        parts.append("")

    return "\n".join(parts).strip()
