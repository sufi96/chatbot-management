"""MCP servers as connectors: list their tools, call one, see if one answers.

Built on FastMCP's client over streamable HTTP. A tool keeps its MCP name in
the connector tool's `path` (its `name` is the model-safe one the portal made).
Never raises: every failure is a CallResult or a sentence, and the server's
address and its own error text stay out of what a visitor or a model sees.
"""
import base64
import time

from fastmcp import Client
from fastmcp.client.transports import StreamableHttpTransport

from connectors.rest import MAX_CHARS, TIMEOUT, CallResult, Returned


def _headers(auth_type, auth_header, auth_secret) -> dict:
    secret = auth_secret or ""
    if auth_type == "bearer" and secret:
        return {"Authorization": f"Bearer {secret}"}
    if auth_type == "header" and auth_header and secret:
        return {auth_header: secret}
    if auth_type == "basic" and secret:
        return {"Authorization": "Basic " + base64.b64encode(secret.encode()).decode()}
    return {}


def _client(base_url, auth_type, auth_header, auth_secret, transport=None) -> Client:
    # transport: a FastMCP server held in memory, for tests.
    target = transport or StreamableHttpTransport(base_url, headers=_headers(auth_type, auth_header, auth_secret))
    return Client(target, timeout=TIMEOUT, init_timeout=TIMEOUT)


EXTENSIONS = {"image/png": "png", "image/jpeg": "jpg", "image/webp": "webp", "application/pdf": "pdf"}


def _files(result, tool_name: str) -> list:
    """Images and embedded file resources in a tool's result, as files."""
    found = []
    for item in result.content or []:
        mime, data, name = getattr(item, "mimeType", None) or getattr(item, "mime_type", None), None, None
        if getattr(item, "type", "") == "image" and getattr(item, "data", None):
            data = item.data
        resource = getattr(item, "resource", None)
        if resource is not None and getattr(resource, "blob", None):
            data, mime = resource.blob, getattr(resource, "mimeType", None) or getattr(resource, "mime_type", None)
            name = str(getattr(resource, "uri", "")).rstrip("/").rsplit("/", 1)[-1] or None
        if data and mime:
            try:
                raw = base64.b64decode(data)
            except ValueError:
                continue
            found.append(Returned(name or f"{tool_name}.{EXTENSIONS.get(mime, 'bin')}", mime, raw))
    return found


def _text(result) -> str:
    parts = [getattr(item, "text", "") for item in (result.content or [])]
    return "\n".join(p for p in parts if p)[:MAX_CHARS]


async def list_tools(base_url, auth_type, auth_header, auth_secret, transport=None) -> dict:
    try:
        async with _client(base_url, auth_type, auth_header, auth_secret, transport) as client:
            tools = await client.list_tools()
    except Exception as error:
        return {"success": False, "message": f"Could not list the server's tools: {error}"}

    return {"success": True, "tools": [{
        "name": tool.name,
        "description": tool.description or "",
        "parameters": tool.input_schema or {"type": "object", "properties": {}},
        # Only a tool that says it only reads runs without the visitor's Confirm.
        "writes": not bool(getattr(tool.annotations, "read_only_hint", False)),
    } for tool in tools]}


async def call(connector, row, arguments: dict, transport=None) -> CallResult:
    started = time.monotonic()

    def elapsed() -> int:
        return int((time.monotonic() - started) * 1000)

    try:
        async with _client(connector.base_url, connector.auth_type, connector.auth_header,
                           connector.auth_secret, transport) as client:
            result = await client.call_tool(row.path, arguments or {}, raise_on_error=False)
    except Exception as error:
        return CallResult(ok=False, error="The system could not be reached.", ms=elapsed(),
                          detail=f"MCP {connector.base_url}: {error}")

    text = _text(result)
    if result.is_error:
        return CallResult(ok=False, error="The system could not do that.", ms=elapsed(),
                          detail=f"MCP tool {row.path} failed: {text[:300]}")
    handed = _files(result, row.path)
    if handed and not text:
        text = "The system returned " + ", ".join(f"a file: {f.name}" for f in handed)
    return CallResult(ok=True, text=text, ms=elapsed(), files=handed)


async def ping(base_url, auth_type, auth_header, auth_secret, transport=None) -> dict:
    # No protocol ping: FastMCP servers don't answer it ("Method not found").
    # Connecting and listing the tools is the proof that matters anyway.
    try:
        async with _client(base_url, auth_type, auth_header, auth_secret, transport) as client:
            tools = await client.list_tools()
    except Exception as error:
        return {"success": False, "message": f"Could not reach the MCP server: {error}"}
    return {"success": True, "message": f"Reached the MCP server ({len(tools)} tools)."}
