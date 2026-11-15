// Upload in Stücken: start -> chunk (der Reihe nach) -> finish -> verarbeiten. Ein Foto nach dem anderen;
// fällt eines aus, laufen die anderen weiter. Alles ohne Fremdbibliotheken, Ausgabe nur per textContent.
(function () {
  var box = document.getElementById('uploader');
  var list = document.getElementById('queue');
  var input = document.getElementById('files');
  if (!box || !list || !input) { return; }
  var csrf = box.getAttribute('data-csrf');
  var maxBytes = parseInt(box.getAttribute('data-max-mb'), 10) * 1048576;
  var gallery = box.getAttribute('data-gallery') || '';
  var queue = [];
  var running = false;

  function addRow(name) {
    var li = document.createElement('li');
    li.className = 'qrow';
    var label = document.createElement('span');
    label.className = 'mono wrap';
    label.textContent = name;
    var bar = document.createElement('progress');
    bar.max = 100; bar.value = 0;
    var msg = document.createElement('span');
    msg.className = 'small muted';
    msg.textContent = 'wartet';
    li.appendChild(label); li.appendChild(bar); li.appendChild(msg);
    list.appendChild(li);
    return { bar: bar, msg: msg, li: li };
  }

  function post(url, body, headers) {
    headers = headers || {};
    headers['X-CSRF-Token'] = csrf;
    return fetch(url, { method: 'POST', body: body, headers: headers, credentials: 'same-origin' }).then(function (r) {
      return r.json().catch(function () { return { error: 'Unerwartete Antwort vom Server.' }; }).then(function (j) { j._status = r.status; return j; });
    });
  }

  function form(obj) {
    var p = new URLSearchParams();
    Object.keys(obj).forEach(function (k) { p.set(k, obj[k]); });
    return p;
  }

  function fail(row, text) {
    row.msg.textContent = text;
    row.li.className = 'qrow failed';
  }

  function sendChunks(file, id, size, row, offset, retries) {
    if (offset >= file.size) { return Promise.resolve(true); }
    var end = Math.min(offset + size, file.size);
    return post('/admin/upload/' + id + '/chunk?offset=' + offset, file.slice(offset, end), { 'Content-Type': 'application/octet-stream' }).then(function (j) {
      if (j._status === 409 && typeof j.received === 'number') { return sendChunks(file, id, size, row, j.received, retries); } // fortsetzen
      if (j.error) {
        if (retries > 0 && j._status >= 500) { return sendChunks(file, id, size, row, offset, retries - 1); }
        fail(row, j.error); return false;
      }
      row.bar.value = Math.round(j.received / file.size * 90);
      return sendChunks(file, id, size, row, j.received, 3);
    }).catch(function () {
      if (retries > 0) { return sendChunks(file, id, size, row, offset, retries - 1); }
      fail(row, 'Die Verbindung wurde unterbrochen.'); return false;
    });
  }

  function one(file) {
    var row = addRow(file.name);
    if (file.size > maxBytes) { fail(row, 'Zu groß (höchstens ' + (maxBytes / 1048576) + ' MB).'); return Promise.resolve(); }
    if (file.size < 1) { fail(row, 'Die Datei ist leer.'); return Promise.resolve(); }
    row.msg.textContent = 'lädt hoch …';
    return post('/admin/upload/start', form(gallery ? { name: file.name, size: file.size, gallery: gallery } : { name: file.name, size: file.size }), { 'Content-Type': 'application/x-www-form-urlencoded' }).then(function (s) {
      if (s.error) { fail(row, s.error); return null; }
      return sendChunks(file, s.id, s.chunk, row, 0, 3).then(function (ok) {
        if (!ok) { return null; }
        row.msg.textContent = 'prüft …';
        return post('/admin/upload/' + s.id + '/finish', '', {});
      });
    }).then(function (f) {
      if (!f) { return null; }
      if (f.error) { fail(row, f.error); return null; }
      if (f.duplicate) { row.bar.value = 100; row.msg.textContent = f.in_trash ? 'Gibt es schon (im Papierkorb).' : 'Gibt es schon, übersprungen.'; row.li.className = 'qrow dup'; return null; }
      row.msg.textContent = 'verkleinert …'; row.bar.value = 92;
      return post('/admin/fotos/' + f.photo_id + '/verarbeiten', '', {}).then(function (p) {
        if (p.status === 'failed' || p.error) { fail(row, p.error || 'Verarbeitung fehlgeschlagen.'); return null; }
        row.bar.value = 100; row.msg.textContent = 'fertig'; row.li.className = 'qrow done';
        return null;
      });
    }).catch(function () { fail(row, 'Unerwarteter Fehler beim Hochladen.'); });
  }

  function next() {
    if (running) { return; }
    var file = queue.shift();
    if (!file) { return; }
    running = true;
    one(file).then(function () { running = false; next(); });
  }

  function add(files) {
    for (var i = 0; i < files.length; i++) { queue.push(files[i]); }
    next();
  }

  input.addEventListener('change', function () { add(input.files); input.value = ''; });
  ['dragenter', 'dragover'].forEach(function (ev) {
    box.addEventListener(ev, function (e) { e.preventDefault(); box.classList.add('over'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    box.addEventListener(ev, function (e) { e.preventDefault(); box.classList.remove('over'); });
  });
  box.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files) { add(e.dataTransfer.files); } });
})();
