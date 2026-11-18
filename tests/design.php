<?php
declare(strict_types=1);

// Umschalter Design (Warm, Klar, Studio, Blau) und Farbschema: Knöpfe in den Seiten, Skript, CSS-Variablensätze, CSP-Verträglichkeit.

require_once __DIR__ . '/support.php';

$f = lk_new_tmp($tmp, 'design');
$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$js = (string) file_get_contents($root . '/public/assets/js/design.js');

$m = lk_make($f . '/a');
$b = new TestBrowser($m['app'], $m['config']);
$login = $b->get('/admin/login');
$s = lk_setup($m);
$admin = $s['b']->get('/admin');

foreach (['Anmeldeseite' => $login, 'Admin' => $admin] as $name => $r) {
    check("Design: $name hat alle vier Design-Knöpfe", str_contains($r->body, 'data-design-set="warm"') && str_contains($r->body, 'data-design-set="klar"') && str_contains($r->body, 'data-design-set="studio"') && str_contains($r->body, 'data-design-set="blau"') && !str_contains($r->body, 'data-design-set="rothe"') && str_contains($r->body, 'data-theme-set="dark"') && str_contains($r->body, 'data-theme-set="light"'));
    check("Design: $name lädt design.js im Kopf (vor dem Inhalt)", preg_match('~<script src="/assets/js/design\.js"></script>\s*</head>~', $r->body) === 1);
    check("Design: $name ohne Inline-Style und Inline-Skript", !preg_match('~\sstyle=|<script(?![^>]*\ssrc=)|\sonclick=~i', $r->body));
}

foreach (['warm', 'klar', 'studio', 'blau'] as $d) {
    check("Design: CSS kennt $d, hell und dunkel", str_contains($css, "[data-design=\"$d\"]") && str_contains($css, "[data-design=\"$d\"][data-theme=\"dark\"]") && str_contains($css, "/*@set $d hell*/") && str_contains($css, "/*@set $d dunkel*/"));
}
check('Design: Warm mit 2 px Radien, Klar und Studio rund, Blau klein', str_contains($css, '--r-card:2px') && str_contains($css, '--r-card:14px') && str_contains($css, '--r-card:18px') && str_contains($css, '--r-card:6px'));
check('Design: Kontrast aller Designs mindestens WCAG AA (tools/kontrast.py)', (static function () use ($root): bool {
    $out = [];
    exec('python3 ' . escapeshellarg($root . '/tools/kontrast.py') . ' 2>&1', $out, $rc);
    return $rc === 0 || (str_contains(implode('', $out), 'python3') && $rc === 127);
})());
check('Design: Schriften nur lokal', !preg_match('~https?://|@import~i', $css));
check('Design: Skript merkt Wahl, fängt gesperrten Speicher ab', str_contains($js, 'localStorage') && str_contains($js, 'catch') && str_contains($js, "'lichtkammer-design'"));
check('Design: Skript setzt nur bekannte Werte und bildet alte Namen ab', str_contains($js, "['warm', 'klar', 'studio', 'blau']") && str_contains($js, "'lurohome' ? 'warm'") && str_contains($js, "'rothe' ? 'klar'"));
check('Design: Skript ohne eval und ohne externe Quellen', !preg_match('~eval\(|innerHTML|https?://~', $js));

// --- Admin-Navigation und Seiten (Entwurf 23) ---
$sb = $s['b'];
$csrfN = TestBrowser::csrf($sb->get('/admin'));
$dash = $sb->get('/admin')->body;
check('Navigation: Abschnitte Inhalte, Seiten, Verwaltung', preg_match_all('~class="sidehead">([^<]+)<~', $dash, $hm) === 3 && $hm[1] === ['Inhalte', 'Seiten', 'Verwaltung']);
check('Navigation: Unterpunkte eingerückt (class="sub"), Abschnittsüberschrift fett mit Linie', substr_count($dash, 'class="sub"') >= 12 && preg_match('~\.sidehead\{[^}]*font:700[^}]*border-bottom~', $css) === 1 && str_contains($css, '.sidenav a.sub{margin-left:14px}'));
$verw = substr($dash, (int) strpos($dash, '>Verwaltung<'));
$inh = substr($dash, (int) strpos($dash, '>Inhalte<'), (int) strpos($dash, '>Seiten<') - (int) strpos($dash, '>Inhalte<'));
$sei = substr($dash, (int) strpos($dash, '>Seiten<'), (int) strpos($dash, '>Verwaltung<') - (int) strpos($dash, '>Seiten<'));
check('Navigation: Logos, Einstellungen und Papierkorb unter Verwaltung', str_contains($verw, 'href="/admin/logos"') && str_contains($verw, 'href="/admin/einstellungen"') && str_contains($verw, 'href="/admin/papierkorb"') && !str_contains($inh . $sei, '/admin/logos') && !str_contains($inh . $sei, '/admin/einstellungen') && !str_contains($inh . $sei, '/admin/papierkorb'));
check('Navigation: Seiten mit Startseite, Alle Seiten, Neue Seite, Menü & Fußzeile', str_contains($sei, 'href="/admin/startseite"') && str_contains($sei, '>Alle Seiten<') && str_contains($sei, 'href="/admin/seiten/neu"') && str_contains($sei, 'href="/admin/menue"') && str_contains($sei, 'Menü &amp; Fußzeile'));

foreach (['impressum', 'datenschutz'] as $v) {
    $sb->post('/admin/seiten', ['_csrf' => $csrfN, 'vorlage' => $v]);
}
$sb->post('/admin/seiten', ['_csrf' => $csrfN, 'title' => 'Importiert Eins']);
$sb->post('/admin/seiten', ['_csrf' => $csrfN, 'title' => 'Zebra']);
$all = $sb->get('/admin/seiten');
check('Alle Seiten: Impressum, Datenschutz und weitere Seiten stehen alle in der Liste, ohne Formular', $all->status === 200 && substr_count($all->body, 'class="row"') === 4 && str_contains($all->body, '>Impressum<') && str_contains($all->body, '>Datenschutz<') && str_contains($all->body, '>Importiert Eins<') && str_contains($all->body, '>Zebra<') && !str_contains($all->body, 'name="title"'));
check('Alle Seiten: nach Titel geordnet, Menü-Gruppen stehen dort nicht', strpos($all->body, '>Datenschutz<') < strpos($all->body, '>Zebra<') && !str_contains($all->body, 'Im Menü oben'));
check('Alle Seiten: im Menü markiert', str_contains($all->body, 'href="/admin/seiten" aria-current="page"') && !str_contains($all->body, 'href="/admin/seiten/neu" aria-current'));
$neu = $sb->get('/admin/seiten/neu');
check('Neue Seite: eigener Aufruf mit Titel-Feld, Vorlage und Anlegen', $neu->status === 200 && str_contains($neu->body, 'name="title"') && str_contains($neu->body, 'action="/admin/seiten"') && str_contains($neu->body, 'href="/admin/seiten/neu" aria-current="page"') && !str_contains($neu->body, 'href="/admin/seiten" aria-current'));
$bad = $sb->post('/admin/seiten', ['_csrf' => $csrfN, 'title' => '  ']);
check('Neue Seite: leerer Titel zeigt das Formular mit Fehler (400)', $bad->status === 400 && str_contains($bad->body, 'name="title"') && str_contains($bad->body, 'class="notice error"') && str_contains($bad->body, 'href="/admin/seiten/neu" aria-current="page"') && !str_contains($bad->body, 'href="/admin/seiten" aria-current'));
$mn = $sb->get('/admin/menue');
check('Menü & Fußzeile: Gruppen, Verlinkung, Pfeile; Fußzeile enthält Impressum', $mn->status === 200 && str_contains($mn->body, 'Im Menü oben') && str_contains($mn->body, 'In der Fußzeile') && str_contains($mn->body, '/verlinken') && str_contains($mn->body, '/bewegen') && str_contains($mn->body, 'href="/admin/menue" aria-current="page"'));
$rd = $sb->post('/admin/seiten/1/verlinken', ['_csrf' => $csrfN, 'nav' => 'main']);
check('Menü & Fußzeile: Ändern führt zurück auf die Menü-Seite', ($rd->headers['Location'] ?? '') === '/admin/menue?m=pagenav');
$rm = $sb->post('/admin/seiten/1/bewegen', ['_csrf' => $csrfN, 'richtung' => 'runter']);
check('Menü & Fußzeile: Verschieben führt zurück auf die Menü-Seite', ($rm->headers['Location'] ?? '') === '/admin/menue');
check('Menü & Fußzeile: nur mit Anmeldung', (new TestBrowser($m['app'], $m['config']))->get('/admin/menue')->status !== 200 && (new TestBrowser($m['app'], $m['config']))->get('/admin/seiten/neu')->status !== 200);
check('Navigation: keine PHP-Warnungen in der Seitenleiste', !str_contains($dash, 'Warning:') && !str_contains($dash, 'Undefined'));
check('Design: CSS-Kommentare sind sauber geschlossen (keine verschluckte Regel)', substr_count($css, '/*') === substr_count($css, '*/') && preg_match('~/\*(?:(?!\*/).)*/\*~s', $css) === 0);

// --- Design der öffentlichen Seite (Entwurf 25) ---
$siteCss = (string) file_get_contents($root . '/public/assets/css/site.css');
foreach (['warm', 'klar', 'studio', 'blau'] as $d) {
    check("Öffentliches Design: site.css kennt $d, hell und dunkel (auch nach prefers-color-scheme)", str_contains($siteCss, "/*@set $d hell*/") && str_contains($siteCss, "/*@set $d dunkel*/") && str_contains($siteCss, "[data-design=\"$d\"][data-theme=\"dark\"]") && str_contains($siteCss, "[data-design=\"$d\"]:not([data-theme=\"light\"])"));
}
check('Öffentliches Design: Warm unverändert (Farben, Schatten, runde Rahmen), auch ohne data-design', str_contains($siteCss, ':root,:root[data-design="warm"]{color-scheme:light;--bg:#f5f2ed;--surface:#fffdf9;--soft:#eee8df;--ink:#242321;--muted:#6b675f;--line:#d9d3ca;--accent:#c75d3b;--accent-strong:#a7472c;--on-accent:#fff;--bar:rgba(245,242,237,.78);')
    && str_contains($siteCss, '--shadow:0 1px 2px rgba(36,35,33,.05),0 12px 32px rgba(36,35,33,.08)') && str_contains($siteCss, '--r-card:16px;--r-hero:28px;--r-img:10px;--r-pill:980px') && str_contains($siteCss, ':root:not([data-theme="light"]):not([data-design])')
    && str_contains($siteCss, ':root:not([data-design])[data-theme="dark"]{'));
check('Öffentliches Design: Kontrast von Admin und Seite in tools/kontrast.py', (static function () use ($root): bool {
    $out = [];
    exec('python3 ' . escapeshellarg($root . '/tools/kontrast.py') . ' -v 2>&1', $out, $rc);
    $t = implode("\n", $out);
    return $rc === 127 || ($rc === 0 && str_contains($t, 'site:studio/hell') && str_contains($t, 'site:blau/dunkel') && str_contains($t, 'app:klar/hell'));
})());
