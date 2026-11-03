"""The token that lets the portal's own test chat see what a bot did.

The Behaviour page embeds the real widget. The portal mints a token for that
bot, good for fifteen minutes, signed with the shared admin secret; a request
carrying a valid one is sent a line per tool call and loaded skill. A visitor
on a customer's site has no token and never sees the trace.
"""
import hashlib
import hmac

LIFETIME = 900


def _mac(bot_id: str, expires: int, secret: str) -> str:
    return hmac.new(secret.encode(), f"{bot_id}|{expires}".encode(), hashlib.sha256).hexdigest()


def token(bot_id: str, secret: str, now: int) -> str:
    expires = int(now) + LIFETIME
    return f"{expires}.{_mac(bot_id, expires, secret)}"


def valid(token_text, bot_id: str, secret: str, now: int) -> bool:
    if not token_text or not secret or not isinstance(token_text, str):
        return False
    expires, _, mac = token_text.partition(".")
    if not expires.isdigit() or not mac:
        return False
    if int(expires) < int(now) or int(expires) > int(now) + LIFETIME:
        return False
    return hmac.compare_digest(mac, _mac(bot_id, int(expires), secret))
