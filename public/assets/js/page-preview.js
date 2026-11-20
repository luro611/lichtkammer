// Vorschau neben dem Editor (Entwurf 29, Teil F). Schickt den aktuellen Stand der Blöcke per POST an /admin/vorschau-live
// (speichert nichts) und zeigt die Antwort in einem Rahmen ohne Skripte. Ohne Bibliothek. Läuft nur mit page-editor.js (das ruft
// window.LKPreview(api) auf). Der Server prüft die Blöcke wie beim Speichern; hier entsteht nur eine Anzeige.
window.LKPreview = function (api) {
  'use strict';
  var root = api.root, el = api.el, btn = api.btn, say = api.say;
  var url = root.getAttribute('data-preview-url') || '';
  var target = root.getAttribute('data-preview-target') || '';
  var csrfEl = api.form.querySelector('input[name="_csrf"]');
  if (!url || !target || !csrfEl) { return; }

  var open = false, inflight = false, queued = false, lastAt = 0, timer = null, guard = null, scrollY = 0;
  var MIN_GAP = 2500; // höchstens eine Anfrage je 2,5 Sekunden, nie mehrere gleichzeitig

  // Panel
  var panel = el('section', 'epreview');
  panel.hidden = true;
  panel.setAttribute('aria-label', 'Vorschau neben dem Editor');
  var head = el('div', 'eprevhead');
  head.appendChild(el('strong', 'eprevtag', 'Vorschau, nicht gespeichert, nicht online'));
  var refreshBtn = btn('Aktualisieren', '', function () { refresh(true); }, 'Vorschau jetzt aktualisieren');
  head.appendChild(refreshBtn);
  var autoBox = el('input');
  autoBox.type = 'checkbox';
  autoBox.checked = true;
  var autoLab = el('label', 'check');
  autoLab.appendChild(autoBox);
  autoLab.appendChild(document.createTextNode(' Automatisch aktualisieren'));
  head.appendChild(autoLab);
  var backBtn = btn('Zurück zum Bearbeiten', 'eprevback', function () { toggle(false); }, 'Vorschau schließen und weiter bearbeiten');
  head.appendChild(backBtn);
  panel.appendChild(head);
  var status = el('p', 'small muted');
  status.setAttribute('role', 'status');
  panel.appendChild(status);
  var frameName = 'lk-preview-' + Math.random().toString(36).slice(2, 8);
  var frame = el('iframe', 'eprevframe');
  frame.name = frameName;
  // Nur Anzeige: der Rahmen führt kein Skript aus, öffnet keine Fenster, sendet keine Formulare und navigiert die Seite darüber nicht.
  frame.setAttribute('sandbox', 'allow-same-origin');
  frame.setAttribute('title', 'Vorschau der Seite (nicht gespeichert, nicht online)');
  frame.setAttribute('referrerpolicy', 'no-referrer');
  panel.appendChild(frame);
  panel.appendChild(el('p', 'small muted', 'Zeigt deine Änderungen schon vor dem Speichern. Links, Karussell-Bewegung und Vergrößern funktionieren hier nicht; so sieht die Seite online aus, nachdem du veröffentlicht hast.'));
  api.wrap.appendChild(panel);

  // Eigenes Formular außerhalb des Editor-Formulars (Formulare lassen sich nicht schachteln); sendet in den Rahmen.
  var pf = el('form');
  pf.method = 'post';
  pf.action = url;
  pf.target = frameName;
  pf.hidden = true;
  var fBlocks = el('input'), fTarget = el('input'), fCsrf = el('input');
  [[fBlocks, 'blocks'], [fTarget, 'ziel'], [fCsrf, '_csrf']].forEach(function (a) { a[0].type = 'hidden'; a[0].name = a[1]; pf.appendChild(a[0]); });
  document.body.appendChild(pf);

  // Knopf in der oberen Leiste
  var toggleBtn = btn('Vorschau neben dem Editor', 'eprevtoggle', function () { toggle(!open); }, 'Vorschau neben dem Editor ein- oder ausblenden');
  toggleBtn.setAttribute('aria-pressed', 'false');
  api.toolbar.insertBefore(toggleBtn, api.toolbar.firstChild.nextSibling);

  function toggle(on) {
    open = on;
    panel.hidden = !on;
    toggleBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
    root.classList.toggle('show-preview', on);
    if (on) { refresh(true); say('Vorschau eingeblendet.'); } else { clearTimeout(timer); say('Vorschau ausgeblendet.'); toggleBtn.focus(); }
  }

  function schedule() {
    if (!open || !autoBox.checked) { return; }
    clearTimeout(timer);
    timer = setTimeout(function () { refresh(false); }, 1000);
  }
  function refresh(now) {
    if (!open) { return; }
    clearTimeout(timer);
    if (inflight) { queued = true; return; }
    var gap = Date.now() - lastAt;
    if (!now && gap < MIN_GAP) { timer = setTimeout(function () { refresh(false); }, MIN_GAP - gap); return; }
    if (now && gap < 800) { return; } // doppelter Klick
    try { scrollY = frame.contentWindow ? frame.contentWindow.scrollY || 0 : 0; } catch (e) { scrollY = 0; }
    fBlocks.value = api.json();
    fTarget.value = target;
    fCsrf.value = csrfEl.value;
    inflight = true;
    lastAt = Date.now();
    status.textContent = 'Wird aktualisiert …';
    panel.setAttribute('aria-busy', 'true');
    clearTimeout(guard);
    guard = setTimeout(done, 20000);
    pf.submit();
  }
  function done() {
    clearTimeout(guard);
    inflight = false;
    panel.removeAttribute('aria-busy');
    var d = new Date();
    // Fehlerseiten der Route tragen data-lk-fehler am <body>: dann die Meldung oben zeigen, nicht „Aktualisiert“ (Prüfung 42, N3)
    var err = '';
    try { var bd = frame.contentDocument && frame.contentDocument.body; err = bd && bd.hasAttribute('data-lk-fehler') ? (bd.textContent || '').trim() : ''; } catch (e) { err = ''; }
    status.textContent = err ? 'Vorschau nicht aktualisiert: ' + err : 'Aktualisiert um ' + ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2) + ':' + ('0' + d.getSeconds()).slice(-2) + ' Uhr.';
    if (err) { say(status.textContent); }
    if (queued) { queued = false; schedule(); }
  }
  frame.addEventListener('load', function () {
    if (!inflight) { return; }
    try { if (scrollY && frame.contentWindow) { frame.contentWindow.scrollTo(0, scrollY); } } catch (e) { /* weg */ }
    done();
  });

  api.onChange(schedule);
  // Suche im Seitenstreifen und Knöpfe der Vorschau selbst ändern die Seite nicht (Prüfung 42, N2)
  var fromPage = function (e) { var t = e.target; if (!(t && t.closest && t.closest('.emat,.epreview'))) { schedule(); } };
  api.form.addEventListener('input', fromPage);
  api.form.addEventListener('change', fromPage);
};
