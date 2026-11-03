"""One call to a REST connector's tool, and the portal's connection test."""
import json
from types import SimpleNamespace

import httpx
import pytest
from fastapi import FastAPI
from httpx import ASGITransport, AsyncClient

from config import settings
from connectors import rest
from routers import connectors as connectors_router


def connector(**fields):
    base = dict(base_url="https://api.example.test/v1", auth_type="none", auth_header=None, auth_secret=None)
    return SimpleNamespace(**{**base, **fields})


def tool(path="/applications/{id}", method="get", parameters=None):
    parameters = parameters or {"type": "object", "properties": {
        "id": {"type": "string", "x-in": "path"}}}
    return SimpleNamespace(path=path, method=method, parameters=parameters)


def recorder(status=200, text='{"ok": true}'):
    seen = []

    def handler(request):
        seen.append(request)
        return httpx.Response(status, text=text)

    return seen, httpx.MockTransport(handler)


async def test_path_arguments_are_encoded_not_followed():
    seen, transport = recorder()

    result = await rest.call(connector(), tool(), {"id": "a/b?c"}, transport=transport)

    assert result.ok
    assert seen[0].url.raw_path.decode().endswith("/v1/applications/a%2Fb%3Fc")


async def test_dot_dot_is_refused_without_a_request():
    seen, transport = recorder()

    result = await rest.call(connector(), tool(), {"id": "../admin"}, transport=transport)

    assert result.ok is False
    assert result.error
    assert seen == []


async def test_query_body_and_bearer_auth():
    seen, transport = recorder()
    params = {"type": "object", "properties": {
        "status": {"type": "string", "x-in": "query"},
        "body": {"type": "object"}}}

    await rest.call(connector(auth_type="bearer", auth_secret="tok"),
                    tool(path="/tickets", method="get", parameters=params),
                    {"status": "open"}, transport=transport)
    await rest.call(connector(), tool(path="/tickets", method="post", parameters=params),
                    {"body": {"subject": "x"}}, transport=transport)

    assert seen[0].url.params["status"] == "open"
    assert seen[0].headers["authorization"] == "Bearer tok"
    assert seen[1].method == "POST"
    assert json.loads(seen[1].content) == {"subject": "x"}


async def test_header_and_basic_auth():
    seen, transport = recorder()

    await rest.call(connector(auth_type="header", auth_header="X-Api-Key", auth_secret="k1"),
                    tool(), {"id": "1"}, transport=transport)
    await rest.call(connector(auth_type="basic", auth_secret="user:pa:ss"),
                    tool(), {"id": "1"}, transport=transport)

    assert seen[0].headers["x-api-key"] == "k1"
    assert seen[1].headers["authorization"].startswith("Basic ")


async def test_long_responses_are_cut():
    _, transport = recorder(text="x" * 20000)

    result = await rest.call(connector(), tool(), {"id": "1"}, transport=transport)

    assert len(result.text) == 8000


async def test_an_error_status_is_not_ok():
    _, transport = recorder(status=404, text="no such application")

    result = await rest.call(connector(), tool(), {"id": "1"}, transport=transport)

    assert result.ok is False
    assert result.status == 404
    assert "404" in result.error


async def test_unreachable_host_is_a_sentence():
    def refuse(request):
        raise httpx.ConnectError("refused")

    transport = httpx.MockTransport(refuse)

    result = await rest.call(connector(), tool(), {"id": "1"}, transport=transport)
    pinged = await rest.ping("https://api.example.test", "none", None, None, transport=transport)

    assert result.ok is False and result.error
    assert pinged["success"] is False and pinged["message"]


async def test_ping_names_an_auth_failure():
    _, transport = recorder(status=401)

    pinged = await rest.ping("https://api.example.test", "bearer", None, "bad", transport=transport)

    assert pinged["success"] is True
    assert "credentials" in pinged["message"]


async def test_test_route_needs_the_admin_token(monkeypatch):
    monkeypatch.setattr(settings, "ADMIN_API_TOKEN", "portal-token")
    app = FastAPI()
    app.include_router(connectors_router.router)

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        response = await client.post("/api/v1/connectors/test",
                                     json={"base_url": "http://127.0.0.1:9", "auth_type": "none"})

    assert response.status_code == 401


@pytest.mark.parametrize("value", [".", "", "...", " "])
async def test_a_path_argument_that_would_change_the_route_is_refused(value):
    seen, transport = recorder()

    result = await rest.call(connector(), tool(), {"id": value}, transport=transport)

    assert result.ok is False
    assert seen == []


async def test_errors_do_not_name_the_api_address():
    def refuse(request):
        raise httpx.ConnectError("refused")

    result = await rest.call(connector(base_url="http://10.1.2.3:8080"), tool(), {"id": "1"},
                             transport=httpx.MockTransport(refuse))

    assert "10.1.2.3" not in result.error
    assert "10.1.2.3" in result.detail
