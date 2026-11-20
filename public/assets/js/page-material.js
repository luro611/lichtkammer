// Seitenstreifen „Material“ im Seiten-Editor (Entwurf 29, Teil D): Fotos und Galerien suchen und auf die Seite ziehen.
// Ohne Bibliothek. Läuft nur mit page-editor.js (das ruft window.LKMaterial(api) auf). Gespeichert wird weiter nur die Foto-Id;
// der Server baut alles neu auf (Blocks::parse). Ausgabe nur über textContent und Attribute. Jede Zieh-Handlung hat einen Weg ohne Maus:
// Enter oder Antippen auf der Kachel öffnet „Wohin?“.
window.LKMaterial = function (api) {
  'use strict';
  var root = api.root, el = api.el, btn = api.btn, say = api.say;
  var photoUrl = api.pickUrl;
  var galUrl = root.getAttribute('data-gal-url') || '';
  var galPhotosUrl = root.getAttribute('data-gal-photos-url') || '';
  if (!photoUrl || !galUrl || !galPhotosUrl) { return; }

  var box = el('details', 'emat');
  if (window.matchMedia && window.matchMedia('(min-width:1100px)').matches) { box.open = true; }
  box.appendChild(el('summary', null, 'Material'));
  box.appendChild(el('p', 'small muted', 'Auf die Seite ziehen, oder Kachel mit Enter bzw. Antippen wählen und dann das Ziel.'));

  var tabs = el('div', 'emattabs');
  tabs.setAttribute('role', 'tablist');
  var q = el('input');
  q.type = 'search';
  q.placeholder = 'Suchen …';
  var info = el('p', 'small muted');
  info.setAttribute('role', 'status');
  var grid = el('ul', 'emgrid');
  var more = btn('Mehr laden', '', function () { load(false); });
  more.hidden = true;

  var mode = 'photos', page = 1, seq = 0, timer = null;
  var tabBtns = {};
  [['photos', 'Fotos'], ['galleries', 'Galerien']].forEach(function (t) {
    var b = el('button', 'etab', t[1]);
    b.type = 'button';
    b.setAttribute('role', 'tab');
    b.addEventListener('click', function () { setMode(t[0]); });
    tabBtns[t[0]] = b;
    tabs.appendChild(b);
  });
  box.appendChild(tabs);
  q.setAttribute('aria-label', 'Material suchen');
  box.appendChild(q);
  box.appendChild(info);
  box.appendChild(grid);
  box.appendChild(more);
  api.side.appendChild(box);

  function setMode(m) {
    mode = m;
    Object.keys(tabBtns).forEach(function (k) { tabBtns[k].setAttribute('aria-selected', k === m ? 'true' : 'false'); });
    q.value = '';
    q.setAttribute('aria-label', m === 'photos' ? 'Fotos suchen (Titel, Ort, Alt-Text, Dateiname)' : 'Galerien suchen (Titel)');
    load(true);
  }

  // Eine Galerie-Kachel holt ihre Fotos erst, wenn sie gebraucht werden (Ziehen beginnt oder „Wohin?“ gewählt); ein Abruf je Galerie.
  var galCache = {};
  function loadGallery(id) {
    if (!galCache[id]) {
      galCache[id] = fetch(galPhotosUrl + '/' + encodeURIComponent(String(id)), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { if (!r.ok) { throw new Error('x'); } return r.json(); })
        .then(function (d) { return { rows: Array.isArray(d.rows) ? d.rows : [], total: d.total || 0 }; });
      galCache[id].catch(function () { delete galCache[id]; });
      setTimeout(function () { delete galCache[id]; }, 60000); // nicht ewig: die Galerie kann sich ändern
    }
    return galCache[id];
  }

  function card(item, isGal) {
    var li = el('li');
    var c = el('div', 'ematcard');
    c.tabIndex = 0;
    c.setAttribute('role', 'button');
    c.draggable = true;
    var label = isGal ? 'Galerie „' + item.t + '“ (' + item.n + ' Fotos)' : 'Foto „' + item.t + '“';
    c.setAttribute('aria-label', label + ': Enter wählt das Ziel auf der Seite');
    if (item.u) {
      var im = el('img');
      im.src = item.u;
      im.alt = '';
      im.loading = 'lazy';
      im.draggable = false;
      c.appendChild(im);
    } else {
      c.appendChild(el('span', 'ematnoimg small muted', 'Kein Bild'));
    }
    c.appendChild(el('span', 'small', item.t));
    if (isGal) { c.appendChild(el('span', 'small muted', item.n === 1 ? '1 Foto' : item.n + ' Fotos')); }
    c.addEventListener('dragstart', function (e) {
      if (isGal) {
        api.startDrag(e, { kind: 'gallery', load: function () { return loadGallery(item.id); } });
        loadGallery(item.id).catch(function () { /* beim Ablegen gemeldet */ });
      } else {
        api.startDrag(e, { kind: 'photo', photo: item });
      }
    });
    c.addEventListener('dragend', api.endDrag);
    var act = function () { where(c, item, isGal); };
    c.addEventListener('click', act);
    c.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); act(); } });
    li.appendChild(c);
    return li;
  }

  function load(reset) {
    if (reset) { page = 1; grid.textContent = ''; } else { page++; }
    var my = ++seq;
    info.textContent = 'Lädt …';
    var url = (mode === 'photos' ? photoUrl + '?n=60&' : galUrl + '?') + 'q=' + encodeURIComponent(q.value) + '&seite=' + page;
    var isGal = mode === 'galleries';
    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) { throw new Error('x'); } return r.json(); })
      .then(function (d) {
        if (my !== seq) { return; }
        (d.rows || []).forEach(function (r) { grid.appendChild(card(r, isGal)); });
        more.hidden = !d.more;
        info.textContent = grid.children.length ? '' : (isGal ? 'Keine passenden Galerien gefunden.' : 'Keine passenden Fotos gefunden.');
      })
      .catch(function () { if (my === seq) { info.textContent = 'Das Material konnte nicht geladen werden. Bitte noch einmal versuchen.'; } });
  }
  q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { load(true); }, 250); });

  // --- „Wohin?“: gleichwertiger Weg ohne Ziehen ---
  function blockLabel(path, b) {
    var s = api.summary(b);
    return path + ': ' + (api.TYPES[b.t] ? api.TYPES[b.t].name : b.t) + (s ? ' – ' + s : '');
  }

  function where(opener, item, isGal) {
    var dlg = el('dialog', 'picker wohin');
    dlg.setAttribute('aria-label', 'Wohin soll ' + (isGal ? 'die Galerie' : 'das Foto') + '?');
    var inner = el('div', 'pickin');
    inner.appendChild(el('h2', null, 'Wohin?'));
    inner.appendChild(el('p', 'small muted', (isGal ? 'Galerie „' : 'Foto „') + item.t + '“'));
    var list = el('div', 'wohinlist');
    inner.appendChild(list);
    var foot = el('div', 'actions');
    foot.appendChild(btn('Abbrechen', '', function () { dlg.close(); }));
    inner.appendChild(foot);
    dlg.appendChild(inner);

    function add(label, run) {
      var b = el('button', 'btn small ghost wohinbtn', label);
      b.type = 'button';
      b.addEventListener('click', function () { dlg.close(); run(); });
      list.appendChild(b);
    }
    var state = api.state();
    var allowedMain = ['image', 'gallery']; // Hauptseite: beides erlaubt; Spalten bekommen api.SIMPLE (ohne Galerie)

    if (!isGal) {
      // Foto einsetzen in Blöcke mit einem Foto (auch in Spalten)
      state.forEach(function (b, i) {
        if (api.PHOTO_TYPES.indexOf(b.t) >= 0) {
          add('Einsetzen in ' + blockLabel('Block ' + (i + 1), b), function () { api.setPhoto(b, item); });
        }
        if (b.t === 'columns') {
          [['left', 'links'], ['right', 'rechts']].forEach(function (s) {
            (b[s[0]] || []).forEach(function (cb, k) {
              if (api.PHOTO_TYPES.indexOf(cb.t) >= 0) {
                add('Einsetzen in ' + blockLabel('Block ' + (i + 1) + ', Spalte ' + s[1] + ', Block ' + (k + 1), cb), function () { api.setPhoto(cb, item); });
              }
            });
          });
        }
      });
    }
    var newLabel = isGal ? 'Neues Galerie-Raster' : 'Neuer Bildblock';
    var put = function (lst, idx, allowed) {
      if (isGal) { loadGallery(item.id).then(function (g) { api.insertGallery(lst, Math.min(idx, lst.length), g, allowed); }).catch(function () { api.hint('Die Galerie konnte nicht geladen werden. Bitte noch einmal versuchen.'); }); } else { api.insertPhoto(lst, idx, item, allowed); }
    };
    add(newLabel + ' am Ende der Seite', function () { put(state, state.length, allowedMain); });
    state.forEach(function (b, i) {
      add(newLabel + ' hinter ' + blockLabel('Block ' + (i + 1), b), function () { put(state, i + 1, allowedMain); });
    });
    if (!isGal) {
      state.forEach(function (b, i) {
        if (b.t !== 'columns') { return; }
        [['left', 'links'], ['right', 'rechts']].forEach(function (s) {
          if (api.colFull(b[s[0]])) { return; }
          add(newLabel + ' ans Ende der Spalte ' + s[1] + ' von Block ' + (i + 1), function () { put(b[s[0]], b[s[0]].length, api.SIMPLE); });
        });
      });
    }
    dlg.addEventListener('close', function () {
      if (dlg.parentNode) { dlg.parentNode.removeChild(dlg); }
      // Hat die Aktion den Fokus schon in den neuen Block gesetzt, bleibt er dort; sonst zurück auf die Kachel.
      var a = document.activeElement;
      if ((!a || a === document.body) && opener && opener.focus) { try { opener.focus({ preventScroll: true }); } catch (e) { /* weg */ } }
    });
    document.body.appendChild(dlg);
    if (dlg.showModal) { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
    var first = list.querySelector('button');
    if (first) { first.focus(); }
  }

  setMode('photos');
};
