#!/usr/bin/env python3
"""
Hybrid release builder:
- If the git working tree is clean, prefer `git archive` to produce a zip (fast + reproducible).
- Otherwise, fall back to staging from the working tree and zipping (includes untracked changes).

Outputs to ./distribution/agile-alliance-ad-manager.zip by default.

Usage:
  python scripts/build_release.py
  python scripts/build_release.py --mode auto|git|stage
"""

from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path

from stage_dist import stage_dist
from zip_dist import zip_dist


DEFAULT_PLUGIN_SLUG = "agile-alliance-ad-manager"
DEFAULT_OUT_ROOT = "distribution"


def _run(cmd: list[str], cwd: Path) -> subprocess.CompletedProcess:
    return subprocess.run(cmd, cwd=str(cwd), capture_output=True, text=True)


def _git_available(repo_root: Path) -> bool:
    return (repo_root / ".git").exists()


def _git_is_clean(repo_root: Path) -> bool:
    # Empty output means clean (no modified/added/untracked).
    cp = _run(["git", "status", "--porcelain"], cwd=repo_root)
    if cp.returncode != 0:
        return False
    return cp.stdout.strip() == ""


def _git_archive_zip(repo_root: Path, out_root: Path, plugin_slug: str) -> Path:
    out_root.mkdir(parents=True, exist_ok=True)
    zip_path = out_root / f"{plugin_slug}.zip"
    cp = _run(
        ["git", "archive", "--format=zip", f"--prefix={plugin_slug}/", "HEAD", "-o", str(zip_path)],
        cwd=repo_root,
    )
    if cp.returncode != 0:
        raise RuntimeError(f"git archive failed:\n{cp.stderr.strip()}\n{cp.stdout.strip()}")
    return zip_path


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description="Build a WP-installable plugin zip into distribution/")
    parser.add_argument("--mode", choices=["auto", "git", "stage"], default="auto")
    parser.add_argument("--out-root", default=DEFAULT_OUT_ROOT)
    parser.add_argument("--plugin-slug", default=DEFAULT_PLUGIN_SLUG)
    args = parser.parse_args(argv)

    repo_root = Path(__file__).resolve().parents[1]
    out_root = repo_root / args.out_root

    if args.mode in ("auto", "git") and _git_available(repo_root) and _git_is_clean(repo_root):
        zip_path = _git_archive_zip(repo_root=repo_root, out_root=out_root, plugin_slug=args.plugin_slug)
        print(f"Wrote zip via git archive: {zip_path}")
        return 0

    if args.mode == "git":
        raise SystemExit("Mode=git requested, but repo is not a clean git working tree.")

    # Fallback: stage from working tree + zip.
    staged = stage_dist(repo_root=repo_root, out_root=out_root, plugin_slug=args.plugin_slug)
    zip_path = zip_dist(out_root=out_root, plugin_slug=args.plugin_slug)
    print(f"Staged plugin to: {staged}")
    print(f"Wrote zip via staging: {zip_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))

