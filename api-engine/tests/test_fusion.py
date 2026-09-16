from kb.fusion import fuse_rankings


def test_single_branch_preserves_order():
    result = fuse_rankings([["a", "b", "c"]])
    assert [item for item, _ in result] == ["a", "b", "c"]


def test_top_of_one_branch_scores_one_over_sixty_one():
    result = fuse_rankings([["a"]])
    assert abs(result[0][1] - 1 / 61) < 1e-9


def test_appearing_in_both_branches_outranks_either_alone():
    result = fuse_rankings([["a", "b"], ["c", "b"]])
    ranked = [item for item, _ in result]
    assert ranked[0] == "b"


def test_empty_branches_yield_nothing():
    assert fuse_rankings([]) == []
    assert fuse_rankings([[], []]) == []


def test_ignores_an_empty_branch():
    result = fuse_rankings([["a", "b"], []])
    assert [item for item, _ in result] == ["a", "b"]


def test_scores_descend():
    result = fuse_rankings([["a", "b", "c"], ["b", "a"]])
    scores = [score for _, score in result]
    assert scores == sorted(scores, reverse=True)
