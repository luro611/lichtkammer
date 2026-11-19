"""Aufnahmen Teil 2: Medien, Fotos, Galerien."""
import sys, json, re; sys.path.insert(0, "/arbeit")
from shotlib import *
def wait_done(pg, sel="#mqueue"):
    for i in range(120):
        t = pg.inner_text(sel)
        if "Ansicht aktualisieren" in t or "Fertig" in t: return
        pg.wait_for_timeout(1500)
with sync_playwright() as p:
    b, ctx, pg = start(p)
    pg.goto(BASE + "/admin"); shot(pg, "t_dashboard")
    pg.goto(BASE + "/admin/medien")
    pg.set_input_files("#mdir", "/arbeit/ordner/Reise"); pg.wait_for_selector("#msummary[open]")
    pg.check("input[name=gal][value=each]"); pg.wait_for_timeout(300)
    pg.locator("#msummary").screenshot(path=str(SHOTS/"t_medien_zusammenfassung.png"))
    pg.click("#msummary button:has-text('Hochladen starten')"); wait_done(pg); pg.wait_for_timeout(1000)
    shot(pg, "t_medien_upload_fertig", full=True)
    pg.set_input_files("#mfiles", ["/arbeit/Preisliste-Beispiel.pdf"]); wait_done(pg); pg.wait_for_timeout(800)
    pg.goto(BASE + "/admin/medien")
    pg.get_by_role("button", name="Öffentlich schalten").first.click(); pg.wait_for_load_state()
    shot(pg, "t_medien_dateien", full=True)
    pg.goto(BASE + "/admin/medien"); shot(pg, "t_medien_liste", full=True)
    # Fotografen
    pg.goto(BASE + "/admin/fotografen")
    for n in ("Mara Beispiel", "Jonas Muster"):
        pg.fill("input[name=name] >> nth=-1", n); pg.locator("form:has(input[value=neu]) button[type=submit]").click(); pg.wait_for_load_state()
    # Mehrfachbearbeitung: alle Fotos
    pg.goto(BASE + "/admin/fotos")
    pg.check("text=alle auf dieser Seite"); pg.click("button:has-text('Auswahl bearbeiten')"); pg.wait_for_load_state()
    pg.check("input[name=set_photographer_id]"); pg.select_option("select[name=photographer_id]", label="Mara Beispiel")
    pg.check("input[name=set_year]"); pg.fill("input[name=year]", "2026")
    pg.check("input[name=set_alt_text]"); pg.fill("textarea[name=alt_text]", "Beispielfoto: Landschaft im weichen Licht")
    shot(pg, "t_mehrfach", full=True)
    pg.click("button[type=submit]:has-text('Speichern')"); pg.wait_for_load_state()
    pg.goto(BASE + "/admin/fotos"); shot(pg, "t_fotos_liste", full=True)
    pg.goto(BASE + "/admin/fotos/1"); shot(pg, "t_foto_detail", full=True)
    # Galerien
    pg.goto(BASE + "/admin/galerien"); shot(pg, "t_galerien_liste", full=True)
    pg.goto(BASE + "/admin/galerien/1"); shot(pg, "t_galerie_edit", full=True)
    pg.goto(BASE + "/admin/galerien/1")
    pg.locator("button:has-text('Titelbild')").first.click(); pg.wait_for_load_state()
    pg.locator("button:has-text('Startseite')").first.click(); pg.wait_for_load_state()
    pg.goto(BASE + "/admin/galerien/1"); shot(pg, "t_galerie_fotos", full=True)
    for g in (1, 2, 3):
        pg.goto(BASE + f"/admin/galerien/{g}"); pg.click("button:has-text('Galerie online stellen')"); pg.wait_for_load_state()
        pg.locator("button:has-text('Startseite')").first.click(); pg.wait_for_load_state()
    save_state(ctx); b.close()
