/* Öffentliche Seite: Galerien-Filter und Lightbox. Ohne Skript bleibt alles als Links auf die großen Bilder nutzbar. */
(function () {
  'use strict';

  // --- Filter auf der Galerienübersicht ---
  var form = document.querySelector('[data-filters]');
  if (form) {
    var cards = Array.prototype.slice.call(document.querySelectorAll('[data-cards] .card'));
    var selects = Array.prototype.slice.call(form.querySelectorAll('select[data-filter]'));
    var none = document.querySelector('[data-filter-none]');
    var counter = form.querySelector('[data-filter-count]');
    var readHash = function () {
      var p = new URLSearchParams(location.hash.replace(/^#/, ''));
      selects.forEach(function (s) {
        var v = p.get(s.getAttribute('data-filter')) || '';
        s.value = Array.prototype.some.call(s.options, function (o) { return o.value === v; }) ? v : '';
      });
    };
    var apply = function () {
      var shown = 0;
      cards.forEach(function (c) {
        var ok = selects.every(function (s) {
          if (s.value === '') { return true; }
          return (' ' + c.getAttribute('data-' + s.getAttribute('data-filter')) + ' ').indexOf(' ' + s.value + ' ') !== -1;
        });
        c.hidden = !ok;
        if (ok) { shown++; }
      });
      if (none) { none.hidden = shown !== 0; }
      var active = selects.some(function (s) { return s.value !== ''; });
      if (counter) { counter.textContent = active ? shown + (shown === 1 ? ' Galerie' : ' Galerien') : ''; }
    };
    selects.forEach(function (s) {
      s.addEventListener('change', function () {
        var p = new URLSearchParams();
        selects.forEach(function (x) { if (x.value !== '') { p.set(x.getAttribute('data-filter'), x.value); } });
        history.replaceState(null, '', location.pathname + (p.toString() ? '#' + p.toString() : ''));
        apply();
      });
    });
    var reset = form.querySelector('[data-filter-reset]');
    if (reset) {
      reset.addEventListener('click', function () {
        selects.forEach(function (s) { s.value = ''; });
        history.replaceState(null, '', location.pathname);
        apply();
      });
    }
    form.hidden = false;
    readHash();
    apply();
  }

  // --- Lightbox ---
  var links = Array.prototype.slice.call(document.querySelectorAll('[data-lightbox] a[data-srcset]'));
  if (!links.length) { return; }
  var lb = null, idx = 0, opener = null, startX = 0, startY = 0, tracking = false, infoOn = true;
  var reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text) { n.textContent = text; }
    return n;
  }

  function build() {
    lb = el('div', 'lb');
    lb.setAttribute('role', 'dialog');
    lb.setAttribute('aria-modal', 'true');
    lb.setAttribute('aria-label', 'Bildansicht');
    var stage = el('div', 'lb-stage');
    var img = el('img');
    var close = el('button', 'lb-btn lb-close', '✕');
    close.type = 'button'; close.setAttribute('aria-label', 'Schließen');
    var prev = el('button', 'lb-btn lb-prev', '‹');
    prev.type = 'button'; prev.setAttribute('aria-label', 'Vorheriges Foto');
    var next = el('button', 'lb-btn lb-next', '›');
    next.type = 'button'; next.setAttribute('aria-label', 'Nächstes Foto');
    var infoBtn = el('button', 'lb-btn lb-info-btn', 'i');
    infoBtn.type = 'button'; infoBtn.setAttribute('aria-label', 'Bildinfo ein- oder ausblenden'); infoBtn.setAttribute('aria-pressed', 'true');
    stage.appendChild(img); stage.appendChild(prev); stage.appendChild(next); stage.appendChild(infoBtn); stage.appendChild(close);
    var bar = el('div', 'lb-bar');
    var text = el('div', 'lb-text');
    var count = el('div', 'lb-count');
    count.setAttribute('aria-live', 'polite');
    bar.appendChild(text); bar.appendChild(count);
    lb.appendChild(stage); lb.appendChild(bar);
    lb._ = { img: img, text: text, count: count, close: close, prev: prev, next: next, info: infoBtn };
    close.addEventListener('click', closeBox);
    prev.addEventListener('click', function () { go(-1); });
    next.addEventListener('click', function () { go(1); });
    infoBtn.addEventListener('click', toggleInfo);
    lb.addEventListener('touchstart', function (e) {
      if (e.touches.length !== 1) { tracking = false; return; }
      tracking = true; startX = e.touches[0].clientX; startY = e.touches[0].clientY;
    }, { passive: true });
    lb.addEventListener('touchend', function (e) {
      if (!tracking) { return; }
      tracking = false;
      var t = e.changedTouches[0], dx = t.clientX - startX, dy = t.clientY - startY;
      if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.4) { go(dx < 0 ? 1 : -1); }
      else if (dy > 80 && dy > Math.abs(dx) * 1.4) { closeBox(); }
    }, { passive: true });
    stage.addEventListener('click', function (e) { if (e.target === stage) { closeBox(); } });
  }

  function toggleInfo() {
    infoOn = !infoOn;
    lb.classList.toggle('noinfo', !infoOn);
    lb._.info.setAttribute('aria-pressed', String(infoOn));
  }

  function show(i) {
    idx = (i + links.length) % links.length;
    var a = links[idx], d = a.dataset, o = lb._;
    o.img.removeAttribute('src');
    o.img.sizes = '100vw';
    o.img.srcset = d.srcset;
    o.img.src = a.getAttribute('href');
    o.img.alt = d.alt || '';
    o.text.textContent = '';
    if (d.title) { o.text.appendChild(el('h2', 'lb-title', d.title)); }
    if (d.caption) { o.text.appendChild(el('p', 'lb-cap', d.caption)); }
    [d.meta, d.photographer ? 'Foto: ' + d.photographer : '', d.gear, d.settings, d.taken].forEach(function (t) {
      if (t) { o.text.appendChild(el('p', 'lb-meta', t)); }
    });
    o.count.textContent = (idx + 1) + ' / ' + links.length;
    try { history.replaceState(null, '', '#foto-' + d.id); } catch (e) { /* ohne Verlauf */ }
    [idx + 1, idx - 1].forEach(function (j) { // Nachbarn vorladen
      var n = links[(j + links.length) % links.length];
      var pre = new Image(); pre.srcset = n.dataset.srcset; pre.sizes = '100vw'; pre.src = n.getAttribute('href');
    });
  }

  function go(step) { if (lb) { show(idx + step); } }

  function openBox(i, from) {
    if (!lb) { build(); }
    opener = from || null;
    for (var s = document.body.firstElementChild; s; s = s.nextElementSibling) {
      if (s !== lb && s.setAttribute) { s.setAttribute('inert', ''); }
    }
    document.body.appendChild(lb);
    document.body.classList.add('lb-open');
    show(i);
    lb._.close.focus();
  }

  function closeBox() {
    if (!lb || !lb.parentNode) { return; }
    lb.parentNode.removeChild(lb);
    document.body.classList.remove('lb-open');
    Array.prototype.forEach.call(document.body.children, function (s) { if (s.removeAttribute) { s.removeAttribute('inert'); } });
    try { history.replaceState(null, '', location.pathname + location.search); } catch (e) { /* ohne Verlauf */ }
    if (opener) { opener.focus(); }
  }

  document.addEventListener('keydown', function (e) {
    if (!lb || !lb.parentNode) { return; }
    var k = e.key;
    if (k === 'Escape') { e.preventDefault(); closeBox(); }
    else if (k === 'ArrowRight') { e.preventDefault(); go(1); }
    else if (k === 'ArrowLeft') { e.preventDefault(); go(-1); }
    else if (k === 'Home') { e.preventDefault(); show(0); }
    else if (k === 'End') { e.preventDefault(); show(links.length - 1); }
    else if (k === 'i' || k === 'I') { toggleInfo(); }
    else if (k === 'Tab') { // Fokus bleibt im Dialog
      var f = Array.prototype.slice.call(lb.querySelectorAll('button'));
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  links.forEach(function (a, i) {
    a.addEventListener('click', function (e) {
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) { return; }
      e.preventDefault();
      openBox(i, a);
    });
  });

  var m = /^#foto-(\d+)$/.exec(location.hash);
  if (m) {
    links.some(function (a, i) { if (a.dataset.id === m[1]) { openBox(i, a); return true; } return false; });
  }
  void reduce;
})();

// Karussell (Entwurf 14): Grundlage ist ein waagerecht scrollbarer Streifen mit Einrastpunkten (geht auch ohne Skript).
// Hier kommen Knöpfe, Punkte, Pause und Automatik dazu. Automatik nie bei „weniger Bewegung“, hält an bei Maus, Fokus und Pause.
(function () {
  'use strict';
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  Array.prototype.forEach.call(document.querySelectorAll('[data-carousel]'), function (root) {
    var track = root.querySelector('.bcar-track');
    var slides = track ? Array.prototype.slice.call(track.children) : [];
    if (!track || slides.length < 2) { return; }
    var cur = 0, timer = null, paused = false, hovering = false;
    function mk(tag, cls, text) { var n = document.createElement(tag); n.className = cls; if (text) { n.textContent = text; } return n; }
    function button(cls, label, text, fn) {
      var b = mk('button', cls, text);
      b.type = 'button';
      b.setAttribute('aria-label', label);
      b.addEventListener('click', fn);
      return b;
    }
    var live = mk('p', 'sr');
    live.setAttribute('aria-live', 'polite');
    var bar = mk('div', 'bcar-ctl');
    var prev = button('bcar-btn bcar-prev', 'Vorheriges Foto', '‹', function () { go(cur - 1, true); });
    var next = button('bcar-btn bcar-next', 'Nächstes Foto', '›', function () { go(cur + 1, true); });
    var dots = mk('div', 'bcar-dots');
    var dotBtns = slides.map(function (s, i) {
      var d = button('bcar-dot', 'Foto ' + (i + 1) + ' von ' + slides.length, '', function () { go(i, true); });
      dots.appendChild(d);
      return d;
    });
    var auto = parseInt(root.getAttribute('data-auto') || '0', 10) * 1000;
    var pause = null;
    if (auto > 0 && !reduce) {
      pause = button('bcar-btn bcar-pause', 'Automatisches Blättern anhalten', 'Ⅱ', function () {
        paused = !paused;
        pause.textContent = paused ? '▶' : 'Ⅱ';
        pause.setAttribute('aria-label', paused ? 'Automatisches Blättern fortsetzen' : 'Automatisches Blättern anhalten');
        schedule();
      });
    }
    bar.appendChild(prev);
    bar.appendChild(dots);
    if (pause) { bar.appendChild(pause); }
    bar.appendChild(next);
    root.appendChild(bar);
    root.appendChild(live);
    function mark() {
      dotBtns.forEach(function (d, i) { if (i === cur) { d.setAttribute('aria-current', 'true'); } else { d.removeAttribute('aria-current'); } });
    }
    function go(i, user) {
      cur = (i + slides.length) % slides.length;
      track.scrollTo({ left: slides[cur].offsetLeft - track.offsetLeft, behavior: reduce ? 'auto' : 'smooth' });
      mark();
      if (user) { live.textContent = 'Foto ' + (cur + 1) + ' von ' + slides.length; }
      schedule();
    }
    var t2 = null;
    track.addEventListener('scroll', function () {
      clearTimeout(t2);
      t2 = setTimeout(function () {
        var w = track.clientWidth || 1;
        var i = Math.round(track.scrollLeft / w);
        if (i !== cur && i >= 0 && i < slides.length) { cur = i; mark(); }
      }, 80);
    }, { passive: true });
    function schedule() {
      clearTimeout(timer);
      if (auto > 0 && !reduce && !paused && !hovering) { timer = setTimeout(function () { go(cur + 1, false); }, auto); }
    }
    root.addEventListener('mouseenter', function () { hovering = true; schedule(); });
    root.addEventListener('mouseleave', function () { hovering = false; schedule(); });
    root.addEventListener('focusin', function () { hovering = true; schedule(); });
    root.addEventListener('focusout', function () { hovering = false; schedule(); });
    document.addEventListener('visibilitychange', function () { if (document.hidden) { clearTimeout(timer); } else { schedule(); } });
    track.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { e.preventDefault(); go(cur + 1, true); }
      else if (e.key === 'ArrowLeft') { e.preventDefault(); go(cur - 1, true); }
    });
    root.classList.add('is-js');
    mark();
    schedule();
  });
})();
