// Seiten-Editor mit Blöcken (Entwurf 12). Ohne Bibliothek. Baut die Oberfläche aus dem eingebetteten JSON,
// schreibt beim Speichern das Block-JSON in das versteckte Feld "blocks". Der Server prüft alles noch einmal.
// Ausgabe nur über textContent und Attribute (nie als HTML eingefügt). Alles per Tastatur bedienbar (Pfeilknöpfe statt nur Ziehen).
(function () {
  'use strict';
  var root = document.getElementById('page-editor');
  var dataEl = document.getElementById('editor-data');
  var field = document.getElementById('blocks-field');
  if (!root || !dataEl || !field || !field.form) { return; }
  var form = field.form;
  var init;
  try { init = JSON.parse(dataEl.textContent || '{}'); } catch (e) { init = {}; }
  var state = Array.isArray(init.blocks) ? init.blocks : [];
  var photos = init.photos && typeof init.photos === 'object' ? init.photos : {};
  var isHome = !!init.home;
  var pickUrl = root.getAttribute('data-pick-url') || '';
  var dirty = false, submitting = false, uid = 0, focusId = null, focusSel = '';

  var TYPES = {
    heading: { name: 'Überschrift', desc: 'Zwischentitel für einen Abschnitt' },
    text: { name: 'Text', desc: 'Fließtext mit fett, kursiv, Listen und Links' },
    image: { name: 'Bild', desc: 'Ein Foto aus der Bibliothek mit Bildunterschrift' },
    gallery: { name: 'Galerie-Raster', desc: 'Mehrere Fotos als Raster, Klick vergrößert' },
    quote: { name: 'Zitat', desc: 'Hervorgehobener Satz mit Quelle' },
    rule: { name: 'Trennlinie', desc: 'Eine dünne Linie zwischen Abschnitten' },
    button: { name: 'Knopf / Link', desc: 'Ein Knopf zu einer anderen Seite oder Adresse' },
    columns: { name: 'Zwei Spalten', desc: 'Zwei Spalten nebeneinander, auf dem Handy untereinander' },
    imagetext: { name: 'Bild und Text', desc: 'Ein Foto neben einem Text, links oder rechts' },
    carousel: { name: 'Karussell', desc: 'Mehrere Fotos zum Durchblättern, auf Wunsch automatisch' },
    hero: { name: 'Titelbild', desc: 'Großes Bild mit Überschrift (nur Startseite)' },
    homephotos: { name: 'Startseiten-Fotos', desc: 'Zeigt die Fotos, die in der Galerie mit „Startseite“ markiert sind (Karussell, Raster oder ein Bild)' },
    galleries: { name: 'Galerien-Kacheln', desc: 'Die Galerien als Kacheln mit Titelbild' }
  };
  var ALL = (isHome ? ['hero'] : []).concat(['heading', 'text', 'imagetext', 'image', 'homephotos', 'carousel', 'gallery', 'galleries', 'quote', 'rule', 'button', 'columns']);
  var SIMPLE = ['heading', 'text', 'image', 'quote', 'button'];

  function make(t) {
    switch (t) {
      case 'heading': return { t: t, level: 2, text: '', style: 'normal' };
      case 'text': return { t: t, text: '', style: 'normal' };
      case 'imagetext': return { t: t, photo: 0, alt: '', decor: false, caption: '', text: '', side: 'links', style: 'normal' };
      case 'carousel': return { t: t, photos: [], auto: false, interval: 6, ratio: '3-2', span: 'box' };
      case 'hero': return { t: t, photo: 0, alt: '', decor: true, title: '', span: 'box', height: 'normal', fx: 50, fy: 50 };
      case 'galleries': return { t: t, title: 'Galerien', limit: 0 };
      case 'homephotos': return { t: t, mode: 'karussell', span: 'voll', ratio: '16-9', auto: true, interval: 6, limit: 8, height: 'normal' };
      case 'image': return { t: t, photo: 0, alt: '', decor: false, caption: '', width: 'normal', href: '' };
      case 'gallery': return { t: t, photos: [], cols: 3 };
      case 'quote': return { t: t, text: '', by: '', style: 'normal' };
      case 'rule': return { t: t };
      case 'button': return { t: t, label: '', href: '', style: 'normal' };
      default: return { t: 'columns', left: [], right: [] };
    }
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = text; }
    return n;
  }
  function btn(label, cls, onclick, aria) {
    var b = el('button', 'btn small ghost ' + (cls || ''), label);
    b.type = 'button';
    if (aria) { b.setAttribute('aria-label', aria); }
    b.addEventListener('click', onclick);
    return b;
  }
  function labelled(text, control) {
    var l = el('label', 'field');
    l.appendChild(el('span', null, text));
    l.appendChild(control);
    return l;
  }
  function input(obj, key, max, type) {
    var i = el('input');
    i.type = type || 'text';
    i.value = obj[key] || '';
    if (max) { i.maxLength = max; }
    i.addEventListener('input', function () { obj[key] = i.value; dirty = true; });
    return i;
  }
  function textarea(obj, key, max, rows) {
    var t = el('textarea');
    t.rows = rows || 6;
    t.value = obj[key] || '';
    if (max) { t.maxLength = max; }
    t.addEventListener('input', function () { obj[key] = t.value; dirty = true; });
    return t;
  }
  function select(obj, key, options, conv) {
    var s = el('select');
    options.forEach(function (o) {
      var op = el('option', null, o[1]);
      op.value = String(o[0]);
      if (String(obj[key]) === String(o[0])) { op.selected = true; }
      s.appendChild(op);
    });
    s.addEventListener('change', function () { obj[key] = conv ? conv(s.value) : s.value; dirty = true; });
    return s;
  }

  // --- Ansage für Screenreader ---
  var live = el('p', 'sr');
  live.setAttribute('role', 'status');
  live.setAttribute('aria-live', 'polite');
  function say(t) { live.textContent = ''; setTimeout(function () { live.textContent = t; }, 30); }

  // --- Liste und Blöcke ---
  var drag = null;

  function renderAll() {
    root.textContent = '';
    root.appendChild(live);
    root.appendChild(renderList(state, ALL));
    if (focusId !== null) {
      var target = root.querySelector('[data-bid="' + focusId + '"] ' + (focusSel || '.bbody input, .bbody textarea, .bbody select, .bbody button'));
      if (target) { target.focus(); }
      var card = root.querySelector('[data-bid="' + focusId + '"]');
      if (card && card.scrollIntoView) { card.scrollIntoView({ block: 'nearest' }); }
      focusId = null; focusSel = '';
    }
  }

  function renderList(list, allowed) {
    var wrap = el('div', 'blist');
    list.forEach(function (b, i) { wrap.appendChild(renderBlock(list, i, allowed)); });
    wrap.appendChild(addMenu(list, allowed));
    return wrap;
  }

  function addMenu(list, allowed) {
    var d = el('details', 'badd');
    d.appendChild(el('summary', 'btn ghost', '+ Block hinzufügen'));
    var g = el('div', 'baddgrid');
    allowed.forEach(function (t) {
      var b = el('button', 'baddcard');
      b.type = 'button';
      b.appendChild(el('strong', null, TYPES[t].name));
      b.appendChild(el('span', 'small muted', TYPES[t].desc));
      b.addEventListener('click', function () {
        var nb = make(t);
        nb._id = ++uid;
        list.push(nb);
        dirty = true;
        focusId = nb._id;
        focusSel = t === 'rule' ? '.bhead .bup' : '';
        say('Block „' + TYPES[t].name + '“ hinzugefügt.');
        renderAll();
      });
      g.appendChild(b);
    });
    d.appendChild(g);
    return d;
  }

  function summary(b) {
    var cut = function (s) { s = String(s || '').replace(/\s+/g, ' ').trim(); return s.length > 70 ? s.slice(0, 70) + '…' : s; };
    switch (b.t) {
      case 'heading': case 'text': case 'quote': return cut(b.text);
      case 'image': return cut(b.caption || b.alt || (photos[b.photo] && photos[b.photo].t));
      case 'imagetext': return cut(b.text || b.alt);
      case 'hero': return cut(b.title);
      case 'galleries': return cut(b.title);
      case 'homephotos': return 'Fotos mit Schalter „Startseite“';
      case 'carousel': case 'gallery': return (b.photos.length === 1 ? '1 Foto' : b.photos.length + ' Fotos');
      case 'button': return cut(b.label);
      case 'columns': return 'links ' + b.left.length + ', rechts ' + b.right.length + ' Blöcke';
      default: return '';
    }
  }

  function isEmpty(b) {
    return (b.t === 'heading' || b.t === 'text' || b.t === 'quote') && !String(b.text || '').trim();
  }

  function renderBlock(list, i, allowed) {
    var b = list[i];
    if (!b._id) { b._id = ++uid; }
    var tname = TYPES[b.t] ? TYPES[b.t].name : b.t;
    var nr = i + 1;
    var card = el('article', 'bblock' + (b._c ? ' collapsed' : ''));
    card.setAttribute('data-bid', String(b._id));
    card.setAttribute('aria-label', 'Block ' + nr + ': ' + tname);
    var head = el('div', 'bhead');
    var handle = el('span', 'bhandle', '⠿');
    handle.draggable = true;
    handle.setAttribute('aria-hidden', 'true');
    handle.title = 'Zum Sortieren ziehen';
    handle.addEventListener('dragstart', function (e) {
      drag = { list: list, i: i };
      if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', 'block'); if (e.dataTransfer.setDragImage) { e.dataTransfer.setDragImage(card, 10, 10); } }
      card.classList.add('dragging');
    });
    handle.addEventListener('dragend', function () { drag = null; clearDrop(); card.classList.remove('dragging'); });
    head.appendChild(handle);
    var ttl = el('strong', 'btitle', nr + '. ' + tname);
    ttl.tabIndex = -1;
    head.appendChild(ttl);
    head.appendChild(el('span', 'bsummary small muted', b._c ? summary(b) : ''));
    var tools = el('div', 'btools');
    var up = btn('↑', 'bup', function () { move(list, i, -1); }, tname + ' ' + nr + ' nach oben');
    var down = btn('↓', 'bdown', function () { move(list, i, 1); }, tname + ' ' + nr + ' nach unten');
    if (i === 0) { up.disabled = true; }
    if (i === list.length - 1) { down.disabled = true; }
    tools.appendChild(up);
    tools.appendChild(down);
    var tg = btn(b._c ? 'Aufklappen' : 'Zuklappen', '', function () { b._c = !b._c; focusId = b._id; focusSel = '.bhead button:nth-of-type(3)'; renderAll(); }, (b._c ? 'Aufklappen: ' : 'Zuklappen: ') + tname + ' ' + nr);
    tg.setAttribute('aria-expanded', b._c ? 'false' : 'true');
    tools.appendChild(tg);
    tools.appendChild(btn('Duplizieren', '', function () {
      var copy = JSON.parse(JSON.stringify(b, replacer));
      copy._id = ++uid;
      list.splice(i + 1, 0, copy);
      dirty = true;
      focusId = copy._id;
      focusSel = '.bhead .bup';
      say('Block dupliziert.');
      renderAll();
    }, tname + ' ' + nr + ' duplizieren'));
    tools.appendChild(btn('Löschen', '', function () {
      if (!isEmpty(b) && !window.confirm('Diesen Block („' + tname + '“) löschen?')) { return; }
      list.splice(i, 1);
      dirty = true;
      var near = list[i] || list[i - 1];
      if (near) { focusId = near._id || null; focusSel = '.bhead .btitle'; }
      say('Block gelöscht.');
      renderAll();
    }, tname + ' ' + nr + ' löschen'));
    head.appendChild(tools);
    card.appendChild(head);
    var body = el('div', 'bbody');
    if (b._c) { body.hidden = true; }
    fill(body, b);
    card.appendChild(body);

    // Ablegen beim Ziehen
    card.addEventListener('dragover', function (e) {
      if (!drag || drag.list !== list) { return; }
      e.preventDefault();
      e.stopPropagation();
      var r = card.getBoundingClientRect();
      clearDrop();
      card.classList.add(e.clientY < r.top + r.height / 2 ? 'drop-before' : 'drop-after');
    });
    card.addEventListener('drop', function (e) {
      if (!drag || drag.list !== list) { return; }
      e.preventDefault();
      e.stopPropagation();
      var r = card.getBoundingClientRect();
      var to = e.clientY < r.top + r.height / 2 ? i : i + 1;
      var from = drag.i;
      drag = null;
      clearDrop();
      if (to > from) { to--; }
      if (to !== from) { var it = list.splice(from, 1)[0]; list.splice(to, 0, it); dirty = true; focusId = it._id; focusSel = '.bhead .bup'; say('Block verschoben auf Position ' + (to + 1) + ' von ' + list.length + '.'); }
      renderAll();
    });
    return card;
  }

  function clearDrop() {
    Array.prototype.forEach.call(root.querySelectorAll('.drop-before,.drop-after'), function (n) { n.classList.remove('drop-before', 'drop-after'); });
  }

  function move(list, i, d) {
    var j = i + d;
    if (j < 0 || j >= list.length) { return; }
    var it = list.splice(i, 1)[0];
    list.splice(j, 0, it);
    dirty = true;
    focusId = it._id;
    focusSel = (j === 0 ? '.bhead .bdown' : (j === list.length - 1 ? '.bhead .bup' : (d < 0 ? '.bhead .bup' : '.bhead .bdown')));
    say('Block verschoben auf Position ' + (j + 1) + ' von ' + list.length + '.');
    renderAll();
  }

  // --- Felder je Blocktyp ---
  function fill(body, b) {
    switch (b.t) {
      case 'heading':
        body.appendChild(labelled('Größe', select(b, 'level', [[2, 'Große Überschrift'], [3, 'Kleinere Überschrift']], Number)));
        body.appendChild(labelled('Überschrift', input(b, 'text', 200)));
        body.appendChild(labelled('Stil', select(b, 'style', [['normal', 'Linksbündig'], ['mitte', 'Zentriert']])));
        break;
      case 'text':
        body.appendChild(labelled('Text', textarea(b, 'text', 20000, 7)));
        body.appendChild(labelled('Stil', select(b, 'style', [['normal', 'Normal'], ['gross', 'Groß (Einleitung)'], ['mitte', 'Zentriert'], ['flaeche', 'Auf heller Fläche']])));
        body.appendChild(el('p', 'small muted', 'Gestaltung: **fett**, *kursiv*, Liste mit „- “ am Zeilenanfang, Link als [Text](https://adresse.de). Leerzeile = neuer Absatz.'));
        break;
      case 'quote':
        body.appendChild(labelled('Zitat', textarea(b, 'text', 1000, 3)));
        body.appendChild(labelled('Quelle (optional)', input(b, 'by', 200)));
        body.appendChild(labelled('Stil', select(b, 'style', [['normal', 'Normal'], ['gross', 'Groß'], ['flaeche', 'Auf heller Fläche']])));
        break;
      case 'rule':
        body.appendChild(el('p', 'small muted', 'Eine dünne Linie. Hier gibt es nichts auszufüllen.'));
        break;
      case 'button':
        body.appendChild(labelled('Beschriftung', input(b, 'label', 80)));
        body.appendChild(labelled('Ziel', input(b, 'href', 500)));
        body.appendChild(labelled('Stil', select(b, 'style', [['normal', 'Linksbündig'], ['mitte', 'Zentriert']])));
        body.appendChild(el('p', 'small muted', 'Eigene Seite wie /galerien/ oder /ueber-uns/, eine Adresse mit https:// oder eine Mail-Adresse mit mailto:.'));
        break;
      case 'image': fillImage(body, b); break;
      case 'gallery': fillGallery(body, b); break;
      case 'carousel': fillGallery(body, b, true); break;
      case 'imagetext':
        fillImage(body, b, true);
        break;
      case 'hero':
        fillHero(body, b);
        break;
      case 'homephotos':
        body.appendChild(el('p', 'small muted', 'Dieser Block zeigt die Fotos, die du in einer Galerie mit dem Knopf „Startseite“ markiert hast. So änderst du die Bilder der Startseite, ohne diese Seite zu bearbeiten.'));
        body.appendChild(labelled('Darstellung', select(b, 'mode', [['karussell', 'Karussell (Diashow)'], ['raster', 'Raster'], ['titelbild', 'Ein großes Bild (das erste markierte)']])));
        body.appendChild(labelled('Breite', select(b, 'span', [['box', 'Im Seitenrahmen mit runden Ecken'], ['voll', 'Über die ganze Fensterbreite']])));
        body.appendChild(labelled('Format (Karussell)', select(b, 'ratio', [['3-2', 'Standard (3:2)'], ['16-9', 'Breit (16:9)'], ['2-1', 'Sehr breit (2:1)'], ['21-9', 'Panorama (21:9)'], ['3-1', 'Schmales Band (3:1)']])));
        body.appendChild(labelled('Höhe (großes Bild)', select(b, 'height', [['niedrig', 'Niedrig'], ['normal', 'Normal'], ['hoch', 'Hoch']])));
        body.appendChild(labelled('Höchstens so viele Fotos', select(b, 'limit', [[4, '4'], [6, '6'], [8, '8'], [12, '12']], Number)));
        var au = el('input');
        au.type = 'checkbox';
        au.checked = !!b.auto;
        au.addEventListener('change', function () { b.auto = au.checked; dirty = true; });
        var aul = el('label', 'check');
        aul.appendChild(au);
        aul.appendChild(document.createTextNode(' Automatisch weiterblättern (Karussell)'));
        body.appendChild(aul);
        break;
      case 'galleries':
        body.appendChild(labelled('Überschrift über den Kacheln (leer = keine)', input(b, 'title', 100)));
        body.appendChild(labelled('Anzahl', select(b, 'limit', [[0, 'Alle Galerien'], [3, 'Die ersten 3'], [6, 'Die ersten 6'], [9, 'Die ersten 9']], Number)));
        body.appendChild(el('p', 'small muted', 'Zeigt die veröffentlichten Galerien mit ihrem Titelbild. Hier gibt es sonst nichts auszufüllen.'));
        break;
      case 'columns': {
        var cols = el('div', 'bcolsedit');
        [['left', 'Linke Spalte'], ['right', 'Rechte Spalte']].forEach(function (s) {
          var c = el('div', 'bcol');
          c.appendChild(el('h4', null, s[1]));
          c.appendChild(renderList(b[s[0]], SIMPLE));
          cols.appendChild(c);
        });
        body.appendChild(cols);
        break;
      }
    }
  }

  function thumb(id) {
    var p = photos[id];
    var box = el('div', 'bthumb');
    if (p && p.u) {
      var im = el('img');
      im.src = p.u;
      im.alt = '';
      im.loading = 'lazy';
      box.appendChild(im);
    } else {
      box.appendChild(el('span', 'small muted', p && p.x ? 'Foto nicht mehr vorhanden' : 'Kein Foto'));
    }
    return box;
  }

  function altRow(obj, noDecor) {
    var wrap = el('div', 'altrow');
    var a = input(obj, 'alt', 250);
    var warn = el('p', 'warn small', 'Alt-Text fehlt: Bitte beschreiben, was auf dem Bild zu sehen ist (für Screenreader und Suchmaschinen). Ohne Alt-Text lässt sich die Seite nicht veröffentlichen.');
    var chk = el('input');
    chk.type = 'checkbox';
    chk.checked = !!obj.decor;
    var lab = el('label', 'check');
    lab.appendChild(chk);
    lab.appendChild(document.createTextNode(' Nur Dekoration (kein Alt-Text nötig)'));
    var upd = function () { warn.hidden = !!obj.decor || String(obj.alt || '').trim() !== ''; a.disabled = !!obj.decor; };
    a.addEventListener('input', upd);
    chk.addEventListener('change', function () { obj.decor = chk.checked; dirty = true; upd(); });
    upd();
    wrap.appendChild(labelled('Alt-Text (Beschreibung des Bildes)', a));
    wrap.appendChild(warn);
    if (!noDecor) { wrap.appendChild(lab); }
    return wrap;
  }

  function fillImage(body, b, withText) {
    var row = el('div', 'bphoto');
    row.appendChild(thumb(b.photo));
    var side = el('div', 'bphotoside');
    var choose = btn(b.photo ? 'Foto ändern' : 'Foto auswählen', '', function () {
      pick(false, function (rows) {
        var r = rows[0];
        photos[r.id] = { t: r.t, a: r.a, c: r.c, u: r.u, x: 0 };
        b.photo = r.id;
        if (!b.alt && r.a) { b.alt = r.a; }
        if (!b.caption && r.c) { b.caption = r.c; }
        dirty = true;
        focusId = b._id;
        focusSel = '.bphotoside button';
        renderAll();
      });
    });
    side.appendChild(choose);
    if (!b.photo) { side.appendChild(el('p', 'warn small', 'Bitte ein Foto auswählen.')); }
    row.appendChild(side);
    body.appendChild(row);
    body.appendChild(altRow(b));
    body.appendChild(labelled('Bildunterschrift (optional)', input(b, 'caption', 300)));
    if (withText) {
      body.appendChild(labelled('Text neben dem Bild', textarea(b, 'text', 20000, 6)));
      body.appendChild(labelled('Bild steht', select(b, 'side', [['links', 'Links vom Text'], ['rechts', 'Rechts vom Text']])));
      body.appendChild(labelled('Stil', select(b, 'style', [['normal', 'Normal'], ['flaeche', 'Auf heller Fläche']])));
      return;
    }
    body.appendChild(labelled('Breite', select(b, 'width', [['schmal', 'Schmal'], ['normal', 'Normal'], ['voll', 'Volle Breite']])));
    body.appendChild(labelled('Link beim Anklicken (optional)', input(b, 'href', 500)));
  }

  // Bildausschnitt: Klick auf das Bild (oder die Regler) legt fest, welcher Punkt beim Zuschneiden im Bild bleibt.
  function focusPicker(obj) {
    var p = photos[obj.photo];
    if (!p || !p.u) { return null; }
    if (typeof obj.fx !== 'number') { obj.fx = 50; }
    if (typeof obj.fy !== 'number') { obj.fy = 50; }
    var wrap = el('div', 'bfocus');
    wrap.appendChild(el('p', 'small muted', 'Ausschnitt: Auf das Bild klicken, was im Mittelpunkt bleiben soll. Bei breiten oder flachen Formaten wird der Rest abgeschnitten.'));
    var box = el('div', 'bfocusbox');
    var im = el('img');
    im.src = p.u;
    im.alt = '';
    box.appendChild(im);
    var dot = el('span', 'bfocusdot');
    box.appendChild(dot);
    var rx = el('input'), ry = el('input');
    function place() { dot.style.left = obj.fx + '%'; dot.style.top = obj.fy + '%'; rx.value = obj.fx; ry.value = obj.fy; }
    box.addEventListener('click', function (e) {
      var r = box.getBoundingClientRect();
      obj.fx = Math.max(0, Math.min(100, Math.round(((e.clientX - r.left) / r.width) * 10) * 10));
      obj.fy = Math.max(0, Math.min(100, Math.round(((e.clientY - r.top) / r.height) * 10) * 10));
      dirty = true;
      place();
    });
    wrap.appendChild(box);
    [[rx, 'fx', 'Waagerecht (links bis rechts)'], [ry, 'fy', 'Senkrecht (oben bis unten)']].forEach(function (a) {
      a[0].type = 'range'; a[0].min = 0; a[0].max = 100; a[0].step = 10;
      a[0].addEventListener('input', function () { obj[a[1]] = Number(a[0].value); dirty = true; place(); });
      wrap.appendChild(labelled(a[2], a[0]));
    });
    place();
    return wrap;
  }

  function fillHero(body, b) {
    var row = el('div', 'bphoto');
    row.appendChild(thumb(b.photo));
    var side = el('div', 'bphotoside');
    side.appendChild(btn(b.photo ? 'Foto ändern' : 'Foto auswählen', '', function () {
      pick(false, function (rows) {
        var r = rows[0];
        photos[r.id] = { t: r.t, a: r.a, c: r.c, u: r.u, x: 0 };
        b.photo = r.id;
        if (!b.alt && r.a) { b.alt = r.a; }
        dirty = true; focusId = b._id; focusSel = '.bphotoside button'; renderAll();
      });
    }));
    if (b.photo) {
      side.appendChild(btn('Foto entfernen', '', function () { b.photo = 0; dirty = true; focusId = b._id; focusSel = '.bphotoside button'; renderAll(); }));
    } else {
      side.appendChild(el('p', 'small muted', 'Ohne Foto erscheint nur die Überschrift.'));
    }
    row.appendChild(side);
    body.appendChild(row);
    body.appendChild(labelled('Überschrift auf dem Bild', input(b, 'title', 200)));
    body.appendChild(labelled('Breite', select(b, 'span', [['box', 'Im Seitenrahmen mit runden Ecken'], ['voll', 'Über die ganze Fensterbreite']])));
    body.appendChild(labelled('Höhe', select(b, 'height', [['niedrig', 'Niedrig'], ['normal', 'Normal'], ['hoch', 'Hoch (fast bildschirmfüllend)']])));
    if (b.photo) {
      var fp = focusPicker(b);
      if (fp) { body.appendChild(fp); }
      body.appendChild(altRow(b));
    }
  }

  function fillGallery(body, b, carousel) {
    if (carousel) {
      var auto = el('input');
      auto.type = 'checkbox';
      auto.checked = !!b.auto;
      auto.addEventListener('change', function () { b.auto = auto.checked; dirty = true; });
      var al = el('label', 'check');
      al.appendChild(auto);
      al.appendChild(document.createTextNode(' Automatisch weiterblättern (nicht bei „weniger Bewegung“ im System)'));
      body.appendChild(al);
      body.appendChild(labelled('Wechsel nach', select(b, 'interval', [[4, '4 Sekunden'], [6, '6 Sekunden'], [8, '8 Sekunden'], [12, '12 Sekunden']], Number)));
      body.appendChild(labelled('Format', select(b, 'ratio', [['3-2', 'Standard (3:2)'], ['16-9', 'Breit (16:9)'], ['2-1', 'Sehr breit (2:1)'], ['21-9', 'Panorama (21:9)'], ['3-1', 'Schmales Band (3:1)']])));
      body.appendChild(labelled('Breite', select(b, 'span', [['box', 'Im Seitenrahmen mit runden Ecken'], ['voll', 'Über die ganze Fensterbreite']])));
    } else {
      body.appendChild(labelled('Spalten', select(b, 'cols', [[2, '2 Spalten'], [3, '3 Spalten'], [4, '4 Spalten']], Number)));
    }
    var ul = el('ul', 'bgal');
    b.photos.forEach(function (p, i) {
      var li = el('li', 'bgalitem');
      li.appendChild(thumb(p.photo));
      var side = el('div', 'bgalside');
      side.appendChild(altRow(p, !carousel));
      if (carousel) {
        var fd = el('details', 'bfocusdet');
        fd.appendChild(el('summary', 'small', 'Bildausschnitt wählen'));
        var fpp = focusPicker(p);
        if (fpp) { fd.appendChild(fpp); }
        side.appendChild(fd);
      }
      var tools = el('div', 'btools');
      var u = btn('↑', '', function () { reorder(b.photos, i, -1, b); }, 'Foto ' + (i + 1) + ' nach vorn');
      var d = btn('↓', '', function () { reorder(b.photos, i, 1, b); }, 'Foto ' + (i + 1) + ' nach hinten');
      if (i === 0) { u.disabled = true; }
      if (i === b.photos.length - 1) { d.disabled = true; }
      tools.appendChild(u);
      tools.appendChild(d);
      tools.appendChild(btn('Entfernen', '', function () { b.photos.splice(i, 1); dirty = true; focusId = b._id; focusSel = '.bgalbtn'; renderAll(); }, 'Foto ' + (i + 1) + ' aus dem Raster entfernen'));
      side.appendChild(tools);
      li.appendChild(side);
      ul.appendChild(li);
    });
    body.appendChild(ul);
    if (!b.photos.length) { body.appendChild(el('p', 'warn small', 'Bitte mindestens ein Foto hinzufügen.')); }
    var add = btn('Fotos hinzufügen', 'bgalbtn', function () {
      pick(true, function (rows) {
        rows.forEach(function (r) {
          if (b.photos.length >= 12) { say('Höchstens 12 Fotos je Raster: nicht alle wurden übernommen.'); return; }
          photos[r.id] = { t: r.t, a: r.a, c: r.c, u: r.u, x: 0 };
          b.photos.push({ photo: r.id, alt: r.a || '', decor: false });
        });
        dirty = true;
        focusId = b._id;
        focusSel = '.bgalbtn';
        renderAll();
      });
    });
    body.appendChild(add);
    body.appendChild(el('p', 'small muted', 'Höchstens 12 Fotos je Raster.'));
  }

  function reorder(arr, i, d, owner) {
    var j = i + d;
    if (j < 0 || j >= arr.length) { return; }
    var it = arr.splice(i, 1)[0];
    arr.splice(j, 0, it);
    dirty = true;
    focusId = owner._id;
    focusSel = '.bgalbtn';
    say('Foto verschoben auf Position ' + (j + 1) + ' von ' + arr.length + '.');
    renderAll();
  }

  // --- Fotoauswahl ---
  function pick(multi, done) {
    var opener = document.activeElement;
    var dlg = el('dialog', 'picker');
    dlg.setAttribute('aria-label', multi ? 'Fotos auswählen' : 'Foto auswählen');
    var inner = el('div', 'pickin');
    inner.appendChild(el('h2', null, multi ? 'Fotos auswählen' : 'Foto auswählen'));
    var q = el('input');
    q.type = 'search';
    q.setAttribute('aria-label', 'Fotos suchen (Titel, Ort, Alt-Text, Dateiname)');
    q.placeholder = 'Suchen …';
    inner.appendChild(q);
    var info = el('p', 'small muted');
    info.setAttribute('role', 'status');
    inner.appendChild(info);
    var grid = el('ul', 'pgrid');
    inner.appendChild(grid);
    var more = btn('Mehr laden', '', function () { load(false); });
    more.hidden = true;
    inner.appendChild(more);
    var foot = el('div', 'actions');
    var ok = btn('Übernehmen', '', function () {
      var rows = Object.keys(sel).map(function (k) { return sel[k]; });
      dlg.close();
      if (rows.length) { done(rows); }
    });
    var cancel = btn('Abbrechen', '', function () { dlg.close(); });
    if (multi) { ok.disabled = true; foot.appendChild(ok); }
    foot.appendChild(cancel);
    inner.appendChild(foot);
    dlg.appendChild(inner);
    var sel = {}, page = 1, timer = null, seq = 0;

    function tile(r) {
      var li = el('li');
      var b = el('button', 'ptile');
      b.type = 'button';
      var im = el('img');
      im.src = r.u;
      im.alt = '';
      im.loading = 'lazy';
      b.appendChild(im);
      b.appendChild(el('span', 'small', r.t));
      if (multi) { b.setAttribute('aria-pressed', sel[r.id] ? 'true' : 'false'); }
      b.addEventListener('click', function () {
        if (!multi) { dlg.close(); done([r]); return; }
        if (sel[r.id]) { delete sel[r.id]; b.setAttribute('aria-pressed', 'false'); } else { sel[r.id] = r; b.setAttribute('aria-pressed', 'true'); }
        var n = Object.keys(sel).length;
        ok.textContent = n ? 'Übernehmen (' + n + ')' : 'Übernehmen';
        ok.disabled = n === 0;
      });
      li.appendChild(b);
      return li;
    }

    function load(reset) {
      if (reset) { page = 1; grid.textContent = ''; } else { page++; }
      var my = ++seq;
      info.textContent = 'Lädt …';
      fetch(pickUrl + '?q=' + encodeURIComponent(q.value) + '&seite=' + page, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { if (!r.ok) { throw new Error('x'); } return r.json(); })
        .then(function (d) {
          if (my !== seq) { return; }
          (d.rows || []).forEach(function (r) { grid.appendChild(tile(r)); });
          more.hidden = !d.more;
          info.textContent = grid.children.length ? '' : 'Keine passenden Fotos gefunden.';
        })
        .catch(function () { if (my === seq) { info.textContent = 'Die Fotos konnten nicht geladen werden. Bitte noch einmal versuchen.'; } });
    }
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { load(true); }, 250); });
    dlg.addEventListener('close', function () { if (dlg.parentNode) { dlg.parentNode.removeChild(dlg); } if (opener && opener.focus) { try { opener.focus(); } catch (e) { /* weg */ } } });
    document.body.appendChild(dlg);
    if (dlg.showModal) { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
    q.focus();
    load(true);
  }

  // --- Speichern, Verlassen ---
  function replacer(k, v) { return k.charAt(0) === '_' ? undefined : v; }
  function sync() { field.value = JSON.stringify({ v: 1, blocks: state }, replacer); }
  form.addEventListener('submit', function () { sync(); submitting = true; });
  form.addEventListener('input', function () { dirty = true; });
  form.addEventListener('change', function () { dirty = true; });
  window.addEventListener('beforeunload', function (e) {
    if (dirty && !submitting) { e.preventDefault(); e.returnValue = ''; }
  });

  renderAll();
  dirty = false;
})();
