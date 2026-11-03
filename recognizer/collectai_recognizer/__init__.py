"""collectAI's local car recognizer.

Finds diecast cars in a photo, reads what is printed on them with Tesseract,
and matches that against the catalog index the Laravel app exports. Runs on
the server or on your own computer; it never calls a remote API.
"""

__version__ = "1.0.0"
