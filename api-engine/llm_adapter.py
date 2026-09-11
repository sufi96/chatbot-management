import json
import httpx
from typing import AsyncGenerator, List, Dict, Any

from reasoning import ReasoningSplitter

class LLMAdapter:
    @staticmethod
    def _normalize_endpoint(base_url: str) -> str:
        url = base_url.strip().rstrip("/")
        if url.endswith("/chat/completions"):
            return url
        if url.endswith("/v1"):
            return f"{url}/chat/completions"
        return f"{url}/v1/chat/completions"

    @classmethod
    async def stream_chat(
        cls,
        base_url: str,
        api_key: str,
        model_name: str,
        system_prompt: str,
        temperature: float,
        max_tokens: int,
        history: List[Dict[str, str]],
        user_message: str,
        top_p: float = 1.0,
        top_k_sampling: int = None,
        presence_penalty: float = 0.0,
        frequency_penalty: float = 0.0,
        thinking_level: str = "off",
        transport=None
    ) -> AsyncGenerator[str, None]:
        endpoint = cls._normalize_endpoint(base_url)
        headers = {
            "Content-Type": "application/json"
        }
        if api_key and api_key.strip():
            headers["Authorization"] = f"Bearer {api_key.strip()}"

        messages = []
        if system_prompt and system_prompt.strip():
            messages.append({"role": "system", "content": system_prompt.strip()})

        for msg in history:
            role = msg.get("role") or msg.get("sender") or "user"
            content = msg.get("content", "")
            if role in ["user", "assistant", "system"] and content:
                messages.append({"role": role, "content": content})

        messages.append({"role": "user", "content": user_message})

        payload = {
            "model": model_name,
            "messages": messages,
            "stream": True,
            # The final chunk then carries prompt and completion counts.
            # Endpoints that do not know the key are handled on the retry below.
            "stream_options": {"include_usage": True},
            "temperature": float(temperature or 0.7),
            "max_tokens": int(max_tokens or 1024)
        }

        # Only send what the caller actually set. Endpoints differ in what they
        # accept, and an unexpected key is rejected outright by some of them.
        if top_p is not None and float(top_p) != 1.0:
            payload["top_p"] = float(top_p)
        if top_k_sampling:
            payload["top_k"] = int(top_k_sampling)
        if presence_penalty:
            payload["presence_penalty"] = float(presence_penalty)
        if frequency_penalty:
            payload["frequency_penalty"] = float(frequency_penalty)
        # Hybrid reasoning models such as Qwen3 read this chat template switch,
        # and it is the only thing that genuinely stops them thinking. An
        # endpoint that does not template its prompt ignores the key.
        thinking_on = bool(thinking_level) and thinking_level != "off"
        payload["chat_template_kwargs"] = {"enable_thinking": thinking_on}
        if thinking_on:
            # A depth hint for providers that grade reasoning effort. Qwen on
            # vLLM has no depth control, so there this only means "think".
            payload["reasoning_effort"] = thinking_level

        # Catches thinking however it arrives: its own delta field when the
        # server runs a reasoning parser, inline <think> tags when it does not.
        splitter = ReasoningSplitter(enabled=thinking_on)

        client_timeout = httpx.Timeout(connect=10.0, read=60.0, write=10.0, pool=10.0)
        try:
            async with httpx.AsyncClient(timeout=client_timeout, transport=transport) as client:
                while True:
                    reported_model = None
                    usage = None

                    async with client.stream("POST", endpoint, headers=headers, json=payload) as response:
                        if response.status_code != 200:
                            error_body = await response.aread()
                            err_text = error_body.decode(errors="replace")
                            # A strict OpenAI-compatible endpoint rejects a body
                            # key it does not know. Every optional key here is a
                            # nicety, so drop whichever one it named and ask again
                            # rather than failing the chat over it.
                            refused = [key for key in ("chat_template_kwargs", "stream_options")
                                       if key in payload and key in err_text]
                            if response.status_code == 400 and refused:
                                for key in refused:
                                    payload.pop(key)
                                continue
                            yield f"data: {json.dumps({'error': f'LLM Provider returned error ({response.status_code}): {err_text[:200]}'})}\n\n"
                            yield "data: [DONE]\n\n"
                            return

                        async for line in response.aiter_lines():
                            if not line:
                                continue
                            if line.startswith("data: "):
                                raw_data = line[6:].strip()
                                if raw_data == "[DONE]":
                                    break
                                try:
                                    chunk = json.loads(raw_data)
                                    if chunk.get("model"):
                                        reported_model = chunk["model"]
                                    if chunk.get("usage"):
                                        usage = chunk["usage"]
                                    choices = chunk.get("choices", [])
                                    if choices:
                                        delta = choices[0].get("delta", {})
                                        for kind, text in splitter.feed(delta):
                                            yield f"data: {json.dumps({kind: text})}\n\n"
                                except Exception:
                                    continue

                        for kind, text in splitter.flush():
                            yield f"data: {json.dumps({kind: text})}\n\n"

                        # What the answer cost, for the widget to show on
                        # request. Token counts are left out rather than sent
                        # as zeros when the endpoint reported none.
                        meta = {"model": reported_model or model_name}
                        if usage:
                            if usage.get("prompt_tokens") is not None:
                                meta["tokens_in"] = usage["prompt_tokens"]
                            if usage.get("completion_tokens") is not None:
                                meta["tokens_out"] = usage["completion_tokens"]
                        yield f"data: {json.dumps({'meta': meta})}\n\n"
                        break

            yield "data: [DONE]\n\n"

        except httpx.ConnectError:
            yield f"data: {json.dumps({'error': f'Cannot connect to LLM server at {endpoint}. Ensure Ollama or custom API is running.'})}\n\n"
            yield "data: [DONE]\n\n"
        except httpx.TimeoutException:
            yield f"data: {json.dumps({'error': 'LLM request timed out. Please try again.'})}\n\n"
            yield "data: [DONE]\n\n"
        except Exception as e:
            yield f"data: {json.dumps({'error': f'Unexpected streaming error: {str(e)}'})}\n\n"
            yield "data: [DONE]\n\n"

    @classmethod
    async def fetch_models(cls, base_url: str, api_key: str = "") -> Dict[str, Any]:
        """Fetch available models from Ollama or OpenAI-compatible endpoints."""
        raw = base_url.strip().rstrip("/")
        if not raw:
            return {"success": False, "models": [], "message": "Base URL cannot be empty."}

        # Derive root and v1 URLs
        if raw.endswith("/chat/completions"):
            raw = raw[:-17].rstrip("/")
        if raw.endswith("/v1"):
            root_url = raw[:-3].rstrip("/")
            v1_url = raw
        else:
            root_url = raw
            v1_url = f"{raw}/v1"

        headers = {"Content-Type": "application/json"}
        if api_key and api_key.strip():
            headers["Authorization"] = f"Bearer {api_key.strip()}"

        # Determine candidate URLs based on whether it looks like local Ollama or remote
        is_local = "localhost" in raw or "127.0.0.1" in raw or "11434" in raw or not api_key
        if is_local:
            candidates = [
                f"{root_url}/api/tags",      # Native Ollama endpoint
                f"{v1_url}/models",          # Ollama OpenAI-compatible endpoint
                f"{raw}/models"
            ]
        else:
            candidates = [
                f"{raw}/models",             # Standard OpenAI / remote endpoint
                f"{v1_url}/models",
                f"{root_url}/api/tags"
            ]

        # Remove duplicates while preserving order
        seen = set()
        unique_candidates = []
        for c in candidates:
            if c not in seen:
                seen.add(c)
                unique_candidates.append(c)

        last_error = ""
        client_timeout = httpx.Timeout(connect=6.0, read=15.0, write=6.0, pool=6.0)

        async with httpx.AsyncClient(timeout=client_timeout) as client:
            for url in unique_candidates:
                try:
                    res = await client.get(url, headers=headers)
                    if res.status_code == 200:
                        try:
                            data = res.json()
                        except Exception:
                            continue

                        models = []
                        # Ollama format: {"models": [{"name": "llama3.2:latest", ...}]}
                        if isinstance(data, dict) and "models" in data and isinstance(data["models"], list):
                            for m in data["models"]:
                                if isinstance(m, dict) and "name" in m:
                                    models.append(m["name"])
                                elif isinstance(m, str):
                                    models.append(m)

                        # OpenAI format: {"data": [{"id": "gpt-4o", ...}]}
                        if not models and isinstance(data, dict) and "data" in data and isinstance(data["data"], list):
                            for m in data["data"]:
                                if isinstance(m, dict) and "id" in m:
                                    models.append(m["id"])
                                elif isinstance(m, str):
                                    models.append(m)

                        if models:
                            return {
                                "success": True,
                                "models": models,
                                "count": len(models),
                                "source_url": url,
                                "message": f"Successfully retrieved {len(models)} model(s)."
                            }
                    elif res.status_code == 401:
                        return {
                            "success": False,
                            "models": [],
                            "message": "Authentication failed (HTTP 401). Please verify your API Key."
                        }
                    else:
                        last_error = f"HTTP {res.status_code} from {url}: {res.text[:120]}"
                except httpx.ConnectError:
                    last_error = f"Cannot connect to {url}. Ensure server is running."
                except httpx.TimeoutException:
                    last_error = f"Timed out connecting to {url}."
                except Exception as e:
                    err_str = str(e) if str(e).strip() else type(e).__name__
                    last_error = f"Error with {url}: {err_str}"

        return {
            "success": False,
            "models": [],
            "message": f"Connection failed: {last_error or 'No response from model endpoints.'}"
        }

    @classmethod
    async def test_connection(cls, base_url: str, api_key: str, model_name: str) -> Dict[str, Any]:
        """Test whether the LLM endpoint is reachable and responsive."""
        if not model_name or not model_name.strip():
            return {"success": False, "message": "Please specify or select a Model Name first."}

        endpoint = cls._normalize_endpoint(base_url)
        headers = {"Content-Type": "application/json"}
        if api_key and api_key.strip():
            headers["Authorization"] = f"Bearer {api_key.strip()}"

        payload = {
            "model": model_name.strip(),
            "messages": [{"role": "user", "content": "Hi"}],
            "max_tokens": 5,
            "stream": False
        }

        # Generous 60s timeout for cold model weights loading in Ollama/vLLM
        client_timeout = httpx.Timeout(connect=10.0, read=60.0, write=10.0, pool=10.0)
        try:
            async with httpx.AsyncClient(timeout=client_timeout) as client:
                res = await client.post(endpoint, headers=headers, json=payload)
                if res.status_code == 200:
                    return {"success": True, "message": f"Connection successful! Model '{model_name}' responded."}
                return {
                    "success": False, 
                    "message": f"Server responded with status {res.status_code}: {res.text[:150]}"
                }
        except httpx.TimeoutException:
            return {
                "success": False,
                "message": f"Connection timed out (60s). Ollama may be loading large model weights '{model_name}' into memory. Please retry in a moment."
            }
        except httpx.ConnectError:
            return {
                "success": False,
                "message": f"Cannot connect to server at {endpoint}. Ensure Ollama or your LLM service is running."
            }
        except Exception as e:
            err_msg = str(e) if str(e).strip() else type(e).__name__
            return {"success": False, "message": f"Connection failed: {err_msg}"}
