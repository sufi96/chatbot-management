"""Which search a bot uses, and on whose account.

A bot chooses under Behaviour:

- platform: the provider and key set in Admin Settings, as every bot did before
  workspaces could bring their own. Admin Settings can stop lending it
  (web_search_lending = none); a workspace bot then searches DuckDuckGo, and
  only the console's own bots keep the platform's account.
- duckduckgo: free, no key.
- own: one of its workspace's keys (web_search_keys), Tavily or Brave.

Anything missing, a key that was deleted or a provider nobody knows, ends at
DuckDuckGo rather than at no search, and never at the platform's paid account:
a workspace that meant to pay for its own searches must not quietly spend the
platform's.
"""
from dataclasses import dataclass

from sqlalchemy import select

KEYLESS = "duckduckgo"
PAID = ("tavily", "brave")


@dataclass(frozen=True)
class Account:
    provider: str
    api_key: str = ""
    # Whose account pays: "platform", "workspace" or "free". For the log.
    payer: str = "free"


def platform_account(settings: dict) -> Account:
    provider = (settings.get("web_search_provider") or KEYLESS).strip().lower()
    if provider not in PAID:
        return Account(KEYLESS)
    key = settings.get(f"web_search_{provider}_key") or ""
    return Account(provider, key, "platform") if key else Account(KEYLESS)


def lends(settings: dict) -> bool:
    return (settings.get("web_search_lending") or "all").strip().lower() != "none"


async def account_for(session, bot, settings: dict) -> Account:
    mode = (getattr(bot, "web_search_mode", None) or "platform").strip().lower()

    if mode == "own":
        key_id = getattr(bot, "web_search_key_id", None)
        if key_id and session is not None:
            from database import WebSearchKey
            row = (await session.execute(
                select(WebSearchKey).where(WebSearchKey.id == key_id,
                                           WebSearchKey.system_id == bot.system_id))).scalars().first()
            if row and row.provider in PAID and row.api_key:
                return Account(row.provider, row.api_key, "workspace")
        print(f"[WebSearch] Bot {getattr(bot, 'id', '?')} has no usable key of its own; using DuckDuckGo.")
        return Account(KEYLESS)

    if mode == "duckduckgo":
        return Account(KEYLESS)

    if lends(settings) or getattr(bot, "is_platform", False):
        return platform_account(settings)

    return Account(KEYLESS)
