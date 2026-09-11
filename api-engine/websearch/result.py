"""The one shape every provider is reduced to.

Adapters differ wildly in what they return. Nothing provider-specific is
allowed past this type, which is what keeps the chat route from caring which
service answered.
"""
from dataclasses import dataclass


@dataclass
class SearchResult:
    title: str
    url: str
    text: str
