"""The test chat's trace token: minted by the portal, checked by the engine."""
import hashlib
import hmac

import trace_token

SECRET = "portal-token"


def test_a_token_is_good_for_its_bot_until_it_expires():
    token = trace_token.token("bot_1", SECRET, now=1000)

    assert trace_token.valid(token, "bot_1", SECRET, now=1000 + 899)
    assert not trace_token.valid(token, "bot_1", SECRET, now=1000 + 901)
    assert not trace_token.valid(token, "bot_2", SECRET, now=1000)
    assert not trace_token.valid(token, "bot_1", "another-secret", now=1000)


def test_tampered_or_malformed_tokens_are_refused():
    token = trace_token.token("bot_1", SECRET, now=1000)
    expires, mac = token.split(".")

    assert not trace_token.valid(f"{int(expires) + 9999}.{mac}", "bot_1", SECRET, now=1000)
    for bad in ("", "nonsense", ".", "abc.def", None):
        assert not trace_token.valid(bad, "bot_1", SECRET, now=1000)
    assert not trace_token.valid(token, "bot_1", "", now=1000)


def test_the_format_is_the_one_the_portal_mints():
    expires = 1900
    mac = hmac.new(SECRET.encode(), f"bot_1|{expires}".encode(), hashlib.sha256).hexdigest()

    assert trace_token.valid(f"{expires}.{mac}", "bot_1", SECRET, now=1000)
