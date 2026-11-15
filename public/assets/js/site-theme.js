/* Hell/Dunkel für die öffentliche Seite: Wahl merken, vor dem ersten Zeichnen setzen (kein Flackern). Kein Cookie, nur localStorage. */
(function () {
  var root = document.documentElement, KEY = 'lichtkammer-site-theme';
  function get() { try { return localStorage.getItem(KEY); } catch (e) { return null; } }
  function set(v) { try { localStorage.setItem(KEY, v); } catch (e) { /* gilt dann nur bis zum Neuladen */ } }
  var t = get();
  if (t === 'dark' || t === 'light') { root.setAttribute('data-theme', t); }
  function isDark() {
    var a = root.getAttribute('data-theme');
    return a ? a === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
  }
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
      b.addEventListener('click', function () {
        var v = isDark() ? 'light' : 'dark';
        root.setAttribute('data-theme', v); set(v);
      });
    });
  });
})();
