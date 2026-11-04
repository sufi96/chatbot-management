"""Per-bot file settings: off unless set, clamped to sane ranges, unknown values dropped."""
import json
from types import SimpleNamespace

from files import settings


def bot(value):
    return SimpleNamespace(file_settings=value)


def test_a_bot_without_settings_has_everything_off_with_the_defaults():
    got = settings.for_bot(bot(None))

    assert (got.in_enabled, got.out_enabled, got.visuals) == (False, False, False)
    assert (got.max_mb, got.per_message, got.daily, got.retention_days) == (10, 3, 20, 30)
    assert got.out_mode == "on_request"
    assert got.in_types == settings.IN_TYPES and got.out_formats == settings.OUT_FORMATS


def test_a_bot_from_before_the_column_existed_is_off():
    assert settings.for_bot(SimpleNamespace()).in_enabled is False


def test_unknown_types_and_formats_are_dropped():
    got = settings.for_bot(bot({"in_types": ["pdf", "exe"], "out_formats": ["xlsx", "html"]}))

    assert got.in_types == ("pdf",) and got.out_formats == ("xlsx",)


def test_numbers_are_clamped():
    got = settings.for_bot(bot({"max_mb": 900, "per_message": 0, "daily": -4, "retention_days": 4000}))

    assert (got.max_mb, got.per_message, got.daily, got.retention_days) == (50, 1, 1, 365)


def test_a_json_string_is_read_and_an_unknown_mode_falls_back():
    got = settings.for_bot(bot(json.dumps({"in_enabled": True, "out_mode": "always", "visuals": True})))

    assert got.in_enabled is True and got.visuals is True and got.out_mode == "on_request"


def test_own_initiative_is_kept():
    assert settings.for_bot(bot({"out_mode": "own_initiative"})).out_mode == "own_initiative"
