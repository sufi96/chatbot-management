"""What the widget is told about files: off unless the bot has them on."""
from tests.test_bot_config_offline import config_for


async def test_a_bot_without_files_offers_none():
    files = (await config_for(is_active=True)).json()["files"]

    assert files == {"in": {"enabled": False}, "out": {"enabled": False}}


async def test_a_bot_with_files_tells_the_widget_its_limits():
    files = (await config_for(is_active=True, file_settings={
        "in_enabled": True, "in_types": ["pdf", "jpg"], "max_mb": 5, "per_message": 2,
        "out_enabled": True, "out_formats": ["docx"]})).json()["files"]

    assert files["in"] == {"enabled": True, "accept": ".pdf,.jpg,.jpeg", "max_mb": 5, "per_message": 2}
    assert files["out"] == {"enabled": True, "formats": ["docx"]}
