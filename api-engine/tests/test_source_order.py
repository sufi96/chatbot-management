"""The order a bot consults its sources in, as the engine reads it.

Normalised on read as well as on save, because the column is also written by
migrations and by hand.
"""
from sources.order import DEFAULT, SOURCES, normalise


def test_a_well_formed_order_is_kept_as_it_is():
    assert normalise("database,documents,web") == ["database", "documents", "web"]


def test_a_missing_source_is_appended_in_default_order():
    assert normalise("web") == ["web", "documents", "database"]


def test_an_unknown_token_is_dropped():
    assert normalise("web,telepathy") == ["web", "documents", "database"]


def test_a_duplicate_keeps_its_first_position():
    assert normalise("web,web,web") == ["web", "documents", "database"]


def test_an_empty_order_is_the_default():
    assert normalise("") == list(SOURCES)
    assert normalise(None) == list(SOURCES)


def test_spacing_and_case_do_not_matter():
    assert normalise(" Database , WEB ") == ["database", "web", "documents"]


def test_the_default_string_matches_the_default_order():
    assert normalise(DEFAULT) == list(SOURCES)
