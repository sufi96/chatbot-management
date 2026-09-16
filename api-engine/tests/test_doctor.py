"""Checking every endpoint an install depends on, against faked servers.

Each check has to tell an operator three things: whether it worked, what it
reached, and in words what to do when it did not.
"""
import json

import httpx
import pytest

import doctor
from database import SETTING_DEFAULTS

PORTAL = "http://portal:8080"


def settings(**overrides):
    return {**SETTING_DEFAULTS, **overrides}


def routes(**by_path):
    """A transport answering each path with a (status, body) pair, or raising."""
    def handler(request: httpx.Request) -> httpx.Response:
        for path, answer in by_path.items():
            if request.url.path.endswith(path):
                if isinstance(answer, Exception):
                    raise answer
                status, body = answer
                if isinstance(body, str):
                    return httpx.Response(status, text=body)
                return httpx.Response(status, json=body)
        raise AssertionError(f"unexpected request to {request.url}")
    return httpx.MockTransport(handler)


CHAT_OK = (200, {"choices": [{"message": {"content": "OK"}}]})


# Answer models and generative roles

@pytest.mark.asyncio
async def test_a_model_that_answers_passes():
    check = await doctor.check_chat("answer", "http://llm:8000/v1", "k", "qwen3.5-4b",
                                    transport=routes(**{"/chat/completions": CHAT_OK}))

    assert check.status == "ok"
    assert check.model == "qwen3.5-4b"
    assert check.endpoint == "http://llm:8000/v1"


@pytest.mark.asyncio
async def test_a_model_the_server_does_not_have_fails_with_the_status():
    check = await doctor.check_chat("answer", "http://llm:8000/v1", "", "missing-model",
                                    transport=routes(**{"/chat/completions": (404, "model not found")}))

    assert check.status == "fail"
    assert "404" in check.detail


@pytest.mark.asyncio
async def test_a_server_that_cannot_be_reached_fails_saying_so():
    check = await doctor.check_chat(
        "answer", "http://llm:8000/v1", "", "m",
        transport=routes(**{"/chat/completions": httpx.ConnectError("refused")}))

    assert check.status == "fail"
    assert "reach" in check.detail.lower()


@pytest.mark.asyncio
async def test_a_timeout_with_no_message_still_says_what_went_wrong():
    """httpx raises some timeouts with an empty message, which printed as
    "Could not reach the server:" and nothing more."""
    check = await doctor.check_chat(
        "answer", "http://laptop:8000/v1", "", "m",
        transport=routes(**{"/chat/completions": httpx.ConnectTimeout("")}))

    assert check.status == "fail"
    assert "ConnectTimeout" in check.detail


@pytest.mark.asyncio
async def test_an_error_body_is_kept_on_one_line():
    """The report is one line per check. A pretty-printed JSON error broke it."""
    body = '{\n  "error": {\n    "message": "Incorrect API key provided"\n  }\n}'

    check = await doctor.check_chat("answer", "https://api.example/v1", "sk-x", "m",
                                    transport=routes(**{"/chat/completions": (401, body)}))

    assert "\n" not in check.detail
    assert "Incorrect API key provided" in check.detail


def test_every_detail_in_the_report_is_one_line():
    text = doctor.render([doctor.Check("portal", status="fail", detail="first line\nsecond line")])

    assert len(text.splitlines()) == 1


# Embedding

@pytest.mark.asyncio
async def test_an_embedding_of_the_configured_size_passes_and_says_the_size():
    body = {"data": [{"embedding": [1.0] + [0.0] * 767}]}

    check = await doctor.check_embedding(settings(embedding_dimensions="768"),
                                         transport=routes(**{"/embeddings": (200, body)}))

    assert check.status == "ok"
    assert "768" in check.detail


@pytest.mark.asyncio
async def test_an_embedding_of_the_wrong_size_fails_with_the_fix():
    body = {"data": [{"embedding": [1.0] + [0.0] * 4095}]}

    check = await doctor.check_embedding(settings(embedding_dimensions="1024"),
                                         transport=routes(**{"/embeddings": (200, body)}))

    assert check.status == "fail"
    assert "4096" in check.detail and "1024" in check.detail


# Reranker

@pytest.mark.asyncio
async def test_a_blank_reranker_is_skipped():
    check = await doctor.check_rerank(settings())

    assert check.status == "skipped"


@pytest.mark.asyncio
async def test_a_reranker_that_puts_the_warranty_passage_first_passes():
    ranked = {"results": [{"index": 0, "relevance_score": 0.99},
                          {"index": 1, "relevance_score": 0.01},
                          {"index": 2, "relevance_score": 0.0}]}

    check = await doctor.check_rerank(
        settings(rerank_model_base_url="http://spark-b:8003/v1", rerank_model_name="bge"),
        transport=routes(**{"/rerank": (200, ranked)}))

    assert check.status == "ok"


@pytest.mark.asyncio
async def test_a_reranker_whose_scores_do_not_follow_relevance_fails():
    """A server can answer the protocol with a model that is not a reranker."""
    ranked = {"results": [{"index": 2, "relevance_score": 0.7},
                          {"index": 0, "relevance_score": 0.6},
                          {"index": 1, "relevance_score": 0.5}]}

    check = await doctor.check_rerank(
        settings(rerank_model_base_url="http://spark-b:8003/v1", rerank_model_name="bge"),
        transport=routes(**{"/rerank": (200, ranked)}))

    assert check.status == "fail"


# Vision

@pytest.mark.asyncio
async def test_a_blank_vision_model_is_skipped():
    assert (await doctor.check_vision(settings())).status == "skipped"


@pytest.mark.asyncio
async def test_a_vision_model_that_reads_an_image_passes():
    seen = {}

    def handler(request):
        seen["body"] = json.loads(request.read().decode())
        return httpx.Response(200, json={"choices": [{"message": {"content": ""}}]})

    check = await doctor.check_vision(
        settings(vision_model_base_url="http://spark-b:8005/v1", vision_model_name="qwen3-vl"),
        transport=httpx.MockTransport(handler))

    assert check.status == "ok"
    assert seen["body"]["messages"][0]["content"][1]["type"] == "image_url"


# The portal and its shared secret

@pytest.mark.asyncio
async def test_a_portal_that_accepts_the_token_passes():
    reply = {"ok": False, "columns": [], "rows": [], "row_count": 0, "elapsed_ms": 0,
             "message": "No such connection."}

    check = await doctor.check_portal(PORTAL, "secret",
                                      transport=routes(**{"/internal/db/query": (200, reply)}))

    assert check.status == "ok"


@pytest.mark.asyncio
async def test_a_portal_without_its_token_fails_naming_the_setting():
    check = await doctor.check_portal(PORTAL, "secret",
                                      transport=routes(**{"/internal/db/query": (503, "not set")}))

    assert check.status == "fail"
    assert "PORTAL_INTERNAL_TOKEN" in check.detail
    assert "admin-laravel/.env" in check.detail


@pytest.mark.asyncio
async def test_a_portal_with_a_different_token_fails_saying_they_differ():
    check = await doctor.check_portal(PORTAL, "secret",
                                      transport=routes(**{"/internal/db/query": (401, "invalid")}))

    assert check.status == "fail"
    assert "differ" in check.detail


@pytest.mark.asyncio
async def test_an_engine_without_its_token_fails_without_asking():
    def handler(request):
        raise AssertionError("a blank token must not be sent")

    check = await doctor.check_portal(PORTAL, "", transport=httpx.MockTransport(handler))

    assert check.status == "fail"
    assert "api-engine/.env" in check.detail


# The whole run

@pytest.mark.asyncio
async def test_blank_roles_are_skipped_and_a_configured_one_is_checked_at_its_endpoint():
    seen = []

    def handler(request):
        seen.append(str(request.url))
        if request.url.path.endswith("/chat/completions"):
            return httpx.Response(200, json={"choices": [{"message": {"content": "OK"}}]})
        if request.url.path.endswith("/embeddings"):
            return httpx.Response(200, json={"data": [{"embedding": [1.0] + [0.0] * 767}]})
        if request.url.path.endswith("/internal/db/query"):
            return httpx.Response(200, json={"ok": False, "message": "No such connection."})
        raise AssertionError(f"unexpected request to {request.url}")

    checks = await doctor.run_checks(
        settings(intent_model_base_url="http://spark-b:8001/v1", intent_model_name="qwen3.5-35b-a3b"),
        [doctor.AnswerTarget("http://laptop:8000/v1", "k", "qwen3.5-4b", ["Kedai Aina Assistant"])],
        PORTAL, "secret", transport=httpx.MockTransport(handler))

    by_name = {check.name: check for check in checks}
    assert by_name["intent"].status == "ok"
    assert by_name["intent"].endpoint == "http://spark-b:8001/v1"
    assert by_name["sql"].status == "skipped"
    assert by_name["guard"].status == "skipped"
    assert by_name["rerank"].status == "skipped"
    assert by_name["vision"].status == "skipped"
    assert "Kedai Aina Assistant" in checks[0].name
    assert any(url.startswith("http://spark-b:8001/v1") for url in seen)


def test_skipped_checks_do_not_fail_the_run():
    assert doctor.exit_code([doctor.Check("rerank", status="skipped"),
                             doctor.Check("embedding", status="ok")]) == 0


def test_any_failure_fails_the_run():
    assert doctor.exit_code([doctor.Check("embedding", status="ok"),
                             doctor.Check("portal", status="fail")]) == 1


def test_the_report_puts_the_status_first_on_every_line():
    text = doctor.render([doctor.Check("embedding", "http://x/v1", "nomic", "ok", "768 dimensions", 0.1)])

    assert text.splitlines()[0].startswith("ok")
    assert "768 dimensions" in text
