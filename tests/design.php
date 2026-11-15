<?php
declare(strict_types=1);

// Umschalter Design (lurohome / rothe.it) und Farbschema: Knöpfe in den Seiten, Skript, CSS-Variablensätze, CSP-Verträglichkeit.

require_once __DIR__ . '/support.php';

$f = lk_new_tmp($tmp, 'design');
$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$js = (string) file_get_contents($root . '/public/assets/js/design.js');

$m = lk_make($f . '/a');
$b = new TestBrowser($m['app'], $m['config']);
$login = $b->get('/admin/login');
$home = $b->get('/');
$s = lk_setup($m);
$admin = $s['b']->get('/admin');

foreach (['Anmeldeseite' => $login, 'Startseite' => $home, 'Admin' => $admin] as $name => $r) {
    check("Design: $name hat beide Umschalter", str_contains($r->body, 'data-design-set="rothe"') && str_contains($r->body, 'data-design-set="lurohome"') && str_contains($r->body, 'data-theme-set="dark"') && str_contains($r->body, 'data-theme-set="light"'));
    check("Design: $name lädt design.js im Kopf (vor dem Inhalt)", preg_match('~<script src="/assets/js/design\.js"></script>\s*</head>~', $r->body) === 1);
    check("Design: $name ohne Inline-Style und Inline-Skript", !preg_match('~\sstyle=|<script(?![^>]*\ssrc=)|\sonclick=~i', $r->body));
}

check('Design: CSS kennt beide Designs, hell und dunkel', str_contains($css, 'data-design="rothe"') && str_contains($css, '[data-design="rothe"][data-theme="dark"]') && str_contains($css, '[data-design="lurohome"][data-theme="dark"]'));
check('Design: lurohome mit 2 px Radien, rothe.it mit rundem Stil', str_contains($css, '--r-card:2px') && str_contains($css, '--r-card:14px'));
check('Design: Schriften nur lokal', !preg_match('~https?://|@import~i', $css));
check('Design: Skript merkt Wahl, fängt gesperrten Speicher ab', str_contains($js, 'localStorage') && str_contains($js, 'catch') && str_contains($js, "'lichtkammer-design'"));
check('Design: Skript setzt nur bekannte Werte', str_contains($js, "'rothe' ? 'rothe' : 'lurohome'"));
check('Design: Skript ohne eval und ohne externe Quellen', !preg_match('~eval\(|innerHTML|https?://~', $js));
