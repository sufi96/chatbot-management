"""What a run found: a summary to read, and a JSON record to compare runs by."""
import statistics
from dataclasses import dataclass


@dataclass
class Result:
    case: object
    observed: object
    failures: list
    judgement: object = None

    @property
    def passed(self) -> bool:
        return not self.failures


def _nearest_rank(values: list[float], fraction: float) -> float:
    ordered = sorted(values)
    return ordered[min(len(ordered) - 1, round(fraction * (len(ordered) - 1)))]


def _rounded(value):
    return None if value is None else round(value, 3)


def _seconds(value) -> str:
    return "—" if value is None else f"{value:.2f} s"


def summarise(results: list[Result]) -> dict:
    first = [r.observed.first_token_seconds for r in results
             if r.observed.first_token_seconds is not None]
    totals = [r.observed.total_seconds for r in results]
    scores = [r.judgement.score for r in results
              if r.judgement is not None and r.judgement.score is not None]
    passed = sum(1 for r in results if r.passed)

    return {
        "cases": len(results),
        "passed": passed,
        "failed": len(results) - passed,
        "median_first_token_seconds": round(statistics.median(first), 2) if first else None,
        # Nearest rank rather than interpolated: with a handful of cases, the
        # slowest real answer says more than a number between two of them.
        "p90_total_seconds": round(_nearest_rank(totals, 0.9), 2) if totals else None,
        "mean_judge_score": round(statistics.mean(scores), 2) if scores else None,
        "judged": len(scores),
    }


def to_json(eval_set, results: list[Result], run: dict) -> dict:
    return {
        "set": eval_set.name,
        "bot_id": eval_set.bot_id,
        "run": run,
        "summary": summarise(results),
        "cases": [{
            "id": r.case.id,
            "message": r.case.message,
            "passed": r.passed,
            "failures": list(r.failures),
            "source": r.observed.source,
            "citations": list(r.observed.citations),
            "model": r.observed.model,
            "tokens_in": r.observed.tokens_in,
            "tokens_out": r.observed.tokens_out,
            "first_token_seconds": _rounded(r.observed.first_token_seconds),
            "total_seconds": _rounded(r.observed.total_seconds),
            "answer": r.observed.answer,
            "error": r.observed.error,
            "judge_score": r.judgement.score if r.judgement else None,
            "judge_reason": r.judgement.reason if r.judgement else "",
        } for r in results],
    }


def to_markdown(eval_set, results: list[Result], run: dict) -> str:
    summary = summarise(results)
    judged = (f"{summary['mean_judge_score']} across {summary['judged']} judged"
              if summary["judged"] else "not judged")

    lines = [
        f"# Evaluation: {eval_set.name}",
        "",
        f"Bot `{eval_set.bot_id}` on {run.get('engine', '')}, started {run.get('started', '')}. "
        f"Judge: {run.get('judge') or 'none'}.",
        "",
        f"**{summary['passed']} of {summary['cases']} passed.** "
        f"Median first token {_seconds(summary['median_first_token_seconds'])}, "
        f"p90 total {_seconds(summary['p90_total_seconds'])}, judge score {judged}.",
        "",
        "| Case | Result | Source | First token | Total | Tokens out | Judge |",
        "|---|---|---|---|---|---|---|",
    ]

    for r in results:
        score = r.judgement.score if (r.judgement and r.judgement.score is not None) else "—"
        tokens = r.observed.tokens_out if r.observed.tokens_out is not None else "—"
        lines.append(f"| {r.case.id} | {'pass' if r.passed else 'FAIL'} | {r.observed.source} | "
                     f"{_seconds(r.observed.first_token_seconds)} | "
                     f"{_seconds(r.observed.total_seconds)} | {tokens} | {score} |")

    failed = [r for r in results if not r.passed]
    if failed:
        lines += ["", "## Failures"]
        for r in failed:
            lines += ["", f"### {r.case.id}", "", f"Asked: {r.case.message}", ""]
            lines += [f"- {reason}" for reason in r.failures]
            lines += ["", "Answer given:", ""]
            lines += [f"> {line}" if line else ">"
                      for line in (r.observed.answer or "(no answer)").splitlines()]
            if r.judgement and r.judgement.reason:
                lines += ["", f"Judge: {r.judgement.reason}"]

    return "\n".join(lines) + "\n"
