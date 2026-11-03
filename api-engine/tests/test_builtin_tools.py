"""Tools that need no connector: today's date and time, and asking for a person."""
from datetime import datetime
from types import SimpleNamespace

import agent
import builtin_tools


def a_bot(*names):
    return SimpleNamespace(id="b", builtin_tools=list(names), agentic_max_calls=3)


def test_a_bot_gets_only_the_built_ins_it_switched_on():
    assert [t.name for t in builtin_tools.for_bot(a_bot("current_datetime"))] == ["current_datetime"]
    assert builtin_tools.for_bot(a_bot()) == []
    assert builtin_tools.for_bot(a_bot("format_disk")) == []
    assert builtin_tools.for_bot(SimpleNamespace(id="b")) == []


async def test_the_date_is_malaysia_time():
    tool = builtin_tools.for_bot(a_bot("current_datetime"))[0]

    outcome = await tool.local({})

    assert outcome.ok
    stamp = datetime.fromisoformat(outcome.text.split()[0])
    assert stamp.utcoffset().total_seconds() == 8 * 3600


async def test_a_built_in_runs_in_the_loop_without_any_connector():
    connector_calls = []

    async def call(*args, **kwargs):
        connector_calls.append(args)

    async def ask(base_url, api_key, model, messages, tools, **kwargs):
        if len(messages) == 2:
            return {"role": "assistant", "tool_calls": [{"id": "c1", "type": "function", "function": {
                "name": "request_human", "arguments": '{"reason": "Wants a refund over RM500"}'}}]}
        return {"role": "assistant", "content": "ok"}

    result = await agent.run(a_bot("request_human"), "I want a person", builtin_tools.for_bot(a_bot("request_human")),
                             ("u", "k", "m"), ask=ask, call=call)

    assert connector_calls == []
    assert [(c.name, c.status) for c in result.calls] == [("request_human", "ran")]
    assert result.calls[0].arguments == {"reason": "Wants a refund over RM500"}
    assert "member of staff" in result.context_block
