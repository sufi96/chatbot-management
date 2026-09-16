"""Reciprocal Rank Fusion.

Merges rankings by position rather than score. A vector similarity and a BM25
score are not comparable numbers, so averaging them is meaningless; their ranks
always are.
"""


def fuse_rankings(branches: list[list[str]], k: int = 60) -> list[tuple[str, float]]:
    scores: dict[str, float] = {}
    for branch in branches:
        for rank, item_id in enumerate(branch, start=1):
            scores[item_id] = scores.get(item_id, 0.0) + 1.0 / (k + rank)

    return sorted(scores.items(), key=lambda pair: pair[1], reverse=True)
