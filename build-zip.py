#!/usr/bin/env python3
"""Packs the plugin into dist/hurrry-checkout-<version>.zip, ready for the
WordPress uploader.

WordPress expects a single top-level folder inside the zip, so every file is
stored under "hurrry-checkout/". The version is read from the plugin header so
the zip name always says what is inside, and WordPress offers "replace current
with uploaded" because the uploaded version is newer.

    python build-zip.py
"""

import os
import re
import zipfile

SLUG = "hurrry-checkout"
ROOT = os.path.dirname(os.path.abspath(__file__))
DIST = os.path.join(ROOT, "dist")

# Anything that must never reach a production site.
SKIP_DIRS = {".git", "dist", "node_modules", ".idea", ".vscode", "__pycache__"}
SKIP_FILES = {"build-zip.py", ".gitignore", ".DS_Store"}


def plugin_version():
    with open(os.path.join(ROOT, SLUG + ".php"), encoding="utf-8") as fh:
        head = fh.read(4000)
    m = re.search(r"^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)", head, re.M)
    if not m:
        raise SystemExit("Version header not found in %s.php" % SLUG)
    return m.group(1)


def main():
    version = plugin_version()
    out = os.path.join(DIST, "%s-%s.zip" % (SLUG, version))
    os.makedirs(DIST, exist_ok=True)
    count = 0

    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as zf:
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

    print("\n%d files -> %s" % (count, out))


if __name__ == "__main__":
    main()
