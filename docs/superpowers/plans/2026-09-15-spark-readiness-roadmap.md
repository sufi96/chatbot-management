# Spark Readiness Roadmap

The improvements from `docs/model-stack-review.md`, cut into plans that each
ship working software on the 8 GB machine today and need only a settings change
when the DGX Sparks arrive.

Image generation is out of scope until everything below is done.

Each plan is written in full only when the plan before it has merged, because
each one builds on names the previous one lands. Writing them all now would
mean rewriting them.

| # | Plan | Model role it uses | On the 8 GB machine | Depends on | Status |
|---|---|---|---|---|---|
| 1 | Model roles, and a trace of which model answered | `sql`, `intent`, `rerank`, `guard` registered | qwen3.5-4b for every generative job | | Done: `2026-09-15-model-roles.md` |
| 2 | Standalone questions and intent | `intent` | qwen3.5-4b | 1 | Done: `2026-09-15-follow-up-questions.md` |
| 3 | Reranking, and a relevance floor on its score | `rerank` | bge-reranker-v2-m3 on llama.cpp; blank skips the stage | 1 | Done: `2026-09-15-reranking.md` |
| 4 | Input and output guard | `guard` | Qwen3Guard-Gen-0.6B, or qwen3.5-4b with a prompt | 1, 2 | Done: `2026-09-15-guard.md` |
| 5 | Golden set and evaluation runner | `judge` | A hosted API, or skip judging and compare by hand | 1 | Done: `2026-09-15-evaluation.md` |
| 6 | Vision OCR for scanned uploads | `vision` | None; blank skips the stage | 1 | Done: `2026-09-15-scanned-documents.md` |
| 7 | Arrival: vLLM per node, embedding switch and re-index | `embedding` | | 1 to 5 | Ready, to run on arrival: `2026-09-15-sparks-arrival.md`, runbook `docs/sparks-setup.md` |

## What each plan must hold to

- **Blank means today's behaviour.** A role nobody configured either borrows
  the bot's own model or skips its stage. No install gets worse by upgrading.
- **One place resolves a role.** `api-engine/roles.py`, from plan 1. No job
  reads its own `*_model_*` settings.
- **Every decision a model makes is recorded** on the message, so an operator
  can answer "why did my bot say that".
- **The source order stays the operator's.** Intent decides the kind of reply,
  never which source answers. See section 5 of the review.

## Plan 2 in outline

A per-bot switch, "Understand follow-up questions". When on, one JSON call to
the `intent` role reads the message and the last few turns, and returns
`{"intent": "chat" | "facts", "query": "...", "language": "..."}`. `facts`
sends the rewritten query into the source cascade instead of the raw message.
`chat` skips the cascade. The word-rule gate stays as the fast path, and a
message it calls a question is never downgraded to `chat`. Any failure or
unparseable reply means `facts` with the raw message, which is today's
behaviour. The verdict and the rewritten query are stored on the visitor's
message.

## Plan 3 in outline

A `kb/rerank.py` client for the Cohere-style `POST {base}/rerank`
(`{model, query, documents, top_n}` in, `results[{index, relevance_score}]` out),
which both vLLM and llama.cpp serve. Retrieval fuses as today, reranks the
fused candidates when the `rerank` role is available, and applies a new per-bot
`rerank_min_score` to the reranker's score. The RRF floor stays for bots on an
install with no reranker. Scores are recorded for the logs screen.

## Plan 4 in outline

The input check runs in parallel with intent and can veto it; a flagged message
gets the bot's refusal text and never reaches a source. The output check runs
on the finished answer and flags the message rather than retracting what was
already streamed. Both are per-bot switches, off by default.
