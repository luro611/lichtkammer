"""Aufnahmen Teil 4: Hinweisband, Veröffentlichen, Designs, öffentliche Seite, Verwaltung."""
import sys, json; sys.path.insert(0, "/arbeit")
from shotlib import *
def design_veroeffentlichen(pg, d):
    pg.goto(BASE + "/admin/einstellungen"); pg.check(f"input[name=design][value={d}]")
    pg.click("button:has-text('Speichern und veröffentlichen')"); pg.wait_for_load_state(); pg.wait_for_timeout(1500)
with sync_playwright() as p:
    b, ctx, pg = start(p)
    pg.goto(BASE + "/admin/sicherung")
    pg.fill("input[name=current_password]", CREDS["pw"]); sec = (ARB/"secret.txt").read_text()
    pg.wait_for_timeout(31000)
    pg.fill("input[name=code]", totp(sec)); pg.click("button:has-text('Bestätigen')"); pg.wait_for_load_state()
    shot(pg, "t_sicherung", full=True)
    pg.goto(BASE + "/admin/varianten"); shot(pg, "t_varianten", full=True)
    pg.goto(BASE + "/admin/log"); shot(pg, "t_protokoll", full=True)
    pg.goto(BASE + "/admin/account"); shot(pg, "t_konto", full=True)
    pg.goto(BASE + "/admin/import"); shot(pg, "t_import", full=True)
    save_state(ctx); b.close()
