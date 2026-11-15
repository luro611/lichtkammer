/* Design (lurohome / rothe.it) und Farbschema (hell / dunkel) wählen und merken.
   Wird im <head> synchron geladen, damit beim ersten Zeichnen schon alles stimmt (kein Flackern). */
(function () {
  var root = document.documentElement;
  var KEY_D = 'lichtkammer-design', KEY_T = 'lichtkammer-theme';
  function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* ohne Speicher: gilt nur bis zum Neuladen */ } }
  var d = get(KEY_D);
  root.setAttribute('data-design', d === 'rothe' ? 'rothe' : 'lurohome');
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
        var v = b.getAttribute('data-design-set') === 'rothe' ? 'rothe' : 'lurohome';
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
