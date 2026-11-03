"""One call to an operation on a REST connector.

The connector says where the API lives and how to sign in; the tool says which
operation, and its parameter schema says where each argument goes (x-in: path
or query; "body" is the JSON body). Arguments come from a model, so a path
argument is percent-encoded whole and can never steer the request to another
route, and ".." is refused outright.

Never raises: every failure is a CallResult with a sentence in it, because a
tool that fails is a tool that did not answer, not a broken chat.
"""
import time
from dataclasses import dataclass
from urllib.parse import quote

import httpx

TIMEOUT = 15.0
MAX_CHARS = 8000


@dataclass
class CallResult:
    ok: bool
    status: int = 0
    text: str = ""
    # Safe to show a visitor or a model: never the API's address.
    error: str = ""
    ms: int = 0
    # The full reason, for the operator's transcript only.
    detail: str = ""


def _auth(auth_type, auth_header, auth_secret) -> tuple[dict, object]:
    secret = auth_secret or ""
    if auth_type == "bearer" and secret:
        return {"Authorization": f"Bearer {secret}"}, None
    if auth_type == "header" and auth_header and secret:
        return {auth_header: secret}, None
    if auth_type == "basic" and secret:
        user, _, password = secret.partition(":")
        return {}, httpx.BasicAuth(user, password)
    return {}, None


def _request_parts(tool, arguments: dict) -> tuple[str, dict, object]:
    """The path, query and body for these arguments. Raises ValueError on a bad path argument."""
    properties = ((tool.parameters or {}).get("properties") or {})
    path = tool.path
    query = {}

    for name, value in arguments.items():
        where = (properties.get(name) or {}).get("x-in")
        if where == "path":
            text = str(value)
            # Empty, or only dots, would turn /users/{id} into /users or /users/.
            if ".." in text or text.strip(" .") == "":
                raise ValueError(f"The value for {name} is not allowed in a path.")
            path = path.replace("{" + name + "}", quote(text, safe=""))
        elif where == "query":
            query[name] = value if isinstance(value, (str, int, float, bool)) else str(value)

    return path, query, arguments.get("body")


async def call(connector, tool, arguments: dict, transport=None) -> CallResult:
    started = time.monotonic()

    def elapsed() -> int:
        return int((time.monotonic() - started) * 1000)

    try:
        path, query, body = _request_parts(tool, arguments or {})
    except ValueError as error:
        return CallResult(ok=False, error=str(error))

    headers, auth = _auth(connector.auth_type, connector.auth_header, connector.auth_secret)
    url = connector.base_url.rstrip("/") + "/" + path.lstrip("/")

    try:
        async with httpx.AsyncClient(timeout=TIMEOUT, transport=transport) as client:
            response = await client.request(
                tool.method.upper(), url, params=query or None, headers=headers, auth=auth,
                **({"json": body} if body is not None else {}))
    except httpx.TimeoutException:
        return CallResult(ok=False, error="The system did not answer in time.", ms=elapsed(),
                          detail=f"{connector.base_url} did not answer within {int(TIMEOUT)} seconds.")
    except httpx.HTTPError as error:
        return CallResult(ok=False, error="The system could not be reached.", ms=elapsed(),
                          detail=f"Could not reach {connector.base_url}: {error}")

    text = response.text[:MAX_CHARS]
    if response.status_code >= 400:
        return CallResult(ok=False, status=response.status_code, text=text,
                          error=f"The system answered {response.status_code}.", ms=elapsed(),
                          detail=f"{tool.method.upper()} {url} answered {response.status_code}: {text[:300]}")

    return CallResult(ok=True, status=response.status_code, text=text, ms=elapsed())


async def ping(base_url: str, auth_type: str, auth_header, auth_secret, transport=None) -> dict:
    """Whether the API answers at all. Any status below 500 means it is there."""
    headers, auth = _auth(auth_type, auth_header, auth_secret)

    try:
        async with httpx.AsyncClient(timeout=TIMEOUT, transport=transport) as client:
            response = await client.get(base_url, headers=headers, auth=auth)
    except httpx.TimeoutException:
        return {"success": False, "message": f"{base_url} did not answer within {int(TIMEOUT)} seconds."}
    except httpx.HTTPError as error:
        return {"success": False, "message": f"Could not reach {base_url}: {error}"}

    status = response.status_code
    if status in (401, 403):
        return {"success": True, "message": f"Reached the API, but it answered {status}: check the credentials."}
    if status >= 500:
        return {"success": False, "message": f"The API answered {status}, a server error."}
    return {"success": True, "message": f"Reached the API (status {status})."}
