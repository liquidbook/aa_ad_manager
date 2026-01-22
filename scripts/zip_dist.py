#!/usr/bin/env python3
"""
Zip a staged WordPress plugin folder from ./distribution/agile-alliance-ad-manager/
into ./distribution/agile-alliance-ad-manager.zip.

Important: WordPress expects the zip root to contain a single folder whose name
matches the plugin slug (e.g. agile-alliance-ad-manager/...), not a flat list of files.

Usage:
  python scripts/zip_dist.py
  python scripts/zip_dist.py --out-root distribution --plugin-slug agile-alliance-ad-manager
"""

from __future__ import annotations

import argparse
import os
import sys
import zipfile
from pathlib import Path
import posixpath


DEFAULT_PLUGIN_SLUG = "agile-alliance-ad-manager"
DEFAULT_OUT_ROOT = "distribution"


def _iter_files(root: Path):
    for p in root.rglob("*"):
        if p.is_file():
            yield p


def zip_dist(out_root: Path, plugin_slug: str) -> Path:
    out_root = out_root.resolve()
    stage_dir = out_root / plugin_slug
    if not stage_dir.exists() or not stage_dir.is_dir():
        raise RuntimeError(f"Staged folder does not exist: {stage_dir} (run scripts/stage_dist.py first)")

    zip_path = out_root / f"{plugin_slug}.zip"
    if zip_path.exists():
        zip_path.unlink()

    staged_root = stage_dir.resolve()

    with zipfile.ZipFile(zip_path, mode="w", compression=zipfile.ZIP_DEFLATED) as zf:
        for file_path in _iter_files(staged_root):
            rel = file_path.relative_to(staged_root)
            rel_posix = "/".join(rel.parts)
            arcname = posixpath.join(plugin_slug, rel_posix) if rel_posix else plugin_slug
            zf.write(file_path, arcname=arcname)

    # Validate zip contents include the plugin entry at the correct path.
    expected_entry = f"{plugin_slug}/agile-alliance-ad-manager.php"
    with zipfile.ZipFile(zip_path, mode="r") as zf:
        names = set(zf.namelist())
        if expected_entry not in names:
            raise RuntimeError(
                "Zip validation failed. Expected entry missing: "
                f"{expected_entry}. First entries: {sorted(list(names))[:20]}"
            )

    return zip_path


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description="Zip a staged plugin folder into a WP-installable zip")
    parser.add_argument("--out-root", default=DEFAULT_OUT_ROOT, help="Output root folder (default: distribution)")
    parser.add_argument("--plugin-slug", default=DEFAULT_PLUGIN_SLUG, help="Plugin folder name inside output root")
    args = parser.parse_args(argv)

    repo_root = Path(__file__).resolve().parents[1]
    out_root = repo_root / args.out_root
    zip_path = zip_dist(out_root=out_root, plugin_slug=args.plugin_slug)
    print(f"Wrote zip: {zip_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))

