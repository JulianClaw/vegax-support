#!/usr/bin/env python3
"""Syntax-check every PHP file in the plugin with tree-sitter (php -l substitute).

This host has no php binary, so we parse with tree-sitter and fail on any
ERROR / missing node. Run before every build:
    python3 php_syntax_check.py
"""
import glob
import os
import sys

import tree_sitter_php as pkg
from tree_sitter import Language, Parser

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "vegax-support")
parser = Parser(Language(pkg.language_php()))


def main():
    files = sorted(glob.glob(os.path.join(ROOT, "**", "*.php"), recursive=True))
    bad = 0
    for f in files:
        src = open(f, "rb").read()
        tree = parser.parse(src)
        errs = []
        stack = list(tree.root_node.children)
        while stack:
            n = stack.pop()
            if n.type == "ERROR" or n.is_missing:
                errs.append(n)
            stack.extend(n.children)
        rel = os.path.relpath(f, ROOT)
        if errs:
            bad += 1
            print(f"!! {rel}: {len(errs)} problem(s)")
            for e in errs[:5]:
                r, c = e.start_point
                snippet = src[e.start_byte:e.end_byte][:80]
                print(f"   line {r + 1} col {c + 1} type={e.type} "
                      f"missing={e.is_missing} text={snippet!r}")
        else:
            print(f"ok {rel}")

    # crude HTML sanity on the settings renderer (form tags must balance)
    admin = open(os.path.join(ROOT, "includes/class-vegax-ais-admin.php")).read()
    op, cl = admin.count("<form"), admin.count("</form>")
    print(f"admin.php form tags: <form={op} </form>={cl} -> {'ok' if op == cl else 'MISMATCH'}")

    print()
    print("RESULT:", "ALL PHP FILES PARSE CLEAN" if bad == 0 and op == cl
          else f"{bad} PHP file(s) with syntax problems")
    return 0 if bad == 0 and op == cl else 1


if __name__ == "__main__":
    sys.exit(main())
