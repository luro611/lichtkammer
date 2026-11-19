"""Aufnahmen Teil 3: Seiten, Editor, Startseite, Menü, Logos, Einstellungen, Veröffentlichen, Designs."""
import sys, json; sys.path.insert(0, "/arbeit")
from shotlib import *
def neu(pg, vorlage):
    pg.goto(BASE + "/admin/seiten/neu")
    f = pg.locator("main form").first; f.locator("select").select_option(label=vorlage); f.locator("button[type=submit]").click(); pg.wait_for_load_state()
with sync_playwright() as p:
    b, ctx, pg = start(p)
    neu(pg, "Über uns"); url1 = pg.url
    pg.locator(".epalcard[data-type=imagetext]").click(); pg.locator(".binsbtn").last.click()
    pg.get_by_role("button", name="Foto auswählen").first.click(); pg.wait_for_selector("dialog.picker .ptile")
    pg.locator("dialog.picker .ptile").nth(2).click(); pg.wait_for_timeout(500)
    shot(pg, "t_editor", full=True)
    blk = pg.locator("#page-editor .blist > *:has(h3), #page-editor .blist > div").last
    try: blk.screenshot(path=str(SHOTS/"t_block_bildtext.png"))
    except Exception as e: print("block", e); pg.screenshot(path=str(SHOTS/"t_block_bildtext.png"))
    pg.locator("form[action$=speichern] [name=veroeffentlichen], button:has-text('Nur speichern')").last.scroll_into_view_if_needed()
    pg.locator("select[name=status]").select_option(index=1) if pg.locator("select[name=status]").count() else None
    shot(pg, "t_seite_unten", clip=None)
    pg.locator("button:has-text('Nur speichern')").click(); pg.wait_for_load_state()
    print(pg.inner_text(".notice")[:200] if pg.locator(".notice").count() else "kein notice")
    pg.goto(BASE + "/admin/seiten"); shot(pg, "t_seiten_liste", full=True)
    neu(pg, "Impressum")
    # Startseite
    pg.goto(BASE + "/admin/startseite")
    pg.locator("button:has-text('Nur speichern')").click(); pg.wait_for_load_state()
    pg.goto(BASE + "/admin/startseite"); shot(pg, "t_start", full=True)
    pg.goto(BASE + "/admin/menue"); shot(pg, "t_menue", full=True)
    # Logos
    pg.goto(BASE + "/admin/logos"); pg.set_input_files("#logo-file", ["/arbeit/logo-beispiel.svg", "/arbeit/logo-beispiel-dunkel.svg"]); pg.wait_for_timeout(3000)
    pg.goto(BASE + "/admin/logos"); shot(pg, "t_logos", full=True)
    pg.goto(BASE + "/admin/einstellungen"); shot(pg, "t_einstellungen", full=True)
    save_state(ctx); b.close()
