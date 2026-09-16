"""Whether an observed answer meets what its case expects.

Deterministic on purpose: these are the checks that never disagree with
themselves from one run to the next. Whether an answer is actually good is the
judge's question, asked separately.
"""


def failures(case, observed) -> list[str]:
    """Every way the answer fell short, in words; empty when it passed."""
    expect = case.expect
    found: list[str] = []

    if observed.error:
        found.append(f"The engine reported an error: {observed.error}")

    if expect.source is not None and observed.source != expect.source:
        found.append(f"Expected the answer from {expect.source}, got {observed.source}.")

    titles = [str(citation.get("title", "")).casefold() for citation in observed.citations]
    for wanted in expect.cites:
        if not any(wanted.casefold() in title for title in titles):
            found.append(f"No citation named {wanted!r}.")

    answer = (observed.answer or "").casefold()

    if expect.must_include_any and not any(
            phrase.casefold() in answer for phrase in expect.must_include_any):
        listed = ", ".join(repr(phrase) for phrase in expect.must_include_any)
        found.append(f"The answer contains none of: {listed}.")

    for phrase in expect.must_not_include:
        if phrase.casefold() in answer:
            found.append(f"The answer contains {phrase!r}.")

    if expect.max_first_token_seconds is not None:
        if observed.first_token_seconds is None:
            found.append("No answer text arrived.")
        elif observed.first_token_seconds > expect.max_first_token_seconds:
            found.append(f"First token took {observed.first_token_seconds:.2f}s, "
                         f"over {expect.max_first_token_seconds:g}s.")

    if expect.max_total_seconds is not None and observed.total_seconds > expect.max_total_seconds:
        found.append(f"The answer took {observed.total_seconds:.2f}s, "
                     f"over {expect.max_total_seconds:g}s.")

    return found
