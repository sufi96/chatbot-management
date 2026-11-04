"""A bot's file settings, read the same way everywhere.

One JSON column on the bot (bot_profiles.file_settings), written by the
Behaviour page. A bot that never had it set has every direction off, so it
behaves exactly as before the feature existed. Whatever the column holds is
clamped here: a hand-edited value cannot give a public bot a 900 MB upload.
"""
import json
from dataclasses import dataclass

# What a person may attach, and what the bot may hand back. The portal offers
# the same lists (BotProfile::FILE_IN_TYPES / FILE_OUT_FORMATS); keep them in step.
IN_TYPES = ("pdf", "docx", "xlsx", "csv", "pptx", "txt", "md", "png", "jpg", "webp")
OUT_FORMATS = ("txt", "md", "csv", "docx", "xlsx", "pdf")
OUT_MODES = ("on_request", "own_initiative")
IMAGE_TYPES = ("png", "jpg", "webp")


@dataclass(frozen=True)
class FileSettings:
    in_enabled: bool = False
    in_types: tuple = IN_TYPES
    max_mb: int = 10
    per_message: int = 3
    daily: int = 20
    out_enabled: bool = False
    out_formats: tuple = OUT_FORMATS
    out_mode: str = "on_request"
    visuals: bool = False
    retention_days: int = 30


def _clamp(value, low: int, high: int, default: int) -> int:
    try:
        return max(low, min(high, int(value)))
    except (TypeError, ValueError):
        return default


def _pick(values, allowed: tuple) -> tuple:
    if not isinstance(values, (list, tuple)):
        return allowed
    return tuple(v for v in allowed if v in values)


def for_bot(bot) -> FileSettings:
    raw = getattr(bot, "file_settings", None)
    if isinstance(raw, str):
        try:
            raw = json.loads(raw)
        except ValueError:
            raw = None
    if not isinstance(raw, dict):
        return FileSettings()

    d = FileSettings()
    return FileSettings(
        in_enabled=bool(raw.get("in_enabled")),
        in_types=_pick(raw.get("in_types"), IN_TYPES),
        max_mb=_clamp(raw.get("max_mb"), 1, 50, d.max_mb),
        per_message=_clamp(raw.get("per_message"), 1, 10, d.per_message),
        daily=_clamp(raw.get("daily"), 1, 500, d.daily),
        out_enabled=bool(raw.get("out_enabled")),
        out_formats=_pick(raw.get("out_formats"), OUT_FORMATS),
        out_mode=raw.get("out_mode") if raw.get("out_mode") in OUT_MODES else d.out_mode,
        visuals=bool(raw.get("visuals")),
        retention_days=_clamp(raw.get("retention_days"), 1, 365, d.retention_days),
    )
