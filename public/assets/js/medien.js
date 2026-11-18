// Medienverwaltung: Auswahl (Klick, Umschalt, Strg/Cmd, Tastatur), Ziehen zum Verschieben, Ordner-Upload.
// Ohne dieses Skript funktioniert alles Übrige (Formulare). Ausgabe nur über textContent, keine Fremdbibliotheken.
// Der Browser ist nie die Sicherheitsgrenze: der Server prüft jeden Namen, jeden Pfad und jede Datei noch einmal.
(function () {
  'use strict';

  var main = document.querySelector('.mmain');
  var form = document.getElementById('msel');

  // ---------- Auswahl ----------
  var rows = function () { return form ? Array.prototype.slice.call(form.querySelectorAll('.mrow, .mcell')) : []; };
  var boxOf = function (el) { return el.querySelector('input[type=checkbox]'); };
  var last = null;
  var countEl = document.getElementById('mcount');

  function refresh() {
    var n = 0;
    rows().forEach(function (r) {
      var b = boxOf(r);
      var on = !!(b && b.checked);
      r.classList.toggle('selected', on);
      if (on) { n++; }
    });
    if (countEl) { countEl.textContent = n > 0 ? n + ' ausgewählt' : ''; }
  }

  function setOnly(row) {
    rows().forEach(function (r) { var b = boxOf(r); if (b) { b.checked = (r === row); } });
  }

  if (form) {
    rows().forEach(function (r) { r.setAttribute('draggable', 'true'); if (!r.hasAttribute('tabindex')) { r.setAttribute('tabindex', '0'); } });
    var all = document.getElementById('mall');
    if (all) {
      all.addEventListener('change', function () {
        rows().forEach(function (r) { var b = boxOf(r); if (b) { b.checked = all.checked; } });
        refresh();
      });
    }
    form.addEventListener('click', function (e) {
      var t = e.target;
      if (t.closest && t.closest('a, button, select, label, textarea') ) { return; }
      var row = t.closest ? t.closest('.mrow, .mcell') : null;
      if (!row) { return; }
      var b = boxOf(row);
      if (t === b) { last = row; refresh(); return; }
      if (e.shiftKey && last) {
        var list = rows();
        var a = list.indexOf(last), z = list.indexOf(row);
        list.slice(Math.min(a, z), Math.max(a, z) + 1).forEach(function (r) { var x = boxOf(r); if (x) { x.checked = true; } });
      } else if (e.metaKey || e.ctrlKey) {
        if (b) { b.checked = !b.checked; }
        last = row;
      } else {
        setOnly(row);
        last = row;
      }
      refresh();
    });
    form.addEventListener('change', function (e) { if (e.target && e.target.type === 'checkbox' && e.target.id !== 'mall') { last = e.target.closest('.mrow, .mcell'); refresh(); } });

    form.addEventListener('keydown', function (e) {
      var row = e.target.closest ? e.target.closest('.mrow, .mcell') : null;
      if (e.target.tagName === 'INPUT' && e.target.type === 'text') { return; }
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'a' && e.target.tagName !== 'SELECT') {
        e.preventDefault();
        rows().forEach(function (r) { var b = boxOf(r); if (b) { b.checked = true; } });
        refresh();
        return;
      }
      if (!row) { return; }
      var list = rows(), i = list.indexOf(row);
      if (e.key === 'ArrowDown' && list[i + 1]) { e.preventDefault(); list[i + 1].focus(); }
      else if (e.key === 'ArrowUp' && list[i - 1]) { e.preventDefault(); list[i - 1].focus(); }
      else if (e.key === ' ' && e.target === row) { e.preventDefault(); var b = boxOf(row); if (b) { b.checked = !b.checked; refresh(); } }
      else if (e.key === 'Enter' && e.target === row) { var a = row.querySelector('a.mname, a.tilelink'); if (a) { a.click(); } }
      else if (e.key === 'F2') { var rn = row.querySelector('a[aria-label$="umbenennen"]'); if (rn) { e.preventDefault(); rn.click(); } }
      else if ((e.key === 'Delete' || e.key === 'Backspace') && e.target === row) {
        var b2 = boxOf(row); if (b2 && !b2.checked) { setOnly(row); refresh(); }
        var del = form.querySelector('button[formaction="/admin/medien/loeschen"]'); if (del) { e.preventDefault(); del.click(); }
      }
    });

    // ---------- Ziehen zum Verschieben ----------
    var dragging = false;
    form.addEventListener('dragstart', function (e) {
      var row = e.target.closest ? e.target.closest('.mrow, .mcell') : null;
      if (!row) { return; }
      var b = boxOf(row);
      if (b && !b.checked) { setOnly(row); refresh(); }
      dragging = true;
      if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', 'lk-medien'); }
      rows().forEach(function (r) { var x = boxOf(r); if (x && x.checked) { r.classList.add('dragging'); } });
    });
    form.addEventListener('dragend', function () {
      dragging = false;
      rows().forEach(function (r) { r.classList.remove('dragging'); });
      Array.prototype.forEach.call(document.querySelectorAll('.dropover'), function (x) { x.classList.remove('dropover'); });
    });
  }

  function dropTarget(e) { return e.target.closest ? e.target.closest('[data-drop-folder]') : null; }
  var isFiles = function (e) { return e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') >= 0; };

  document.addEventListener('dragover', function (e) {
    var t = dropTarget(e);
    if (!t) { return; }
    if ((dragging && !t.classList.contains('dragging')) || isFiles(e)) { e.preventDefault(); t.classList.add('dropover'); }
  });
  document.addEventListener('dragleave', function (e) { var t = dropTarget(e); if (t) { t.classList.remove('dropover'); } });
  document.addEventListener('drop', function (e) {
    var t = dropTarget(e);
    if (!t) { return; }
    t.classList.remove('dropover');
    var target = t.getAttribute('data-drop-folder');
    if (dragging && form) {
      e.preventDefault();
      var sel = document.getElementById('mziel');
      if (!sel) { return; }
      sel.value = target;
      if (sel.value !== target) { return; }
      form.submit();
    } else if (isFiles(e)) {
      e.preventDefault();
      e.stopPropagation();
      collectFromDrop(e.dataTransfer).then(function (items) { begin(items, parseInt(target, 10) || 0); });
    }
  });

  // ---------- Upload ----------
  var box = document.getElementById('mupload');
  var queueEl = document.getElementById('mqueue');
  if (!box || !queueEl) { return; }
  box.hidden = false;
  var note = document.getElementById('noscriptnote');
  if (note) { note.hidden = true; }
  var csrf = box.getAttribute('data-csrf');
  var curFolder = parseInt(box.getAttribute('data-folder'), 10) || 0;
  var maxPhoto = parseInt(box.getAttribute('data-max-mb'), 10) * 1048576;
  var maxPdf = parseInt(box.getAttribute('data-max-file-mb'), 10) * 1048576;
  var photosOk = box.getAttribute('data-photos') === '1';
  var LIMITS = { svg: 1048576, image: 5242880 };
  var MAX_FILES = 5000;
  var dialog = document.getElementById('msummary');
  var running = false;

  function store(k, v) { try { if (v === undefined) { return window.localStorage.getItem(k); } window.localStorage.setItem(k, v); } catch (x) { /* ohne Speicher */ } return null; }

  function isJunk(name) {
    var l = name.toLowerCase();
    return name === '' || name.charAt(0) === '.' || l === 'thumbs.db' || l === 'desktop.ini' || l === '__macosx';
  }
  function extOf(name) { var m = /\.([A-Za-z0-9]+)$/.exec(name); return m ? m[1].toLowerCase() : ''; }
  function classify(file) {
    var ext = extOf(file.name);
    if (ext === 'pdf' || file.type === 'application/pdf') { return 'pdf'; }
    if (ext === 'svg' || file.type === 'image/svg+xml') { return 'svg'; }
    if (['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'].indexOf(ext) >= 0 || /^image\/(jpeg|png|webp|heic|heif)$/.test(file.type)) { return 'image'; }
    return '';
  }
  var natural = function (a, b) { return a.localeCompare(b, 'de', { numeric: true, sensitivity: 'base' }); };
  function fmt(n) { return n >= 1048576 ? (n / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }
  function el(tag, cls, text) { var x = document.createElement(tag); if (cls) { x.className = cls; } if (text !== undefined) { x.textContent = text; } return x; }

  // Eingabe sammeln: { files: [{path:[...], file}], dirs: [[...]] }
  function readAll(reader) {
    return new Promise(function (resolve) {
      var all = [];
      (function next() {
        reader.readEntries(function (batch) {
          if (!batch.length) { resolve(all); return; } // der Browser liefert höchstens 100 je Aufruf: bis „leer“ weiterlesen
          all = all.concat(Array.prototype.slice.call(batch));
          next();
        }, function () { resolve(all); });
      })();
    });
  }
  function walk(entry, path, out) {
    if (entry.isFile) {
      if (isJunk(entry.name)) { out.junk++; return Promise.resolve(); }
      return new Promise(function (res) { entry.file(function (f) { out.files.push({ path: path.slice(), file: f }); res(); }, function () { res(); }); });
    }
    if (!entry.isDirectory) { return Promise.resolve(); }
    if (isJunk(entry.name)) { out.junk++; return Promise.resolve(); }
    var p = path.concat(entry.name);
    out.dirs.push(p);
    return readAll(entry.createReader()).then(function (children) {
      return children.reduce(function (chain, c) { return chain.then(function () { return walk(c, p, out); }); }, Promise.resolve());
    });
  }
  function collectFromDrop(dt) {
    var out = { files: [], dirs: [], junk: 0 };
    var entries = [];
    var items = dt.items || [];
    for (var i = 0; i < items.length; i++) { // webkitGetAsEntry nur synchron aufrufen
      var en = items[i].kind === 'file' && items[i].webkitGetAsEntry ? items[i].webkitGetAsEntry() : null;
      if (en) { entries.push(en); }
    }
    if (!entries.length && dt.files) {
      for (var j = 0; j < dt.files.length; j++) { out.files.push({ path: [], file: dt.files[j] }); }
      return Promise.resolve(out);
    }
    return entries.reduce(function (chain, en) { return chain.then(function () { return walk(en, [], out); }); }, Promise.resolve()).then(function () { return out; });
  }
  function collectFromInput(input, withDirs) {
    var out = { files: [], dirs: [], junk: 0 };
    var seen = {};
    for (var i = 0; i < input.files.length; i++) {
      var f = input.files[i];
      var rel = withDirs && f.webkitRelativePath ? f.webkitRelativePath.split('/') : [f.name];
      var path = rel.slice(0, -1);
      if (rel.some(function (s) { return isJunk(s); })) { out.junk++; continue; }
      for (var d = 1; d <= path.length; d++) { var k = path.slice(0, d).join('/'); if (!seen[k]) { seen[k] = 1; out.dirs.push(path.slice(0, d)); } }
      out.files.push({ path: path, file: f });
    }
    return out;
  }

  // ---------- Fingerabdruck (Prüfung 17, H2): SHA-256 über erste und letzte 64 KB plus Größe ----------
  function fingerprint(file) {
    if (!window.crypto || !window.crypto.subtle || !file.slice || !file.arrayBuffer) { return Promise.resolve(''); }
    var head = file.slice(0, 65536), tail = file.size > 65536 ? file.slice(Math.max(65536, file.size - 65536)) : null;
    return Promise.all([head.arrayBuffer(), tail ? tail.arrayBuffer() : Promise.resolve(new ArrayBuffer(0))]).then(function (b) {
      var meta = new TextEncoder().encode('|' + file.size + '|');
      var buf = new Uint8Array(b[0].byteLength + b[1].byteLength + meta.length);
      buf.set(new Uint8Array(b[0]), 0); buf.set(new Uint8Array(b[1]), b[0].byteLength); buf.set(meta, b[0].byteLength + b[1].byteLength);
      return window.crypto.subtle.digest('SHA-256', buf);
    }).then(function (h) {
      return Array.prototype.map.call(new Uint8Array(h), function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    }).catch(function () { return ''; });
  }

  // ---------- Zusammenfassung vor dem Start ----------
  function begin(input, target) {
    if (running) { return; }
    var skipped = [];
    var entries = [];
    input.files.sort(function (a, b) { return natural(a.path.join('/'), b.path.join('/')) || natural(a.file.name, b.file.name); });
    input.files.forEach(function (it) {
      var f = it.file, kind = classify(f);
      if (!kind) { skipped.push([f.name, 'nicht erlaubter Dateityp']); return; }
      if (f.size < 1) { skipped.push([f.name, 'leer']); return; }
      entries.push({ path: it.path, file: f, kind: kind, name: f.name });
    });
    if (!entries.length && !input.dirs.length) {
      showReport({ added: 0, dups: [], skipped: skipped.concat(input.junk ? [[input.junk + ' Systemdatei(en)', 'Versteck- und Systemdateien']] : []), failed: [], galleries: [] }, true);
      return;
    }
    if (entries.length > MAX_FILES) {
      showReport({ added: 0, dups: [], skipped: [], failed: [['Mehr als 5 000 Dateien', 'Bitte den Ordner in Teilen hochladen.']], galleries: [] }, true);
      return;
    }
    var hasDirs = input.dirs.length > 0;
    if (!hasDirs) { run(entries, [[]], { mode: 'none', asFiles: false, auto: true, junk: input.junk, skipped: skipped, titles: {}, on: {} }, target); return; }
    summary(entries, input, skipped, target);
  }

  function summary(entries, input, skipped, target) {
    var dirs = [];
    var seen = {};
    input.dirs.forEach(function (d) { var k = d.join('/'); if (!seen[k]) { seen[k] = 1; dirs.push(d); } });
    dirs.sort(function (a, b) { return natural(a.join('/'), b.join('/')); });
    var totalBytes = entries.reduce(function (s, e) { return s + e.file.size; }, 0);
    dialog.textContent = '';
    var h = el('h2', null, 'Ordner hochladen'); h.id = 'msumtitle'; dialog.appendChild(h);
    dialog.appendChild(el('p', null, dirs.length + ' Ordner, ' + entries.length + ' Dateien, ' + fmt(totalBytes) + '.'));
    var existing = Array.prototype.map.call(document.querySelectorAll('.mtree .titem'), function (a) { return a.textContent.trim().toLowerCase(); });
    var merged = dirs.filter(function (d) { return d.length === 1 && target === curFolder && curFolder === 0 && existing.indexOf(d[0].toLowerCase()) >= 0; });
    merged.forEach(function (d) { dialog.appendChild(el('p', 'small', 'Ordner „' + d[0] + '“ gibt es schon: der Inhalt wird ergänzt.')); });
    var junk = input.junk || 0;
    if (skipped.length || junk) {
      var sk = el('p', 'small muted', (skipped.length ? skipped.length + ' Datei(en) werden übersprungen (nicht erlaubter Typ oder leer). ' : '') + (junk ? junk + ' Versteck- und Systemdateien (.DS_Store, Thumbs.db …) werden ignoriert.' : ''));
      dialog.appendChild(sk);
    }
    var opts = el('div', 'opts');
    var saved = store('lkmedien.gal') || 'none';
    var modes = [['none', 'Nur ablegen, keine Galerie'], ['each', 'Jeder Ordner mit Fotos wird eine Galerie (nur die Fotos direkt darin)'], ['top', 'Nur der oberste Ordner wird eine Galerie (alle Fotos aus den Unterordnern kommen hinein)']];
    var radios = {};
    modes.forEach(function (m) {
      var lab = el('label'); var r = el('input'); r.type = 'radio'; r.name = 'gal'; r.value = m[0]; r.checked = saved === m[0]; radios[m[0]] = r;
      lab.appendChild(r); lab.appendChild(el('span', null, m[1])); opts.appendChild(lab);
    });
    if (!opts.querySelector('input[name=gal]:checked') && radios.none) { radios.none.checked = true; } // in opts suchen: der Dialog hängt noch nicht im Dokument
    dialog.appendChild(opts);
    var note2 = el('p', 'small muted', 'Neue Galerien sind immer Entwürfe: nichts wird durch das Hochladen öffentlich.'); dialog.appendChild(note2);
    var list = el('ul', 'fl'); list.hidden = true; dialog.appendChild(list);
    var titles = {}, on = {};
    function photoDirs(mode) {
      var set = {};
      entries.forEach(function (e) {
        if (e.kind === 'pdf' || e.kind === 'svg') { return; }
        if (mode === 'each' && e.path.length) { set[e.path.join('/')] = e.path; }
        if (mode === 'top' && e.path.length) { set[e.path[0]] = [e.path[0]]; }
      });
      return Object.keys(set).sort(natural).map(function (k) { return set[k]; });
    }
    function drawList() {
      var mode = (dialog.querySelector('input[name=gal]:checked') || {}).value || 'none';
      list.textContent = '';
      list.hidden = mode === 'none';
      if (mode === 'none') { return; }
      photoDirs(mode).forEach(function (d) {
        var k = d.join('/');
        if (!(k in on)) { on[k] = true; titles[k] = d[d.length - 1]; }
        var li = el('li'); var cb = el('input'); cb.type = 'checkbox'; cb.checked = on[k]; cb.setAttribute('aria-label', 'Galerie für ' + k);
        cb.addEventListener('change', function () { on[k] = cb.checked; });
        var t = el('input'); t.type = 'text'; t.value = titles[k]; t.maxLength = 200; t.setAttribute('aria-label', 'Titel der Galerie für ' + k);
        t.addEventListener('input', function () { titles[k] = t.value; });
        li.appendChild(cb); li.appendChild(el('span', 'mono small', k + ' →')); li.appendChild(t); list.appendChild(li);
      });
    }
    opts.addEventListener('change', drawList);
    drawList();
    var asFilesLab = el('label', 'check'); var asFiles = el('input'); asFiles.type = 'checkbox'; asFiles.checked = false;
    asFilesLab.appendChild(asFiles); asFilesLab.appendChild(el('span', null, 'PNG, JPEG und WebP als Dateien behandeln (Logos, Grafiken): unverändert, ohne Verkleinern und ohne GPS-Entfernen, bis 5 MB'));
    dialog.appendChild(asFilesLab);
    var autoLab = el('label', 'check'); var auto = el('input'); auto.type = 'checkbox'; auto.checked = true;
    autoLab.appendChild(auto); autoLab.appendChild(el('span', null, 'Fotos in Ordner mit Entwurfs-Galerie dort automatisch aufnehmen'));
    dialog.appendChild(autoLab);
    if (!photosOk) { dialog.appendChild(el('p', 'notice error', 'Auf diesem Server gibt es keine Bildbibliothek: Fotos können nicht hochgeladen werden, nur PDF, SVG und Bilder als Dateien.')); }
    var actions = el('div', 'actions');
    var cancel = el('button', 'btn ghost', 'Abbrechen'); cancel.type = 'button';
    var go = el('button', 'btn', 'Hochladen starten'); go.type = 'button';
    cancel.addEventListener('click', function () { dialog.close(); });
    go.addEventListener('click', function () {
      var mode = (dialog.querySelector('input[name=gal]:checked') || {}).value || 'none';
      store('lkmedien.gal', mode);
      dialog.close();
      run(entries, dirs, { mode: mode, asFiles: asFiles.checked, auto: auto.checked, junk: junk, skipped: skipped, titles: titles, on: on }, target);
    });
    actions.appendChild(cancel); actions.appendChild(go); dialog.appendChild(actions);
    dialog.showModal();
  }

  // ---------- Hochladen ----------
  var ui = null;
  function buildUi(total, bytes) {
    queueEl.textContent = '';
    var t = el('div', 'mqtotal');
    var bar = el('progress'); bar.max = 100; bar.value = 0;
    var txt = el('span', 'mono small', '0 von ' + total);
    var stop = el('button', 'btn small ghost', 'Abbrechen'); stop.type = 'button';
    t.appendChild(bar); t.appendChild(txt); t.appendChild(stop);
    queueEl.appendChild(t);
    var cur = el('li', 'qrow'); var cl = el('span', 'mono wrap', ''); var cp = el('progress'); cp.max = 100; cp.value = 0; var cm = el('span', 'small muted', '');
    cur.appendChild(cl); cur.appendChild(cp); cur.appendChild(cm); queueEl.appendChild(cur);
    return { bar: bar, txt: txt, stop: stop, cl: cl, cp: cp, cm: cm, total: total, bytes: bytes, doneBytes: 0, cancel: false, t0: Date.now() };
  }

  function post(url, body, headers) {
    headers = headers || {};
    headers['X-CSRF-Token'] = csrf;
    return fetch(url, { method: 'POST', body: body, headers: headers, credentials: 'same-origin' }).then(function (r) {
      return r.json().catch(function () { return { error: 'Unerwartete Antwort vom Server.' }; }).then(function (j) { j._status = r.status; return j; });
    });
  }
  function formBody(o) { var p = new URLSearchParams(); Object.keys(o).forEach(function (k) { p.set(k, o[k]); }); return p; }

  function sendChunks(file, id, size, offset, retries) {
    if (offset >= file.size) { return Promise.resolve(true); }
    var end = Math.min(offset + size, file.size);
    return post('/admin/upload/' + id + '/chunk?offset=' + offset, file.slice(offset, end), { 'Content-Type': 'application/octet-stream' }).then(function (j) {
      if (j._status === 409 && typeof j.received === 'number') { return sendChunks(file, id, size, j.received, retries); }
      if (j.error) {
        if (retries > 0 && j._status >= 500) { return sendChunks(file, id, size, offset, retries - 1); }
        return j.error;
      }
      ui.cp.value = Math.round(j.received / file.size * 90);
      return sendChunks(file, id, size, j.received, 3);
    }).catch(function () {
      if (retries > 0) { return sendChunks(file, id, size, offset, retries - 1); }
      return 'Die Verbindung wurde unterbrochen.';
    });
  }

  function limitFor(e, asFiles) {
    if (e.kind === 'pdf') { return maxPdf; }
    if (e.kind === 'svg') { return LIMITS.svg; }
    return asFiles ? LIMITS.image : maxPhoto;
  }

  function planChunks(arr, n) { var out = []; for (var i = 0; i < arr.length; i += n) { out.push(arr.slice(i, i + n)); } return out; }

  function run(entries, dirs, opt, target) {
    running = true;
    var report = { added: 0, dups: [], skipped: opt.skipped.slice(), failed: [], galleries: [], processed: 0 };
    if (opt.junk) { report.skipped.push([opt.junk + ' Systemdatei(en)', 'Versteck- und Systemdateien']); }
    // Art und Grenzen je Datei
    var work = [];
    entries.forEach(function (e) {
      var asFile = e.kind === 'pdf' || e.kind === 'svg' || (opt.asFiles && e.kind === 'image');
      if (!asFile && !photosOk) { report.skipped.push([e.name, 'keine Bildbibliothek auf dem Server']); return; }
      if (e.file.size > limitFor(e, opt.asFiles)) { report.skipped.push([e.name, 'zu groß (höchstens ' + fmt(limitFor(e, opt.asFiles)) + ')']); return; }
      e.intent = asFile ? 'file' : (opt.auto ? 'photo' : 'photo_plain');
      work.push(e);
    });
    var folderIndex = {};
    var planFolders = [{ path: [] }];
    dirs.forEach(function (d) { if (d.length) { folderIndex[d.join('/')] = planFolders.length; var on = false, title = d[d.length - 1], gk = null; planFolders.push({ path: d, gallery: false, title: title }); } });
    // Galerien je Modus
    work.forEach(function (e) { e.dir = e.path.length ? folderIndex[e.path.join('/')] || 0 : 0; e.galleryFolder = null; });
    if (opt.mode !== 'none') {
      work.forEach(function (e) {
        if (e.intent === 'file' || !e.path.length) { return; }
        var k = opt.mode === 'top' ? e.path[0] : e.path.join('/');
        if (opt.on[k] === false) { return; }
        var idx = folderIndex[k];
        if (idx === undefined) { return; }
        planFolders[idx].gallery = true;
        planFolders[idx].title = opt.titles[k] || planFolders[idx].title;
        e.galleryFolder = idx;
      });
    }
    var totalBytes = work.reduce(function (s, e) { return s + e.file.size; }, 0);
    ui = buildUi(work.length, totalBytes);
    var tokens = [];
    var started = false;
    ui.stop.addEventListener('click', function () { ui.cancel = true; ui.stop.disabled = true; ui.cm.textContent = 'Wird nach der laufenden Datei beendet …'; });
    var batches = planChunks(work, 4000);
    if (!batches.length) { batches = [[]]; }

    function eta() {
      var el2 = (Date.now() - ui.t0) / 1000;
      if (ui.doneBytes < 1 || el2 < 5) { return ''; }
      var left = (ui.bytes - ui.doneBytes) / (ui.doneBytes / el2);
      return ' · noch etwa ' + (left > 90 ? Math.round(left / 60) + ' Min.' : Math.round(left) + ' Sek.');
    }
    function tick(done) { ui.bar.value = ui.total ? Math.round(ui.doneBytes / Math.max(1, ui.bytes) * 100) : 100; ui.txt.textContent = done + ' von ' + ui.total + eta(); }

    var doneCount = 0;
    function doBatch(bi) {
      if (bi >= batches.length || ui.cancel) { return Promise.resolve(); }
      var batch = batches[bi];
      ui.cl.textContent = 'Plan wird geprüft …'; ui.cm.textContent = 'Fingerabdrücke und Ordner';
      return batch.reduce(function (chain, e) { return chain.then(function () { return fingerprint(e.file).then(function (fp) { e.fp = fp; }); }); }, Promise.resolve()).then(function () {
        var body = { target: target, folders: planFolders, files: batch.map(function (e) { return { f: e.dir, name: e.name, size: e.file.size, fp: e.fp || '' }; }) };
        return fetch('/admin/medien/plan', { method: 'POST', body: JSON.stringify(body), headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, credentials: 'same-origin' })
          .then(function (r) { return r.json().catch(function () { return { error: 'Unerwartete Antwort vom Server.' }; }); });
      }).then(function (plan) {
        if (plan.error) { batch.forEach(function (e) { report.failed.push([e.name, plan.error]); }); ui.cancel = true; return; }
        tokens.push(plan.plan);
        started = true;
        (plan.folders || []).forEach(function (f, i) { if (f.gallery_id && report.galleries.every(function (g) { return g.id !== f.gallery_id; })) { report.galleries.push({ id: f.gallery_id, title: planFolders[i].title }); } });
        (plan.folders || []).forEach(function (f, i) { if (f.warn) { report.skipped.push([planFolders[i].title || 'Ordner', f.warn]); } });
        if (plan.summary && plan.summary.names_changed) { report.skipped.push([plan.summary.names_changed + ' Ordnername(n)', 'enthielten unsichtbare Zeichen, entfernt']); }
        var idx = 0;
        function nextFile() {
          if (ui.cancel || idx >= batch.length) { return Promise.resolve(); }
          var e = batch[idx], info = plan.files[idx]; idx++;
          if (info && info.skip) { report.skipped.push([e.name, 'schon vorhanden (gleicher Ordner, Name, Größe und Inhalt)']); ui.doneBytes += e.file.size; doneCount++; tick(doneCount); return nextFile(); }
          return one(e, info ? info.folder_id : target, e.galleryFolder !== null ? (plan.folders[e.galleryFolder] || {}).gallery_id : 0, report).then(function () { ui.doneBytes += e.file.size; doneCount++; tick(doneCount); return nextFile(); });
        }
        return nextFile();
      }).then(function () { return doBatch(bi + 1); });
    }

    doBatch(0).catch(function () { report.failed.push(['Ordner-Upload', 'Unerwarteter Fehler.']); }).then(function () {
      var cancelled = ui.cancel;
      // Abbruch: leer gebliebene Ordner und Galerien des Laufs entfernen. Fertig: Plan als erledigt melden, damit das Aufräumen
      // nach 24 Stunden die (auch absichtlich leeren) Ordner des Laufs nicht anfasst.
      var endUrl = cancelled ? '/admin/medien/plan/abbrechen' : '/admin/medien/plan/fertig';
      var cleanup = tokens.reduce(function (c, t) { return c.then(function () { return post(endUrl, formBody({ plan: t }), { 'Content-Type': 'application/x-www-form-urlencoded' }).catch(function () { return null; }); }); }, Promise.resolve());
      return cleanup.then(function () { running = false; showReport(report, false, cancelled); });
    });
  }

  function one(e, folderId, galleryId, report) {
    ui.cl.textContent = (e.path.length ? e.path.join(' / ') + ' / ' : '') + e.name; ui.cp.value = 0; ui.cm.textContent = 'lädt hoch …';
    var body = { name: e.name, size: e.file.size, folder: folderId, intent: e.intent };
    if (galleryId) { body.gallery = galleryId; }
    if (e.fp) { body.fp = e.fp; }
    return post('/admin/upload/start', formBody(body), { 'Content-Type': 'application/x-www-form-urlencoded' }).then(function (s) {
      if (s.error) { report.failed.push([e.name, s.error]); return null; }
      return sendChunks(e.file, s.id, s.chunk, s.received || 0, 3).then(function (res) {
        if (res !== true) { report.failed.push([e.name, res]); return null; }
        ui.cm.textContent = 'prüft …';
        return post('/admin/upload/' + s.id + '/finish', '', {});
      });
    }).then(function (f) {
      if (!f) { return null; }
      if (f.error) { report.failed.push([e.name, f.error]); return null; }
      if (f.duplicate) { report.dups.push([e.name, f.in_trash ? 'liegt im Papierkorb' : 'schon vorhanden in Ordner „' + (f.folder || 'Medien') + '“']); return null; }
      if (f.notes && f.notes.length) { report.skipped.push([e.name, f.notes.join(' ')]); }
      if (f.intent === 'file') { report.added++; return null; }
      ui.cm.textContent = 'verkleinert …'; ui.cp.value = 92;
      return post('/admin/fotos/' + f.photo_id + '/verarbeiten', '', {}).then(function (p) {
        if (p.status === 'failed' || p.error) { report.failed.push([e.name, p.error || 'Verarbeitung fehlgeschlagen.']); } else { report.added++; }
        return null;
      });
    }).catch(function () { report.failed.push([e.name, 'Unerwarteter Fehler beim Hochladen.']); });
  }

  function listBlock(parent, title, items) {
    if (!items.length) { return; }
    parent.appendChild(el('p', null, title));
    var ul = el('ul');
    items.slice(0, 50).forEach(function (it) { ul.appendChild(el('li', 'small', it[0] + ': ' + it[1])); });
    if (items.length > 50) { ul.appendChild(el('li', 'small muted', '… und ' + (items.length - 50) + ' weitere')); }
    parent.appendChild(ul);
  }

  function showReport(r, instant, cancelled) {
    queueEl.textContent = '';
    var box2 = el('div', 'mreport'); box2.setAttribute('role', 'status');
    box2.appendChild(el('p', null, (cancelled ? 'Abgebrochen. ' : 'Fertig. ') + r.added + ' neu hochgeladen' + (r.dups.length ? ', ' + r.dups.length + ' schon vorhanden' : '') + (r.skipped.length ? ', ' + r.skipped.length + ' übersprungen oder geändert' : '') + (r.failed.length ? ', ' + r.failed.length + ' fehlgeschlagen' : '') + '.'));
    if (r.galleries.length) {
      var p = el('p', null, 'Neue Galerien (Entwurf): ');
      r.galleries.forEach(function (g, i) { var a = el('a', null, g.title); a.href = '/admin/galerien/' + g.id; if (i) { p.appendChild(document.createTextNode(', ')); } p.appendChild(a); });
      box2.appendChild(p);
    }
    listBlock(box2, 'Schon vorhanden:', r.dups);
    listBlock(box2, 'Übersprungen oder geändert:', r.skipped);
    listBlock(box2, 'Fehlgeschlagen:', r.failed);
    var btn = el('button', 'btn small', 'Ansicht aktualisieren'); btn.type = 'button';
    btn.addEventListener('click', function () { window.location.reload(); });
    box2.appendChild(btn);
    queueEl.appendChild(box2);
    if (!instant && r.added > 0) { /* Aktualisieren bleibt Sache der Nutzerin: der Bericht soll lesbar bleiben */ }
    running = false;
  }

  // ---------- Eingänge ----------
  var fileInput = document.getElementById('mfiles');
  var dirInput = document.getElementById('mdir');
  if (fileInput) { fileInput.addEventListener('change', function () { var o = collectFromInput(fileInput, false); fileInput.value = ''; begin(o, curFolder); }); }
  if (dirInput) {
    if (!('webkitdirectory' in dirInput)) { dirInput.disabled = true; }
    dirInput.addEventListener('change', function () { var o = collectFromInput(dirInput, true); dirInput.value = ''; begin(o, curFolder); });
  }
  ['dragenter', 'dragover'].forEach(function (ev) {
    main.addEventListener(ev, function (e) { if (isFiles(e)) { e.preventDefault(); box.classList.add('over'); } });
  });
  main.addEventListener('dragleave', function (e) { if (!main.contains(e.relatedTarget)) { box.classList.remove('over'); } });
  main.addEventListener('drop', function (e) {
    box.classList.remove('over');
    // Abgelegt auf einem Ordner: das übernimmt der Ordner-Empfänger (document), sonst landete alles im aktuellen Ordner
    if (!isFiles(e) || e.defaultPrevented || dropTarget(e)) { return; }
    e.preventDefault();
    collectFromDrop(e.dataTransfer).then(function (items) { begin(items, curFolder); });
  });
  // Verhindern, dass ein danebengelegter Ordner die Seite verlässt
  window.addEventListener('dragover', function (e) { if (isFiles(e)) { e.preventDefault(); } });
  window.addEventListener('drop', function (e) { if (isFiles(e) && !e.defaultPrevented) { e.preventDefault(); } });

  // Wartende Fotos (nach dem Wiederherstellen) nacheinander aufbereiten
  var pend = document.getElementById('pendingbar');
  if (pend) {
    var loop = function () {
      fetch('/admin/medien/aufbereiten', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.remaining > 0) { window.setTimeout(loop, 500); } else if (running) { window.setTimeout(loop, 3000); } else { window.location.reload(); } }) // nie neu laden, während ein Upload läuft
        .catch(function () { /* Knopf bleibt als Rückfall */ });
    };
    window.setTimeout(loop, 300);
  }
})();
