"""The collector keeps the model the endpoint said it used.

A bot configured for one model name can be served by another, an alias or a
quantised build, and the endpoint's own answer is the one worth recording.
"""
from reasoning import TranscriptCollector


def test_the_collector_keeps_the_model_the_endpoint_reported():
    collector = TranscriptCollector()
    collector.observe({"content": "Hello"})
    collector.observe({"meta": {"model": "qwen3.5:4b", "tokens_in": 10, "tokens_out": 2}})

    assert collector.model == "qwen3.5:4b"
    assert collector.tokens == 12


def test_the_model_is_none_when_no_meta_arrived():
    assert TranscriptCollector().model is None


def test_a_meta_event_without_a_model_does_not_erase_one():
    collector = TranscriptCollector()
    collector.observe({"meta": {"model": "qwen3.5:4b"}})
    collector.observe({"meta": {"tokens_in": 1}})

    assert collector.model == "qwen3.5:4b"
