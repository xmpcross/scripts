#!/usr/bin/env python3
"""
Convert nxt.deals product images to WebP with TRANSPARENT backgrounds.

For each product image it flood-fills the contiguous near-white/neutral border
background to fully transparent (alpha 0), then writes WebP versions of the base
image + all 5 PrestaShop thumbnail sizes (with alpha). Images whose border is
coloured / non-uniform (lifestyle, packaging) keep their background but are still
written as (opaque) WebP, so the whole catalogue is consistent.

The original .jpg files are LEFT IN PLACE, so rolling back is just:
    set PS_IMAGE_FORMAT back to 'jpg'  (then no .webp is referenced)

Usage:
    python3 transparent_webp.py --test 1332 1310 4314     # write /tmp/twebp/*.webp + report, no store writes
    python3 transparent_webp.py --apply                   # all COVER images -> webp
    python3 transparent_webp.py --apply --all             # every product image -> webp
    python3 transparent_webp.py --clean                   # delete every generated .webp (full revert)
"""
import os, sys, argparse, subprocess
from PIL import Image, ImageDraw, ImageChops

SITE     = "/var/www/html/nxt.deals"
IMG_DIR  = os.path.join(SITE, "img", "p")
DB       = ["mysql", "-h127.0.0.1", "-ukryptok", "-pafhajT11!@", "nxtdeals_data", "-N", "-e"]
TYPES    = [("cart_default", 125, 125), ("small_default", 160, 160),
            ("home_default", 550, 550), ("medium_default", 750, 750),
            ("large_default", 1000, 1000)]
WEBP_Q   = 82
# border-detection thresholds (same spirit as whiten_product_bg.py)
MIN_BRIGHTNESS, MAX_SPREAD, MAX_CHAN_SPREAD, FLOOD_THRESH = 205, 40, 22, 36
SENT     = (1, 254, 2)   # sentinel fill colour used to derive the alpha mask


def db(sql):
    out = subprocess.check_output(DB + [sql], stderr=subprocess.DEVNULL).decode()
    return [l.split("\t") for l in out.splitlines() if l.strip()]


def base_dir(idi): return os.path.join(IMG_DIR, *list(str(idi)))
def jpg_base(idi): return os.path.join(base_dir(idi), f"{idi}.jpg")


def analyze_border(im):
    w, h = im.size
    pts = [(2, 2), (w - 3, 2), (2, h - 3), (w - 3, h - 3),
           (w // 2, 2), (w // 2, h - 3), (2, h // 2), (w - 3, h // 2)]
    cols = [im.getpixel(p) for p in pts]
    avg = tuple(sum(c) // len(cols) for c in zip(*cols))
    return avg, sum(avg) / 3.0, max(max(c) - min(c) for c in zip(*cols))


def make_rgba(path):
    """Return (rgba_image, transparent_bool). Transparent when the border is
    near-white/neutral; otherwise the original opaque image (as RGBA)."""
    im = Image.open(path).convert("RGB")
    avg, bright, spread = analyze_border(im)
    chan = max(avg) - min(avg)
    if bright < MIN_BRIGHTNESS or spread > MAX_SPREAD or chan > MAX_CHAN_SPREAD:
        return im.convert("RGBA"), False            # keep background, opaque webp
    w, h = im.size
    work = im.copy()
    seeds = [(1, 1), (w - 2, 1), (1, h - 2), (w - 2, h - 2),
             (w // 2, 1), (w // 2, h - 2), (1, h // 2), (w - 2, h // 2)]
    for s in seeds:
        ImageDraw.floodfill(work, s, SENT, thresh=FLOOD_THRESH)
    # Build the alpha mask without per-pixel Python loops (fast, C-level):
    # diff==0 exactly where work == SENT (the flood-filled background).
    diff = ImageChops.difference(work, Image.new("RGB", im.size, SENT))
    r, g, b = diff.split()
    maxdiff = ImageChops.lighter(ImageChops.lighter(r, g), b)   # 0 only where ==SENT
    alpha = maxdiff.point(lambda v: 0 if v == 0 else 255)        # 0 = transparent bg
    rgba = im.convert("RGBA")
    rgba.putalpha(alpha)
    return rgba, True


OUT_FMT = "WEBP"   # set by --format (WEBP | PNG)
OUT_EXT = "webp"


def save_img(img, out):
    if OUT_FMT == "PNG":
        img.save(out, "PNG", optimize=True)
    else:
        img.save(out, "WEBP", quality=WEBP_Q, method=6)


def chown_www(idi):
    import shutil
    d = base_dir(idi)
    for f in os.listdir(d):
        if f.startswith(str(idi)) and f.endswith("." + OUT_EXT):
            try: shutil.chown(os.path.join(d, f), "www-data", "www-data")
            except (PermissionError, LookupError): pass


def process(idi):
    p = jpg_base(idi)
    if not os.path.exists(p):
        return None
    rgba, transparent = make_rgba(p)
    d = base_dir(idi)
    save_img(rgba, os.path.join(d, f"{idi}.{OUT_EXT}"))       # base
    for name, tw, th in TYPES:
        t = rgba.copy(); t.thumbnail((tw, th), Image.LANCZOS)
        save_img(t, os.path.join(d, f"{idi}-{name}.{OUT_EXT}"))
    chown_www(idi)
    return transparent


def cover_ids(): return [r[0] for r in db("SELECT id_image FROM o6kfr_image WHERE cover=1 ORDER BY id_product")]
def all_ids():   return [r[0] for r in db("SELECT id_image FROM o6kfr_image ORDER BY id_product")]


def main():
    global OUT_FMT, OUT_EXT
    ap = argparse.ArgumentParser()
    ap.add_argument("--test", nargs="+", metavar="ID")
    ap.add_argument("--apply", action="store_true")
    ap.add_argument("--all", action="store_true")
    ap.add_argument("--clean", action="store_true")
    ap.add_argument("--format", choices=["webp", "png"], default="webp")
    a = ap.parse_args()
    OUT_EXT = a.format
    OUT_FMT = a.format.upper()

    if a.clean:
        n = 0
        for root, _, files in os.walk(IMG_DIR):
            for f in files:
                if f.endswith(".webp") or f.endswith(".png"):
                    os.remove(os.path.join(root, f)); n += 1
        print(f"removed {n} generated .webp/.png files")
        return

    if a.test:
        os.makedirs("/tmp/twebp", exist_ok=True)
        for idi in a.test:
            p = jpg_base(idi)
            if not os.path.exists(p): print(f"{idi}: missing"); continue
            rgba, tr = make_rgba(p)
            out = f"/tmp/twebp/{idi}.{OUT_EXT}"; save_img(rgba, out)
            jpg_kb = os.path.getsize(p) // 1024
            print(f"{idi}: {'TRANSPARENT' if tr else 'opaque (skipped bg)'}  -> {out} "
                  f"({os.path.getsize(out)//1024} KB {OUT_EXT} vs {jpg_kb} KB jpg, mode={rgba.mode})")
        return

    if a.apply:
        ids = all_ids() if a.all else cover_ids()
        print(f"processing {len(ids)} {'all' if a.all else 'cover'} images -> {OUT_EXT} ...")
        done = tr = miss = 0
        for i, idi in enumerate(ids, 1):
            r = process(idi)
            if r is None: miss += 1; continue
            done += 1; tr += 1 if r else 0
            if i % 100 == 0: print(f"  {i}/{len(ids)}  (transparent so far: {tr})")
        print(f"DONE  webp written for {done} images  (transparent={tr}, opaque={done-tr}, missing={miss})")
        return

    ap.print_help()


if __name__ == "__main__":
    main()
