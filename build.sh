#!/usr/bin/env bash
# Đóng gói plugin thành zip phát hành + md5 manifest. KHÔNG cần lệnh 'zip' ngoài
# (nhiều máy VPS không có) — dùng python3 zipfile, ghi timestamp cố định nên zip
# TÁI LẬP ĐƯỢC (cùng nội dung ⇒ cùng md5 trên mọi máy).
set -euo pipefail
cd "$(dirname "$0")"

MAIN="vegax-support/vegax-support.php"
[ -f "$MAIN" ] || { echo "build: thiếu $MAIN"; exit 1; }

VER=$(grep -m1 -iE '^[[:space:]]*\*[[:space:]]*Version:' "$MAIN" | sed 's/.*Version:[[:space:]]*//' | tr -d '\r')
[ -n "$VER" ] || { echo "build: không đọc được Version trong plugin header"; exit 1; }

mkdir -p dist
OUT="dist/vegax-support-${VER}.zip"
rm -f "$OUT" "$OUT.md5"

python3 - "$OUT" <<'PY'
import os, sys, zipfile
out = sys.argv[1]
files = []
for root, dirs, names in os.walk("vegax-support"):
    dirs[:] = [d for d in dirs if d not in (".git", ".svn", "__MACOSX", "node_modules")]
    for n in names:
        # Skip editor/backup junk: hidden files, *.bak*, *~, Thumbs.db.
        # A stray .bak inside the plugin folder used to end up INSIDE the shipped zip.
        if n.startswith(".") or ".bak" in n or n.endswith("~") or n == "Thumbs.db":
            continue
        fpath = os.path.join(root, n)
        # assets/ belong in SVN /assets/ not in plugin trunk
        if "/assets/" in fpath:
            continue
        files.append(fpath)
files.sort()
with zipfile.ZipFile(out, "w") as z:
    for p in files:
        zi = zipfile.ZipInfo(p.replace(os.sep, "/"), date_time=(1980, 1, 1, 0, 0, 0))
        zi.external_attr = (0o100644 & 0xFFFF) << 16
        zi.compress_type = zipfile.ZIP_DEFLATED
        with open(p, "rb") as fh:
            z.writestr(zi, fh.read())
print("files:", len(files))
PY

md5sum "$OUT" | tee "$OUT.md5"
echo "OK: $OUT ($(stat -c%s "$OUT") bytes)"
