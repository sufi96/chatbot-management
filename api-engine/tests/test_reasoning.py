from reasoning import ReasoningSplitter


def drain(splitter, deltas):
    """Feed every delta then flush, returning the flat event list."""
    events = []
    for delta in deltas:
        events.extend(splitter.feed(delta))
    events.extend(splitter.flush())
    return events


def test_reasoning_content_field_becomes_a_reasoning_event():
    splitter = ReasoningSplitter(enabled=True)
    events = drain(splitter, [{"reasoning_content": "weighing it up"}])
    assert events == [("reasoning", "weighing it up")]


def test_plain_content_passes_through_untouched():
    splitter = ReasoningSplitter(enabled=True)
    events = drain(splitter, [{"content": "Hello there"}])
    assert events == [("content", "Hello there")]


def test_inline_think_block_is_split_from_the_answer():
    splitter = ReasoningSplitter(enabled=True)
    events = drain(splitter, [{"content": "<think>ponder</think>Hi!"}])
    assert events == [("reasoning", "ponder"), ("content", "Hi!")]


def test_opening_tag_split_across_chunks_is_still_recognised():
    splitter = ReasoningSplitter(enabled=True)
    events = drain(splitter, [
        {"content": "<thi"},
        {"content": "nk>deep</thi"},
        {"content": "nk>Answer"},
    ])
    text = lambda kind: "".join(t for k, t in events if k == kind)
    assert text("reasoning") == "deep"
    assert text("content") == "Answer"


def test_thinking_disabled_drops_reasoning_and_keeps_the_answer():
    splitter = ReasoningSplitter(enabled=False)
    events = drain(splitter, [
        {"reasoning_content": "should vanish"},
        {"content": "<think>also gone</think>Only this"},
    ])
    assert events == [("content", "Only this")]


def test_unclosed_think_block_is_flushed_as_reasoning():
    splitter = ReasoningSplitter(enabled=True)
    events = drain(splitter, [{"content": "<think>cut off mid"}])
    assert events == [("reasoning", "cut off mid")]


def test_a_lone_angle_bracket_is_not_held_back():
    splitter = ReasoningSplitter(enabled=True)
    events = drain(splitter, [{"content": "use 5 < 6 in code"}])
    assert "".join(t for k, t in events if k == "content") == "use 5 < 6 in code"


from reasoning import TranscriptCollector


def test_collector_joins_content_into_the_answer():
    collector = TranscriptCollector()
    collector.observe({"content": "Refunds take "})
    collector.observe({"content": "30 days."})
    assert collector.answer == "Refunds take 30 days."


def test_collector_keeps_thinking_apart_from_the_answer():
    collector = TranscriptCollector()
    collector.observe({"reasoning": "check the policy"})
    collector.observe({"content": "30 days."})
    assert collector.answer == "30 days."
    assert collector.thinking == "check the policy"


def test_collector_reports_no_thinking_when_none_arrived():
    collector = TranscriptCollector()
    collector.observe({"content": "Hi"})
    assert collector.thinking is None


def test_collector_ignores_events_that_are_not_transcript():
    collector = TranscriptCollector()
    collector.observe({"type": "sources", "sources": [{"n": 1}]})
    collector.observe({"error": "upstream exploded"})
    collector.observe({"content": "Hi"})
    assert collector.answer == "Hi"
    assert collector.thinking is None


# A Qwen3 template that pre-fills the opening tag into the prompt leaves the
# model emitting only the closing one. Everything before it was thinking, but
# that is only knowable once the tag arrives.

def test_a_closing_tag_with_no_opening_one_reclassifies_what_came_before():
    splitter = ReasoningSplitter(enabled=True)
    events = drain(splitter, [
        {"content": "Thinking Process: weigh "},
        {"content": "the options</think>Hello!"},
    ])
    assert events == [
        ("content", "Thinking Process: weigh "),
        ("content", "the options"),
        ("reclassify", "reasoning"),
        ("content", "Hello!"),
    ]


def test_only_the_first_orphan_close_reclassifies():
    splitter = ReasoningSplitter(enabled=True)
    events = drain(splitter, [{"content": "think</think>answer </think> tag"}])
    assert events.count(("reclassify", "reasoning")) == 1
    assert "".join(t for k, t in events if k == "content").endswith("answer </think> tag")


def test_a_server_that_sends_reasoning_separately_never_reclassifies():
    splitter = ReasoningSplitter(enabled=True)
    events = drain(splitter, [
        {"reasoning_content": "thought through"},
        {"content": "Use </think> to close the block."},
    ])
    assert not any(k == "reclassify" for k, _ in events)
    assert "".join(t for k, t in events if k == "content") == "Use </think> to close the block."


def test_thinking_off_drops_an_orphan_tag_without_losing_the_answer():
    splitter = ReasoningSplitter(enabled=False)
    events = drain(splitter, [{"content": "stray</think>Answer"}])
    assert not any(k == "reclassify" for k, _ in events)
    assert "".join(t for k, t in events if k == "content") == "strayAnswer"


def test_collector_moves_earlier_content_into_thinking_on_reclassify():
    collector = TranscriptCollector()
    collector.observe({"content": "Thinking Process: weigh it"})
    collector.observe({"reclassify": "reasoning"})
    collector.observe({"content": "Hello!"})
    assert collector.answer == "Hello!"
    assert collector.thinking == "Thinking Process: weigh it"


def test_collector_keeps_the_token_total_from_a_meta_event():
    collector = TranscriptCollector()
    collector.observe({"content": "Hi"})
    collector.observe({"meta": {"model": "m", "tokens_in": 10, "tokens_out": 4}})
    assert collector.tokens == 14


def test_collector_reports_no_token_total_when_none_was_reported():
    collector = TranscriptCollector()
    collector.observe({"content": "Hi"})
    assert collector.tokens == 0
