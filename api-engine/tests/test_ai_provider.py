"""The engine's view of the provider table Laravel owns.

A bot no longer carries its own endpoint. It points at a provider row, so an
operator who moves the laptop edits one record and every bot on it follows.
These tests pin that the engine reads the endpoint through that link, because
reading a dropped column fails at runtime rather than at import.
"""
import pytest

from database import AiProvider, BotProfile
from dbquery import _sql_endpoint


class FakeProvider:
    def __init__(self, base_url, api_key=""):
        self.base_url = base_url
        self.api_key = api_key


class FakeBot:
    def __init__(self, provider, model_name="llama3.2"):
        self.provider = provider
        self.model_name = model_name


def test_the_table_is_named_as_laravel_named_it():
    assert AiProvider.__tablename__ == "ai_providers"


def test_a_provider_carries_the_endpoint_a_bot_used_to_hold():
    for column in ("id", "system_id", "name", "base_url", "api_key"):
        assert hasattr(AiProvider, column), column


def test_a_bot_points_at_a_provider_rather_than_copying_it():
    assert hasattr(BotProfile, "provider_id")
    assert hasattr(BotProfile, "provider")


def test_a_bot_no_longer_carries_its_own_endpoint():
    """The columns are gone from Laravel's schema, so the engine must not
    claim they exist; a stale attribute here would read as an empty URL."""
    for dropped in ("base_url", "api_key", "provider_type"):
        assert not hasattr(BotProfile, dropped), dropped


def test_query_work_falls_back_to_the_bots_provider():
    bot = FakeBot(FakeProvider("http://192.168.1.5:11434/v1", "k"))

    assert _sql_endpoint(bot, {}) == ("http://192.168.1.5:11434/v1", "k", "llama3.2")


def test_a_configured_sql_endpoint_still_wins_over_the_bots_provider():
    bot = FakeBot(FakeProvider("http://192.168.1.5:11434/v1", "k"))
    settings = {
        "sql_model_base_url": "https://api.groq.com/openai/v1",
        "sql_model_api_key": "gsk",
        "sql_model_name": "llama-3.3-70b",
    }

    assert _sql_endpoint(bot, settings) == (
        "https://api.groq.com/openai/v1", "gsk", "llama-3.3-70b")


def test_a_bot_with_no_provider_yields_a_blank_endpoint_rather_than_raising():
    """An unattached bot is a misconfiguration, not a crash. The caller
    reports an unreachable endpoint; it does not lose the request to an
    AttributeError on None."""
    assert _sql_endpoint(FakeBot(None), {}) == ("", "", "llama3.2")


def test_the_endpoint_helper_reads_through_the_link():
    """chat.py and dbquery both need this, and a second copy of the None
    handling is a second place to get it wrong."""
    from database import provider_endpoint

    bot = FakeBot(FakeProvider("http://10.0.0.4:11434/v1", "k"))

    assert provider_endpoint(bot) == ("http://10.0.0.4:11434/v1", "k")


def test_the_endpoint_helper_is_blank_for_an_unattached_bot():
    from database import provider_endpoint

    assert provider_endpoint(FakeBot(None)) == ("", "")


def test_the_endpoint_helper_treats_a_null_key_as_no_key():
    from database import provider_endpoint

    assert provider_endpoint(FakeBot(FakeProvider("http://x/v1", None))) == ("http://x/v1", "")
