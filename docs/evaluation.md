# Evaluating a Bot

A model change, a new reranker or a rewritten system prompt is only an
improvement if the bot does better on the same questions as before. An
evaluation set is those questions, and `python -m evals` asks them of a running
engine and writes down what happened.

It asks exactly as the widget does, over HTTP, so it measures what a visitor
gets: the real models, the real database, the real network.

---

## Running one

From `api-engine/`, with the engine running:

```
.venv\Scripts\python.exe -m evals evals/sets/corporate-support-smoke.json
```

To have a model score answers against their references as well, name an AI
provider exactly as it appears in the portal, and a model on it:

```
.venv\Scripts\python.exe -m evals evals/sets/corporate-support-smoke.json --judge-provider "Laptop vLLM - 8GB VRAM" --judge-model qwen3.5-4b
```

The provider is read from the database, so its key never appears on the
command line. Other options: `--engine` for an engine elsewhere (default
`http://127.0.0.1:8000`) and `--out` for another report directory.

The command exits 0 when every case passes, 1 when any fails, and 2 when the
set cannot be run. Reports are written to `api-engine/evals/reports/`, which
git ignores: one Markdown file to read, and one JSON file to compare runs.

Evaluation conversations are real conversations. They appear under
Conversations with session ids beginning `eval-`.

---

## Writing a set

A set is a JSON file. Anything wrong with it is reported before a single
question is asked.

```json
{
  "name": "shop-support",
  "bot_id": "bot_demo_default",
  "cases": [
    {
      "id": "warranty-follow-up",
      "history": [
        {"role": "user", "content": "How much is the X200 air fryer?"},
        {"role": "assistant", "content": "The X200 is RM 399."}
      ],
      "message": "and the warranty?",
      "expect": {
        "source": "documents",
        "cites": ["Warranty"],
        "must_include_any": ["two years", "2 years"],
        "must_not_include": ["I don't know"],
        "max_first_token_seconds": 5,
        "max_total_seconds": 30
      },
      "reference": "The X200 air fryer has a two-year warranty."
    }
  ]
}
```

| Field | Meaning |
|---|---|
| `id` | Unique within the set; names the case in the report |
| `message` | What the visitor sends |
| `history` | Earlier turns, each with `role` of `user` or `assistant` |
| `expect.source` | `documents`, `database`, `web`, or `none` for no source at all |
| `expect.cites` | Titles, or parts of titles, that must be among the citations |
| `expect.must_include_any` | At least one must appear in the answer, ignoring case |
| `expect.must_not_include` | None may appear in the answer, ignoring case |
| `expect.max_first_token_seconds` | How long before the first word may take |
| `expect.max_total_seconds` | How long the whole answer may take |
| `reference` | What a correct answer says; only cases with one are judged |

Every `expect` field is optional. A case with none still records its answer
and timings.

---

## Reading the report

- **Pass and fail** come only from `expect`. They are deterministic: the same
  answer always gets the same result.
- **The judge score**, 1 to 5, is not. Two runs can score one answer
  differently, and a small model judging its own answers is generous. Watch
  the mean across a model change; do not gate on a single score.
- **The first case measures warm-up.** A vLLM server's first request after it
  starts has been seen to take 24 seconds where a warm one takes half a second.
  Start a set with a greeting to absorb it, or run the set twice.

To compare two setups, run the same set on each and compare the two JSON
files: `summary` holds the pass count, median first token, p90 total and mean
judge score, and `cases` holds every answer.
