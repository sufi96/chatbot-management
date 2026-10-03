"""Reciprocal Rank Fusion.

Merges rankings by position rather than score. A vector similarity and a BM25
score are not comparable numbers, so averaging them is meaningless; their ranks
always are.

A branch can carry a weight. 1 for every branch is plain RRF; a bot whose
visitors ask by product code can lean on keywords, and one whose visitors
paraphrase can lean on meaning, without either branch's scores ever being
compared with the other's.
"""


def fuse_rankings(branches: list[list[str]], k: int = 60,
                  weights: list[float] | None = None) -> list[tuple[str, float]]:
    scores: dict[str, float] = {}
    for index, branch in enumerate(branches):
        weight = 1.0 if weights is None else float(weights[index])
        if weight <= 0:
            continue
        for rank, item_id in enumerate(branch, start=1):
            scores[item_id] = scores.get(item_id, 0.0) + weight / (k + rank)

    return sorted(scores.items(), key=lambda pair: pair[1], reverse=True)
