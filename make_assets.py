#!/usr/bin/env python3
"""Generate WordPress.org listing assets for Vegax Support.

Brand colour #07A87F. No text uses the word "AI" (brand rule).
Writes into vegax-support/assets/ : icon.svg, icon-128x128.png,
icon-256x256.png, banner-772x250.png, banner-1544x500.png
"""
import os
from PIL import Image, ImageDraw, ImageFont

BRAND = (7, 168, 127, 255)      # #07A87F
DARK = (10, 46, 40, 255)        # #0A2E28
GREY = (90, 107, 102, 255)      # #5A6B66
WHITE = (255, 255, 255, 255)

OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                   "vegax-support", "assets")
os.makedirs(OUT, exist_ok=True)

font_cache = {}
def font(size):
    if size not in font_cache:
        font_cache[size] = ImageFont.load_default(size=size)
    return font_cache[size]


def draw_mark(d, size, bubble_scale=1.0):
    """White speech bubble with three brand dots, drawn inside a `size` box."""
    s = size / 256.0
    # bubble body
    x0, y0, x1, y1 = 56 * s, 64 * s, 200 * s, 176 * s
    d.rounded_rectangle([x0, y0, x1, y1], radius=28 * s, fill=WHITE)
    # tail (bottom-left)
    d.polygon([(92 * s, 168 * s), (92 * s, 212 * s), (132 * s, 176 * s)], fill=WHITE)
    # three dots
    for cx in (100, 128, 156):
        d.ellipse([(cx - 11) * s, 109 * s, (cx + 11) * s, 131 * s], fill=BRAND)


def make_icon_master(size=256):
    img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    d.rounded_rectangle([0, 0, size - 1, size - 1], radius=int(size * 0.22), fill=BRAND)
    draw_mark(d, size)
    return img


def make_banner(w, h):
    img = Image.new("RGBA", (w, h), WHITE)
    d = ImageDraw.Draw(img)
    # left brand tile with the mark
    pad = int(h * 0.16)
    tile = int(h * 0.56)
    tx, ty = pad, (h - tile) // 2
    d.rounded_rectangle([tx, ty, tx + tile, ty + tile], radius=int(tile * 0.22), fill=BRAND)
    # shift the mark into the tile (draw_mark paints at the origin, so composite it)
    mark = Image.new("RGBA", (tile, tile), (0, 0, 0, 0))
    md = ImageDraw.Draw(mark)
    draw_mark(md, tile)
    img.alpha_composite(mark, (tx, ty))

    x = tx + tile + int(h * 0.14)
    d.text((x, int(h * 0.24)), "Vegax Support", font=font(int(h * 0.20)), fill=DARK)
    d.text((x, int(h * 0.50)), "Customer support for WooCommerce", font=font(int(h * 0.095)), fill=BRAND)
    d.text((x, int(h * 0.66)), "Answers product, shipping & returns questions in chat.",
           font=font(int(h * 0.072)), fill=GREY)
    return img


ICON_SVG = """<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256" role="img" aria-label="Vegax Support">
  <rect width="256" height="256" rx="56" fill="#07A87F"/>
  <rect x="56" y="64" width="144" height="112" rx="28" fill="#FFFFFF"/>
  <polygon points="92,168 92,212 132,176" fill="#FFFFFF"/>
  <circle cx="100" cy="120" r="11" fill="#07A87F"/>
  <circle cx="128" cy="120" r="11" fill="#07A87F"/>
  <circle cx="156" cy="120" r="11" fill="#07A87F"/>
</svg>
"""

with open(os.path.join(OUT, "icon.svg"), "w", encoding="utf-8") as fh:
    fh.write(ICON_SVG)

master = make_icon_master(256)
master.save(os.path.join(OUT, "icon-256x256.png"))
master.resize((128, 128), Image.LANCZOS).save(os.path.join(OUT, "icon-128x128.png"))

big = make_banner(1544, 500)
big.save(os.path.join(OUT, "banner-1544x500.png"))
big.resize((772, 250), Image.LANCZOS).save(os.path.join(OUT, "banner-772x250.png"))

for name in sorted(os.listdir(OUT)):
    p = os.path.join(OUT, name)
    if name.endswith(".png"):
        with Image.open(p) as im:
            print(f"{name:26s} {im.size[0]:>5}x{im.size[1]:<5} {os.path.getsize(p):>8} bytes")
    else:
        print(f"{name:26s} {'svg':>11} {os.path.getsize(p):>8} bytes")
