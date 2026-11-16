// Logos hochladen: Datei im Browser lesen und als Data-URL an die Verwaltung schicken (ohne Mehrteil-Formular).
(function () {
  'use strict';
  var file = document.getElementById('logo-file');
  var form = document.getElementById('logo-upload');
  var status = document.getElementById('logo-status');
  if (!file || !form) { return; }
  var csrf = form.querySelector('input[name="_csrf"]').value;
  file.addEventListener('change', function () {
    var files = Array.prototype.slice.call(file.files || []);
    if (!files.length) { return; }
    var i = 0, ok = 0, failed = [];
    function next() {
      if (i >= files.length) {
        status.textContent = ok + ' Logo(s) hochgeladen' + (failed.length ? ', nicht angenommen: ' + failed.join('; ') : '') + '.';
        if (ok) { window.setTimeout(function () { window.location.href = '/admin/logos' + (failed.length ? '' : '?m=logoadded'); }, failed.length ? 4000 : 400); }
        return;
      }
      var f = files[i++];
      status.textContent = 'Lade ' + f.name + ' hoch …';
      var r = new FileReader();
      r.onload = function () {
        var body = new URLSearchParams({ _csrf: csrf, name: f.name, daten: String(r.result) });
        fetch('/admin/logos', { method: 'POST', body: body, credentials: 'same-origin', redirect: 'manual' })
          .then(function (res) {
            if (res.status === 400) { return res.text().then(function (t) { var m = /class="notice error"[^>]*>([^<]*)</.exec(t); failed.push(f.name + (m ? ' (' + m[1].trim() + ')' : '')); }); }
            ok++;
          })
          .catch(function () { failed.push(f.name); })
          .then(next);
      };
      r.onerror = function () { failed.push(f.name); next(); };
      r.readAsDataURL(f);
    }
    next();
  });
})();
