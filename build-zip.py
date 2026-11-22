#!/usr/bin/env python3
"""Packs the plugin into dist/hurrry-checkout-<version>.zip, ready for the
WordPress uploader.

WordPress expects a single top-level folder inside the zip, so every file is
stored under "hurrry-checkout/". The version is read from the plugin header so
the zip name always says what is inside, and WordPress offers "replace current\nwith uploaded" because the uploaded version is newer.

    python build-zip.py

It also writes dist/update.json, the manifest the installed plugins read to
update themselves. Upload both files to the CDN, the zip first:

    https://cdn.hurrypayments.com/wordpress/hurrry-checkout-<version>.zip
    https://cdn.hurrypayments.com/wordpress/update.json

The manifest carries the digest of this exact zip, signed with the release
key (see sign-update.js): the two files always travel together, and a zip
that is already on the CDN is never replaced by a rebuilt one.
"""

import hashlib
import html
import json
import os
import re
import subprocess
import zipfile

SLUG = "hurrry-checkout"
ROOT = os.path.dirname(os.path.abspath(__file__))
DIST = os.path.join(ROOT, "dist")

# Anything that must never reach a production site.
SKIP_DIRS = {".git", "dist", "node_modules", ".idea", ".vscode", "__pycache__"}
SKIP_FILES = {"build-zip.py", "sign-update.js", ".gitignore", ".DS_Store"}

CDN_BASE = "https://cdn.hurrypayments.com/wordpress/"


def plugin_version():
    with open(os.path.join(ROOT, SLUG + ".php"), encoding="utf-8") as fh:
        head = fh.read(4000)
    m = re.search(r"^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)", head, re.M)
    if not m:
        raise SystemExit("Version header not found in %s.php" % SLUG)
    return m.group(1)


def readme_field(readme, label):
    m = re.search(r"^%s:\s*([0-9][0-9.]*)\s*$" % re.escape(label), readme, re.M)
    return m.group(1) if m else ""


def changelog_html(readme, version):
    """The "= <version> =" block of the readme, as the list WordPress shows."""
    m = re.search(r"^= %s =\s*\n(.*?)(?=^= |\Z)" % re.escape(version), readme, re.M | re.S)
    if not m:
        return ""
    items = [line.strip()[1:].strip() for line in m.group(1).splitlines() if line.strip().startswith("*")]
    return "<ul>%s</ul>" % "".join("<li>%s</li>" % html.escape(i) for i in items) if items else ""


def sign(message):
    """Base64 Ed25519 signature from sign-update.js, or None without Node or key."""
    try:
        done = subprocess.run(
            ["node", os.path.join(ROOT, "sign-update.js"), "sign", message],
            capture_output=True, text=True, check=False,
        )
    except OSError:
        print("\n! Node.js not found: update.json was NOT written.")
        return None
    if done.returncode != 0:
        print("\n! " + done.stderr.strip() + "\n! update.json was NOT written.")
        return None
    return done.stdout.strip()


def write_manifest(version, zip_path):
    with open(zip_path, "rb") as fh:
        digest = hashlib.sha256(fh.read()).hexdigest()
    signature = sign("%s|%s|%s" % (SLUG, version, digest))
    if not signature:
        return

    with open(os.path.join(ROOT, "readme.txt"), encoding="utf-8") as fh:
        readme = fh.read().replace("\r\n", "\n")

    manifest = {
        SLUG: {
            "version": version,
            "package": "%s%s-%s.zip" % (CDN_BASE, SLUG, version),
            "sha256": digest,
            "signature": signature,
            "requires": readme_field(readme, "Requires at least"),
            "requires_php": readme_field(readme, "Requires PHP"),
            "tested": readme_field(readme, "Tested up to"),
            "changelog": changelog_html(readme, version),
        }
    }
    out = os.path.join(DIST, "update.json")
    with open(out, "w", encoding="utf-8", newline="\n") as fh:
        json.dump(manifest, fh, indent=2, ensure_ascii=False)
        fh.write("\n")
    print("manifest -> %s" % out)
    print("\nUpload to %s (zip first, then update.json):" % CDN_BASE)
    print("  %s" % os.path.basename(zip_path))
    print("  update.json")


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
    write_manifest(version, out)


if __name__ == "__main__":
    main()
