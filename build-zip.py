#!/usr/bin/env python3
"""Packs the plugin into dist/hurrry-checkout.zip, ready for the WordPress uploader.

WordPress expects a single top-level folder inside the zip, so every file is
stored under "hurrry-checkout/".

    python build-zip.py
"""

import os
import zipfile

SLUG = "hurrry-checkout"
ROOT = os.path.dirname(os.path.abspath(__file__))
DIST = os.path.join(ROOT, "dist")
OUT = os.path.join(DIST, SLUG + ".zip")

# Anything that must never reach a production site.
SKIP_DIRS = {".git", "dist", "node_modules", ".idea", ".vscode", "__pycache__"}
SKIP_FILES = {"build-zip.py", ".gitignore", ".DS_Store"}


def main():
    os.makedirs(DIST, exist_ok=True)
    count = 0

    with zipfile.ZipFile(OUT, "w", zipfile.ZIP_DEFLATED) as zf:
        for dirpath, dirnames, filenames in os.walk(ROOT):
            dirnames[:] = [d for d in dirnames if d not in SKIP_DIRS]
            for name in sorted(filenames):
                if name in SKIP_FILES:
                    continue
                abs_path = os.path.join(dirpath, name)
                rel_path = os.path.relpath(abs_path, ROOT).replace(os.sep, "/")
                zf.write(abs_path, SLUG + "/" + rel_path)
                count += 1
                print("  +", rel_path)

    print("\n%d files -> %s" % (count, OUT))


if __name__ == "__main__":
    main()
