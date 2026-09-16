"""Decide whether a message is worth searching the knowledge base for.

Deterministic on purpose. Asking a model to judge intent would double the wait
before the first token, and at the sizes that run locally it classifies badly.
A greeting is the case that actually occurs, and a fixed vocabulary catches it.
"""
import re

# Deliberately small, and containing no domain words. A term that could ever
# appear in a real question does not belong here.
SMALLTALK = frozenset({
    "hi", "hiya", "hello", "hey", "yo", "sup", "greetings",
    "morning", "afternoon", "evening",
    "thanks", "thank", "ty", "cheers", "appreciate", "appreciated",
    "bye", "goodbye", "later", "night",
    "ok", "okay", "sure", "yes", "yeah", "yep", "no", "nope",
    "cool", "great", "nice", "good", "well", "got", "it",
    "you", "there", "so", "much", "very", "a", "lot", "please",
})

WORD_RE = re.compile(r"[a-z]+")


def should_retrieve(message: str) -> bool:
    """False when the message cannot be a question worth searching for."""
    text = (message or "").strip()
    if not text:
        return False

    # Punctuated as a question, so search whatever the words are. This is what
    # stops the vocabulary ever swallowing a real question.
    if "?" in text:
        return True

    words = WORD_RE.findall(text.lower())
    if not words:
        return False
    if all(word in SMALLTALK for word in words):
        return False

    # A message with nothing longer than two letters cannot carry a question.
    return any(len(word) > 2 for word in words)
