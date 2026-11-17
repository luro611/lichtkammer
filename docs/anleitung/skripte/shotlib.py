"""Gemeinsame Hilfen für die Bildschirmfotos der Anleitung (Playwright, Container siehe pw_run.sh)."""
import base64, hashlib, hmac, json, os, re, struct, time
from pathlib import Path
from playwright.sync_api import sync_playwright

BASE = "http://localhost:8080"
ARB = Path("/arbeit")
SHOTS = ARB / "shots"
SHOTS.mkdir(exist_ok=True)
CREDS = json.loads((ARB / "creds.json").read_text()) if (ARB / "creds.json").exists() else {}

def totp(secret_b32: str) -> str:
    s = secret_b32.replace(" ", "").upper()
    key = base64.b32decode(s + "=" * (-len(s) % 8))
    h = hmac.new(key, struct.pack(">Q", int(time.time()) // 30), hashlib.sha1).digest()
    o = h[-1] & 15
    return f"{(struct.unpack('>I', h[o:o+4])[0] & 0x7fffffff) % 10**6:06d}"

def start(p, state=True, scheme="light", width=1280, height=1000):
    b = p.chromium.launch(args=["--host-resolver-rules=MAP localhost:8080 127.0.0.1:80"])
    kw = dict(viewport={"width": width, "height": height}, color_scheme=scheme, locale="de-DE", device_scale_factor=1)
    sf = ARB / "state.json"
    if state and sf.exists():
        kw["storage_state"] = str(sf)
    ctx = b.new_context(**kw)
    return b, ctx, ctx.new_page()

def save_state(ctx):
    ctx.storage_state(path=str(ARB / "state.json"))

def shot(pg, name, full=False, clip=None, selector=None, wait=400):
    pg.wait_for_timeout(wait)
    path = str(SHOTS / f"{name}.png")
    if selector:
        pg.locator(selector).first.screenshot(path=path)
    elif full:
        # Lange Seiten: nur der Inhaltsbereich (die Seitenleiste klebt sonst mitten im Bild).
        h = pg.evaluate("document.documentElement.scrollHeight")
        if h > 1150:
            pg.locator("main").first.screenshot(path=path)
        else:
            pg.screenshot(path=path, full_page=True)
    else:
        pg.screenshot(path=path, clip=clip)
    print("shot", name)
