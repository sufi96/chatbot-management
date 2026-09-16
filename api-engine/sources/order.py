"""The order a bot consults its sources in.

The same rule as the portal's SourceOrder helper, and deliberately so: a value
either side writes is one the other can read. Normalising rather than rejecting
means a token added in a later version is consulted last on an older engine
instead of breaking it.
"""

SOURCES = ("documents", "database", "web")

DEFAULT = ",".join(SOURCES)


def normalise(raw: str | None) -> list[str]:
    order: list[str] = []

    for token in (raw or "").split(","):
        token = token.strip().lower()
        if token in SOURCES and token not in order:
            order.append(token)

    # A token nobody listed is consulted last rather than not at all.
    for token in SOURCES:
        if token not in order:
            order.append(token)

    return order
