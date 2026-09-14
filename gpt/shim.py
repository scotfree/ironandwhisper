"""Locate Karpathy's dependency-free GPT and import it unmodified.

`karpathy.py` lives in the sibling lcgpt project, where the notebooks quote it
*by line number* and verify it byte-for-byte. So it is imported, never edited,
and never copied — a copy would drift silently and take the notebooks' anchor
with it.

Override the location with IAW_KARPATHY=/path/to/karpathy.py if the checkout
moves.
"""

from __future__ import annotations

import importlib.util
import os
from pathlib import Path

DEFAULT = Path(__file__).resolve().parents[3] / "lightningcatcher" / "lcgpt" / "karpathy.py"


def load_karpathy():
    path = Path(os.environ.get("IAW_KARPATHY", DEFAULT))
    if not path.exists():
        raise FileNotFoundError(
            f"karpathy.py not found at {path}. Set IAW_KARPATHY to its location."
        )
    spec = importlib.util.spec_from_file_location("karpathy", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


K = load_karpathy()
