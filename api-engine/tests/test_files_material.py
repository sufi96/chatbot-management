"""The block of attached-file material the answer model reads."""
from types import SimpleNamespace

from files import material


def a_file(name, text=None, note="", flag=None):
    return SimpleNamespace(name=name, text=text, read_error=None if text else "x", flag=flag, note=note)


def test_a_file_that_fits_goes_whole():
    block = material.block([a_file("menu.txt", "Nasi lemak RM5. Teh tarik RM2.")], "price of teh tarik", 4000)

    assert "Files the visitor attached" in block
    assert "menu.txt" in block and "Nasi lemak RM5. Teh tarik RM2." in block


def test_a_long_file_sends_the_passages_about_the_question():
    filler = " ".join(["The weather was mild and nothing happened."] * 400)
    text = filler + " The refund policy allows returns within 14 days. " + filler

    block = material.block([a_file("policy.txt", text)], "what is the refund policy", 2500)

    assert "refund policy allows returns within 14 days" in block
    assert len(block) < 3200


def test_an_unreadable_file_tells_the_model_not_to_guess():
    block = material.block([a_file("scan.png")], "what does it say", 4000)

    assert "scan.png" in block and "could not be read" in block and "guess" in block


def test_no_files_is_no_block():
    assert material.block([], "anything", 4000) == ""
