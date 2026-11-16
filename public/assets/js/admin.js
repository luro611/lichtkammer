// Verwaltung: Rückfrage bei heiklen Knöpfen, „alle auswählen“, Ziehen zum Sortieren.
// Ohne dieses Skript funktioniert alles weiter (Pfeile, Formulare). Ausgabe nur über textContent.
(function () {
  // Aufklappmenüs im Kopf: nur eines offen, Esc und Klick daneben schließen (ohne Skript bleiben sie per Klick bedienbar).
  var groups = Array.prototype.slice.call(document.querySelectorAll('.navgroup'));
  groups.forEach(function (g) {
    g.addEventListener('toggle', function () {
      if (g.open) { groups.forEach(function (o) { if (o !== g) { o.open = false; } }); }
    });
  });
  document.addEventListener('click', function (e) {
    groups.forEach(function (g) { if (g.open && !g.contains(e.target)) { g.open = false; } });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') { return; }
    groups.forEach(function (g) {
      if (g.open) { g.open = false; var s = g.querySelector('summary'); if (s && g.contains(document.activeElement)) { s.focus(); } }
    });
  });

  document.addEventListener('click', function (e) {
    var t = e.target;
    while (t && t !== document && !(t.getAttribute && t.getAttribute('data-confirm'))) { t = t.parentNode; }
    if (t && t !== document && !window.confirm(t.getAttribute('data-confirm'))) { e.preventDefault(); }
  });

  document.addEventListener('change', function (e) {
    if (e.target.hasAttribute && e.target.hasAttribute('data-autosubmit') && e.target.form) { e.target.form.submit(); return; }
    var id = e.target.getAttribute && e.target.getAttribute('data-select-all');
    if (!id) { return; }
    var boxes = document.querySelectorAll('input[type=checkbox][name="ids[]"]');
    for (var i = 0; i < boxes.length; i++) {
      var f = boxes[i].getAttribute('form') || (boxes[i].form && boxes[i].form.id);
      if (f === id) { boxes[i].checked = e.target.checked; }
    }
  });

  var status = document.getElementById('sortstatus');
  function say(text) { if (status) { status.textContent = text; } }

  Array.prototype.forEach.call(document.querySelectorAll('[data-sortable]'), function (list) {
    var url = list.getAttribute('data-url');
    var csrf = list.getAttribute('data-csrf');
    var axisY = list.getAttribute('data-axis') === 'y';
    var dragging = null;
    var before = '';
    var items = function () { return Array.prototype.slice.call(list.children).filter(function (c) { return c.getAttribute('data-id'); }); };
    items().forEach(function (li) { li.setAttribute('draggable', 'true'); });

    list.addEventListener('dragstart', function (e) {
      var li = e.target.closest ? e.target.closest('[data-id]') : null;
      if (!li || li.parentNode !== list) { return; }
      dragging = li;
      before = items().map(function (c) { return c.getAttribute('data-id'); }).join(',');
      li.classList.add('dragging');
      if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', li.getAttribute('data-id')); }
    });
    list.addEventListener('dragover', function (e) {
      if (!dragging) { return; }
      e.preventDefault();
      var li = e.target.closest ? e.target.closest('[data-id]') : null;
      if (!li || li === dragging || li.parentNode !== list) { return; }
      var r = li.getBoundingClientRect();
      var first = axisY ? e.clientY < r.top + r.height / 2 : e.clientX < r.left + r.width / 2;
      list.insertBefore(dragging, first ? li : li.nextSibling);
    });
    function finish() {
      if (!dragging) { return; }
      dragging.classList.remove('dragging');
      dragging = null;
      var ids = items().map(function (c) { return c.getAttribute('data-id'); });
      if (ids.join(',') === before) { return; }
      var p = new URLSearchParams();
      ids.forEach(function (id) { p.append('ids[]', id); });
      say('Speichert …');
      fetch(url, { method: 'POST', body: p, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' } })
        .then(function (r) { return r.json(); })
        .then(function (j) { say(j && j.ok ? 'Reihenfolge gespeichert.' : 'Die Reihenfolge konnte nicht gespeichert werden. Bitte Seite neu laden.'); })
        .catch(function () { say('Die Reihenfolge konnte nicht gespeichert werden. Bitte Seite neu laden.'); });
    }
    list.addEventListener('drop', function (e) { e.preventDefault(); finish(); });
    list.addEventListener('dragend', finish);
  });
})();
