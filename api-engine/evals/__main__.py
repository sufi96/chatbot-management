"""Run an evaluation set against a running engine.

From api-engine/:

    .venv\\Scripts\\python.exe -m evals evals/sets/corporate-support-smoke.json
    .venv\\Scripts\\python.exe -m evals evals/sets/corporate-support-smoke.json \\
        --judge-provider "Laptop vLLM - 8GB VRAM" --judge-model qwen3.5-4b

Exits 0 when every case passes, 1 when any fails, 2 when the set cannot be run.
"""
import argparse
import asyncio
import json
import sys
from datetime import datetime
from pathlib import Path

from evals.cases import load_set
from evals.checks import failures
from evals.judge import judge
from evals.report import Result, summarise, to_json, to_markdown
from evals.stream import ask

REPORTS = Path(__file__).parent / "reports"
SESSION_CHARS = 100


def parse_arguments(argv=None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="python -m evals", description="Run an evaluation set against a running engine.")
    parser.add_argument("set", help="Path to the evaluation set, a JSON file.")
    parser.add_argument("--engine", default="http://127.0.0.1:8000",
                        help="Where the engine is listening.")
    parser.add_argument("--judge-provider",
                        help="The AI provider to judge with, named as it is in the portal.")
    parser.add_argument("--judge-model", help="The model to judge with, on that provider.")
    parser.add_argument("--out", default=str(REPORTS), help="Directory for the reports.")

    args = parser.parse_args(argv)
    if bool(args.judge_provider) != bool(args.judge_model):
        parser.error("--judge-provider and --judge-model go together.")

    return args


async def judge_endpoint(provider_name: str) -> tuple[str, str]:
    """The base URL and key of the provider with this name, read from the portal's table.

    Named rather than typed in, so a key never has to appear on a command line
    or in shell history.
    """
    from sqlalchemy import select

    import database

    await database.init_db()
    async with database.async_session_factory() as session:
        rows = (await session.execute(
            select(database.AiProvider).where(database.AiProvider.name == provider_name))
        ).scalars().all()

    if not rows:
        raise ValueError(f"No AI provider is named {provider_name!r}.")
    if len({(row.base_url, row.api_key) for row in rows}) > 1:
        raise ValueError(f"More than one AI provider is named {provider_name!r}, with different "
                         "endpoints. Rename one in the portal.")

    return rows[0].base_url, rows[0].api_key or ""


async def run(args: argparse.Namespace) -> int:
    eval_set = load_set(args.set)
    endpoint = await judge_endpoint(args.judge_provider) if args.judge_provider else None
    started = datetime.now().strftime("%Y%m%d-%H%M%S")

    # One at a time: cases run side by side would slow each other down, and
    # the timings would measure the evaluation rather than the bot.
    results: list[Result] = []
    for number, case in enumerate(eval_set.cases, start=1):
        session_id = f"eval-{eval_set.name}-{started}-{case.id}"[:SESSION_CHARS]
        observed = await ask(args.engine, eval_set.bot_id, case, session_id)
        judgement = (await judge(case, observed.answer, endpoint[0], endpoint[1], args.judge_model)
                     if endpoint else None)

        result = Result(case, observed, failures(case, observed), judgement)
        results.append(result)
        print(f"[{number}/{len(eval_set.cases)}] {case.id}: "
              f"{'pass' if result.passed else 'FAIL'} in {observed.total_seconds:.2f}s")

    run_info = {"started": started, "engine": args.engine,
                "judge": f"{args.judge_model} via {args.judge_provider}" if endpoint else ""}

    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    markdown_path = out / f"{eval_set.name}-{started}.md"
    markdown_path.write_text(to_markdown(eval_set, results, run_info), encoding="utf-8")
    (out / f"{eval_set.name}-{started}.json").write_text(
        json.dumps(to_json(eval_set, results, run_info), indent=2, ensure_ascii=False),
        encoding="utf-8")

    summary = summarise(results)
    print(f"{summary['passed']} of {summary['cases']} passed. Report: {markdown_path}")

    return 0 if summary["failed"] == 0 else 1


def main(argv=None) -> int:
    args = parse_arguments(argv)
    try:
        return asyncio.run(run(args))
    except (ValueError, OSError) as error:
        print(f"Cannot run the evaluation: {error}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
