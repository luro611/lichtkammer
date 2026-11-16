// Import aus WordPress: ein Foto nach dem anderen übernehmen, danach Galerien/Seiten anlegen.
// Ausgabe nur per textContent; fällt ein Foto aus, läuft der Import weiter.
(function () {
  var box = document.getElementById('import-box');
  var btn = document.getElementById('import-run');
  if (!box || !btn) { return; }
  var csrf = box.getAttribute('data-csrf');
  var total = parseInt(box.getAttribute('data-total'), 10) || 0;
  var done = parseInt(box.getAttribute('data-done'), 10) || 0;
  var bar = document.getElementById('import-bar');
  var text = document.getElementById('import-text');
  var log = document.getElementById('import-log');
  var running = false;

  function post(url) {
    return fetch(url, { method: 'POST', headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin' }).then(function (r) {
      return r.json().catch(function () { return { error: 'Unerwartete Antwort vom Server.' }; }).then(function (j) { j._status = r.status; return j; });
    });
  }

  function line(msg, bad) {
    var li = document.createElement('li');
    li.className = 'row';
    li.textContent = msg;
    if (bad) { li.style.color = 'var(--accent-strong)'; }
    log.appendChild(li);
  }

  function progress() {
    bar.value = done;
    text.textContent = done + ' von ' + total + ' Fotos übernommen';
  }

  function stop(msg) {
    running = false;
    btn.disabled = false;
    btn.textContent = 'Fortsetzen';
    if (msg) { line(msg, true); }
  }

  function next(retries) {
    post('/admin/import/schritt').then(function (j) {
      if (j.error) {
        if (retries > 0 && j._status >= 500) { return next(retries - 1); }
        return stop(j.error);
      }
      if (j.done) { return finish(); }
      done = Math.max(done, j.index);
      progress();
      if (j.status === 'failed') { line(j.name + ': ' + j.message, true); }
      else if (j.status === 'duplicate') { line(j.name + ': ' + j.message, false); }
      next(3);
    }).catch(function () {
      if (retries > 0) { return setTimeout(function () { next(retries - 1); }, 1500); }
      stop('Die Verbindung wurde unterbrochen. „Fortsetzen“ macht an der Stelle weiter.');
    });
  }

  function finish() {
    text.textContent = 'Galerien und Seiten werden angelegt …';
    post('/admin/import/abschluss').then(function (j) {
      if (j.error) { return stop(j.error); }
      text.textContent = 'Fertig: ' + j.galleries + ' Galerien, ' + j.pages + ' Seiten, ' + j.redirects + ' Weiterleitungen.';
      (j.problems || []).forEach(function (p) { line(p, true); });
      btn.hidden = true;
      if (!(j.problems || []).length) { window.location.reload(); }
      else { line('Bitte die Hinweise lesen; danach die Seite neu laden für die Durchsicht.', false); }
    }).catch(function () { stop('Der Abschluss ist fehlgeschlagen. „Fortsetzen“ versucht es noch einmal.'); });
  }

  btn.addEventListener('click', function () {
    if (running) { return; }
    running = true;
    btn.disabled = true;
    btn.textContent = 'Läuft …';
    log.textContent = '';
    next(3);
  });
})();
