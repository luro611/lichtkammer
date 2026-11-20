// Seiten-Editor mit Blöcken (Entwurf 12). Ohne Bibliothek. Baut die Oberfläche aus dem eingebetteten JSON,
// schreibt beim Speichern das Block-JSON in das versteckte Feld "blocks". Der Server prüft alles noch einmal.
// Ausgabe nur über textContent und Attribute (nie als HTML eingefügt). Alles per Tastatur bedienbar (Pfeilknöpfe statt nur Ziehen).
(function () {
  'use strict';
  var root = document.getElementById('page-editor');
  var dataEl = document.getElementById('editor-data');
  var field = document.getElementById('blocks-field');
  if (!root || !dataEl || !field || !field.form) { return; }
  var form = field.form;
  var init;
  try { init = JSON.parse(dataEl.textContent || '{}'); } catch (e) { init = {}; }
  var state = Array.isArray(init.blocks) ? init.blocks : [];
  var photos = init.photos && typeof init.photos === 'object' ? init.photos : {};
  var files = init.files && typeof init.files === 'object' ? init.files : {}; // Dateien aus Medien: Id → { n: Name, k: Art, s: Größe, x: 0|1 (nicht verfügbar), r: Grund }; nur für die Anzeige
  var fileUrl = root.getAttribute('data-file-url') || '';
  var isHome = !!init.home;
  var homePhotos = Array.isArray(init.homePhotos) ? init.homePhotos : [];
  var pickUrl = root.getAttribute('data-pick-url') || '';
  var dirty = false, submitting = false, uid = 0, focusId = null, focusSel = '', dropFocus = null;

  var TYPES = {
    heading: { name: 'Überschrift', desc: 'Zwischentitel für einen Abschnitt' },
    text: { name: 'Text', desc: 'Fließtext mit fett, kursiv, Listen und Links' },
    image: { name: 'Bild', desc: 'Ein Foto aus der Bibliothek mit Bildunterschrift' },
    gallery: { name: 'Galerie-Raster', desc: 'Mehrere Fotos als Raster, Klick vergrößert' },
    quote: { name: 'Zitat', desc: 'Hervorgehobener Satz mit Quelle' },
    rule: { name: 'Trennlinie', desc: 'Eine dünne Linie zwischen Abschnitten' },
    button: { name: 'Knopf / Link', desc: 'Ein Knopf zu einer anderen Seite, einer Adresse oder einer Datei aus Medien' },
    download: { name: 'Datei zum Herunterladen', desc: 'Eine öffentliche Datei aus Medien (z. B. PDF) als Link mit Name und Größe' },
    columns: { name: 'Zwei Spalten', desc: 'Zwei Spalten nebeneinander, auf dem Handy untereinander' },
    imagetext: { name: 'Bild und Text', desc: 'Ein Foto neben einem Text, links oder rechts' },
    carousel: { name: 'Karussell', desc: 'Mehrere Fotos zum Durchblättern, auf Wunsch automatisch' },
    hero: { name: 'Titelbild', desc: 'Großes Bild mit Überschrift (nur Startseite)' },
    homephotos: { name: 'Startseiten-Fotos', desc: 'Zeigt die Fotos, die in der Galerie mit „Startseite“ markiert sind (Karussell, Raster oder ein Bild)' },
    galleries: { name: 'Galerien-Kacheln', desc: 'Die Galerien als Kacheln mit Titelbild' }
  };
  var ALL = (isHome ? ['hero'] : []).concat(['heading', 'text', 'imagetext', 'image', 'homephotos', 'carousel', 'gallery', 'galleries', 'quote', 'rule', 'button', 'download', 'columns']);
  var SIMPLE = ['heading', 'text', 'image', 'quote', 'button', 'download'];
  var FILE_TYPES = ['button', 'download']; // Blöcke, in die sich eine Datei aus Medien einsetzen lässt

  function make(t) {
    switch (t) {
      case 'heading': return { t: t, level: 2, text: '', style: 'normal' };
      case 'text': return { t: t, text: '', style: 'normal' };
      case 'imagetext': return { t: t, photo: 0, alt: '', decor: false, caption: '', text: '', side: 'links', style: 'normal' };
      case 'carousel': return { t: t, photos: [], auto: false, interval: 6, ratio: '3-2', span: 'box' };
      case 'hero': return { t: t, photo: 0, alt: '', decor: true, title: '', show: true, span: 'box', height: 'normal', fx: 50, fy: 50 };
      case 'galleries': return { t: t, title: 'Galerien', limit: 0 };
      case 'homephotos': return { t: t, focus: {}, mode: 'karussell', span: 'voll', ratio: '16-9', auto: true, interval: 6, limit: 8, height: 'normal' };
      case 'image': return { t: t, photo: 0, alt: '', decor: false, caption: '', width: 'normal', href: '' };
      case 'gallery': return { t: t, photos: [], cols: 3 };
      case 'quote': return { t: t, text: '', by: '', style: 'normal' };
      case 'rule': return { t: t };
      case 'button': return { t: t, label: '', href: '', style: 'normal' };
      case 'download': return { t: t, file: 0, title: '', style: 'normal' };
      default: return { t: 'columns', left: [], right: [] };
    }
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = text; }
    return n;
  }
  function btn(label, cls, onclick, aria) {
    var b = el('button', 'btn small ghost ' + (cls || ''), label);
    b.type = 'button';
    if (aria) { b.setAttribute('aria-label', aria); }
    b.addEventListener('click', onclick);
    return b;
  }
  function labelled(text, control) {
    var l = el('label', 'field');
    l.appendChild(el('span', null, text));
    l.appendChild(control);
    return l;
  }
  function input(obj, key, max, type) {
    var i = el('input');
    i.type = type || 'text';
    i.value = obj[key] || '';
    if (max) { i.maxLength = max; }
    i.addEventListener('input', function () { obj[key] = i.value; dirty = true; });
    return i;
  }
  function textarea(obj, key, max, rows) {
    var t = el('textarea');
    t.rows = rows || 6;
    t.value = obj[key] || '';
    if (max) { t.maxLength = max; }
    t.addEventListener('input', function () { obj[key] = t.value; dirty = true; });
    return t;
  }
  // Wechselzeit des Karussells in Sekunden (2 bis 30, Standard 6); die Grenzen prüft der Server noch einmal (Blocks.php).
  function intervalField(b) {
    var i = el('input');
    i.type = 'number'; i.min = '2'; i.max = '30'; i.step = '1';
    i.value = String(b.interval || 6);
    i.addEventListener('input', function () { var n = parseInt(i.value, 10); if (n >= 2 && n <= 30) { b.interval = n; dirty = true; } });
    i.addEventListener('change', function () {
      var n = parseInt(i.value, 10);
      n = isNaN(n) ? 6 : Math.max(2, Math.min(30, n));
      i.value = String(n); b.interval = n; dirty = true;
    });
    var l = labelled('Wechselzeit in Sekunden (2 bis 30, Standard 6)', i);
    return l;
  }
  function select(obj, key, options, conv) {
    var s = el('select');
    options.forEach(function (o) {
      var op = el('option', null, o[1]);
      op.value = String(o[0]);
      if (String(obj[key]) === String(o[0])) { op.selected = true; }
      s.appendChild(op);
    });
    s.addEventListener('change', function () { obj[key] = conv ? conv(s.value) : s.value; dirty = true; });
    return s;
  }

  // --- Ansage für Screenreader ---
  var live = el('p', 'sr');
  live.setAttribute('role', 'status');
  live.setAttribute('aria-live', 'polite');
  function say(t) { live.textContent = ''; setTimeout(function () { live.textContent = t; }, 30); }

  // --- Zustand für Ziehen, Rückgängig, Hinweise ---
  var MIME = 'application/x-lichtkammer'; // nur selbst gesetzte Zieh-Daten gelten, alles andere (z. B. Dateien aus dem Finder) wird ignoriert
  var drag = null;      // { kind: 'move', list, b }, { kind: 'new', type }, { kind: 'photo', photo }, { kind: 'gallery', load } oder { kind: 'file', file } (die drei letzten kommen aus dem Seitenstreifen „Material“)
  var PHOTO_TYPES = ['image', 'imagetext', 'hero']; // Blöcke mit genau einem Foto: ein gezogenes Foto setzt sich dort ein
  var changeFns = [];   // Rückrufe bei jeder Änderung (Vorschau neben dem Editor)
  var armed = null;     // gewählter Baustein, der an einem Einfügepunkt abgelegt wird (Tastatur, Handy)
  var undoStack = [], UNDO_MAX = 30, barTimer = null, lastHint = '';

  // Obere Leiste: Rückgängig und Hinweis (werden einmal gebaut, nicht bei jedem Neuzeichnen)
  var toolbar = el('div', 'etoolbar');
  var undoBtn = btn('↶ Rückgängig', 'eundo', function () { undo(); }, 'Letzte Änderung rückgängig machen');
  undoBtn.disabled = true;
  toolbar.appendChild(undoBtn);
  toolbar.appendChild(el('span', 'small muted', 'Rückgängig gilt nur bis zum Speichern (auch Strg/Cmd+Z, außerhalb von Textfeldern).'));
  var hintEl = el('span', 'bdrophint small');
  hintEl.setAttribute('aria-hidden', 'true');
  toolbar.appendChild(hintEl);

  // Meldungsleiste nach dem Löschen
  var bar = el('div', 'bundo');
  bar.hidden = true;
  var barText = el('span');
  bar.appendChild(barText);
  bar.appendChild(btn('Rückgängig', 'bundobtn', function () { undo(); }, 'Löschen rückgängig machen'));

  function hint(t) {
    hintEl.textContent = t || '';
    if (t && t !== lastHint) { say(t); }
    lastHint = t || '';
  }
  function hideBar() { clearTimeout(barTimer); bar.hidden = true; }
  // Die Leiste bleibt, solange Maus oder Fokus darauf sind; danach läuft die Frist neu an (Prüfung 30, N5).
  var barHover = false;
  function barHeld() { return barHover || bar.contains(document.activeElement); }
  function startBarTimer() {
    clearTimeout(barTimer);
    if (barHeld()) { return; }
    barTimer = setTimeout(function () { bar.hidden = true; }, 8000);
  }
  function showBar(t) {
    barText.textContent = t;
    bar.hidden = false;
    startBarTimer();
  }
  bar.addEventListener('mouseenter', function () { barHover = true; clearTimeout(barTimer); });
  bar.addEventListener('mouseleave', function () { barHover = false; startBarTimer(); });
  bar.addEventListener('focusin', function () { clearTimeout(barTimer); });
  bar.addEventListener('focusout', function () { setTimeout(function () { if (!bar.hidden) { startBarTimer(); } }, 0); });
  function updateUndoBtn() { undoBtn.disabled = undoStack.length === 0; }
  // Vor jeder Strukturänderung (Hinzufügen, Löschen, Verschieben, Duplizieren) aufrufen, nicht beim Tippen.
  function pushUndo() {
    undoStack.push(JSON.stringify(state));
    if (undoStack.length > UNDO_MAX) { undoStack.shift(); }
    hint('');
    hideBar();
    updateUndoBtn();
  }
  function byId(list, m) {
    list.forEach(function (b) {
      if (b && b._id) { m[b._id] = b; }
      if (b && b.t === 'columns') { byId(b.left || [], m); byId(b.right || [], m); }
    });
    return m;
  }
  function keepContent(list, m) {
    list.forEach(function (b) {
      var c = b && b._id ? m[b._id] : null;
      // _r: Block, in den ein Foto eingesetzt wurde (setPhoto); hier gilt der alte Inhalt, sonst wäre Rückgängig wirkungslos (Prüfung 42, B1)
      if (b && b._r) { delete b._r; c = null; }
      if (c && c.t === b.t) {
        Object.keys(c).forEach(function (k) { if (k !== 'left' && k !== 'right' && k !== '_c') { b[k] = c[k]; } });
      }
      if (b && b.t === 'columns') { keepContent(b.left || [], m); keepContent(b.right || [], m); }
    });
  }
  function undo() {
    if (!undoStack.length) { return; }
    var prev;
    try { prev = JSON.parse(undoStack.pop()); } catch (e) { updateUndoBtn(); return; }
    prev = Array.isArray(prev) ? prev : [];
    // Rückgängig stellt nur die Struktur zurück. Was seitdem in noch vorhandene Blöcke getippt wurde, bleibt (Prüfung 30, B2).
    keepContent(prev, byId(state, {}));
    state = prev;
    dirty = true;
    stickyHint = '';
    hint('');
    hideBar();
    renderAll();
    updateUndoBtn();
    var t = (!undoBtn.disabled ? undoBtn : root.querySelector('.bhead .btitle') || root.querySelector('.epal summary'));
    if (t && t.focus) { try { t.focus({ preventScroll: true }); } catch (e) { t.focus(); } }
    say('Änderung rückgängig gemacht.');
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && armed) { setArmed(null); say('Baustein abgewählt.'); return; }
    if (!((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && String(e.key).toLowerCase() === 'z')) { return; }
    var a = document.activeElement, tag = a && a.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (a && a.isContentEditable)) { return; }
    if (document.querySelector('dialog[open]') || !undoStack.length) { return; }
    e.preventDefault();
    undo();
  });

  // Welche Ablage ist für das gerade gezogene Ding erlaubt? null = nicht unser Ziehen, 'ok', 'bad'
  function dragStatus(e, allowed) {
    if (!drag || !e.dataTransfer || Array.prototype.indexOf.call(e.dataTransfer.types || [], MIME) < 0) { return null; }
    return allowed.indexOf(dragType()) >= 0 ? 'ok' : 'bad';
  }
  // Welcher Blocktyp entsteht oder wandert gerade? Ein Foto wird ein Bildblock, eine Galerie ein Galerie-Raster.
  function dragType() {
    return drag.kind === 'new' ? drag.type : (drag.kind === 'photo' ? 'image' : (drag.kind === 'gallery' ? 'gallery' : (drag.kind === 'file' ? 'download' : drag.b.t)));
  }
  function badText() {
    var t = dragType();
    return 'Das geht nicht in Spalten: „' + (TYPES[t] ? TYPES[t].name : t) + '“ kann nur auf der Hauptseite stehen.';
  }
  // Ablehnungs-Hinweis bleibt stehen, auch wenn danach das Ende des Ziehens (dragend) kommt, bis zur nächsten Handlung
  var stickyHint = '';
  function endDrag() { drag = null; root.classList.remove('is-dragging'); clearDrop(); hint(stickyHint); }
  function startDrag(e, d, effect) {
    drag = d;
    stickyHint = '';
    root.classList.add('is-dragging');
    if (e.dataTransfer) { e.dataTransfer.effectAllowed = effect; e.dataTransfer.setData(MIME, d.kind); }
  }
  function clearDrop() {
    Array.prototype.forEach.call(root.querySelectorAll('.drop-before,.drop-after,.drop-on,.drop-bad'), function (n) { n.classList.remove('drop-before', 'drop-after', 'drop-on', 'drop-bad'); });
  }
  // Gemeinsame Ablage-Logik für Karten, Einfügepunkte und Listenende
  function dropTarget(node, allowed, getIdx, list, mark, blk) {
    var mk = mark && mark !== 'card' ? mark : node;
    // Ein Foto auf einen Block mit einem Foto (eine Datei auf Knopf oder Datei-Block): einsetzen statt davor/dahinter einfügen
    var replaces = function () { return !!(blk && drag && ((drag.kind === 'photo' && PHOTO_TYPES.indexOf(blk.t) >= 0) || (drag.kind === 'file' && FILE_TYPES.indexOf(blk.t) >= 0))); };
    node.addEventListener('dragover', function (e) {
      var st = dragStatus(e, allowed);
      if (!st) { return; }
      e.stopPropagation();
      clearDrop();
      // Verbotene Ablage ausdrücklich ablehnen (dropEffect none), sonst fällt sie zur umgebenden Spalten-Karte durch (Prüfung 30, B1)
      if (st === 'bad') { e.preventDefault(); if (e.dataTransfer) { e.dataTransfer.dropEffect = 'none'; } mk.classList.add('drop-bad'); stickyHint = badText(); hint(stickyHint); return; }
      e.preventDefault();
      if (e.dataTransfer) { e.dataTransfer.dropEffect = drag.kind === 'move' ? 'move' : 'copy'; }
      stickyHint = '';
      hint('');
      if (replaces()) { node.classList.add('drop-on'); return; }
      var idx = getIdx(e);
      if (mark === 'card') { node.classList.add(idx.after ? 'drop-after' : 'drop-before'); } else { mk.classList.add('drop-on'); }
    });
    node.addEventListener('dragleave', function (e) {
      if (e.relatedTarget && node.contains(e.relatedTarget)) { return; }
      node.classList.remove('drop-before', 'drop-after', 'drop-on', 'drop-bad');
      mk.classList.remove('drop-before', 'drop-after', 'drop-on', 'drop-bad');
    });
    node.addEventListener('drop', function (e) {
      var st = dragStatus(e, allowed);
      if (!st) { return; }
      e.preventDefault();
      e.stopPropagation();
      if (st !== 'ok') { var bad = badText(); endDrag(); stickyHint = bad; hint(bad); return; }
      var rp = replaces(), idx = getIdx(e), d = drag;
      endDrag();
      var at = typeof idx === 'number' ? idx : idx.at;
      if (d.kind === 'photo') { if (rp) { setPhoto(blk, d.photo); } else { insertPhoto(list, at, d.photo, allowed); } return; }
      if (d.kind === 'file') { if (rp) { setFile(blk, d.file); } else { insertFile(list, at, d.file, allowed); } return; }
      if (d.kind === 'gallery') { insertGalleryLater(list, at, allowed, d); return; }
      if (d.kind === 'new') { insertAt(list, at, d.type, allowed); } else { moveBlock(d.b, d.list, list, at, allowed); }
    });
  }

  var COL_MAX = 20; // wie im Server (Blocks.php): höchstens 20 Blöcke je Spalte
  function colFull(list) { return list !== state && list.length >= COL_MAX; }
  var FULL_TEXT = 'Diese Spalte ist voll: Es passen höchstens 20 Blöcke hinein.';
  function insertAt(list, idx, type, allowed, init) {
    if (allowed.indexOf(type) < 0) { hint('Das geht nicht in Spalten: „' + TYPES[type].name + '“ kann nur auf der Hauptseite stehen.'); return; }
    if (colFull(list)) { hint(FULL_TEXT); return; }
    pushUndo();
    var nb = make(type);
    nb._id = ++uid;
    if (init) { init(nb); }
    list.splice(idx, 0, nb);
    dirty = true;
    focusId = nb._id;
    focusSel = type === 'rule' ? '.bhead .btitle' : '';
    setArmed(null);
    say('Block „' + TYPES[type].name + '“ eingefügt auf Position ' + (idx + 1) + '.');
    renderAll();
    if (type === 'image' && !nb.photo) { choosePhoto(nb); }
    if (type === 'download' && !nb.file) { chooseFile(nb); }
  }

  // --- Fotos und Galerien aus dem Seitenstreifen „Material“ (page-material.js) ---
  function remember(r) { photos[r.id] = { t: r.t, a: r.a, c: r.c, u: r.u, x: 0 }; }
  function insertPhoto(list, idx, ph, allowed) {
    insertAt(list, idx, 'image', allowed, function (nb) {
      remember(ph);
      nb.photo = ph.id;
      nb.alt = ph.a || '';
      nb.caption = ph.c || '';
    });
  }
  // Foto in einen vorhandenen Block einsetzen. Alt-Text und Bildunterschrift folgen dem neuen Foto, wenn sie noch die des alten waren oder leer sind.
  function setPhoto(b, ph) {
    if (!b || PHOTO_TYPES.indexOf(b.t) < 0) { return; }
    b._r = 1;
    pushUndo();
    delete b._r;
    var old = photos[b.photo] || null;
    remember(ph);
    if (!b.alt || (old && b.alt === old.a)) { b.alt = ph.a || ''; }
    if (b.t !== 'hero' && (!b.caption || (old && b.caption === old.c))) { b.caption = ph.c || ''; }
    b.photo = ph.id;
    dirty = true;
    focusId = b._id;
    focusSel = '.bphotoside button';
    say('Foto „' + (ph.t || ph.id) + '“ eingesetzt.');
    renderAll();
  }
  function insertGallery(list, idx, g, allowed) {
    var rows = g && g.rows ? g.rows : [];
    if (!rows.length) { hint('Diese Galerie hat noch keine fertigen Fotos.'); return; }
    insertAt(list, idx, 'gallery', allowed, function (nb) {
      rows.slice(0, 12).forEach(function (r) { remember(r); nb.photos.push({ photo: r.id, alt: r.a || '', decor: false }); });
    });
    // sichtbar und angesagt; stickyHint, damit das Ende des Ziehens (dragend) den Hinweis nicht gleich löscht (Prüfung 42, N4)
    if ((g.total || rows.length) > 12) { stickyHint = 'Die Galerie hat ' + g.total + ' Fotos. Ein Raster fasst höchstens 12: die ersten 12 wurden übernommen.'; hint(stickyHint); }
  }
  // --- Dateien aus Medien (Entwurf 44): im Block steht nur die Nummer, Adresse und Größe setzt der Server beim Bau ---
  function rememberFile(f) { files[f.id] = { n: f.n, k: f.k, s: f.s, x: 0, r: '' }; }
  function fileLabel(f) { return String(f.n || '').replace(/\.[A-Za-z0-9]{1,5}$/, '').slice(0, 80); }
  function insertFile(list, idx, f, allowed) {
    insertAt(list, idx, 'download', allowed, function (nb) { rememberFile(f); nb.file = f.id; });
  }
  // Datei in einen vorhandenen Knopf oder Datei-Block einsetzen. Beim Knopf wird die Adresse geleert und eine leere Beschriftung mit dem Dateinamen vorbelegt.
  function setFile(b, f) {
    if (!b || FILE_TYPES.indexOf(b.t) < 0) { return; }
    b._r = 1; // Rückgängig stellt den alten Inhalt wieder her (wie bei setPhoto)
    pushUndo();
    delete b._r;
    rememberFile(f);
    b.file = f.id;
    if (b.t === 'button') { b.href = ''; b._fm = true; if (!String(b.label || '').trim()) { b.label = fileLabel(f); } }
    dirty = true;
    focusId = b._id;
    focusSel = '.bfilepick button';
    say('Datei „' + f.n + '“ eingesetzt.');
    renderAll();
  }
  function clearFile(b) {
    b._r = 1;
    pushUndo();
    delete b._r;
    delete b.file;
    b._fm = false;
    dirty = true;
    focusId = b._id;
    focusSel = '.btargets button';
    renderAll();
  }
  function chooseFile(b) { pickFile(function (f) { setFile(b, f); }); }

  // Lebt die Liste noch im Zustand? (Nach Rückgängig oder Neuzeichnen gehört eine alte Liste nicht mehr dazu.)
  function listAlive(list) {
    if (list === state) { return true; }
    return state.some(function (o) { return o.t === 'columns' && (o.left === list || o.right === list); });
  }
  function insertGalleryLater(list, at, allowed, d) {
    hint('Galerie wird geladen …');
    d.load().then(function (g) {
      hint('');
      if (!listAlive(list)) { hint('Die Seite hat sich inzwischen geändert. Bitte noch einmal ablegen.'); return; }
      insertGallery(list, Math.min(at, list.length), g, allowed);
    }).catch(function () { hint('Die Galerie konnte nicht geladen werden. Bitte noch einmal versuchen.'); });
  }

  // Block aus src nach dst auf Platz idx verschieben (auch zwischen Hauptliste und Spalten)
  function moveBlock(b, src, dst, idx, allowed, label) {
    if (allowed.indexOf(b.t) < 0) { hint('Das geht nicht in Spalten: „' + (TYPES[b.t] ? TYPES[b.t].name : b.t) + '“ kann nur auf der Hauptseite stehen.'); return; }
    var from = src.indexOf(b);
    if (from < 0) { return; }
    if (src !== dst && colFull(dst)) { hint(FULL_TEXT); return; }
    if (src === dst) {
      if (from < idx) { idx--; }
      if (idx === from) { return; }
    }
    pushUndo();
    src.splice(from, 1);
    dst.splice(Math.min(idx, dst.length), 0, b);
    // eine zugeklappte Spalten-Karte muss offen sein, damit man den Block sieht
    state.forEach(function (o) { if (o.t === 'columns' && (o.left === dst || o.right === dst)) { o._c = false; } });
    dirty = true;
    focusId = b._id;
    focusSel = '.bhead .btitle';
    say(label ? 'Block verschoben: ' + label + '.' : 'Block verschoben auf Position ' + (dst.indexOf(b) + 1) + ' von ' + dst.length + '.');
    renderAll();
  }

  // Ziele für „Verschieben nach …“
  function targetsFor(b, src) {
    var out = [];
    var owner = null;
    state.forEach(function (o) { if (o.t === 'columns' && (o.left === src || o.right === src)) { owner = o; } });
    if (src !== state) {
      out.push({ label: 'Hauptseite, hinter dem Spalten-Block', list: state, idx: state.indexOf(owner) + 1, allowed: ALL, said: 'in die Hauptseite' });
    }
    state.forEach(function (o, i) {
      if (o.t !== 'columns' || SIMPLE.indexOf(b.t) < 0) { return; }
      [['left', 'links'], ['right', 'rechts']].forEach(function (s) {
        if (o[s[0]] === src) { return; }
        out.push({ label: 'Spalte ' + s[1] + ' in Block ' + (i + 1) + ' (ans Ende)', list: o[s[0]], idx: o[s[0]].length, allowed: SIMPLE, said: 'in die Spalte ' + s[1] + ' von Block ' + (i + 1) });
      });
    });
    return out;
  }

  function setArmed(t) {
    armed = t;
    Array.prototype.forEach.call(root.querySelectorAll('.epalcard'), function (c) { c.setAttribute('aria-pressed', c.getAttribute('data-type') === t ? 'true' : 'false'); });
    Array.prototype.forEach.call(root.querySelectorAll('.bins'), function (n) { n.classList.toggle('armed', !!t); });
    Array.prototype.forEach.call(root.querySelectorAll('.bins > .binsbtn'), function (b) {
      b.textContent = t ? 'Hier einfügen: ' + TYPES[t].name : '+ hier einfügen';
    });
    Array.prototype.forEach.call(root.querySelectorAll('.badd > summary'), function (n) {
      n.textContent = t ? 'Hier am Ende einfügen: ' + TYPES[t].name : '+ Block hinzufügen';
    });
  }

  function choosePhoto(b) {
    pick(false, function (rows) {
      var r = rows[0];
      photos[r.id] = { t: r.t, a: r.a, c: r.c, u: r.u, x: 0 };
      b.photo = r.id;
      if (!b.alt && r.a) { b.alt = r.a; }
      if (!b.caption && r.c) { b.caption = r.c; }
      dirty = true;
      focusId = b._id;
      focusSel = '.bphotoside button';
      renderAll();
    });
  }

  function dragEndRestoreFocus() {
    endDrag();
    var f = dropFocus; dropFocus = null;
    if (f) { setTimeout(function () { if (document.body.contains(f)) { try { f.focus({ preventScroll: true }); } catch (x) { f.focus(); } } }, 0); }
  }

  // --- Bausteinleiste (einmal gebaut) ---
  var palette = el('details', 'epal');
  if (window.matchMedia && window.matchMedia('(min-width:1100px)').matches) { palette.open = true; }
  palette.appendChild(el('summary', null, 'Bausteine'));
  palette.appendChild(el('p', 'small muted', 'Auf die Seite ziehen oder antippen und dann bei „+ hier einfügen“ ablegen.'));
  var palGrid = el('div', 'epalgrid');
  ALL.forEach(function (t) {
    var c = el('div', 'epalcard', TYPES[t].name);
    c.tabIndex = 0;
    c.setAttribute('role', 'button');
    c.setAttribute('aria-pressed', 'false');
    c.setAttribute('data-type', t);
    c.title = TYPES[t].desc;
    c.draggable = true;
    c.addEventListener('dragstart', function (e) { dropFocus = null; startDrag(e, { kind: 'new', type: t }, 'copy'); });
    // Chromium setzt den Fokus nach dem Ablegen auf die Karte zurück: ins neue Feld nachziehen (Prüfung 30, N2)
    c.addEventListener('dragend', dragEndRestoreFocus);
    var act = function () {
      if (armed === t) { setArmed(null); say('Baustein abgewählt.'); return; }
      setArmed(t);
      say('Baustein „' + TYPES[t].name + '“ gewählt. Jetzt einen Einfügepunkt wählen.');
    };
    c.addEventListener('click', act);
    c.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); act(); } });
    palGrid.appendChild(c);
  });
  palette.appendChild(palGrid);

  var wrapEl = el('div', 'ewrap');
  var mainEl = el('div', 'emain');
  var sideEl = el('div', 'eside');
  sideEl.appendChild(palette);
  wrapEl.appendChild(sideEl);
  wrapEl.appendChild(mainEl);
  root.textContent = '';
  root.appendChild(live);
  root.appendChild(toolbar);
  root.appendChild(wrapEl);
  root.appendChild(bar);

  // --- Liste und Blöcke ---
  function changed() { changeFns.forEach(function (f) { try { f(); } catch (x) { /* eine Erweiterung darf den Editor nicht stören */ } }); }
  function renderAll() {
    mainEl.textContent = '';
    mainEl.appendChild(renderList(state, ALL));
    setArmed(armed);
    updateUndoBtn();
    changed();
    if (focusId !== null) {
      var card = root.querySelector('[data-bid="' + focusId + '"]');
      var target = card && (card.querySelector(focusSel || '.bbody input, .bbody textarea, .bbody select, .bbody button') || card.querySelector('.bhead .btitle'));
      if (target) { target.focus(); dropFocus = target; }
      if (card && card.scrollIntoView) { card.scrollIntoView({ block: 'nearest' }); }
      focusId = null; focusSel = '';
    }
  }

  function renderList(list, allowed) {
    var wrap = el('div', 'blist');
    list.forEach(function (b, i) {
      wrap.appendChild(insertPoint(list, i, allowed));
      wrap.appendChild(renderBlock(list, i, allowed));
    });
    var end = addMenu(list, allowed);
    wrap.appendChild(end);
    // freie Fläche der Liste (z. B. leere Spalte): ans Ende ablegen
    dropTarget(wrap, allowed, function () { return list.length; }, list, end);
    return wrap;
  }

  function typeGrid(list, idx, allowed) {
    var g = el('div', 'baddgrid');
    allowed.forEach(function (t) {
      var b = el('button', 'baddcard');
      b.type = 'button';
      b.appendChild(el('strong', null, TYPES[t].name));
      b.appendChild(el('span', 'small muted', TYPES[t].desc));
      b.addEventListener('click', function () { insertAt(list, idx, t, allowed); });
      g.appendChild(b);
    });
    return g;
  }

  // Einfügepunkt vor einem Block: öffnet das Auswahlmenü an genau dieser Stelle oder legt den gewählten Baustein ab
  function insertPoint(list, idx, allowed) {
    var d = el('div', 'bins');
    var b = el('button', 'binsbtn', '+ hier einfügen');
    b.type = 'button';
    b.setAttribute('aria-label', 'Neuen Block einfügen vor Block ' + (idx + 1));
    b.setAttribute('aria-expanded', 'false');
    var g = typeGrid(list, idx, allowed);
    g.hidden = true;
    b.addEventListener('click', function () {
      if (armed) { insertAt(list, idx, armed, allowed); return; }
      g.hidden = !g.hidden;
      b.setAttribute('aria-expanded', g.hidden ? 'false' : 'true');
    });
    d.appendChild(b);
    d.appendChild(g);
    dropTarget(d, allowed, function () { return idx; }, list);
    return d;
  }

  function addMenu(list, allowed) {
    var d = el('details', 'badd');
    var sm = el('summary', 'btn ghost', '+ Block hinzufügen');
    // Ist ein Baustein gewählt, legt dieser Knopf ihn direkt am Ende ab (auch in leere Spalte oder auf leere Seite), Prüfung 30, N1.
    sm.addEventListener('click', function (e) {
      if (!armed) { return; }
      e.preventDefault();
      insertAt(list, list.length, armed, allowed);
    });
    d.appendChild(sm);
    d.appendChild(typeGrid(list, list.length, allowed));
    return d;
  }

  function summary(b) {
    var cut = function (s) { s = String(s || '').replace(/\s+/g, ' ').trim(); return s.length > 70 ? s.slice(0, 70) + '…' : s; };
    switch (b.t) {
      case 'heading': case 'text': case 'quote': return cut(b.text);
      case 'image': return cut(b.caption || b.alt || (photos[b.photo] && photos[b.photo].t));
      case 'imagetext': return cut(b.text || b.alt);
      case 'hero': return cut(b.title);
      case 'galleries': return cut(b.title);
      case 'homephotos': return 'Fotos mit Schalter „Startseite“';
      case 'carousel': case 'gallery': return (b.photos.length === 1 ? '1 Foto' : b.photos.length + ' Fotos');
      case 'button': return cut(b.label);
      case 'download': return cut(b.title || (files[b.file] && files[b.file].n));
      case 'columns': return 'links ' + b.left.length + ', rechts ' + b.right.length + ' Blöcke';
      default: return '';
    }
  }

  function renderBlock(list, i, allowed) {
    var b = list[i];
    if (!b._id) { b._id = ++uid; }
    var tname = TYPES[b.t] ? TYPES[b.t].name : b.t;
    var nr = i + 1;
    var card = el('article', 'bblock' + (b._c ? ' collapsed' : ''));
    card.setAttribute('data-bid', String(b._id));
    card.setAttribute('aria-label', 'Block ' + nr + ': ' + tname);
    var head = el('div', 'bhead');
    var handle = el('span', 'bhandle', '⠿');
    handle.draggable = true;
    handle.setAttribute('aria-hidden', 'true');
    handle.title = 'Zum Sortieren ziehen';
    handle.addEventListener('dragstart', function (e) {
      startDrag(e, { kind: 'move', list: list, b: b }, 'move');
      if (e.dataTransfer && e.dataTransfer.setDragImage) { e.dataTransfer.setDragImage(card, 10, 10); }
      card.classList.add('dragging');
    });
    handle.addEventListener('dragend', function () { endDrag(); card.classList.remove('dragging'); });
    head.appendChild(handle);
    var ttl = el('strong', 'btitle', nr + '. ' + tname);
    ttl.tabIndex = -1;
    head.appendChild(ttl);
    head.appendChild(el('span', 'bsummary small muted', b._c ? summary(b) : ''));
    var tools = el('div', 'btools');
    var up = btn('↑', 'bup', function () { move(list, i, -1); }, tname + ' ' + nr + ' nach oben');
    var down = btn('↓', 'bdown', function () { move(list, i, 1); }, tname + ' ' + nr + ' nach unten');
    if (i === 0) { up.disabled = true; }
    if (i === list.length - 1) { down.disabled = true; }
    tools.appendChild(up);
    tools.appendChild(down);
    var tg = btn(b._c ? 'Aufklappen' : 'Zuklappen', '', function () { b._c = !b._c; focusId = b._id; focusSel = '.bhead button:nth-of-type(3)'; renderAll(); }, (b._c ? 'Aufklappen: ' : 'Zuklappen: ') + tname + ' ' + nr);
    tg.setAttribute('aria-expanded', b._c ? 'false' : 'true');
    tools.appendChild(tg);
    tools.appendChild(btn('Duplizieren', '', function () {
      if (colFull(list)) { hint(FULL_TEXT); return; } // Prüfung 31: auch Duplizieren füllt eine Spalte
      pushUndo();
      var copy = JSON.parse(JSON.stringify(b, replacer));
      copy._id = ++uid;
      list.splice(i + 1, 0, copy);
      dirty = true;
      focusId = copy._id;
      focusSel = '.bhead .bup';
      say('Block dupliziert.');
      renderAll();
    }, tname + ' ' + nr + ' duplizieren'));
    tools.appendChild(btn('Löschen', '', function () {
      pushUndo();
      list.splice(i, 1);
      dirty = true;
      var near = list[i] || list[i - 1];
      if (near) { focusId = near._id || null; focusSel = '.bhead .btitle'; }
      say('Block gelöscht. Mit „Rückgängig“ lässt er sich zurückholen.');
      renderAll();
      showBar('Block „' + tname + '“ gelöscht.');
    }, tname + ' ' + nr + ' löschen'));
    var tg2 = targetsFor(b, list);
    if (tg2.length) {
      var mv = el('details', 'bmove');
      var ms = el('summary', 'btn small ghost', 'Verschieben nach …');
      ms.setAttribute('aria-label', tname + ' ' + nr + ' verschieben nach …');
      mv.appendChild(ms);
      var ml = el('div', 'bmovelist');
      tg2.forEach(function (t) {
        var mb = el('button', 'btn small ghost', t.label);
        mb.type = 'button';
        mb.addEventListener('click', function () { moveBlock(b, list, t.list, t.idx, t.allowed, tname + ' ' + nr + ' ' + t.said); });
        ml.appendChild(mb);
      });
      mv.appendChild(ml);
      tools.appendChild(mv);
    }
    head.appendChild(tools);
    card.appendChild(head);
    var body = el('div', 'bbody');
    if (b._c) { body.hidden = true; }
    fill(body, b);
    card.appendChild(body);

    // Ablegen beim Ziehen: vor oder hinter dieser Karte (auch aus anderen Listen und von der Bausteinleiste)
    dropTarget(card, allowed, function (e) {
      var r = card.getBoundingClientRect();
      var after = e.clientY >= r.top + r.height / 2;
      return { after: after, at: list.indexOf(b) + (after ? 1 : 0) };
    }, list, 'card', b);
    return card;
  }

  function move(list, i, d) {
    var j = i + d;
    if (j < 0 || j >= list.length) { return; }
    pushUndo();
    var it = list.splice(i, 1)[0];
    list.splice(j, 0, it);
    dirty = true;
    focusId = it._id;
    focusSel = (j === 0 ? '.bhead .bdown' : (j === list.length - 1 ? '.bhead .bup' : (d < 0 ? '.bhead .bup' : '.bhead .bdown')));
    say('Block verschoben auf Position ' + (j + 1) + ' von ' + list.length + '.');
    renderAll();
  }

  // --- Felder je Blocktyp ---
  function fill(body, b) {
    switch (b.t) {
      case 'heading':
        body.appendChild(labelled('Größe', select(b, 'level', [[2, 'Große Überschrift'], [3, 'Kleinere Überschrift']], Number)));
        body.appendChild(labelled('Überschrift', input(b, 'text', 200)));
        body.appendChild(labelled('Stil', select(b, 'style', [['normal', 'Linksbündig'], ['mitte', 'Zentriert']])));
        break;
      case 'text':
        body.appendChild(labelled('Text', textarea(b, 'text', 20000, 7)));
        body.appendChild(labelled('Stil', select(b, 'style', [['normal', 'Normal'], ['gross', 'Groß (Einleitung)'], ['mitte', 'Zentriert'], ['flaeche', 'Auf heller Fläche']])));
        body.appendChild(el('p', 'small muted', 'Gestaltung: **fett**, *kursiv*, Liste mit „- “ am Zeilenanfang, Link als [Text](https://adresse.de). Leerzeile = neuer Absatz.'));
        break;
      case 'quote':
        body.appendChild(labelled('Zitat', textarea(b, 'text', 1000, 3)));
        body.appendChild(labelled('Quelle (optional)', input(b, 'by', 200)));
        body.appendChild(labelled('Stil', select(b, 'style', [['normal', 'Normal'], ['gross', 'Groß'], ['flaeche', 'Auf heller Fläche']])));
        break;
      case 'rule':
        body.appendChild(el('p', 'small muted', 'Eine dünne Linie. Hier gibt es nichts auszufüllen.'));
        break;
      case 'button':
        body.appendChild(labelled('Beschriftung', input(b, 'label', 80)));
        fillTarget(body, b);
        body.appendChild(labelled('Stil', select(b, 'style', [['normal', 'Linksbündig'], ['mitte', 'Zentriert']])));
        break;
      case 'download':
        body.appendChild(fileRow(b, false));
        body.appendChild(labelled('Titel (optional, sonst der Dateiname)', input(b, 'title', 120)));
        body.appendChild(labelled('Stil', select(b, 'style', [['normal', 'Normal'], ['flaeche', 'Auf heller Fläche']])));
        body.appendChild(el('p', 'small muted', 'Zeigt Titel, Art und Größe der Datei als Link. Es gibt nur öffentliche Dateien; in Medien bei der Datei „Öffentlich schalten“ wählen. Wird die Datei später wieder privat oder gelöscht, lässt sich nicht mehr veröffentlichen, bis das behoben ist.'));
        break;
      case 'image': fillImage(body, b); break;
      case 'gallery': fillGallery(body, b); break;
      case 'carousel': fillGallery(body, b, true); break;
      case 'imagetext':
        fillImage(body, b, true);
        break;
      case 'hero':
        fillHero(body, b);
        break;
      case 'homephotos':
        body.appendChild(el('p', 'small muted', 'Dieser Block zeigt die Fotos, die du in einer Galerie mit dem Knopf „Startseite“ markiert hast. So änderst du die Bilder der Startseite, ohne diese Seite zu bearbeiten.'));
        body.appendChild(labelled('Darstellung', select(b, 'mode', [['karussell', 'Karussell (Diashow)'], ['raster', 'Raster'], ['titelbild', 'Ein großes Bild (das erste markierte)']])));
        body.appendChild(labelled('Breite', select(b, 'span', [['box', 'Im Seitenrahmen mit runden Ecken'], ['voll', 'Über die ganze Fensterbreite']])));
        body.appendChild(labelled('Format (Karussell)', select(b, 'ratio', [['3-2', 'Standard (3:2)'], ['16-9', 'Breit (16:9)'], ['2-1', 'Sehr breit (2:1)'], ['21-9', 'Panorama (21:9)'], ['3-1', 'Schmales Band (3:1)']])));
        body.appendChild(labelled('Höhe (großes Bild)', select(b, 'height', [['niedrig', 'Niedrig'], ['normal', 'Normal'], ['hoch', 'Hoch']])));
        body.appendChild(labelled('Höchstens so viele Fotos', select(b, 'limit', [[4, '4'], [6, '6'], [8, '8'], [12, '12']], Number)));
        var au = el('input');
        au.type = 'checkbox';
        au.checked = !!b.auto;
        au.addEventListener('change', function () { b.auto = au.checked; dirty = true; });
        var aul = el('label', 'check');
        aul.appendChild(au);
        aul.appendChild(document.createTextNode(' Automatisch weiterblättern (Karussell)'));
        body.appendChild(aul);
        body.appendChild(intervalField(b));
        if (!b.focus || typeof b.focus !== 'object') { b.focus = {}; }
        body.appendChild(el('h4', null, 'Ausschnitt je Foto'));
        if (!homePhotos.length) {
          body.appendChild(el('p', 'warn small', 'Noch kein Foto mit „Startseite“ markiert. In einer Galerie beim Foto auf „Startseite“ klicken.'));
        }
        homePhotos.forEach(function (hp) {
          var o = b.focus[hp.id] || { fx: 50, fy: 50 };
          o.photo = hp.id;
          b.focus[hp.id] = o;
          var fd = el('details', 'bfocusdet');
          fd.appendChild(el('summary', 'small', hp.t || ('Foto ' + hp.id)));
          var fpp = focusPicker(o);
          if (fpp) { fd.appendChild(fpp); }
          body.appendChild(fd);
        });
        break;
      case 'galleries':
        body.appendChild(labelled('Überschrift über den Kacheln (leer = keine)', input(b, 'title', 100)));
        body.appendChild(labelled('Anzahl', select(b, 'limit', [[0, 'Alle Galerien'], [3, 'Die ersten 3'], [6, 'Die ersten 6'], [9, 'Die ersten 9']], Number)));
        body.appendChild(el('p', 'small muted', 'Zeigt die veröffentlichten Galerien mit ihrem Titelbild. Hier gibt es sonst nichts auszufüllen.'));
        break;
      case 'columns': {
        var cols = el('div', 'bcolsedit');
        [['left', 'Linke Spalte'], ['right', 'Rechte Spalte']].forEach(function (s) {
          var c = el('div', 'bcol');
          c.appendChild(el('h4', null, s[1]));
          c.appendChild(renderList(b[s[0]], SIMPLE));
          cols.appendChild(c);
        });
        body.appendChild(cols);
        break;
      }
    }
  }

  // Ziel eines Knopfes: Adresse eintippen oder Datei aus Medien (zwei Knöpfe, per Tastatur erreichbar)
  function fillTarget(body, b) {
    var fileMode = Number(b.file) > 0 || !!b._fm;
    var modes = el('div', 'bfiletabs btargets');
    modes.setAttribute('role', 'group');
    modes.setAttribute('aria-label', 'Ziel des Knopfes');
    var ba = btn('Adresse eintippen', '', function () { if (fileMode) { if (Number(b.file) > 0) { clearFile(b); } else { b._fm = false; focusId = b._id; focusSel = '.btargets button'; renderAll(); } } });
    var bf = btn('Datei aus Medien', '', function () {
      if (fileMode) { return; }
      b._fm = true; focusId = b._id; focusSel = '.btargets button:nth-of-type(2)'; renderAll();
      if (fileUrl && !Number(b.file)) { chooseFile(b); }
    });
    ba.setAttribute('aria-pressed', fileMode ? 'false' : 'true');
    bf.setAttribute('aria-pressed', fileMode ? 'true' : 'false');
    if (!fileUrl) { bf.disabled = true; }
    modes.appendChild(ba);
    modes.appendChild(bf);
    var tf = el('div', 'field');
    tf.appendChild(el('span', null, 'Ziel'));
    tf.appendChild(modes);
    body.appendChild(tf);
    if (fileMode) {
      body.appendChild(fileRow(b, true));
    } else {
      body.appendChild(labelled('Adresse', input(b, 'href', 500)));
      body.appendChild(el('p', 'small muted', 'Eigene Seite wie /galerien/ oder /ueber-uns/, eine Adresse mit https:// oder eine Mail-Adresse mit mailto:.'));
    }
  }

  // Gewählte Datei: „PDF · Preisliste 2026 · 1,2 MB“ mit „Andere wählen“; nicht verfügbare Dateien rot
  function fileRow(b, removable) {
    var wrap = el('div', 'bfilepick');
    var id = Number(b.file) || 0;
    var f = id ? files[id] : null;
    if (id) {
      var sel = el('div', 'bfilesel');
      if (f && f.n) {
        sel.appendChild(el('strong', null, f.n));
        sel.appendChild(el('span', 'mono small', (f.k ? f.k + ' · ' : '') + (f.s || '')));
      } else {
        sel.appendChild(el('strong', null, f ? 'Datei nicht mehr vorhanden' : 'Datei ' + id));
      }
      wrap.appendChild(sel);
      if (f && f.x) {
        var bad = el('p', 'warn small bfilebad', 'Diese Datei ist nicht verfügbar' + (f.r ? ' (' + f.r + ')' : '') + '. Bitte eine andere wählen oder die Datei in Medien wieder öffentlich schalten; sonst lässt sich die Seite nicht veröffentlichen.');
        wrap.appendChild(bad);
        var ml = el('a', 'small', 'Medien öffnen');
        ml.href = '/admin/medien';
        ml.target = '_blank';
        ml.rel = 'noopener';
        wrap.appendChild(ml);
      }
    } else {
      wrap.appendChild(el('p', 'warn small', 'Bitte eine Datei auswählen.'));
    }
    var row = el('div', 'actions');
    var cb = btn(id ? 'Andere wählen' : 'Datei auswählen', '', function () { chooseFile(b); });
    if (!fileUrl) { cb.disabled = true; }
    row.appendChild(cb);
    if (removable && id) { row.appendChild(btn('Entfernen (zurück zur Adresse)', '', function () { clearFile(b); })); }
    wrap.appendChild(row);
    return wrap;
  }

  function thumb(id) {
    var p = photos[id];
    var box = el('div', 'bthumb');
    if (p && p.u) {
      var im = el('img');
      im.src = p.u;
      im.alt = '';
      im.loading = 'lazy';
      box.appendChild(im);
    } else {
      box.appendChild(el('span', 'small muted', p && p.x ? 'Foto nicht mehr vorhanden' : 'Kein Foto'));
    }
    return box;
  }

  function altRow(obj, noDecor) {
    var wrap = el('div', 'altrow');
    var a = input(obj, 'alt', 250);
    var warn = el('p', 'warn small', 'Alt-Text fehlt: Bitte beschreiben, was auf dem Bild zu sehen ist (für Screenreader und Suchmaschinen). Ohne Alt-Text lässt sich die Seite nicht veröffentlichen.');
    var chk = el('input');
    chk.type = 'checkbox';
    chk.checked = !!obj.decor;
    var lab = el('label', 'check');
    lab.appendChild(chk);
    lab.appendChild(document.createTextNode(' Nur Dekoration (kein Alt-Text nötig)'));
    var upd = function () { warn.hidden = !!obj.decor || String(obj.alt || '').trim() !== ''; a.disabled = !!obj.decor; };
    a.addEventListener('input', upd);
    chk.addEventListener('change', function () { obj.decor = chk.checked; dirty = true; upd(); });
    upd();
    wrap.appendChild(labelled('Alt-Text (Beschreibung des Bildes)', a));
    wrap.appendChild(warn);
    if (!noDecor) { wrap.appendChild(lab); }
    return wrap;
  }

  function fillImage(body, b, withText) {
    var row = el('div', 'bphoto');
    row.appendChild(thumb(b.photo));
    var side = el('div', 'bphotoside');
    var choose = btn(b.photo ? 'Foto ändern' : 'Foto auswählen', '', function () { choosePhoto(b); });
    side.appendChild(choose);
    if (!b.photo) { side.appendChild(el('p', 'warn small', 'Bitte ein Foto auswählen.')); }
    row.appendChild(side);
    body.appendChild(row);
    body.appendChild(altRow(b));
    body.appendChild(labelled('Bildunterschrift (optional)', input(b, 'caption', 300)));
    if (withText) {
      body.appendChild(labelled('Text neben dem Bild', textarea(b, 'text', 20000, 6)));
      body.appendChild(labelled('Bild steht', select(b, 'side', [['links', 'Links vom Text'], ['rechts', 'Rechts vom Text']])));
      body.appendChild(labelled('Stil', select(b, 'style', [['normal', 'Normal'], ['flaeche', 'Auf heller Fläche']])));
      return;
    }
    body.appendChild(labelled('Breite', select(b, 'width', [['schmal', 'Schmal'], ['normal', 'Normal'], ['voll', 'Volle Breite']])));
    body.appendChild(labelled('Link beim Anklicken (optional)', input(b, 'href', 500)));
  }

  // Bildausschnitt: Klick auf das Bild (oder die Regler) legt fest, welcher Punkt beim Zuschneiden im Bild bleibt.
  function focusPicker(obj) {
    var p = photos[obj.photo];
    if (!p || !p.u) { return null; }
    if (typeof obj.fx !== 'number') { obj.fx = 50; }
    if (typeof obj.fy !== 'number') { obj.fy = 50; }
    var wrap = el('div', 'bfocus');
    wrap.appendChild(el('p', 'small muted', 'Ausschnitt: Auf das Bild klicken, was im Mittelpunkt bleiben soll. Bei breiten oder flachen Formaten wird der Rest abgeschnitten.'));
    var box = el('div', 'bfocusbox');
    var im = el('img');
    im.src = p.u;
    im.alt = '';
    box.appendChild(im);
    var dot = el('span', 'bfocusdot');
    box.appendChild(dot);
    var rx = el('input'), ry = el('input');
    function place() { dot.style.left = obj.fx + '%'; dot.style.top = obj.fy + '%'; rx.value = obj.fx; ry.value = obj.fy; }
    box.addEventListener('click', function (e) {
      var r = box.getBoundingClientRect();
      obj.fx = Math.max(0, Math.min(100, Math.round(((e.clientX - r.left) / r.width) * 10) * 10));
      obj.fy = Math.max(0, Math.min(100, Math.round(((e.clientY - r.top) / r.height) * 10) * 10));
      dirty = true;
      place();
    });
    wrap.appendChild(box);
    [[rx, 'fx', 'Waagerecht (links bis rechts)'], [ry, 'fy', 'Senkrecht (oben bis unten)']].forEach(function (a) {
      a[0].type = 'range'; a[0].min = 0; a[0].max = 100; a[0].step = 10;
      a[0].addEventListener('input', function () { obj[a[1]] = Number(a[0].value); dirty = true; place(); });
      wrap.appendChild(labelled(a[2], a[0]));
    });
    place();
    return wrap;
  }

  function fillHero(body, b) {
    var row = el('div', 'bphoto');
    row.appendChild(thumb(b.photo));
    var side = el('div', 'bphotoside');
    side.appendChild(btn(b.photo ? 'Foto ändern' : 'Foto auswählen', '', function () {
      pick(false, function (rows) {
        var r = rows[0];
        photos[r.id] = { t: r.t, a: r.a, c: r.c, u: r.u, x: 0 };
        b.photo = r.id;
        if (!b.alt && r.a) { b.alt = r.a; }
        dirty = true; focusId = b._id; focusSel = '.bphotoside button'; renderAll();
      });
    }));
    if (b.photo) {
      side.appendChild(btn('Foto entfernen', '', function () { b.photo = 0; dirty = true; focusId = b._id; focusSel = '.bphotoside button'; renderAll(); }));
    } else {
      side.appendChild(el('p', 'small muted', 'Ohne Foto erscheint nur die Überschrift.'));
    }
    row.appendChild(side);
    body.appendChild(row);
    body.appendChild(labelled('Überschrift auf dem Bild', input(b, 'title', 200)));
    var sh = el('input');
    sh.type = 'checkbox';
    sh.checked = b.show !== false;
    sh.addEventListener('change', function () { b.show = sh.checked; dirty = true; });
    var shl = el('label', 'check');
    shl.appendChild(sh);
    shl.appendChild(document.createTextNode(' Überschrift auf dem Bild anzeigen (ohne Häkchen bleibt sie nur für Screenreader und Suchmaschinen)'));
    body.appendChild(shl);
    body.appendChild(labelled('Breite', select(b, 'span', [['box', 'Im Seitenrahmen mit runden Ecken'], ['voll', 'Über die ganze Fensterbreite']])));
    body.appendChild(labelled('Höhe', select(b, 'height', [['niedrig', 'Niedrig'], ['normal', 'Normal'], ['hoch', 'Hoch (fast bildschirmfüllend)']])));
    if (b.photo) {
      var fp = focusPicker(b);
      if (fp) { body.appendChild(fp); }
      body.appendChild(altRow(b));
    }
  }

  function fillGallery(body, b, carousel) {
    if (carousel) {
      var auto = el('input');
      auto.type = 'checkbox';
      auto.checked = !!b.auto;
      auto.addEventListener('change', function () { b.auto = auto.checked; dirty = true; });
      var al = el('label', 'check');
      al.appendChild(auto);
      al.appendChild(document.createTextNode(' Automatisch weiterblättern (nicht bei „weniger Bewegung“ im System)'));
      body.appendChild(al);
      body.appendChild(intervalField(b));
      body.appendChild(labelled('Format', select(b, 'ratio', [['3-2', 'Standard (3:2)'], ['16-9', 'Breit (16:9)'], ['2-1', 'Sehr breit (2:1)'], ['21-9', 'Panorama (21:9)'], ['3-1', 'Schmales Band (3:1)']])));
      body.appendChild(labelled('Breite', select(b, 'span', [['box', 'Im Seitenrahmen mit runden Ecken'], ['voll', 'Über die ganze Fensterbreite']])));
    } else {
      body.appendChild(labelled('Spalten', select(b, 'cols', [[2, '2 Spalten'], [3, '3 Spalten'], [4, '4 Spalten']], Number)));
    }
    var ul = el('ul', 'bgal');
    b.photos.forEach(function (p, i) {
      var li = el('li', 'bgalitem');
      li.appendChild(thumb(p.photo));
      var side = el('div', 'bgalside');
      side.appendChild(altRow(p, !carousel));
      if (carousel) {
        var fd = el('details', 'bfocusdet');
        fd.appendChild(el('summary', 'small', 'Bildausschnitt wählen'));
        var fpp = focusPicker(p);
        if (fpp) { fd.appendChild(fpp); }
        side.appendChild(fd);
      }
      var tools = el('div', 'btools');
      var u = btn('↑', '', function () { reorder(b.photos, i, -1, b); }, 'Foto ' + (i + 1) + ' nach vorn');
      var d = btn('↓', '', function () { reorder(b.photos, i, 1, b); }, 'Foto ' + (i + 1) + ' nach hinten');
      if (i === 0) { u.disabled = true; }
      if (i === b.photos.length - 1) { d.disabled = true; }
      tools.appendChild(u);
      tools.appendChild(d);
      tools.appendChild(btn('Entfernen', '', function () { b.photos.splice(i, 1); dirty = true; focusId = b._id; focusSel = '.bgalbtn'; renderAll(); }, 'Foto ' + (i + 1) + ' aus dem Raster entfernen'));
      side.appendChild(tools);
      li.appendChild(side);
      ul.appendChild(li);
    });
    body.appendChild(ul);
    if (!b.photos.length) { body.appendChild(el('p', 'warn small', 'Bitte mindestens ein Foto hinzufügen.')); }
    var add = btn('Fotos hinzufügen', 'bgalbtn', function () {
      pick(true, function (rows) {
        rows.forEach(function (r) {
          if (b.photos.length >= 12) { say('Höchstens 12 Fotos je Raster: nicht alle wurden übernommen.'); return; }
          photos[r.id] = { t: r.t, a: r.a, c: r.c, u: r.u, x: 0 };
          b.photos.push({ photo: r.id, alt: r.a || '', decor: false });
        });
        dirty = true;
        focusId = b._id;
        focusSel = '.bgalbtn';
        renderAll();
      });
    });
    body.appendChild(add);
    body.appendChild(el('p', 'small muted', 'Höchstens 12 Fotos je Raster.'));
  }

  function reorder(arr, i, d, owner) {
    var j = i + d;
    if (j < 0 || j >= arr.length) { return; }
    var it = arr.splice(i, 1)[0];
    arr.splice(j, 0, it);
    dirty = true;
    focusId = owner._id;
    focusSel = '.bgalbtn';
    say('Foto verschoben auf Position ' + (j + 1) + ' von ' + arr.length + '.');
    renderAll();
  }

  // --- Fotoauswahl ---
  function pick(multi, done) {
    var opener = document.activeElement;
    var dlg = el('dialog', 'picker');
    dlg.setAttribute('aria-label', multi ? 'Fotos auswählen' : 'Foto auswählen');
    var inner = el('div', 'pickin');
    inner.appendChild(el('h2', null, multi ? 'Fotos auswählen' : 'Foto auswählen'));
    var q = el('input');
    q.type = 'search';
    q.setAttribute('aria-label', 'Fotos suchen (Titel, Ort, Alt-Text, Dateiname)');
    q.placeholder = 'Suchen …';
    inner.appendChild(q);
    var info = el('p', 'small muted');
    info.setAttribute('role', 'status');
    inner.appendChild(info);
    var grid = el('ul', 'pgrid');
    inner.appendChild(grid);
    var more = btn('Mehr laden', '', function () { load(false); });
    more.hidden = true;
    inner.appendChild(more);
    var foot = el('div', 'actions');
    var ok = btn('Übernehmen', '', function () {
      var rows = Object.keys(sel).map(function (k) { return sel[k]; });
      dlg.close();
      if (rows.length) { done(rows); }
    });
    var cancel = btn('Abbrechen', '', function () { dlg.close(); });
    if (multi) { ok.disabled = true; foot.appendChild(ok); }
    foot.appendChild(cancel);
    inner.appendChild(foot);
    dlg.appendChild(inner);
    var sel = {}, page = 1, timer = null, seq = 0;

    function tile(r) {
      var li = el('li');
      var b = el('button', 'ptile');
      b.type = 'button';
      var im = el('img');
      im.src = r.u;
      im.alt = '';
      im.loading = 'lazy';
      b.appendChild(im);
      b.appendChild(el('span', 'small', r.t));
      if (multi) { b.setAttribute('aria-pressed', sel[r.id] ? 'true' : 'false'); }
      b.addEventListener('click', function () {
        if (!multi) { dlg.close(); done([r]); return; }
        if (sel[r.id]) { delete sel[r.id]; b.setAttribute('aria-pressed', 'false'); } else { sel[r.id] = r; b.setAttribute('aria-pressed', 'true'); }
        var n = Object.keys(sel).length;
        ok.textContent = n ? 'Übernehmen (' + n + ')' : 'Übernehmen';
        ok.disabled = n === 0;
      });
      li.appendChild(b);
      return li;
    }

    function load(reset) {
      if (reset) { page = 1; grid.textContent = ''; } else { page++; }
      var my = ++seq;
      info.textContent = 'Lädt …';
      fetch(pickUrl + '?q=' + encodeURIComponent(q.value) + '&seite=' + page, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { if (!r.ok) { throw new Error('x'); } return r.json(); })
        .then(function (d) {
          if (my !== seq) { return; }
          (d.rows || []).forEach(function (r) { grid.appendChild(tile(r)); });
          more.hidden = !d.more;
          info.textContent = grid.children.length ? '' : 'Keine passenden Fotos gefunden.';
        })
        .catch(function () { if (my === seq) { info.textContent = 'Die Fotos konnten nicht geladen werden. Bitte noch einmal versuchen.'; } });
    }
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { load(true); }, 250); });
    dlg.addEventListener('close', function () { if (dlg.parentNode) { dlg.parentNode.removeChild(dlg); } if (opener && opener.focus) { try { opener.focus(); } catch (e) { /* weg */ } } });
    document.body.appendChild(dlg);
    if (dlg.showModal) { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
    q.focus();
    load(true);
  }

  // --- Dateiauswahl (nur öffentliche Dateien; Quelle /admin/dateien/auswahl) ---
  function pickFile(done) {
    if (!fileUrl) { return; }
    var opener = document.activeElement;
    var dlg = el('dialog', 'picker');
    dlg.setAttribute('aria-label', 'Datei auswählen');
    var inner = el('div', 'pickin');
    inner.appendChild(el('h2', null, 'Datei auswählen'));
    var q = el('input');
    q.type = 'search';
    q.setAttribute('aria-label', 'Dateien suchen (Name oder Ordner)');
    q.placeholder = 'Suchen …';
    inner.appendChild(q);
    var info = el('p', 'small muted');
    info.setAttribute('role', 'status');
    inner.appendChild(info);
    var empty = el('div', 'pfileempty');
    empty.hidden = true;
    var emptyText = el('p', 'small', '');
    empty.appendChild(emptyText);
    var ml = el('a', 'small', 'Medien öffnen');
    ml.href = '/admin/medien';
    ml.target = '_blank';
    ml.rel = 'noopener';
    empty.appendChild(ml);
    inner.appendChild(empty);
    var list = el('ul', 'pfiles');
    inner.appendChild(list);
    var more = btn('Mehr laden', '', function () { load(false); });
    more.hidden = true;
    inner.appendChild(more);
    var foot = el('div', 'actions');
    foot.appendChild(btn('Abbrechen', '', function () { dlg.close(); }));
    inner.appendChild(foot);
    dlg.appendChild(inner);
    var page = 1, timer = null, seq = 0;

    function item(r) {
      var li = el('li');
      var b = el('button', 'pfile');
      b.type = 'button';
      b.appendChild(el('span', 'pn', r.n));
      b.appendChild(el('span', 'pm mono', r.k + ' · ' + r.s));
      if (r.o) { b.appendChild(el('span', 'po', r.o)); }
      b.addEventListener('click', function () { dlg.close(); done(r); });
      li.appendChild(b);
      return li;
    }
    function load(reset) {
      if (reset) { page = 1; list.textContent = ''; } else { page++; }
      var my = ++seq;
      info.textContent = 'Lädt …';
      empty.hidden = true;
      fetch(fileUrl + '?q=' + encodeURIComponent(q.value) + '&seite=' + page, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { if (!r.ok) { throw new Error('x'); } return r.json(); })
        .then(function (d) {
          if (my !== seq) { return; }
          (d.rows || []).forEach(function (r) { list.appendChild(item(r)); });
          more.hidden = !d.more;
          info.textContent = '';
          if (!list.children.length) {
            emptyText.textContent = (q.value ? 'Keine passende Datei gefunden. ' : '') + 'Hier erscheinen nur öffentliche Dateien. In Medien bei der Datei „Öffentlich schalten“ wählen.' + (d.svgHidden ? ' SVG-Dateien fehlen, weil der Webserver den Schutzkopf dafür nicht bestätigt hat.' : '');
            empty.hidden = false;
          }
        })
        .catch(function () { if (my === seq) { info.textContent = 'Die Dateien konnten nicht geladen werden. Bitte noch einmal versuchen.'; } });
    }
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { load(true); }, 250); });
    dlg.addEventListener('close', function () { if (dlg.parentNode) { dlg.parentNode.removeChild(dlg); } if (opener && opener.focus && document.body.contains(opener)) { try { opener.focus(); } catch (e) { /* weg */ } } });
    document.body.appendChild(dlg);
    if (dlg.showModal) { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
    q.focus();
    load(true);
  }

  // --- Speichern, Verlassen ---
  function replacer(k, v) { return k.charAt(0) === '_' ? undefined : v; }
  function sync() { field.value = JSON.stringify({ v: 1, blocks: state }, replacer); }
  form.addEventListener('submit', function () { sync(); submitting = true; });
  form.addEventListener('input', function () { dirty = true; });
  form.addEventListener('change', function () { dirty = true; });
  window.addEventListener('beforeunload', function (e) {
    if (dirty && !submitting) { e.preventDefault(); e.returnValue = ''; }
  });

  form.addEventListener('submit', function () { undoStack = []; });
  renderAll();
  dirty = false;

  // Erweiterungen (page-material.js, page-preview.js) bekommen nur diese Hilfen; Daten nimmt weiter nur der Server an.
  var api = {
    root: root, wrap: wrapEl, side: sideEl, main: mainEl, form: form, toolbar: toolbar, el: el, btn: btn, say: say, hint: hint,
    pickUrl: pickUrl, fileUrl: fileUrl, FILE_TYPES: FILE_TYPES, TYPES: TYPES, SIMPLE: SIMPLE, PHOTO_TYPES: PHOTO_TYPES, isHome: isHome,
    state: function () { return state; },
    json: function () { return JSON.stringify({ v: 1, blocks: state }, replacer); },
    summary: summary,
    startDrag: function (e, d) { dropFocus = null; startDrag(e, d, 'copy'); },
    endDrag: dragEndRestoreFocus,
    insertPhoto: insertPhoto, setPhoto: setPhoto, insertGallery: insertGallery, insertFile: insertFile, setFile: setFile,
    colFull: colFull, onChange: function (f) { changeFns.push(f); }
  };
  if (typeof window.LKMaterial === 'function') { window.LKMaterial(api); }
  if (typeof window.LKPreview === 'function') { window.LKPreview(api); }
})();
