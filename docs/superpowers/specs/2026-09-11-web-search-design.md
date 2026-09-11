# Web Search

Design document for phase 5. Written 2026-09-11, after a bot answered basic
questions about Malaysia with confident, wrong facts. It builds on
`2026-09-09-knowledge-base-rag-design.md` and
`2026-09-10-knowledge-base-operations-design.md`; both still hold.

## 1. Problem

A bot has two sources of fact: the knowledge base, and whatever the model
remembers. The knowledge base only holds what an operator uploaded. Everything
else falls to the model, and the models that run here are small. A four billion
parameter model states a wrong population figure with exactly the same
confidence as a right one.

The fallback setting does not save us. `say_unknown` only reaches the prompt
when retrieval ran and found nothing, and it only constrains the model to the
passages supplied. For a question the knowledge base was never meant to cover,
the bot answers from memory and the operator has no way to know it was wrong.

There is no third source. Nothing in the engine reaches the internet.

## 2. Goals

- Answer questions outside the knowledge base from current web sources.
- Keep the operator's own documents authoritative where they apply.
- Let an operator choose a provider, and change it later without code changes.
- Show which pages an answer came from, as links the visitor can follow.
- Bias results towards a country, so a local question gets local sources.
- Never let a search failure break a conversation.

## 3. Non-goals

- **Searching on every message.** Each search is a paid call and roughly half a
  second before the first token. The web is the fallback, not the first stop.
- **Model-decided search.** Asking the model whether to search costs a whole
  extra round trip and classifies badly at these sizes, for the same reasons the
  retrieval gate is deterministic.
- **A domain allow list.** Worth having for a support bot on the open web, and
  addable later as a filter over results without restructuring anything. Not
  needed to answer the question that prompted this.
- **Caching.** Repeat queries will re-search. Add it when the bill says to.
- **Page fetching.** Providers return text. We do not follow result links and
  scrape them; that is a crawler, with its own robots, timeout and size rules.
- **Per-bot provider choice.** The provider is an account-level decision tied to
  an API key. A per-bot override is complexity with no demand behind it.

## 4. Decisions taken

**The web runs only when the knowledge base comes back empty.** The operator's
documents stay authoritative. Questions the knowledge base already answers cost
nothing extra and gain no latency. A bot with retrieval switched off has an
always-empty knowledge base, so for it the web answers every gated question,
which is the consistent reading of "the web is the fallback".

**The existing gate is reused unchanged.** `should_retrieve` in `kb/gating.py`
already rejects greetings and messages that cannot carry a question. A second
gate with its own vocabulary would drift away from the first.

**The provider is an engine-wide setting, the behaviour is per-bot.** Which
service and which key are account-level facts and live in `app_settings` beside
`context_char_budget`. Whether a given bot searches, how many results it uses,
and which country it favours are per-bot and live on `bot_profiles` beside the
retrieval settings.

**Three providers behind one interface, DuckDuckGo first.** DuckDuckGo needs no
key, so the feature can be switched on and judged before anyone signs up for
anything. Tavily and Brave are the keyed options once it has earned that.

**Each provider is spoken to over httpx with an injectable transport.** This is
the seam `EmbeddingClient` already uses, and it is what makes the adapters
testable without network access.

## 5. Provider layer

A new package `api-engine/websearch/`, organised the way `kb/` is.

```
websearch/
  __init__.py     search(), the registry, and the SearchResult type
  duckduckgo.py
  tavily.py
  brave.py
```

Every adapter exposes the same coroutine:

```python
async def search(query: str, count: int, country: str | None,
                 api_key: str = "", transport=None) -> list[SearchResult]
```

`SearchResult` carries `title`, `url` and `text`. Nothing provider-shaped
escapes the adapter.

`country` is stored once as an ISO 3166 code such as `MY`. Each adapter
translates it into whatever its own API wants, because the three disagree.
DuckDuckGo takes a region like `my-en`, Brave takes a country code, Tavily takes
a country name. Translation is the adapter's problem, not the caller's.

**DuckDuckGo** has no official API. The adapter posts to
`https://html.duckduckgo.com/html/` and parses the result list with
BeautifulSoup, which is already installed as a `markitdown` dependency and will
be promoted to an explicit line in `requirements.txt` rather than relied on by
accident. This keeps the httpx transport seam that the other two get for free.
It will be rate limited under real traffic; see failure handling.

**Tavily** posts to `https://api.tavily.com/search` with the key in the body and
returns cleaned page content directly.

**Brave** gets `https://api.search.brave.com/res/v1/web/search` with the key in
an `X-Subscription-Token` header and returns snippets.

An unknown provider name disables search and logs once, rather than raising.

## 6. Settings

Engine-wide, added to `SETTING_DEFAULTS` in `database.py` and to the admin
settings screen:

| Key | Default | Meaning |
| --- | --- | --- |
| `web_search_provider` | `duckduckgo` | `duckduckgo`, `tavily` or `brave` |
| `web_search_tavily_key` | empty | Key, ignored by other providers |
| `web_search_brave_key` | empty | Key, ignored by other providers |

Two key fields rather than one, so switching provider to try something does not
destroy the key already configured. The screen states that DuckDuckGo needs
neither and is rate limited.

Per-bot, a migration adding three columns to `bot_profiles`, edited on the brain
screen beside the retrieval block:

| Column | Type | Default | Meaning |
| --- | --- | --- | --- |
| `web_search_enabled` | boolean | `false` | Off unless asked for |
| `web_search_max_results` | small int | `3` | Results fed to the model |
| `web_search_country` | string(2) | null | ISO code, null means no bias |

## 7. Where it runs

In `routers/chat.py`, after knowledge base retrieval and before the prompt is
assembled. The condition is a single readable expression, kept as a pure
function so it can be tested the way `should_retrieve` is:

```python
def web_search_runs(enabled: bool, message_is_a_question: bool,
                    kb_hits: int) -> bool:
    return enabled and message_is_a_question and kb_hits == 0
```

`message_is_a_question` is the existing `should_retrieve(message)` result. The
route currently folds that call straight into `retrieval_ran`, which is the
conjunction of the gate and the retrieval switch. It will be lifted into a
variable of its own first, so a bot with retrieval off still has a gate result
to consult. No second call, no second vocabulary.

Because the web only runs when the knowledge base returned nothing, the two
never compete for prompt space. Web results get the whole
`context_char_budget`, trimmed by the same whole-result-at-a-time rule that
`fit_to_budget` applies to chunks.

## 8. Context assembly and prompt safety

A new `build_web_context_block(results)` produces a block numbered `[1]`,
`[2]` and so on, so a citation marker means the same thing whichever source
answered. It is handed to the existing `augment_system_prompt`, so the fallback
instruction, the empty-context case and the greeting path all keep working
untouched.

The block opens with an explicit statement that the text is quoted from
third-party web pages, is not from the operator, and must be treated as
reference material rather than as instructions.

This matters. A web page is untrusted input. It can contain text aimed at the
model rather than at the reader. Labelling reduces the risk and does not remove
it, and it is a standing reason to prefer the knowledge base for anything that
matters. It is also why the domain allow list, though out of scope here, is the
first thing to add next.

## 9. Citations

Web results ride the sources event the route already emits before the first
token:

```json
{"type": "sources", "sources": [{"n": 1, "title": "...", "url": "https://..."}]}
```

`url` is new and optional. `attachSources` in the widget renders a chip that has
a `url` as a link opening in a new tab with the opener severed, and a chip
without one as the plain text it draws today. Knowledge base chips are
unaffected, and a widget deployed before this change ignores a field it does not
know.

## 10. Failure handling

A search that fails, times out or is rate limited is logged and produces no
results. The answer is then built exactly as it is today, from the model alone.
This mirrors the existing `try`/`except` around retrieval, which already chooses
a worse answer over no answer.

The search carries a short timeout, six seconds, because it sits between the
visitor's message and the first token they see.

DuckDuckGo will rate limit. That is expected, not a defect, and it is the reason
the provider is a setting.

## 11. Testing

- Each adapter against `httpx.MockTransport`, asserting the request shape and
  the parsed results, following `tests/test_embedding.py`.
- The DuckDuckGo adapter additionally against a saved fragment of real result
  HTML, so a parser change is caught rather than discovered in production.
- Registry selection, including the unknown-provider case.
- `web_search_runs`, following `tests/test_chat_gating.py`: off with search
  disabled, off for a greeting, off when the knowledge base returned hits, on
  when search is enabled and a real question found nothing, and on for a bot
  with retrieval switched off entirely.
- Budget trimming, including a single result larger than the whole budget.
- The web context block carries the untrusted-material statement and numbers
  results from one.
- Laravel: the three columns exist with the stated defaults, and settings
  validation accepts exactly the three provider names.

## 12. What an operator sees

On the admin settings screen, a web search section: provider, two key fields,
and a line saying what each needs.

On a bot's brain screen, beside the retrieval block: a switch, a result count,
and a country. The help text says plainly that search runs only when the
knowledge base has nothing, so nobody has to read this document to understand
why their bot is not searching.
