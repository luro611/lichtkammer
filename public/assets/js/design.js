/* Design (Warm, Klar, Studio, Blau) und Farbschema (hell / dunkel) wählen und merken.
   Wird im <head> synchron geladen, damit beim ersten Zeichnen schon alles stimmt (kein Flackern). */
(function () {
  var root = document.documentElement;
  var KEY_D = 'lichtkammer-design', KEY_T = 'lichtkammer-theme';
  function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* ohne Speicher: gilt nur bis zum Neuladen */ } }
  var DESIGNS = ['warm', 'klar', 'studio', 'blau'];
  // Alte gespeicherte Namen (bis 0.2): lurohome wird warm, rothe wird klar. Alles Unbekannte: warm.
  function known(v) { v = v === 'lurohome' ? 'warm' : (v === 'rothe' ? 'klar' : v); return DESIGNS.indexOf(v) >= 0 ? v : 'warm'; }
  var d0 = get(KEY_D), d = known(d0);
  if (d0 !== null && d0 !== d) { set(KEY_D, d); }
  root.setAttribute('data-design', d);
  var t = get(KEY_T);
  if (t === 'dark' || t === 'light') { root.setAttribute('data-theme', t); }

  function isDark() {
    var t = root.getAttribute('data-theme');
    return t ? t === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
  }
  function sync() {
    var des = root.getAttribute('data-design');
    document.querySelectorAll('[data-design-set]').forEach(function (b) {
      b.setAttribute('aria-pressed', String(b.getAttribute('data-design-set') === des));
    });
    document.querySelectorAll('[data-theme-set]').forEach(function (b) {
      var v = b.getAttribute('data-theme-set');
      b.setAttribute('aria-pressed', String((v === 'dark') === isDark()));
    });
  }
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-design-set]').forEach(function (b) {
      b.addEventListener('click', function () {
        var v = known(b.getAttribute('data-design-set'));
        root.setAttribute('data-design', v); set(KEY_D, v); sync();
      });
    });
    document.querySelectorAll('[data-theme-set]').forEach(function (b) {
      b.addEventListener('click', function () {
        var v = b.getAttribute('data-theme-set') === 'dark' ? 'dark' : 'light';
        root.setAttribute('data-theme', v); set(KEY_T, v); sync();
      });
    });
    sync();
  });
})();
