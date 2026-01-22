#!/usr/bin/env python3
"""
Stage a clean WordPress plugin folder into ./distribution/agile-alliance-ad-manager/.

This copies a curated set of runtime files/directories from the repo working tree,
which avoids drifting exclude-lists and ensures untracked-but-required files are included.

Usage:
  python scripts/stage_dist.py
  python scripts/stage_dist.py --out-root distribution --plugin-slug agile-alliance-ad-manager
"""

from __future__ import annotations

import argparse
import os
import shutil
import sys
from pathlib import Path


DEFAULT_PLUGIN_SLUG = "agile-alliance-ad-manager"
DEFAULT_OUT_ROOT = "distribution"

# Curated runtime include set (relative to repo root).
INCLUDE_PATHS = [
    "agile-alliance-ad-manager.php",
    "includes",
    "assets",
    "acf-json",
    "acf-export",
    "README.md",
]


def _repo_root_from_script() -> Path:
    return Path(__file__).resolve().parents[1]


def _copy_tree(src: Path, dst: Path) -> None:
    # shutil.copytree() requires dst not exist.
    shutil.copytree(src, dst, dirs_exist_ok=False, copy_function=shutil.copy2)


def _copy_file(src: Path, dst: Path) -> None:
    dst.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(src, dst)


def stage_dist(repo_root: Path, out_root: Path, plugin_slug: str) -> Path:
    out_root = out_root.resolve()
    stage_dir = out_root / plugin_slug

    if stage_dir.exists():
        shutil.rmtree(stage_dir)

    stage_dir.mkdir(parents=True, exist_ok=True)

    copied_any = False
    missing = []
    for rel in INCLUDE_PATHS:
        src = repo_root / rel
        if not src.exists():
            missing.append(rel)
            continue

        dst = stage_dir / rel
        if src.is_dir():
            _copy_tree(src, dst)
        else:
            _copy_file(src, dst)
        copied_any = True

    if not copied_any:
        raise RuntimeError("Nothing was staged; include list produced zero files.")

    # Validate required runtime pieces exist.
    entry = stage_dir / "agile-alliance-ad-manager.php"
    if not entry.exists():
        raise RuntimeError(f"Missing plugin entry file after staging: {entry}")

    for required_dir in ("includes", "assets"):
        p = stage_dir / required_dir
        if not p.exists() or not p.is_dir():
            raise RuntimeError(f"Missing required directory after staging: {p}")

    if missing:
        # Non-fatal: allow staging even if optional files aren't present.
        print("Note: Some optional include paths were missing and were skipped:", file=sys.stderr)
        for rel in missing:
            print(f"  - {rel}", file=sys.stderr)

    return stage_dir


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description="Stage a clean plugin folder into distribution/")
    parser.add_argument("--out-root", default=DEFAULT_OUT_ROOT, help="Output root folder (default: distribution)")
    parser.add_argument("--plugin-slug", default=DEFAULT_PLUGIN_SLUG, help="Plugin folder name inside output root")
    args = parser.parse_args(argv)

    repo_root = _repo_root_from_script()
    out_root = (repo_root / args.out_root)

    staged = stage_dist(repo_root=repo_root, out_root=out_root, plugin_slug=args.plugin_slug)
    print(f"Staged plugin to: {staged}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))

