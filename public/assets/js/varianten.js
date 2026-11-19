// Bildvarianten neu erzeugen: eine Runde nach der anderen (jede höchstens etwa 20 Sekunden, Stand in der Datenbank).
// Ausgabe nur per textContent. Ohne dieses Skript funktionieren die Formulare auch (je Klick eine Runde).
(function () {
  var box = document.getElementById('var-box');
  if (!box) { return; }
  var csrf = box.getAttribute('data-csrf');
  var bar = document.getElementById('var-bar');
  var text = document.getElementById('var-text');
  var log = document.getElementById('var-log');
  var forms = box.querySelectorAll('.var-form');
  var running = false;
  var sum = { created: 0, skipped: 0, problems: 0 };

  function post(query) {
    return fetch('/admin/varianten/schritt' + query, { method: 'POST', headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin' }).then(function (r) {
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

  function buttons(disabled, label) {
    Array.prototype.forEach.call(box.querySelectorAll('.var-form button'), function (b) { b.disabled = disabled; });
    var run = document.getElementById('var-run');
    if (run && label) { run.textContent = label; }
  }

  function stop(msg) {
    running = false;
    buttons(false, 'Weiter');
    if (msg) { line(msg, true); }
  }

  function next(query, retries) {
    post(query).then(function (j) {
      if (j.error) {
        if (retries > 0 && j._status >= 500) { return setTimeout(function () { next('', retries - 1); }, 1500); }
        return stop(j.error);
      }
      sum.created += j.created; sum.skipped += j.skipped; sum.problems += j.failed + j.noorig;
      bar.max = Math.max(1, j.total);
      bar.value = j.position;
      text.textContent = j.position + ' von ' + j.total + ' Fotos geprüft, ' + sum.created + ' neu erzeugt' + (sum.problems ? ', ' + sum.problems + ' mit Problem' : '') + '.';
      if (j.done) {
        text.textContent = 'Fertig: ' + sum.created + ' neu erzeugt, ' + sum.skipped + ' schon fertig' + (sum.problems ? ', ' + sum.problems + ' mit Problem' : '') + '. Die Seite wird neu geladen.';
        setTimeout(function () { window.location.href = '/admin/varianten'; }, 1200);
        return;
      }
      next('', 3);
    }).catch(function () {
      if (retries > 0) { return setTimeout(function () { next('', retries - 1); }, 1500); }
      stop('Die Verbindung wurde unterbrochen. „Weiter“ macht an derselben Stelle weiter.');
    });
  }

  Array.prototype.forEach.call(forms, function (f) {
    f.addEventListener('submit', function (ev) {
      if (!window.fetch) { return; } // alter Browser: normales Absenden, je Klick eine Runde
      ev.preventDefault();
      if (running) { return; }
      running = true;
      sum = { created: 0, skipped: 0, problems: 0 };
      log.textContent = '';
      buttons(true, 'Läuft …');
      var fresh = f.querySelector('input[name="neu"]');
      var force = f.querySelector('input[name="erzwingen"]');
      next(fresh ? '?neu=1' + (force ? '&erzwingen=1' : '') : '', 3);
    });
  });
})();
