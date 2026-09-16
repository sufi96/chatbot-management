# Kedai Aina: test data for real answers

A made-up Malaysian home appliance shop with a customer database and a
knowledge base, built so every answer a bot gives can be checked against a fact
that is known exactly. The questions and their expected answers are in
`api-engine/evals/sets/kedai-aina.json`.

| File | What it is |
|---|---|
| `shop.sql` | The shop's own database: 8 customers, 10 products, 12 orders |
| `policies/*.md` | Warranty and returns, shipping and delivery, stores and contact |
| `seed.php` | Loads all of it through the portal's own code |

## Seeding

Both `.env` files need the shared secrets first (`ENGINE_ADMIN_TOKEN` and
`PORTAL_INTERNAL_TOKEN` in `admin-laravel/.env`, `ADMIN_API_TOKEN` and
`PORTAL_INTERNAL_TOKEN` in `api-engine/.env`, with matching values). Without
them the engine cannot index a source and the portal refuses every database
query.

With the engine and portal running:

```
php demo/kedai-aina/seed.php
```

It builds `shop.sqlite` from `shop.sql`, creates the bot **Kedai Aina
Assistant** (`bot_kedai_demo`) on the "Laptop vLLM - 8GB VRAM" provider,
registers the database and discovers its schema, writes table and column
descriptions, adds five knowledge base sources, and asks the engine to index
them. No other bot is touched. It is safe to run again.

The seed sets the source order to documents first and follow-up understanding
off. Change them on the bot's Brain page to compare.

## Running the questions

From `api-engine/`:

```
.venv\Scripts\python.exe -m evals evals/sets/kedai-aina.json --judge-provider "Laptop vLLM - 8GB VRAM" --judge-model qwen3.5-4b
```

## What the first runs found (2026-09-15, qwen3.5-4b)

| Setup | Passed |
|---|---|
| Documents first, before the fixes | 8 of 15; every database question answered "I do not have that information" |
| Database first, before the context fix | 10 of 15 |
| Database first, after the fixes | 12 of 15 |
| Database first, follow-up understanding on | 13 of 15 |
| Documents first, similarity floor 0.65, follow-up understanding on | 14 of 15 |
| Same, with the bge-reranker-v2-m3 reranker | 14 of 15 |
| Both, after a first message stopped being rewritten | **15 of 15**, with or without the reranker |

With documents first, the knowledge base used to count as a hit for every
question, because fusion scores cannot say "nothing here answers this". The
similarity floor, or a reranker when one is set, now lets it report a miss, so
the database gets its turn. The last failure was follow-up understanding
translating a first message in Malay into English before searching.

## Serving the reranker

Ollama has no rerank endpoint. llama.cpp serves one:

```
llama-server -m bge-reranker-v2-m3-Q8_0.gguf --reranking --host 127.0.0.1 --port 8012 -ngl 99 -c 8192 -b 8192 -ub 8192 --alias bge-reranker-v2-m3
```

Then set Admin Settings, Models, Reranker to `http://127.0.0.1:8012/v1` and
`bge-reranker-v2-m3`. See the Reranking section of `docs/architecture.md`.
