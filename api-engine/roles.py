"""Which model does which job.

Every job that needs a model resolves its endpoint here rather than reading
settings itself. That is what lets the same code run on a laptop where one small
model does everything, and on a server where each job has a model of its own:
moving a job is a settings change, never a code change.

A role configured in Admin Settings is used as configured. A role left blank
falls back, and how depends on the kind of job:

- A generative job (sql, intent, guard, vision) borrows the bot's main
  provider and model. A weaker verdict from a small model beats no verdict.
  Vision runs while indexing, which belongs to no bot, so the indexer passes a
  bot that reads the collection; with no such bot there is nothing to borrow.
- A specialist job (rerank) has no stand-in, because a chat model does not
  speak the rerank protocol. Blank makes it unavailable and its stage is
  skipped, which is exactly what the system did before the stage existed.

The portal lists the same roles in AdminSettingsController::MODEL_ROLES. Keep
the two in step.
"""
import json
from dataclasses import dataclass

from database import provider_endpoint

GENERATIVE = ("sql", "intent", "guard", "vision")
SPECIALIST = ("rerank",)
ROLES = GENERATIVE + SPECIALIST


@dataclass(frozen=True)
class Endpoint:
    role: str
    base_url: str = ""
    api_key: str = ""
    model: str = ""
    # True only when Admin Settings named this role's endpoint. A borrowed or
    # empty endpoint is False, which is what a trace or a screen reports.
    configured: bool = False

    @property
    def available(self) -> bool:
        """Whether there is anything to call at all."""
        return bool(self.base_url and self.model)


def setting_keys(role: str) -> tuple[str, str, str]:
    return (f"{role}_model_base_url", f"{role}_model_api_key", f"{role}_model_name")


def endpoint_for(role: str, bot, settings: dict) -> Endpoint:
    if role not in ROLES:
        raise ValueError(f"Unknown model role: {role!r}")

    url_key, api_key_key, name_key = setting_keys(role)
    base_url = (settings.get(url_key) or "").strip()
    model = (settings.get(name_key) or "").strip()

    # Both halves, or neither. A URL with no model names nothing to call.
    if base_url and model:
        return Endpoint(role, base_url, settings.get(api_key_key) or "", model,
                        configured=True)

    if role in SPECIALIST or bot is None:
        return Endpoint(role)

    bot_url, bot_key = provider_endpoint(bot)

    return Endpoint(role, bot_url, bot_key, bot.model_name or "")


def model_trace(chat_model: str | None, used: dict[str, str]) -> str | None:
    """JSON naming the model behind each job that produced one answer.

    Once jobs run on different machines, "which model said that" stops having a
    single answer, and an operator auditing a wrong one needs all of them. Only
    jobs that ran are named. Nothing known is None, so the column stays empty
    rather than holding "{}".
    """
    trace = {role: model for role, model in used.items() if model}
    if chat_model:
        trace["chat"] = chat_model

    return json.dumps(trace, sort_keys=True) if trace else None
