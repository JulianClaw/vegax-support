#!/usr/bin/env python3
"""Verify the WordPress.org submission zip before uploading.

Checks the things WordPress.org's readme parser and Plugin Check care about:
  * exactly one top-level folder, no editor/backup junk inside the zip
  * header block complete (Contributors / Tags<=5 / Requires / Tested / Stable tag / License)
  * short description <= 150 chars, on the line after the header block
  * all listing sections present, incl. the mandatory "External services"
  * version consistent across plugin header, PHP constant and Stable tag
  * licence declared and a LICENSE file shipped
  * no engine-name ("AI") leakage in user-facing text

Usage: python3 verify_submission.py [dist/...zip]
"""
import hashlib
import os
import re
import sys
import zipfile

REQUIRED_SECTIONS = [
    "== Description ==",
    "== External services ==",
    "== Installation ==",
    "== Frequently Asked Questions ==",
    "== Changelog ==",
    "== Upgrade Notice ==",
]
OPTIONAL_SECTIONS = ["== Screenshots =="]
HEADER_KEYS = ["Contributors", "Tags", "Requires at least", "Tested up to",
               "Requires PHP", "Stable tag", "License", "License URI"]


def main(path):
    failures = []
    z = zipfile.ZipFile(path)
    names = z.namelist()

    tops = {n.split("/")[0] for n in names}
    print(f"zip      : {path}  ({os.path.getsize(path)} bytes, {len(names)} files)")
    print(f"top-level: {tops}")
    if len(tops) != 1:
        failures.append("zip must contain exactly one top-level folder")

    slug = tops.pop() if len(tops) == 1 else "vegax-support"
    junk = [n for n in names if n.startswith(".") or ".bak" in n or n.endswith("~")
            or "__MACOSX" in n or "Thumbs.db" in n]
    print(f"junk     : {junk or 'none'}")
    if junk:
        failures.append(f"junk files in zip: {junk}")

    rd = z.read(f"{slug}/readme.txt").decode("utf-8")
    main_php = [n for n in names if n.endswith(f"/{slug}.php") and n.count("/") == 1]
    php = z.read(main_php[0]).decode("utf-8") if main_php else ""

    lines = rd.split("\n")
    i = 0
    while i < len(lines) and lines[i].strip():
        i += 1
    header = dict()
    for ln in lines[:i]:
        if ":" in ln:
            k, v = ln.split(":", 1)
            header[k.strip()] = v.strip()
    print(f"name     : {lines[0]}")
    for k in HEADER_KEYS:
        if k not in header:
            failures.append(f"readme header missing '{k}'")

    tags = [t.strip() for t in header.get("Tags", "").split(",") if t.strip()]
    print(f"tags     : {len(tags)} {tags}")
    if not 1 <= len(tags) <= 5:
        failures.append(f"Tags must be 1..5, found {len(tags)}")

    short = lines[i + 1].strip() if i + 1 < len(lines) else ""
    print(f"shortdesc: {len(short)} chars -> {short}")
    if not short or len(short) > 150 or "<" in short:
        failures.append("short description must be plain text, <=150 chars")

    for sec in REQUIRED_SECTIONS:
        ok = sec in rd
        print(f"section  : {sec:34s} {'ok' if ok else 'MISSING'}")
        if not ok:
            failures.append(f"missing section {sec}")

    for sec in OPTIONAL_SECTIONS:
        ok = sec in rd
        print(f"section  : {sec:34s} {'ok (optional)' if ok else 'absent (skipped)'}")

    ver_header = re.search(r"^ \* Version:\s*(\S+)", php, re.M)
    ver_const = re.search(r"VEGAX_AIS_VERSION', '([^']+)'", php)
    ver_stable = header.get("Stable tag")
    versions = {ver_header.group(1) if ver_header else None,
                ver_const.group(1) if ver_const else None,
                ver_stable}
    print(f"versions : header={ver_header.group(1) if ver_header else None} "
          f"const={ver_const.group(1) if ver_const else None} stable={ver_stable}")
    if len(versions) != 1:
        failures.append(f"version mismatch: {versions}")

    if "GPL" not in header.get("License", "") and "GPL" not in header.get("License URI", ""):
        failures.append("licence not declared as GPL")
    if not any(n.endswith("/LICENSE") for n in names):
        failures.append("no LICENSE file in the package")

    ai = len(re.findall(r"\bAI\b", rd))
    print(f"engine   : 'AI' occurrences in readme = {ai}")
    if ai:
        failures.append(f"readme still mentions the engine name {ai}x")

    md5 = hashlib.md5(open(path, "rb").read()).hexdigest()
    print(f"md5      : {md5}")

    print()
    if failures:
        print("RESULT: FAIL")
        for f in failures:
            print("  - " + f)
        return 1
    print("RESULT: PASS")
    return 0


if __name__ == "__main__":
    target = sys.argv[1] if len(sys.argv) > 1 else "dist/vegax-support-1.0.8.zip"
    sys.exit(main(target))
