#!/usr/bin/env python3
"""Erzeugt neutrale, selbst gemachte Beispielbilder (Verläufe, Silhouetten) für die Anleitung.
Aufruf: python3 demofotos.py <Zielordner>   (Pillow nötig). Keine echten Fotos, keine Personen, keine Orte."""
import random, sys, math
from pathlib import Path
from PIL import Image, ImageDraw, ImageFilter

W, H = 2400, 1600

def lerp(a, b, t): return tuple(int(a[i] + (b[i] - a[i]) * t) for i in range(3))

def sky(top, bottom, horizon=0.62):
    im = Image.new("RGB", (W, H))
    d = ImageDraw.Draw(im)
    for y in range(H):
        t = min(1, y / (H * horizon))
        d.line([(0, y), (W, y)], fill=lerp(top, bottom, t ** 1.4))
    return im

def ridge(seed, base, amp, rough=0.55, n=9):
    rnd = random.Random(seed)
    pts = {0: rnd.uniform(-1, 1), 2 ** n: rnd.uniform(-1, 1)}
    step, scale = 2 ** n, 1.0
    while step > 1:
        half = step // 2
        for x in range(half, 2 ** n, step):
            pts[x] = (pts[x - half] + pts[x + half]) / 2 + rnd.uniform(-1, 1) * scale
        step, scale = half, scale * rough
    xs = sorted(pts)
    return [(x / 2 ** n * W, base + pts[x] * amp) for x in xs]

def mountains(im, layers, seed):
    d = ImageDraw.Draw(im)
    for i, (col, base, amp) in enumerate(layers):
        pts = ridge(seed + i * 17, base, amp)
        d.polygon(pts + [(W, H), (0, H)], fill=col)

def sun(im, x, y, r, col):
    glow = Image.new("RGB", (W, H), (0, 0, 0))
    d = ImageDraw.Draw(glow)
    d.ellipse([x - r * 3, y - r * 3, x + r * 3, y + r * 3], fill=tuple(c // 3 for c in col))
    glow = glow.filter(ImageFilter.GaussianBlur(r * 1.5))
    from PIL import ImageChops
    im.paste(ImageChops.add(im, glow))
    ImageDraw.Draw(im).ellipse([x - r, y - r, x + r, y + r], fill=col)

def sea(im, y0, top, bottom, seed):
    d = ImageDraw.Draw(im)
    rnd = random.Random(seed)
    for y in range(y0, H):
        t = (y - y0) / (H - y0)
        d.line([(0, y), (W, y)], fill=lerp(top, bottom, t))
    for _ in range(260):
        y = rnd.randint(y0 + 6, H - 4); x = rnd.randint(0, W); l = rnd.randint(40, 260) * (1 + (y - y0) / 300)
        d.line([(x, y), (x + l, y)], fill=lerp(top, (255, 255, 255), 0.18), width=2)

def dunes(im, cols, seed):
    d = ImageDraw.Draw(im)
    for i, (col, base) in enumerate(cols):
        rnd = random.Random(seed + i)
        ph, f = rnd.uniform(0, 6), rnd.uniform(0.0014, 0.0026)
        pts = [(x, base + math.sin(x * f + ph) * 90 + math.sin(x * f * 2.7 + ph) * 28) for x in range(0, W + 20, 20)]
        d.polygon(pts + [(W, H), (0, H)], fill=col)

def trees(im, col, y, seed, count=28):
    d = ImageDraw.Draw(im); rnd = random.Random(seed)
    for _ in range(count):
        x = rnd.randint(0, W); h = rnd.randint(260, 620); w = h * 0.32
        d.rectangle([x - 6, y - 40, x + 6, y + 30], fill=col)
        for k in range(5):
            t = k / 5
            d.polygon([(x, y - h * (1 - t * 0.2) + t * h * 0.55 - 30), (x - w * (0.35 + t * 0.65), y - h * 0.1 - (1 - t) * h * 0.1 + 0), (x + w * (0.35 + t * 0.65), y - h * 0.1 + 0)], fill=col) if False else None
        d.polygon([(x, y - h), (x - w, y), (x + w, y)], fill=col)

def finish(im, seed, grain=7):
    rnd = random.Random(seed)
    noise = Image.effect_noise((W, H), grain).convert("RGB")
    im = Image.blend(im, Image.blend(im, noise, 0.5), 0.08)
    # leichte Vignette
    v = Image.new("L", (W, H), 0); d = ImageDraw.Draw(v)
    d.ellipse([-W * 0.25, -H * 0.3, W * 1.25, H * 1.3], fill=255)
    v = v.filter(ImageFilter.GaussianBlur(220))
    dark = Image.new("RGB", (W, H), (0, 0, 0))
    return Image.composite(im, Image.blend(im, dark, 0.45), v)

def make(name, fn, seed):
    fn(seed).save(OUT / f"{name}.jpg", quality=88)

def abend(seed):
    im = sky((60, 50, 90), (240, 150, 100)); sun(im, 1500, 1010, 70, (255, 226, 170))
    mountains(im, [((120, 80, 100), 1030, 70), ((70, 50, 75), 1130, 90), ((35, 28, 45), 1280, 80)], seed)
    return finish(im, seed)

def meer(seed):
    im = sky((120, 160, 205), (245, 225, 205), 0.55); sun(im, 600, 840, 55, (255, 245, 225))
    sea(im, 900, (90, 125, 160), (30, 55, 85), seed)
    return finish(im, seed)

def duene(seed):
    im = sky((240, 190, 140), (250, 225, 180)); sun(im, 1900, 500, 60, (255, 248, 230))
    dunes(im, [((205, 150, 100), 900), ((170, 110, 70), 1060), ((120, 72, 50), 1250)], seed)
    return finish(im, seed)

def wald(seed):
    im = sky((170, 190, 190), (225, 230, 220), 0.7)
    mountains(im, [((150, 175, 170), 850, 60)], seed)
    trees(im, (40, 70, 60), 1250, seed, 34); trees(im, (22, 45, 40), 1450, seed + 5, 22)
    ImageDraw.Draw(im).rectangle([0, 1380, W, H], fill=(18, 36, 32))
    return finish(im, seed)

def nebel(seed):
    im = sky((200, 205, 215), (235, 235, 235), 0.8)
    mountains(im, [((185, 192, 205), 800, 80), ((150, 160, 178), 950, 90), ((105, 118, 140), 1120, 80), ((60, 72, 95), 1300, 70)], seed)
    return finish(im, seed)

def nacht(seed):
    im = sky((8, 12, 35), (40, 60, 110), 0.8)
    d = ImageDraw.Draw(im); rnd = random.Random(seed)
    for _ in range(420):
        x, y = rnd.randint(0, W), rnd.randint(0, 1000); r = rnd.choice([1, 1, 1, 2, 3]); c = rnd.randint(170, 255)
        d.ellipse([x - r, y - r, x + r, y + r], fill=(c, c, 255))
    mountains(im, [((12, 18, 30), 1200, 110), ((5, 8, 14), 1380, 80)], seed)
    return finish(im, seed, 5)

def herbst(seed):
    im = sky((250, 215, 150), (255, 240, 200)); sun(im, 1200, 760, 90, (255, 250, 235))
    mountains(im, [((200, 130, 60), 1000, 60), ((150, 80, 40), 1150, 70), ((90, 45, 28), 1320, 80)], seed)
    trees(im, (70, 35, 20), 1500, seed, 16)
    return finish(im, seed)

def see(seed):
    im = sky((150, 190, 215), (230, 235, 235), 0.5)
    mountains(im, [((80, 110, 130), 780, 90)], seed)
    sea(im, 880, (110, 150, 170), (50, 85, 105), seed)
    return finish(im, seed)

def blueh(seed):
    im = sky((20, 30, 70), (210, 120, 130), 0.75); sun(im, 800, 1100, 50, (255, 200, 170))
    sea(im, 1100, (110, 70, 110), (25, 25, 50), seed)
    mountains(im, [((15, 15, 30), 1090, 40)], seed)
    return finish(im, seed)

def hochland(seed):
    im = sky((205, 215, 225), (245, 240, 230), 0.7)
    mountains(im, [((160, 170, 150), 900, 100), ((110, 125, 100), 1050, 100), ((70, 85, 65), 1250, 80)], seed)
    return finish(im, seed)

def kueste(seed):
    im = sky((255, 190, 140), (255, 235, 200), 0.6); sun(im, 1700, 880, 80, (255, 245, 220))
    sea(im, 980, (245, 190, 150), (90, 80, 110), seed)
    mountains(im, [((60, 40, 55), 1230, 90)], seed)
    return finish(im, seed)

def morgen(seed):
    im = sky((130, 170, 200), (255, 230, 200), 0.62); sun(im, 1800, 980, 60, (255, 240, 200))
    mountains(im, [((120, 140, 170), 1000, 50), ((80, 100, 120), 1130, 70), ((40, 55, 70), 1300, 60)], seed)
    return finish(im, seed)

def salz(seed):
    im = sky((235, 240, 245), (250, 248, 242), 0.55)
    dunes(im, [((235, 230, 220), 1000), ((215, 208, 195), 1200)], seed)
    return finish(im, seed, 4)

def zwielicht(seed):
    im = sky((70, 60, 110), (225, 120, 110), 0.7)
    sea(im, 1000, (190, 100, 120), (40, 35, 70), seed)
    trees(im, (20, 15, 30), 1000, seed, 12)
    return finish(im, seed)

def dunst(seed):
    im = sky((215, 205, 215), (245, 225, 215), 0.7); sun(im, 1000, 900, 60, (255, 240, 225))
    mountains(im, [((190, 175, 195), 950, 70), ((150, 135, 160), 1100, 80), ((95, 85, 115), 1300, 70)], seed)
    return finish(im, seed)

FOTOS = [("abendlicht", abend, 1), ("meer-am-mittag", meer, 2), ("duenenlinie", duene, 3), ("waldrand", wald, 4),
         ("nebeltal", nebel, 5), ("sternenhimmel", nacht, 6), ("herbstglut", herbst, 7), ("bergsee", see, 8),
         ("blaue-stunde", blueh, 9), ("hochland", hochland, 10), ("kuestenlicht", kueste, 11), ("morgenrot", morgen, 12),
         ("salzebene", salz, 13), ("zwielicht", zwielicht, 14), ("morgendunst", dunst, 15)]

if __name__ == "__main__":
    OUT = Path(sys.argv[1]); OUT.mkdir(parents=True, exist_ok=True)
    for n, f, s in FOTOS:
        make(n, f, s); print(n)
