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


async def test_built_ins_are_not_shown_to_visitors_as_sources():
    from sources import attempts

    handover = builtin_tools.for_bot(a_bot("request_human"))[0]
    record = agent.CallRecord(handover, "request_human", {"reason": "x"}, "ran", text=builtin_tools.HANDOVER_TEXT)

    async def run(*args, **kwargs):
        return agent.AgentResult(calls=[record], context_block="<context>x</context>")

    async def load(session, bot):
        return []

    async def no_skills(session, bot, names):
        return []

    bot = SimpleNamespace(id="b", provider=None, model_name="m", agentic_max_calls=3, builtin_tools=["request_human"])
    found = await attempts.tools(None, bot, "a person please", {}, run=run, load=load, load_skills=no_skills)

    assert found.has_content
    assert found.citations == []


def test_block_numbers_match_the_citations_when_a_built_in_also_ran():
    handover = builtin_tools.for_bot(a_bot("current_datetime"))[0]
    tool = agent.Tool(id="ctl_1", name="getTicket", description="", parameters={}, writes=False,
                      connector=SimpleNamespace(name="Tickets API"), row=None)
    block = agent._block([
        agent.CallRecord(handover, "current_datetime", {}, "ran", text="2026-11-03T15:00:00+08:00"),
        agent.CallRecord(tool, "getTicket", {"id": "7"}, "ran", text="open"),
    ])

    assert "[1] Tickets API: getTicket" in block
    assert "2026-11-03T15:00:00+08:00" in block
    assert "[2]" not in block
