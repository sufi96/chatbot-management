from websearch.gating import web_search_runs


def test_a_bot_with_search_off_never_searches():
    assert web_search_runs(False, True, 0) is False


def test_a_greeting_never_searches():
    assert web_search_runs(True, False, 0) is False


def test_a_question_the_knowledge_base_answered_does_not_search():
    assert web_search_runs(True, True, 5) is False


def test_a_question_the_knowledge_base_missed_searches():
    assert web_search_runs(True, True, 0) is True


def test_a_bot_with_no_knowledge_base_at_all_searches_every_question():
    # Retrieval off means the route never fills the chunk list, so hits stay
    # at zero and the web answers. That is what "the web is the fallback"
    # means for a bot with no documents.
    assert web_search_runs(True, True, 0) is True
