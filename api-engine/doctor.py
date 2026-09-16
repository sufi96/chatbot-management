"""Checking every model endpoint and shared secret an install depends on.

From api-engine/:

    .venv\\Scripts\\python.exe -m doctor

It reads the install's own settings and bots, sends each endpoint one small
request, and reports what answered. Nothing is written anywhere. Run it after
moving a model to another machine, after changing a setting, and first when a
bot starts answering "I do not have that information": every failure found on
2026-09-15 (blank shared secrets, a wrong vector store, an unreachable
reranker) would have shown up here as a line starting with "fail".

Exits 0 when every check that ran passed, and 1 when any failed. A job left
blank is reported as skipped, which is not a failure.
"""
import asyncio
import base64
import sys
import time
from dataclasses import dataclass, field

import httpx

import roles
from dbquery import portal as portal_module
from kb import embedding as embedding_module
from kb import rerank as rerank_module
from kb import vision as vision_module
from llm_adapter import LLMAdapter

TIMEOUT = httpx.Timeout(120.0, connect=10.0)

# A question with one right answer among three passages. A reranker that does
# not put the warranty passage first is not scoring relevance.
RERANK_QUESTION = "How long is the warranty on the X200 air fryer?"
RERANK_PASSAGES = [
    "Air fryers (X200, X100) have a 24-month warranty covering the heating element and fan.",
    "Delivery to Sabah, Sarawak and Labuan takes 5 to 9 working days and costs RM 25.",
    "WhatsApp and email are answered from 9am to 6pm, Monday to Saturday.",
]

ONE_PIXEL_PNG = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=")

# A connection id that cannot exist, so the portal refuses the query after
# checking the token and before touching any database.
NO_SUCH_CONNECTION = "doctor-no-such-connection"


def _one_line(text: str) -> str:
    """The report is one line per check; a pretty-printed error body broke it."""
    return " ".join(str(text or "").split())


def _describe(error: Exception) -> str:
    """An error in words. Some httpx timeouts carry no message at all."""
    message = _one_line(str(error))
    return f"{type(error).__name__}: {message}" if message else type(error).__name__


@dataclass
class Check:
    name: str
    endpoint: str = ""
    model: str = ""
    status: str = "skipped"
    detail: str = ""
    seconds: float = 0.0


@dataclass
class AnswerTarget:
    base_url: str
    api_key: str
    model: str
    bots: list = field(default_factory=list)


async def check_chat(name: str, base_url: str, api_key: str, model: str, transport=None) -> Check:
    check = Check(name, base_url, model)
    headers = {"Content-Type": "application/json"}
    if api_key:
        headers["Authorization"] = f"Bearer {api_key}"
    body = {"model": model, "messages": [{"role": "user", "content": "Reply with OK."}],
            "max_tokens": 5, "temperature": 0.0, "stream": False,
            "chat_template_kwargs": {"enable_thinking": False}}

    started = time.perf_counter()
    try:
        async with httpx.AsyncClient(timeout=TIMEOUT, transport=transport) as client:
            url = LLMAdapter._normalize_endpoint(base_url)
            response = await client.post(url, headers=headers, json=body)
            if (response.status_code == 400
                    and "chat_template_kwargs" in response.text):
                body.pop("chat_template_kwargs")
                response = await client.post(url, headers=headers, json=body)
    except httpx.HTTPError as error:
        check.status, check.detail = "fail", f"Could not reach the server: {_describe(error)}"
        check.seconds = time.perf_counter() - started
        return check
    check.seconds = time.perf_counter() - started

    if response.status_code != 200:
        check.status = "fail"
        check.detail = f"HTTP {response.status_code}: {_one_line(response.text)[:160]}"
    elif "choices" not in response.json():
        check.status, check.detail = "fail", "Answered, but not as a chat completion."
    else:
        check.status, check.detail = "ok", "answered"
    return check


async def check_embedding(settings: dict, transport=None) -> Check:
    client = embedding_module.client_for(settings)
    check = Check("embedding", client.base_url, client.model)

    started = time.perf_counter()
    try:
        vectors = await client.embed(["doctor"], transport=transport)
        check.status, check.detail = "ok", f"{len(vectors[0])} dimensions"
    except Exception as error:
        check.status, check.detail = "fail", _describe(error)[:300]
    check.seconds = time.perf_counter() - started
    return check


async def check_rerank(settings: dict, transport=None) -> Check:
    client = rerank_module.client_for(settings)
    if client is None:
        return Check("rerank", detail="blank: reranking is skipped, the similarity floor decides")

    check = Check("rerank", client.base_url, client.model)
    started = time.perf_counter()
    try:
        ranked = await client.rerank(RERANK_QUESTION, RERANK_PASSAGES, transport=transport)
        if ranked and ranked[0][0] == 0:
            check.status, check.detail = "ok", f"warranty passage first, score {ranked[0][1]:.3f}"
        else:
            check.status = "fail"
            check.detail = ("The warranty passage was not ranked first, so this model's "
                            "scores do not follow relevance. Is it a reranker?")
    except Exception as error:
        check.status, check.detail = "fail", _describe(error)[:300]
    check.seconds = time.perf_counter() - started
    return check


async def check_vision(settings: dict, transport=None) -> Check:
    client = vision_module.client_for(settings)
    if client is None:
        return Check("vision", detail="blank: each collection borrows the main model of a bot that reads it")

    check = Check("vision", client.base_url, client.model)
    started = time.perf_counter()
    try:
        await client.transcribe(ONE_PIXEL_PNG, transport=transport)
        check.status, check.detail = "ok", "read an image"
    except Exception as error:
        check.status, check.detail = "fail", _describe(error)[:300]
    check.seconds = time.perf_counter() - started
    return check


async def check_portal(base_url: str, token: str, transport=None) -> Check:
    check = Check("portal", base_url)
    if not token:
        check.status = "fail"
        check.detail = ("api-engine/.env has no PORTAL_INTERNAL_TOKEN, so every database "
                        "question is refused. Run start-dev.ps1, which sets it in both files.")
        return check

    started = time.perf_counter()
    result, failure = await portal_module.run_query(
        base_url=base_url, token=token, connection_id=NO_SUCH_CONNECTION,
        sql="SELECT 1", max_rows=1, timeout=5, transport=transport)
    check.seconds = time.perf_counter() - started

    if result is not None or failure == "No such connection.":
        check.status, check.detail = "ok", "the portal accepted the engine's token"
    elif "503" in failure:
        check.status = "fail"
        check.detail = ("The portal has no PORTAL_INTERNAL_TOKEN. Set it in admin-laravel/.env "
                        "to the value in api-engine/.env, or run start-dev.ps1.")
    elif "401" in failure:
        check.status = "fail"
        check.detail = ("PORTAL_INTERNAL_TOKEN in api-engine/.env and admin-laravel/.env "
                        "differ. Run start-dev.ps1 to bring them in line.")
    else:
        check.status, check.detail = "fail", failure[:300]
    return check


async def run_checks(settings: dict, answer_targets: list[AnswerTarget],
                     portal_base_url: str, portal_token: str, transport=None) -> list[Check]:
    checks: list[Check] = []

    for target in answer_targets:
        checks.append(await check_chat(f"answer ({', '.join(target.bots)})", target.base_url,
                                       target.api_key, target.model, transport=transport))

    for role in roles.GENERATIVE:
        # Checked by reading an image below: a chat reply proves nothing about that.
        if role == "vision":
            continue
        url_key, api_key_key, name_key = roles.setting_keys(role)
        base_url = (settings.get(url_key) or "").strip()
        model = (settings.get(name_key) or "").strip()
        if base_url and model:
            checks.append(await check_chat(role, base_url, settings.get(api_key_key) or "",
                                           model, transport=transport))
        else:
            checks.append(Check(role, detail="blank: each bot's own model does this"))

    checks.append(await check_embedding(settings, transport=transport))
    checks.append(await check_rerank(settings, transport=transport))
    checks.append(await check_vision(settings, transport=transport))
    checks.append(await check_portal(portal_base_url, portal_token, transport=transport))

    return checks


def exit_code(checks: list[Check]) -> int:
    return 1 if any(check.status == "fail" for check in checks) else 0


def render(checks: list[Check]) -> str:
    lines = []
    for check in checks:
        timing = f"{check.seconds:5.2f}s" if check.status != "skipped" else "     -"
        lines.append(f"{check.status:<8} {check.name:<34} {check.model or '-':<26} "
                     f"{check.endpoint or '-':<32} {timing}  {_one_line(check.detail)}")
    return "\n".join(lines)


async def load_install():
    """The install's settings, and one answer target per endpoint and model its bots use."""
    from sqlalchemy import select

    import database

    await database.init_db()
    async with database.async_session_factory() as session:
        settings = await database.get_settings(session)
        bots = (await session.execute(
            select(database.BotProfile).where(database.BotProfile.is_active.is_(True))
        )).scalars().all()

        targets: dict[tuple, AnswerTarget] = {}
        for bot in bots:
            base_url, api_key = database.provider_endpoint(bot)
            key = (base_url, api_key, bot.model_name or "")
            targets.setdefault(key, AnswerTarget(base_url, api_key, bot.model_name or ""))
            targets[key].bots.append(bot.name)

    return settings, list(targets.values())


async def main_async() -> int:
    from config import settings as engine_settings

    settings, targets = await load_install()
    checks = await run_checks(settings, targets, engine_settings.PORTAL_BASE_URL,
                              engine_settings.PORTAL_INTERNAL_TOKEN)
    print(render(checks))

    failed = sum(1 for check in checks if check.status == "fail")
    print(f"\n{len(checks) - failed} of {len(checks)} checks passed or were skipped; {failed} failed.")
    return exit_code(checks)


def main() -> None:
    sys.exit(asyncio.run(main_async()))


if __name__ == "__main__":
    main()
