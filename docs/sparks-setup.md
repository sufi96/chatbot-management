# Bringing the DGX Sparks Up

From unboxing two DGX Sparks to a chatbot install that uses them, with every
model choice measured rather than guessed. The layout follows
`docs/model-stack-review.md`: node A runs the answer model alone, node B runs
everything else.

Written on 2026-09-15, before the hardware arrived. Everything that could be
built and checked without it has been. The last section lists what could not.

---

## 1. Before power-on

- **Power and cooling.** Give each Spark its own outlet and enough space around its vents; both run hot under sustained load.
- **Cable.** Connect the two Sparks with a QSFP cable between their ConnectX-7 ports. Each node also needs a normal network connection that the machine running the engine and portal can reach.
- **First boot.** Complete DGX OS's first-boot setup on each node, then bring it up to date:

  ```
  sudo apt update && sudo apt full-upgrade -y
  sudo reboot
  ```

- **Check the GPU and containers.** `nvidia-smi` should list the GPU. `docker run --rm --gpus all ubuntu nvidia-smi` should list it from inside a container too; DGX OS ships with Docker and the NVIDIA container toolkit.

## 2. Network

- **Direct link.** Give the direct cable its own small subnet: node A `10.10.0.1/24`, node B `10.10.0.2/24`, set in netplan on each node. It is used for copying model weights between nodes, not for serving. Nothing in this layout splits one model across both.
- **Main network.** Give each node a fixed address the engine can reach. The rest of this runbook calls them `SPARK_A` and `SPARK_B`.
- **Firewall.** Allow ports 8000 (node A) and 8001 to 8005 (node B) only from the machine running the engine. The vLLM API key is a second line of defence, not the first.

## 3. Settings

On each node:

```
git clone https://github.com/sufi96/chatbot-management.git
cd chatbot-management
cp deploy/sparks/.env.example deploy/sparks/.env
sudo mkdir -p /srv/models && sudo chown "$USER" /srv/models
```

Edit `deploy/sparks/.env` on each node. The same values go on both nodes.

- **`VLLM_IMAGE`.** The newest NGC vLLM tag that lists DGX Spark support, from catalog.ngc.nvidia.com.
- **`VLLM_API_KEY`.** Generate it once with `openssl rand -hex 32`, and use the same value on both nodes.
- **`HF_TOKEN`.** Needed only if a chosen model is gated.
- **`ANSWER_MODEL`.** This must be replaced. The example is a placeholder, and the model has to be an NVFP4 or FP8 checkpoint: 16-bit 122B weights are about 244 GB and do not fit in 128 GB.
- **Every other model id.** Confirm each repository exists and is the format the comment beside it says.

## 4. Download the models

Pull the image and fetch the weights before starting anything, so a first start does not stall for an hour inside a health check:

```
docker compose --env-file deploy/sparks/.env -f deploy/sparks/node-b.compose.yaml pull
HF_HOME=/srv/models/huggingface huggingface-cli download Qwen/Qwen3-Embedding-4B
```

Repeat the download for each model the node serves. To save bandwidth, download once and copy over the direct link:

```
rsync -a /srv/models/huggingface/ 10.10.0.2:/srv/models/huggingface/
```

## 5. Start the nodes

Start node B first. Its models are small, so it comes up quickly, and it shows early whether the image and flags are right.

```
docker compose --env-file deploy/sparks/.env -f deploy/sparks/node-b.compose.yaml up -d
docker compose --env-file deploy/sparks/.env -f deploy/sparks/node-b.compose.yaml logs -f
```

When the logs settle, check that each service answers:

```
for port in 8001 8002 8003 8004 8005; do
  curl -s -H "Authorization: Bearer $VLLM_API_KEY" http://localhost:$port/v1/models
done
nvidia-smi
```

Then start node A the same way, with `node-a.compose.yaml`. Loading the answer model takes minutes, so the health check allows 20.

If a pooling model refuses to start, the flag in its `EXTRA_ARGS` has probably changed name in this vLLM version. `docker compose … run --rm embedding --help` lists the current flags.

## 6. Point the install at the Sparks

In the portal:

- **Answer model (node A).**
  - Add an AI provider named `Spark A`, with base URL `http://SPARK_A:8000/v1` and the `VLLM_API_KEY` value.
  - On each bot, choose `Spark A` and set the model to `ANSWER_SERVED_NAME`, for example `qwen3.5-122b-a10b`.
- **Admin Settings → Models**, all with the same API key:

| Job | Base URL | Model |
|---|---|---|
| Intent | `http://SPARK_B:8001/v1` | `INTENT_SQL_SERVED_NAME` |
| SQL | `http://SPARK_B:8001/v1` | `INTENT_SQL_SERVED_NAME` |
| Reranker | `http://SPARK_B:8003/v1` | `RERANK_SERVED_NAME` |
| Guard | `http://SPARK_B:8004/v1` | `GUARD_SERVED_NAME` |
| Vision | `http://SPARK_B:8005/v1` | `VISION_SERVED_NAME` |

Leave the embedding model as it is until section 8.

## 7. Check everything

From `api-engine/` on the machine running the engine:

```
.venv\Scripts\python.exe -m doctor
```

Every line should start with `ok`, or `skipped` for a job you chose to leave blank. The doctor sends each endpoint one small request and changes nothing. What its failures mean:

| Line | Usually means |
|---|---|
| `fail answer … Could not reach the server` | Node A is down, still loading, or blocked by the firewall |
| `fail answer … HTTP 401` | The API key in the provider row differs from `VLLM_API_KEY` |
| `fail answer … HTTP 404` | The bot's model name differs from `ANSWER_SERVED_NAME` |
| `fail embedding … returned N dimensions` | Admin Settings dimensions differ from what the model returns |
| `fail rerank … not ranked first` | The model on port 8003 is not a reranker |
| `fail portal …` | The shared secrets are missing or differ; run `start-dev.ps1` |

## 8. Switch the embedding model

This changes how every passage is stored, so it is done once, deliberately.

1. **Change the embedding settings.** In Admin Settings → Embedding, set the base URL to `http://SPARK_B:8002/v1`, the model to `EMBEDDING_SERVED_NAME`, and the dimensions to `1024`. The engine asks the model for exactly that many and refuses any other length.
2. **Keep within pgvector's limit.** On PostgreSQL, keep dimensions at 2,000 or below; pgvector's HNSW index takes no more.
3. **Save, then rebuild.** Click **Rebuild the index** and watch the sources return to ready.
4. **Re-tune the similarity floor.** A new embedding model has a new scale, so 0.65 was measured for `nomic-embed-text` only. Use the retrieval playground, which shows each passage's similarity. With the reranker set, the reranker decides and the floor is a fallback.

## 9. Choose the models by measurement

The model names in `.env.example` are a starting point. Run the evaluation sets against each candidate and keep what scores best:

```
.venv\Scripts\python.exe -m evals evals/sets/kedai-aina.json --judge-provider "Spark A" --judge-model qwen3.5-122b-a10b
```

- **Add your own set.** Copy `kedai-aina.json` and write questions from a real tenant's material; `docs/evaluation.md` has the format.
- **Try one candidate.** Change its id and served name in `deploy/sparks/.env`, run `docker compose … up -d <service>` for that service only, update the portal's model name, run the same sets, and compare the `summary` blocks in the JSON reports.
- **Judge with the strongest model.** A model judging its own answers is generous, so point the judge at node A's answer model.

## 10. Load test

Before real tenants arrive, find out how many conversations node A holds at once:

```
docker compose --env-file deploy/sparks/.env -f deploy/sparks/node-a.compose.yaml exec answer \
  vllm bench serve --help
```

Run the benchmark with prompts about as long as a real chat, meaning system prompt, retrieved passages and history: roughly 3,000 to 6,000 tokens in and 300 out. Raise the request rate until time to first token passes what a visitor will wait, about 2 seconds. If it gives out too early, lower `--max-model-len`, raise `--gpu-memory-utilization` a little, or cap `--max-num-seqs`.

## 11. Keeping it running

- **Restarts.** Every service restarts on failure. After a reboot, Docker starts them again because Docker itself is enabled at boot; confirm with `docker compose … ps`.
- **Metrics.** Each vLLM process serves Prometheus metrics at `/metrics` on its own port.
- **Logs.** `docker compose … logs --since 1h <service>`.

## 12. What could not be checked before arrival

| Item | Why | Where it matters |
|---|---|---|
| The NGC image tag and its Spark support | Needs the catalog on the day and an arm64 Blackwell node | Section 3 |
| The answer model checkpoint | The NVFP4 repository name is a placeholder | Section 3 |
| Every other model repository and format | Taken from the roster, not pulled | Sections 3 and 4 |
| vLLM's flag for pooling models | Its name has changed between versions | Section 5 |
| Memory per service | Shares are estimates from weight sizes; confirm with `nvidia-smi` | Section 5 |
| Speed and concurrency | Only measurable on the hardware | Section 10 |

What was checked before arrival:

- **Compose files.** Both validate with `docker compose config`, and the rendered `vllm serve` commands have every flag as its own argument.
- **Doctor.** `python -m doctor` ran against the development install and correctly reported working Ollama embedding, the llama.cpp reranker, the portal's shared secret, an unreachable laptop and an invalid API key.
- **Embedding size.** Asking the embedding model for the configured size works against Ollama.
- **Reranker and similarity floor.** Both were measured on the Kedai Aina set: 15 of 15 with either.
