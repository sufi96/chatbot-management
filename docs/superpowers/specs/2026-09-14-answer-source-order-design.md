# Answer Source Order

Design document for phase 7. Written 2026-09-14. It builds on
`2026-09-09-knowledge-base-rag-design.md`, `2026-09-11-web-search-design.md` and
`2026-09-11-database-query-design.md`.

One decision in the database query document is reversed here. Section 4 says
which, why, and what is lost by reversing it.

## 1. Problem

A bot has three sources of fact, and no operator can say in what order they are
consulted, because the answer is "whichever one a small model picks".

The database query document chose model-decided routing. A short call returns
one word, and that word selects the single source that answers. It was the right
call for the problem as stated: no vocabulary separates "what is your returns
policy" from "has my return been processed", so a word list could not stand in.

Two things have been learned since.

The first is that the routing call was returning nothing usable. The configured
model narrates before it answers, and the parser counted every source word in
that narration as a competing verdict, so a correct decision read as indecision.
Separately the router was never told to stop thinking, so it spent its whole
token budget reasoning and the reply ended mid-sentence. Both are fixed, and
with them fixed the model routes correctly on every question tried. That is
worth stating plainly: the reversal below is not because model routing does not
work.

The second is that an operator does not want a source chosen for them. They want
to say "look in my documents, then my database, then the web", or the same three
in a different order, and have that honoured every time. A router that is right
nine times in ten is still a router that cannot be configured, cannot be
predicted, and cannot be explained to somebody whose bot answered from the wrong
place. One question, asked twice, can route differently.

An order is a thing an operator can hold in their head. A verdict is not.

## 2. Goals

- Let an operator set the order in which sources are consulted, per bot.
- Consult them in exactly that order, every time, with no model deciding it.
- Stop at the first source that has something, so a question costs as little as
  it can.
- Let the database step decline a question no table can answer, without a second
  round trip.
- Keep every existing guarantee: read-only SQL, allowed tables only, a failure
  in any source never breaking a conversation.

## 3. Non-goals

- **Combining sources into one answer.** The first source with content answers.
  Merging documents and rows into a single prompt is a larger question about
  citation and contradiction, and nothing here forecloses it.
- **Per-question order.** The order is the bot's, not the visitor's. A bot whose
  order changes by question is a router again, under a different name.
- **Ordering within a source.** Which passages, which rows, which results: all
  unchanged.
- **A fourth source.** The token list is open, so adding one later is a data
  change rather than a rewrite, but none is added here.
- **Retiring the SQL model settings.** Generation still uses them.

## 4. Decisions taken

**The order is configured, not decided.** This reverses "the model routes
between sources" from the database query document. What is lost is real and
should not be understated: a router can send a question about a specific record
straight to the database while leaving policy questions with the documents,
without spending a call on the source that was never going to answer. A fixed
order cannot do that. A documents-first bot that is asked about a record pays a
retrieval it did not need before it reaches the database.

What is gained is that an operator can answer the question "why did my bot say
that", and change it.

**The order lives on the bot.** A sales bot leading with live stock and a
support bot leading with written policy are the same product with different
priorities. A single engine-wide order would force one of them to be wrong.

**First hit wins.** The cascade stops at the first source with content. The
alternative, gathering everything and letting the model choose, costs a SQL
generation and a live query on every question including greetings, and it moves
the decision back into the model that this document just took it out of.

**A query that ran is an answer, even with no rows.** "No projek in Selangor" is
a fact from the operator's own data. Falling through to a web search for it
would replace an authoritative answer with a stranger's guess. A query that
could not be built, was rejected by the validator, or failed at the portal is
not an answer, and the cascade continues.

**The database step may decline.** Ordering the database first would otherwise
make every policy question generate SQL, return no rows, and stop before
reaching the documents. The generation prompt gains one instruction: if no
listed table can answer the question, reply `NO_QUERY`. A decline is a miss and
the cascade continues. It costs nothing extra, because it is the answer to a
call the step was already making.

**The greeting gate stays.** `should_retrieve` in `kb/gating.py` is deterministic
and cheap, and a greeting still reaches no source at all.

## 5. Data model

### Column added to `bot_profiles`

| Column | Type | Default | Meaning |
|---|---|---|---|
| `source_order` | `string(64)` | `documents,database,web` | The order sources are consulted in, most preferred first. |

The tokens are `documents`, `database` and `web`, matching the vocabulary the
engine already used for routing verdicts. The default is the order asked for:
the bot's own written material, then its live records, then the open web.

Stored as a comma-separated string rather than JSON because both codebases read
it, both write it, and neither needs to query into it.

The engine's `BotProfile` gains the same column.

### Normalising, not rejecting

A stored order is never trusted to be well formed. Both sides pass it through
one rule before use:

- Unknown tokens are dropped.
- Duplicates keep their first position.
- Known tokens that are missing are appended in default order.

So `web,web,nonsense` becomes `web,documents,database`, and an empty or absent
value becomes the default. A bot can never end up with a source that is
unreachable because somebody mistyped a setting, and a token added in a later
version degrades to "consulted last" on an older engine rather than breaking it.

Laravel validates on save with the same rule, so what is stored is already
normal. The engine normalises again on read, because the column is also written
by migrations and by hand.

## 6. The cascade

`routers/chat.py` is already long and holds orchestration, transport and
persistence together. The cascade goes in a new `sources.py`, and the route
calls it once.

```
message
  │
  ├─ should_retrieve(message) is false
  │     └─ answer with no context, no source consulted
  │
  └─ for name in normalised(bot.source_order):
         source is switched off for this bot   → skip
         result = await attempt[name](...)
         result.has_content                    → answer from it, stop
  │
  └─ nothing hit → answer under the bot's retrieval_fallback
```

Each source is a callable returning a `SourceResult`:

```python
@dataclass
class SourceResult:
    kind: str               # documents | database | web
    context_block: str = ""
    citations: list = field(default_factory=list)
    sql: str = ""           # database only, for the transcript
    row_count: int = 0      # database only
    has_content: bool = False
```

`has_content` is set by the attempt that built the result, not derived from the
block being non-empty. Section 7 is why: a database query that ran and found
nothing has an empty block and is still an answer.

The three attempts wrap work that already exists: `retrieve_for_collections`
plus `build_context_block` for documents, the generate-validate-run chain for
the database, `websearch.search` plus `build_web_context_block` for the web.
None of that logic changes. What changes is that each is entered by the cascade
rather than by a chain of conditions in the route.

Which switch governs which source:

| Source | Switched off when |
|---|---|
| `documents` | `bot.retrieval_enabled` is false |
| `database` | `bot.db_query_enabled` is false, or no enabled connection with readable tables |
| `web` | `bot.web_search_enabled` is false |

A source that is off is skipped without a call, and without ending the cascade.

## 7. Hit and miss

| Source | Hit, and the cascade stops | Miss, and the cascade continues |
|---|---|---|
| `documents` | one or more passages retrieved | no passages |
| `database` | the query ran, **including with no rows** | `NO_QUERY`, no statement survived validation, the portal refused it, or it timed out |
| `web` | one or more results | no results |

The database row is the one that repays reading twice. A query that ran is an
answer about the operator's data whether it found rows or not. A query that
never ran is not an answer about anything.

## 8. Failure handling

Unchanged in spirit from the documents this builds on: nothing a source does may
break a conversation.

Every attempt is wrapped. An exception is logged and treated as a miss, so the
cascade moves on. A source that throws is indistinguishable, to the visitor,
from a source that found nothing. If every source misses or throws, the bot
answers under its `retrieval_fallback`, which is what it does today when the
knowledge base comes back empty.

The one behaviour worth calling out: a database that is down no longer silently
becomes a documents answer because a router said so. It becomes a miss, and the
next source in the operator's own order is consulted. The order is honoured in
failure as well as in success.

## 9. Citations and logging

Both preserved exactly.

A database answer still cites the connection's name and nothing else. A visitor
on a public site must not learn table names, let alone the statement. A
documents answer cites source titles; a web answer cites titles and URLs, which
the widget turns into links.

`chat_messages.db_sql` and `chat_messages.db_row_count` are still written when
the database answered, because an operator auditing a wrong answer needs the
statement rather than a guess at it. The `SourceResult` carries both to the
route so this does not change.

## 10. Screens

The bot's Brain page already carries the three switches, in three blocks:
retrieval, web search, database. The order control goes above them, because it
governs all three.

An ordered list of the three sources, each row with an up and a down button, over
a hidden field holding the string that is submitted. The rows are labelled the
way the rest of the console labels them, "Knowledge base", "Database" and "Web
search", not by the stored tokens. `documents` is a word the engine uses among
itself. The buttons reorder the rows
and rewrite the field. Each row says whether that source is currently switched
off, so an operator who puts the database first and sees "switched off" beneath
it knows why nothing changed.

The console already requires scripting for its modals, dropdowns and theme, so
the buttons may rely on it. The server normalises whatever arrives regardless, so
a broken or hand-made submission cannot produce a bot with an unreachable source.

## 11. What is removed

- `dbquery/routing.py`, and `tests/test_dbquery_routing.py` with it. Nothing
  routes any more.
- `dbquery.Outcome`, replaced by `SourceResult`.
- `route_and_query` keeps only its second half, as `dbquery.answer`: generate,
  validate, run, build the block. It no longer decides whether it should.
- `websearch/gating.py`. `web_search_runs` encoded "the web is the fallback,
  so it runs when nothing else answered", which is now the cascade's job and
  not a rule any one source holds.
- `context_for` and `sources_payload_for` in `routers/chat.py`, whose branching
  the `SourceResult` replaces.

The recent fix to `parse_verdict` is removed with `routing.py`. It was not
wasted: it is what proved the model reads the schema and answers correctly, and
that evidence is why the `NO_QUERY` decline in section 4 can be relied on. The
fix to `LLMAdapter.complete`, which stops the model thinking and retries without
the switch when an endpoint refuses it, stays and is load-bearing. Generation
uses the same call and was truncating in the same way.

## 12. Testing

The cascade is pure orchestration and is tested without a model, a database or
an HTTP request, the way `should_retrieve` and `context_for` already are. Each
attempt is injected.

Engine:

- the configured order is followed, for each of the six permutations
- the first source with content stops the cascade, and later sources are never
  called
- a source switched off is skipped without being called, and does not end it
- a database miss continues; a database hit with zero rows stops
- `NO_QUERY` is a miss, and no statement reaches the validator
- an attempt that raises is a miss, and the cascade continues
- every source missing falls back on `retrieval_fallback`
- a greeting reaches no source at all
- `web,web,nonsense` normalises to `web,documents,database`; empty gives the
  default
- `db_sql` and `db_row_count` still reach the transcript when the database
  answered

Portal:

- the column defaults to `documents,database,web` for an existing bot
- a malformed order normalises on save rather than failing validation
- the Brain page renders the bot's current order, and saving a new one persists
- a source that is switched off is labelled as such in the control

## 13. Build order

1. The column, both sides, with normalising on each.
2. `SourceResult` and the three attempts, wrapping work that already exists.
3. The cascade, and the route calling it.
4. Deletions from section 11.
5. The Brain page control.
6. Documentation catches up: `docs/architecture.md` gains the order, and the
   database query document gets a line pointing here for its reversed decision.

## 14. What an operator does, start to finish

Opens a bot's Brain page. Sees that questions go to the knowledge base first,
then the database, then the web. Decides this bot is a stock enquiry bot and
moves the database to the top. Saves.

Asks it "how many projek in Selangor". The database is consulted first, writes a
statement, runs it, and answers from the rows. Asks it "what is the hybrid work
policy". The database is consulted first, finds no table that could answer,
declines, and the knowledge base answers instead.

Both answers came from where the operator said to look first.
