"""Calling a connector's tool, whatever kind of connector it is."""
from connectors import mcp, rest


async def call(connector, row, arguments: dict, transport=None):
    """One tool call through the connector's own kind. Looked up at call time, so
    tests can stand in for either kind."""
    if getattr(connector, "type", "rest") == "mcp":
        return await mcp.call(connector, row, arguments, transport=transport)
    return await rest.call(connector, row, arguments, transport=transport)
