#!/usr/bin/env python3
"""Rechnet WCAG-Kontraste der Designs in Admin (app.css) und öffentlicher Seite (site.css). Aufruf: python3 tools/kontrast.py [-v]"""
import re, sys
css = ''.join(f'\n/*@file {n}*/' + open(f'public/assets/css/{n}.css').read() for n in ('app', 'site'))
def lum(h):
    h = h.lstrip('#'); r, g, b = [int(h[i:i+2], 16)/255 for i in (0, 2, 4)]
    f = lambda c: c/12.92 if c <= .03928 else ((c+.055)/1.055)**2.4
    return .2126*f(r)+.7152*f(g)+.0722*f(b)
def cr(a, b):
    la, lb = sorted((lum(a), lum(b)), reverse=True); return (la+.05)/(lb+.05)
bad = 0
sets = []
for part in css.split('\n/*@file ')[1:]:
    datei, _, text = part.partition('*/')
    for m in re.finditer(r'/\*@set (\w+) (\w+)\*/[^{]*\{([^}]*)\}', text):
        sets.append((datei, *m.groups()))
for datei, d, t, body in sets:
    d = datei + ':' + d
    v = dict(re.findall(r'--([\w-]+):(#[0-9a-fA-F]{6})', body))
    pairs = [('ink','bg'),('ink','surface'),('ink','soft'),('muted','bg'),('muted','surface'),('muted','soft'),('accent-strong','bg'),('accent-strong','surface'),('accent-strong','soft'),('on-accent','accent'),('on-accent','accent-strong'),('btn-fg','btn-bg')]
    for a, b in [('accent','bg'),('accent','surface')]:
        if a in v and b in v:
            c = cr(v[a], v[b]); ok = c >= 3; bad += not ok
            if not ok or '-v' in sys.argv: print(f'{d}/{t}: {a} (nur große Schrift) auf {b} = {c:.2f} {"ok" if ok else "ZU NIEDRIG"}')
    for a, b in pairs:
        if a in v and b in v:
            c = cr(v[a], v[b]); ok = c >= 4.5; bad += not ok
            if not ok or '-v' in sys.argv: print(f'{d}/{t}: {a} auf {b} = {c:.2f} {"ok" if ok else "ZU NIEDRIG"}')
print('Fehler:', bad); sys.exit(1 if bad else 0)
