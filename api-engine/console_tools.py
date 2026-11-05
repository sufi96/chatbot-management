"""The console assistant's tools, which live in the portal.

The portal knows who may do what, so the tools are there (admin-laravel/
app/Assistant) and the engine only carries the call. Each call sends two
tokens: the engine's own (X-Portal-Token), which says the call comes from
this engine, and the console token the widget sent, which says whose rights
apply. The engine never reads the console token; it cannot make one either.

Asked fresh on every message, so a tool the portal gains is in the next
answer, and a person whose role changed gets their new rights at once.
Never raises: no token, a bad one or a portal that is down means no tools.
"""
from dataclasses import dataclass, field
from types import SimpleNamespace

import httpx

import agent
from config import settings as app_settings
from connectors.rest import CallResult

CONSOLE = SimpleNamespace(name="C⁴ console")

TIMEOUT = 20


@dataclass
class Console:
    """Who is chatting, and what they may ask the assistant to do."""
    user: dict | None = None
    tools: list = field(default_factory=list)
    token: str = ""

    @property
    def super_admin(self) -> bool:
        return bool(self.user and self.user.get("super_admin"))


async def _post(path: str, payload: dict, transport=None) -> httpx.Response:
    async with httpx.AsyncClient(timeout=TIMEOUT, transport=transport) as client:
        return await client.post(
            app_settings.PORTAL_BASE_URL.rstrip("/") + path, json=payload,
            headers={"X-Portal-Token": app_settings.PORTAL_INTERNAL_TOKEN, "Accept": "application/json"})


async def call(token: str | None, name: str, arguments: dict, transport=None) -> CallResult:
    if not token:
        return CallResult(ok=False, error="Sign in to the console again.")
    try:
        response = await _post("/internal/assistant/call",
                               {"token": token, "name": name, "arguments": arguments or {}}, transport)
        body = response.json()
    except Exception as error:
        return CallResult(ok=False, error="The console could not be reached.", detail=f"Console call {name}: {error}")

    if body.get("ok"):
        return CallResult(ok=True, text=str(body.get("text") or "Done."))
    return CallResult(ok=False, error=str(body.get("error") or "The console refused that."))


def _tool(token: str, spec: dict, transport=None) -> agent.Tool:
    name = str(spec.get("name") or "")

    async def run(arguments: dict) -> CallResult:
        outcome = await call(token, name, arguments, transport=transport)
        if not outcome.ok and not outcome.detail:
            # The portal said no, with a reason the person should hear ("No bot
            # Sales that you can reach"), not "the request failed".
            return CallResult(ok=True, text=f"Not done. The console said: {outcome.error}", ms=outcome.ms)
        return outcome

    return agent.Tool(id=None, name=name, description=str(spec.get("description") or ""),
                      parameters=spec.get("parameters") or {"type": "object", "properties": {}},
                      writes=bool(spec.get("writes")), connector=CONSOLE, row=None, local=run)


async def load(token: str | None, transport=None) -> Console:
    if not token:
        return Console()
    try:
        response = await _post("/internal/assistant/tools", {"token": token}, transport)
        body = response.json() if response.status_code == 200 else {}
    except Exception as error:
        print(f"[Console] Could not load the assistant's tools: {error}")
        return Console()
    if not body.get("ok"):
        return Console()

    return Console(user=body.get("user") or {}, token=token,
                   tools=[_tool(token, spec, transport) for spec in body.get("tools") or [] if spec.get("name")])
