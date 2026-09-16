"""One attempt per source, each reporting whether it answered.

Every attempt is driven through injected callables, so none of this needs a
model, a database or an HTTP request.
"""
import pytest

from dbquery.result import DbAnswer
from sources import attempts


class FakeBot:
    id = "bot_1"
    retrieval_enabled = True
    retrieval_mode = "hybrid"
    retrieval_top_k = 5
    retrieval_candidates = 30
    retrieval_min_score = 0.0
    rerank_min_score = 0.25
    retrieval_min_similarity = 0.65
    db_query_enabled = True
    db_max_rows = 50
    db_query_timeout = 10
    web_search_enabled = True
    web_search_max_results = 3
    web_search_country = None


class FakeChunk:
    def __init__(self, source_id, content, reranked=False):
        self.source_id = source_id
        self.content = content
        self.reranked = reranked


SETTINGS = {"context_char_budget": 6000, "web_search_provider": "duckduckgo"}


@pytest.mark.asyncio
async def test_documents_with_passages_is_an_answer():
    async def retrieve(**kwargs):
        return [FakeChunk("src_1", "Remote work is allowed on Fridays.")]

    async def titles(session, chunks):
        return {"src_1": "Hybrid Work Policy"}

    result = await attempts.documents(
        None, FakeBot(), "can I work from home?", SETTINGS, ["col_1"],
        retrieve=retrieve, load_titles=titles)

    assert result.has_content is True
    assert result.kind == "documents"
    assert result.citations == [
        {"n": 1, "title": "Hybrid Work Policy", "source_id": "src_1"}]


@pytest.mark.asyncio
async def test_documents_with_no_passages_is_not_an_answer():
    async def retrieve(**kwargs):
        return []

    async def titles(session, chunks):
        raise AssertionError("nothing retrieved means no titles to look up")

    result = await attempts.documents(
        None, FakeBot(), "anything", SETTINGS, ["col_1"],
        retrieve=retrieve, load_titles=titles)

    assert result.has_content is False


@pytest.mark.asyncio
async def test_a_database_query_that_ran_is_an_answer_and_cites_the_connection():
    async def ask(session, bot, message, settings):
        return DbAnswer(ran=True, context_block="rows here", sql="SELECT 1",
                        row_count=4, connection_name="Shop database")

    result = await attempts.database(None, FakeBot(), "how many?", SETTINGS, ask=ask)

    assert result.has_content is True
    assert result.sql == "SELECT 1"
    assert result.row_count == 4
    # The connection's name and nothing else. A visitor must not learn the
    # table names, let alone the statement.
    assert result.citations == [{"n": 1, "title": "Shop database"}]


@pytest.mark.asyncio
async def test_a_database_query_that_did_not_run_is_not_an_answer():
    async def ask(session, bot, message, settings):
        return DbAnswer(ran=False)

    result = await attempts.database(None, FakeBot(), "how many?", SETTINGS, ask=ask)

    assert result.has_content is False


@pytest.mark.asyncio
async def test_web_results_are_an_answer_and_carry_their_links():
    class FakeResult:
        def __init__(self, title, url):
            self.title = title
            self.url = url
            self.text = "A forecast."

    async def search(**kwargs):
        return [FakeResult("Weather today", "https://example.test/weather")]

    result = await attempts.web(FakeBot(), "weather?", SETTINGS, search=search)

    assert result.has_content is True
    assert result.citations == [
        {"n": 1, "title": "Weather today", "url": "https://example.test/weather"}]


@pytest.mark.asyncio
async def test_no_web_results_is_not_an_answer():
    async def search(**kwargs):
        return []

    result = await attempts.web(FakeBot(), "weather?", SETTINGS, search=search)

    assert result.has_content is False


def test_duckduckgo_needs_no_key():
    assert attempts.key_for({"web_search_provider": "duckduckgo"}) == ""


def test_the_key_of_the_chosen_provider_is_the_one_used():
    settings = {"web_search_provider": "tavily", "web_search_tavily_key": "tvly-1",
                "web_search_brave_key": "brave-2"}

    assert attempts.key_for(settings) == "tvly-1"


class FakeReranker:
    model = "bge-reranker-v2-m3"


@pytest.mark.asyncio
async def test_a_configured_reranker_is_handed_to_retrieval_with_the_bots_floor():
    seen = {}

    async def retrieve(**kwargs):
        seen.update(kwargs)
        return [FakeChunk("src_1", "Two year warranty.", reranked=True)]

    async def titles(session, chunks):
        return {"src_1": "Warranty"}

    result = await attempts.documents(
        None, FakeBot(), "warranty?", SETTINGS, ["col_1"],
        retrieve=retrieve, load_titles=titles,
        make_reranker=lambda settings: FakeReranker())

    assert isinstance(seen["reranker"], FakeReranker)
    assert seen["rerank_min_score"] == 0.25
    assert result.reranked_by == "bge-reranker-v2-m3"


@pytest.mark.asyncio
async def test_no_reranker_configured_means_none_is_passed():
    seen = {}

    async def retrieve(**kwargs):
        seen.update(kwargs)
        return [FakeChunk("src_1", "Two year warranty.")]

    async def titles(session, chunks):
        return {"src_1": "Warranty"}

    result = await attempts.documents(
        None, FakeBot(), "warranty?", SETTINGS, ["col_1"],
        retrieve=retrieve, load_titles=titles, make_reranker=lambda settings: None)

    assert seen["reranker"] is None
    assert result.reranked_by == ""


@pytest.mark.asyncio
async def test_the_bots_similarity_floor_reaches_retrieval():
    seen = {}

    async def retrieve(**kwargs):
        seen.update(kwargs)
        return []

    async def titles(session, chunks):
        return {}

    await attempts.documents(None, FakeBot(), "What is the status of order KA-1003?",
                             SETTINGS, ["col_1"], retrieve=retrieve, load_titles=titles,
                             make_reranker=lambda settings: None)

    assert seen["min_similarity"] == 0.65


@pytest.mark.asyncio
async def test_passages_that_fell_back_to_fusion_do_not_name_the_reranker():
    async def retrieve(**kwargs):
        return [FakeChunk("src_1", "Two year warranty.", reranked=False)]

    async def titles(session, chunks):
        return {"src_1": "Warranty"}

    result = await attempts.documents(
        None, FakeBot(), "warranty?", SETTINGS, ["col_1"],
        retrieve=retrieve, load_titles=titles,
        make_reranker=lambda settings: FakeReranker())

    assert result.reranked_by == ""
