"""Aufnahmen Teil 4: Hinweisband, Veröffentlichen, Designs, öffentliche Seite, Verwaltung."""
import sys, json; sys.path.insert(0, "/arbeit")
from shotlib import *
def design_veroeffentlichen(pg, d):
    pg.goto(BASE + "/admin/einstellungen"); pg.check(f"input[name=design][value={d}]")
    pg.click("button:has-text('Speichern und veröffentlichen')"); pg.wait_for_load_state(); pg.wait_for_timeout(1500)
with sync_playwright() as p:
    b, ctx, pg = start(p)
    pg.goto(BASE + "/admin/seiten"); shot(pg, "t_pubband", clip={"x": 0, "y": 0, "width": 1280, "height": 150})
    pg.goto(BASE + "/admin/veroeffentlichen"); shot(pg, "t_veroeffentlichen", full=True)
    # Designs der Verwaltung
    pg.goto(BASE + "/admin")
    for d in ("warm", "klar", "studio", "blau", "rot", "gruen"):
        pg.click(f"[data-design-set={d}]"); pg.wait_for_timeout(300); pg.screenshot(path=str(SHOTS/f"t_d_{d}.png"), clip={"x": 0, "y": 0, "width": 1280, "height": 760})
    pg.click("[data-design-set=warm]")
    # Veröffentlichen: Warm, dann Grün
    pg.goto(BASE + "/admin/veroeffentlichen"); pg.check("input[name=alle_entwuerfe], input[type=checkbox]") if False else None
    pg.click("button:has-text('Jetzt veröffentlichen')"); pg.wait_for_load_state(); pg.wait_for_timeout(1500)
    pg.goto(BASE + "/"); shot(pg, "t_oeffentlich_warm")
    design_veroeffentlichen(pg, "gruen")
    pg.goto(BASE + "/"); shot(pg, "t_oeffentlich_gruen")
    pg.goto(BASE + "/admin/veroeffentlichen"); shot(pg, "t_fassungen", full=True)
    design_veroeffentlichen(pg, "warm")
    pg.goto(BASE + "/admin/einstellungen"); shot(pg, "t_einstellungen", full=True)
    # Verwaltung
    pg.goto(BASE + "/admin/users"); shot(pg, "t_konten", full=True)
    pg.select_option("select[name=role]", "admin"); pg.click("button:has-text('Einladungslink erzeugen')"); pg.wait_for_load_state(); shot(pg, "t_confirm", full=True)
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
