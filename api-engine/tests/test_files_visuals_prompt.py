"""A bot with visuals on is told how to draw; one without is told nothing."""
from llm_adapter import LLMAdapter
from routers import chat
from tests.test_chat_route import answering, factory, post, update_bot  # noqa: F401  (fixture)


async def test_a_bot_with_visuals_is_told_the_block_kinds(factory, monkeypatch):
    await update_bot(factory, file_settings={"visuals": True})
    calls = []
    monkeypatch.setattr(LLMAdapter, "stream_chat", answering("ok", calls=calls))

    await post(factory)

    prompt = calls[0]["system_prompt"]
    for kind in ("```chart", "```diagram", "```timeline", "```svg"):
        assert kind in prompt


async def test_a_bot_without_visuals_is_told_nothing(factory, monkeypatch):
    calls = []
    monkeypatch.setattr(LLMAdapter, "stream_chat", answering("ok", calls=calls))

    await post(factory)

    assert "```chart" not in calls[0]["system_prompt"]


def test_the_widget_script_carries_the_visuals():
    from fastapi.testclient import TestClient
    import main

    body = TestClient(main.app).get("/widget.js").text
    assert "__ChatbotVisuals" in body and "__ChatbotMarkdown" in body
    assert TestClient(main.app).get("/widget-visuals.js").status_code == 200
