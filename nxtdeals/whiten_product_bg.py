#!/usr/bin/env python3
"""
Whiten near-uniform light backgrounds on PrestaShop product images (nxt.deals).

What it does
------------
For each target image it flood-fills the *contiguous* background, starting from
the four corners + edge midpoints, to pure white. Because it only floods the
connected region reachable from the edges, the product itself is preserved
(interior pixels of the same colour are never touched).

Safety guards
-------------
* It SKIPS any image whose border is not light AND uniform — i.e. lifestyle
  photos, coloured backdrops or packaging shots are left completely untouched
  (brightness threshold + edge colour-spread threshold).
* In --apply mode every original is copied to a backup dir first.
* After whitening the original it regenerates the 5 PrestaShop JPG thumbnails.

Usage
-----
  python3 whiten_product_bg.py --test 4314 4438 4152 4530 4008 4539
        -> writes a before/after contact sheet to /tmp/bgtest/sheet.png, no writes to the store

  python3 whiten_product_bg.py --apply              # all COVER images
  python3 whiten_product_bg.py --apply --all        # every product image
  python3 whiten_product_bg.py --restore            # roll back from backup
"""
import os, sys, argparse, shutil, subprocess
from PIL import Image, ImageDraw, ImageChops

SITE       = "/var/www/html/nxt.deals"
IMG_DIR    = os.path.join(SITE, "img", "p")
BACKUP_DIR = os.path.join(SITE, "img", "p_bgremove_backup")
DB         = ["mysql", "-h127.0.0.1", "-ukryptok", "-pafhajT11!@", "nxtdeals_data", "-N", "-e"]
TYPES      = [("cart_default", 125, 125), ("small_default", 160, 160),
              ("home_default", 550, 550), ("medium_default", 750, 750),
              ("large_default", 1000, 1000)]
WHITE      = (249, 249, 249)   # #f9f9f9 — background fill colour

# --- tuning -----------------------------------------------------------------
# Whiten ONLY genuinely near-white backgrounds. Floodfill-to-white cannot
# cleanly handle tinted gradients/artistic backdrops (it leaves a colour band),
# and flat-vs-gradient is indistinguishable once a product fills the interior.
# Restricting to near-white means any residual is invisibly white = no banding.
MIN_BRIGHTNESS = 244   # avg of border samples must be >= this (0-255)
MAX_SPREAD     = 20    # max per-channel range across the border samples (uniform)
MAX_CHAN_SPREAD = 10   # max range between the avg R/G/B channels (neutral, not tinted)
DEFAULT_THRESH = 70    # PIL floodfill colour-distance tolerance (sum of |dR|+|dG|+|dB|)
JPEG_QUALITY   = 90


def db(sql):
    out = subprocess.check_output(DB + [sql], stderr=subprocess.DEVNULL).decode()
    return [l.split("\t") for l in out.splitlines() if l.strip()]


def img_path(idi):
    return os.path.join(IMG_DIR, *list(str(idi)), f"{idi}.jpg")


def analyze_border(im):
    """Return (avg_colour, brightness, spread) sampled around the image border."""
    w, h = im.size
    pts = [(2, 2), (w - 3, 2), (2, h - 3), (w - 3, h - 3),
           (w // 2, 2), (w // 2, h - 3), (2, h // 2), (w - 3, h // 2)]
    cols = [im.getpixel(p) for p in pts]
    avg = tuple(sum(c) // len(cols) for c in zip(*cols))
    brightness = sum(avg) / 3.0
    spread = max(max(c) - min(c) for c in zip(*cols))
    return avg, brightness, spread


def whiten_to(path, out_path, thresh):
    """Whiten the contiguous border background. Returns (changed, info)."""
    im = Image.open(path).convert("RGB")
    avg, bright, spread = analyze_border(im)
    chan_spread = max(avg) - min(avg)
    info = {"avg": avg, "bright": round(bright), "spread": spread, "chan": chan_spread}
    if bright < MIN_BRIGHTNESS or spread > MAX_SPREAD or chan_spread > MAX_CHAN_SPREAD:
        info["reason"] = "skipped (not near-white/neutral)"
        return False, info
    w, h = im.size
    seeds = [(1, 1), (w - 2, 1), (1, h - 2), (w - 2, h - 2),
             (w // 2, 1), (w // 2, h - 2), (1, h // 2), (w - 2, h // 2)]
    # Flood to a distinct SENTINEL first: PIL's floodfill no-ops when the fill
    # colour is within `thresh` of the background (e.g. #f9f9f9 vs white), so we
    # fill with a far-away colour, then recolour that region to WHITE.
    SENT = (1, 254, 2)
    for s in seeds:
        ImageDraw.floodfill(im, s, SENT, thresh=thresh)
    diff = ImageChops.difference(im, Image.new("RGB", im.size, SENT))
    r, g, b = diff.split()
    maxd = ImageChops.lighter(ImageChops.lighter(r, g), b)   # 0 only where ==SENT
    mask = maxd.point(lambda v: 255 if v == 0 else 0)         # 255 = flooded bg
    im.paste(WHITE, (0, 0), mask)
    im.save(out_path, "JPEG", quality=JPEG_QUALITY)
    info["reason"] = "whitened"
    return True, info


def regen_thumbs(idi):
    base = os.path.join(IMG_DIR, *list(str(idi)))
    im = Image.open(os.path.join(base, f"{idi}.jpg")).convert("RGB")
    for name, tw, th in TYPES:
        t = im.copy()
        t.thumbnail((tw, th), Image.LANCZOS)
        t.save(os.path.join(base, f"{idi}-{name}.jpg"), "JPEG", quality=JPEG_QUALITY)


def chown_www(idi):
    base = os.path.join(IMG_DIR, *list(str(idi)))
    for f in os.listdir(base):
        if f.startswith(str(idi)):
            try:
                shutil.chown(os.path.join(base, f), "www-data", "www-data")
            except (PermissionError, LookupError):
                pass


def cover_ids():
    return [r[0] for r in db("SELECT id_image FROM o6kfr_image WHERE cover=1 ORDER BY id_product")]


def all_ids():
    return [r[0] for r in db("SELECT id_image FROM o6kfr_image ORDER BY id_product")]


# ---------------------------------------------------------------------------
def cmd_test(ids):
    os.makedirs("/tmp/bgtest", exist_ok=True)
    from PIL import ImageDraw as D, ImageFont
    font = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf", 13)
    cell, pad = 240, 26
    rows = len(ids)
    sheet = Image.new("RGB", (cell * 2 + 30, rows * (cell + pad)), (90, 90, 90))
    draw = D.Draw(sheet)
    for r, idi in enumerate(ids):
        p = img_path(idi)
        if not os.path.exists(p):
            draw.text((10, r * (cell + pad) + 4), f"{idi}: file missing", fill=(255, 120, 120), font=font)
            continue
        out = f"/tmp/bgtest/{idi}.jpg"
        changed, info = whiten_to(p, out, DEFAULT_THRESH)
        before = Image.open(p).convert("RGB"); before.thumbnail((cell - 20, cell - 20))
        after = Image.open(out if changed else p).convert("RGB"); after.thumbnail((cell - 20, cell - 20))
        y = r * (cell + pad)
        for col, im2 in ((0, before), (1, after)):
            x = 10 + col * (cell + 10)
            sheet.paste((255, 255, 255), (x, y + pad, x + cell - 20, y + cell))
            sheet.paste(im2, (x + (cell - 20 - im2.width) // 2, y + pad + (cell - 20 - im2.height) // 2))
        status = "WHITENED" if changed else "SKIPPED"
        col = (90, 220, 120) if changed else (255, 200, 90)
        draw.text((12, y + 6), f"{idi}  border avg{info['avg']} bright{info['bright']} spread{info['spread']}  ->  {status}",
                  fill=col, font=font)
    sheet.save("/tmp/bgtest/sheet.png")
    print("preview -> /tmp/bgtest/sheet.png")


def cmd_apply(ids):
    os.makedirs(BACKUP_DIR, exist_ok=True)
    done = skipped = 0
    for idi in ids:
        p = img_path(idi)
        if not os.path.exists(p):
            continue
        # backup original once
        bkp = os.path.join(BACKUP_DIR, f"{idi}.jpg")
        if not os.path.exists(bkp):
            shutil.copy2(p, bkp)
        changed, info = whiten_to(p, p, DEFAULT_THRESH)
        if changed:
            regen_thumbs(idi)
            chown_www(idi)
            done += 1
        else:
            skipped += 1
        print(f"  {idi}: {info['reason']}  {info['avg']}")
    print(f"\nDONE  whitened={done}  skipped={skipped}  (backups in {BACKUP_DIR})")


def cmd_restore():
    if not os.path.isdir(BACKUP_DIR):
        print("no backup dir"); return
    n = 0
    for f in os.listdir(BACKUP_DIR):
        idi = f[:-4]
        dst = img_path(idi)
        if os.path.exists(dst):
            shutil.copy2(os.path.join(BACKUP_DIR, f), dst)
            regen_thumbs(idi); chown_www(idi); n += 1
    print(f"restored {n} images from backup")


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--test", nargs="+", metavar="ID")
    ap.add_argument("--apply", action="store_true")
    ap.add_argument("--all", action="store_true")
    ap.add_argument("--restore", action="store_true")
    a = ap.parse_args()
    if a.test:
        cmd_test(a.test)
    elif a.restore:
        cmd_restore()
    elif a.apply:
        ids = all_ids() if a.all else cover_ids()
        print(f"processing {len(ids)} {'product' if a.all else 'cover'} images...")
        cmd_apply(ids)
    else:
        ap.print_help()
