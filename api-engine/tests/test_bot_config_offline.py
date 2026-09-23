"""A switched-off bot's widget config: hidden (404) unless set to show a message."""
import pytest
from fastapi import FastAPI
from httpx import ASGITransport, AsyncClient
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine

from database import Base, BotProfile, System, get_db
from routers import bot


async def config_for(**fields):
    engine = create_async_engine("sqlite+aiosqlite:///:memory:")
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
    factory = async_sessionmaker(engine, expire_on_commit=False)
    async with factory() as session:
        session.add(System(id="sys_1", name="W"))
        session.add(BotProfile(id="bot_1", system_id="sys_1", name="Support", **fields))
        await session.commit()

    async def db():
        async with factory() as session:
            yield session

    app = FastAPI()
    app.include_router(bot.router)
    app.dependency_overrides[get_db] = db
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        response = await client.get("/api/v1/bot/bot_1/config")
    await engine.dispose()
    return response


@pytest.mark.asyncio
async def test_an_active_bot_is_online():
    response = await config_for(is_active=True)
    assert response.status_code == 200
    assert response.json()["offline"] is False


@pytest.mark.asyncio
async def test_a_switched_off_bot_set_to_hide_is_not_found():
    response = await config_for(is_active=False, offline_mode="hide")
    assert response.status_code == 404


@pytest.mark.asyncio
async def test_a_switched_off_bot_set_to_message_serves_it():
    response = await config_for(is_active=False, offline_mode="message", offline_message="Back at 9am.",
                                offline_hours="Mon–Fri, 9am–6pm",
                                offline_style={"header_bg": "#111111", "avatar_emoji": "⚡"},
                                offline_icon_url="http://x/away.png", offline_launcher_shape="cutout_ring")
    assert response.status_code == 200
    assert response.json()["offline"] is True
    assert response.json()["offline_message"] == "Back at 9am."
    assert response.json()["offline_icon_url"] == "http://x/away.png"
    assert response.json()["offline_launcher_shape"] == "cutout_ring"
    assert response.json()["offline_close_shape"] == ""
    assert response.json()["offline_hours"] == "Mon–Fri, 9am–6pm"
    assert response.json()["offline_subtitle"] == ""
    assert response.json()["offline_style"] == {"header_bg": "#111111", "avatar_emoji": "⚡"}
