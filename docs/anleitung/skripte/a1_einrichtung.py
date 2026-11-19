"""Aufnahmen Teil 1: Einrichtung und Anmeldung (frische Instanz, Beispielkonto)."""
import sys, json; sys.path.insert(0, "/arbeit")
from shotlib import *
MASK = """document.querySelectorAll('.qr').forEach(q=>{q.innerHTML='<div style="width:200px;height:200px;background:repeating-conic-gradient(#8a857d 0 25%,#d9d3ca 0 50%) 0 0/24px 24px"></div>'});
document.querySelectorAll('.secret').forEach(s=>s.textContent='XXXX XXXX XXXX XXXX XXXX XXXX XXXX XXXX')"""
with sync_playwright() as p:
    b, ctx, pg = start(p, state=False)
    pg.goto(BASE + "/admin/setup")
    pg.fill("input[name=token]", CREDS["token"]); pg.fill("input[name=username]", CREDS["user"]); pg.fill("input[name=password]", CREDS["pw"])
    pg.evaluate("document.querySelector('input[name=token]').type='text'; document.querySelector('input[name=token]').value='••••••••••••••••••••••••••••••••'")
    shot(pg, "t_setup_form")
    pg.evaluate(f"document.querySelector('input[name=token]').type='password'; document.querySelector('input[name=token]').value={json.dumps(CREDS['token'])}")
    pg.click("button[type=submit]"); pg.wait_for_load_state()
    secret = pg.locator(".secret").inner_text(); (ARB/"secret.txt").write_text(secret)
    pg.evaluate(MASK); shot(pg, "t_enroll")
    pg.fill("input[name=code]", totp(secret)); pg.click("button[type=submit]"); pg.wait_for_load_state()
    pg.wait_for_selector(".codes li")
    (ARB/"codes.json").write_text(json.dumps(pg.locator(".codes li").all_inner_texts()))
    pg.evaluate("document.querySelectorAll('.codes li').forEach((l,i)=>l.textContent='XXXX-XXXX-'+String(i+1).padStart(2,'0'))")
    shot(pg, "t_codes", full=True)
    pg.goto(BASE + "/admin"); shot(pg, "t_dashboard_token")
    pg.goto(BASE + "/admin/system"); shot(pg, "t_system", full=True)
    save_state(ctx)
    b2, c2, p2 = start(p, state=False)
    p2.goto(BASE + "/admin"); shot(p2, "t_login")
    p2.fill("input[name=username]", CREDS["user"]); p2.fill("input[name=password]", CREDS["pw"]); p2.click("button[type=submit]"); p2.wait_for_load_state()
    shot(p2, "t_totp"); b2.close(); b.close()
