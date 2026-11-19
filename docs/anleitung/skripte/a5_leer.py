"""Aufnahme: Seite „Admin nicht verfügbar“ mit den zwei Hilfszeilen (Instanz ohne Schlüssel)."""
import sys; sys.path.insert(0, "/arbeit")
from shotlib import *
with sync_playwright() as p:
    b, ctx, pg = start(p, state=False)
    pg.goto(BASE + "/admin")
    pg.evaluate("""document.querySelectorAll('pre, code, .mono').forEach(e=>{ if(/LK_APP_KEY=/.test(e.textContent)) e.textContent='LK_APP_KEY=XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX\\nLK_SETUP_TOKEN=XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX'})""")
    shot(pg, "t_nicht_verfuegbar", full=True); b.close()
