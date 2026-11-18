<?php
declare(strict_types=1);

namespace Lichtkammer\Site;

use Lichtkammer\Auth\Database;
use Lichtkammer\Config;
use Lichtkammer\Media\Galleries;
use Lichtkammer\Media\MediaException;
use Lichtkammer\View;

/**
 * Erzeugt alle öffentlichen Seiten als HTML. Dieselbe Klasse liefert die Fassung beim Veröffentlichen (nur Veröffentlichtes)
 * und die Vorschau im Admin (mit Entwürfen, Adress-Präfix). Zeigt nie GPS, Dateinamen der Originale oder Papierkorb-Fotos.
 */
final class Renderer
{
    /** Einzige Quelle für öffentliche EXIF-Angaben (Positivliste). */
    public const EXIF_PUBLIC = ['camera', 'lens', 'aperture', 'exposure', 'iso', 'focal_length'];

    /** @var list<string> harte Fehler, die das Veröffentlichen sperren */
    public array $errors = [];
    /** @var list<string> */
    public array $warnings = [];
    /** @var array<string,string> Adresse → Bezeichnung (für die Änderungsliste) */
    public array $labels = [];
    /** @var array<string,mixed> */
    private array $data = [];

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly View $view,
        private readonly Settings $settings,
        private readonly bool $preview = false,
        private readonly string $prefix = '',
    ) {
    }

    public static function h(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function isPreview(): bool
    {
        return $this->preview;
    }

    /** Interne Adresse; in der Vorschau mit Präfix. */
    public function url(string $path): string
    {
        return $this->prefix . $path;
    }

    // --- Daten ---

    /** @return array<string,mixed> */
    public function data(): array
    {
        if ($this->data !== []) {
            return $this->data;
        }
        $set = $this->settings->all();
        $gs = $this->db->all('SELECT * FROM galleries ' . ($this->preview ? '' : "WHERE status = 'published' ") . 'ORDER BY sort_order, id');
        $photoIds = [];
        $galleries = [];
        foreach ($gs as $g) {
            $rows = $this->db->all(
                "SELECT p.id, p.title, p.caption, p.alt_text, p.place, p.year, p.taken_at, p.exif_json, p.width, p.height, p.photographer_id, ph.name AS photographer, p.blur_data_uri
                 FROM gallery_photos gp JOIN photos p ON p.id = gp.photo_id LEFT JOIN photographers ph ON ph.id = p.photographer_id
                 WHERE gp.gallery_id = ? AND p.deleted_at IS NULL AND p.status = 'ready' ORDER BY gp.position, p.id",
                [$g['id']],
            );
            $g['photos'] = $rows;
            foreach ($rows as $r) {
                $photoIds[(int) $r['id']] = true;
            }
            $galleries[] = $g;
        }
        $variants = [];
        $ids = array_keys($photoIds);
        foreach (array_chunk($ids, 400) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach ($this->db->all("SELECT photo_id, width, height, format, path FROM photo_variants WHERE photo_id IN ($in) ORDER BY width", $chunk) as $v) {
                $variants[(int) $v['photo_id']][(string) $v['format']][(int) $v['width']] = ['path' => (string) $v['path'], 'height' => (int) $v['height']];
            }
        }
        $out = [];
        foreach ($galleries as $g) {
            $photos = [];
            foreach ($g['photos'] as $p) {
                $p['variants'] = $variants[(int) $p['id']] ?? [];
                if (!isset($p['variants']['jpeg'])) {
                    $this->issue('Galerie „' . $g['title'] . '“: Foto ' . $p['id'] . ' hat keine fertigen Bilddateien.', $g['status'] === 'published');
                    continue;
                }
                if (trim((string) $p['alt_text']) === '') {
                    $this->issue('Galerie „' . $g['title'] . '“: Foto ' . $p['id'] . ' hat keinen Alt-Text.', $g['status'] === 'published');
                    if (!$this->preview) {
                        continue;
                    }
                }
                $photos[] = $p;
            }
            if ($photos === []) {
                if (!$this->preview && $g['status'] === 'published') {
                    $this->warnings[] = 'Galerie „' . $g['title'] . '“ hat keine sichtbaren Fotos und erscheint nicht.';
                }
                if (!$this->preview) {
                    continue;
                }
            }
            $g['photos'] = $photos;
            $g['cover'] = $this->coverOf($g, $photos);
            $g['years'] = array_values(array_unique(array_filter(array_map(static fn (array $p): int => (int) $p['year'], $photos))));
            sort($g['years']);
            $g['places'] = [];
            $g['people'] = [];
            foreach ($photos as $p) {
                if (trim((string) $p['place']) !== '') {
                    $g['places'][Galleries::slugify((string) $p['place'])] = (string) $p['place'];
                }
                if ($p['photographer_id'] !== null && (string) $p['photographer'] !== '') {
                    $g['people'][(int) $p['photographer_id']] = (string) $p['photographer'];
                }
            }
            $out[] = $g;
        }
        $pages = $this->db->all('SELECT * FROM pages ' . ($this->preview ? '' : "WHERE status = 'published' ") . 'ORDER BY sort_order, id');
        foreach ($pages as &$pg) {
            $hard = $pg['status'] === 'published';
            $label = 'Seite „' . $pg['title'] . '“';
            $pg['block_list'] = null;
            if (trim((string) $pg['blocks']) !== '') {
                try {
                    $list = Blocks::decode((string) $pg['blocks']);
                } catch (MediaException $e) {
                    $this->issue("$label: " . $e->getMessage(), $hard);
                    $list = [];
                }
                $pg['block_list'] = $list;
                if ($list === []) {
                    $this->issue("$label hat keinen Inhalt.", $hard);
                }
                if (($n = Blocks::missingAlt($list)) > 0) {
                    $this->issue("$label: $n Bild(er) ohne Alt-Text.", $hard);
                }
                foreach (Blocks::photoIds($list) as $pid) {
                    if ($this->blockPhoto($pid) === null) {
                        $this->issue("$label: Foto $pid ist nicht mehr verfügbar (gelöscht oder nicht fertig).", $hard);
                    }
                }
            } elseif (trim((string) $pg['body']) === '') {
                $this->issue("$label hat keinen Text.", $hard);
            }
        }
        unset($pg);
        $homeList = null;
        if (trim($set['home_blocks']) !== '') {
            try {
                $homeList = Blocks::decode($set['home_blocks'], true);
            } catch (MediaException $e) {
                $this->issue('Startseite: ' . $e->getMessage(), true);
                $homeList = [];
            }
            if ($homeList === []) {
                $this->issue('Die Startseite hat keinen Inhalt.', true);
            }
            if (($n = Blocks::missingAlt($homeList)) > 0) {
                $this->issue("Startseite: $n Bild(er) ohne Alt-Text.", true);
            }
            foreach (Blocks::photoIds($homeList) as $pid) {
                if ($this->blockPhoto($pid) === null) {
                    $this->issue("Startseite: Foto $pid ist nicht mehr verfügbar (gelöscht oder nicht fertig).", true);
                }
            }
        }
        // Hero: gewähltes Foto, wenn es in einer angezeigten Galerie liegt, sonst Titelbild der ersten Galerie.
        $hero = null;
        foreach ($out as $g) {
            foreach ($g['photos'] as $p) {
                if ((int) $p['id'] === $set['hero_photo_id']) {
                    $hero = $p;
                }
            }
        }
        $hero ??= $out[0]['cover'] ?? null;
        return $this->data = ['settings' => $set, 'galleries' => $out, 'pages' => $pages, 'hero' => $hero, 'home' => $homeList];
    }

    private function issue(string $msg, bool $hard): void
    {
        if ($hard && !$this->preview) {
            $this->errors[] = $msg;
        } elseif ($this->preview || !$hard) {
            $this->warnings[] = $msg;
        }
    }

    /** @param array<string,mixed> $g @param list<array<string,mixed>> $photos @return array<string,mixed>|null */
    private function coverOf(array $g, array $photos): ?array
    {
        foreach ($photos as $p) {
            if ((int) $p['id'] === (int) $g['cover_photo_id']) {
                return $p;
            }
        }
        return $photos[0] ?? null;
    }

    /** @var array<int,array<string,mixed>|null> */
    private array $blockPhotos = [];

    /** Foto für einen Seitenblock samt Bilddateien; null, wenn gelöscht, nicht fertig oder ohne JPEG. @return array<string,mixed>|null */
    public function blockPhoto(int $id): ?array
    {
        if (array_key_exists($id, $this->blockPhotos)) {
            return $this->blockPhotos[$id];
        }
        $p = $this->db->one(
            "SELECT p.id, p.title, p.caption, p.alt_text, p.width, p.height, p.blur_data_uri FROM photos p WHERE p.id = ? AND p.deleted_at IS NULL AND p.status = 'ready'",
            [$id],
        );
        if ($p !== null) {
            $p['variants'] = [];
            foreach ($this->db->all('SELECT width, height, format, path FROM photo_variants WHERE photo_id = ? ORDER BY width', [$id]) as $v) {
                $p['variants'][(string) $v['format']][(int) $v['width']] = ['path' => (string) $v['path'], 'height' => (int) $v['height']];
            }
            if (!isset($p['variants']['jpeg'])) {
                $p = null;
            }
        }
        return $this->blockPhotos[$id] = $p;
    }

    // --- Bausteine für die Vorlagen ---

    public function name(): string
    {
        return (string) $this->data()['settings']['site_name'];
    }

    public function brand(): string
    {
        $name = $this->name();
        $pair = $this->settings->logoPair();
        if ($pair['light'] !== null) { // im Admin gewählte(s) Logo(s)
            return '<img class="logo logo-light" src="' . self::h((string) $pair['light']) . '" alt="' . self::h($name) . '" height="40"><img class="logo logo-dark" src="' . self::h((string) $pair['dark']) . '" alt="" aria-hidden="true" height="40">';
        }
        $pub = $this->config->publicDir . '/brand';
        if (is_file($pub . '/logo.svg')) {
            $dark = is_file($pub . '/logo-dark.svg') ? '/brand/logo-dark.svg' : '/brand/logo.svg';
            return '<img class="logo logo-light" src="/brand/logo.svg" alt="' . self::h($name) . '" height="28"><img class="logo logo-dark" src="' . $dark . '" alt="" aria-hidden="true" height="28">';
        }
        $pos = mb_strpos($name, '.');
        if ($pos === false) {
            return self::h($name);
        }
        return self::h(mb_substr($name, 0, $pos)) . '<span class="dot">.</span>' . self::h(mb_substr($name, $pos + 1));
    }

    /** Kleines Logo für den Fuß (neben dem Urheberhinweis), leer wenn keines gewählt ist. */
    public function footerLogo(): string
    {
        $pair = $this->settings->logoPair();
        if ($pair['light'] === null) {
            return '';
        }
        return '<img class="logo logo-light flogo" src="' . self::h((string) $pair['light']) . '" alt="' . self::h($this->name()) . '" height="22"><img class="logo logo-dark flogo" src="' . self::h((string) $pair['dark']) . '" alt="" aria-hidden="true" height="22">';
    }

    public function favicon(): string
    {
        $id = $this->settings->logoChoice()['favicon'];
        if ($id !== '' && ($uri = $this->settings->logoUri($id)) !== null) {
            return $uri;
        }
        return is_file($this->config->publicDir . '/brand/favicon.svg') ? '/brand/favicon.svg' : '';
    }

    public static function count(int $n): string
    {
        return $n === 1 ? '1 Foto' : $n . ' Fotos';
    }

    /** @param array<string,mixed> $g */
    public function yearLabel(array $g): string
    {
        $y = $g['years'];
        if ($y === []) {
            return '';
        }
        return count($y) === 1 || $y[0] === end($y) ? (string) $y[0] : $y[0] . '–' . end($y);
    }

    /** Klasse für das Seitenverhältnis, auf 0,01 gerundet und auf 0,5 bis 3,0 begrenzt. */
    public static function ratioClass(array $p): string
    {
        $r = (int) $p['height'] > 0 ? (int) $p['width'] / (int) $p['height'] : 1.5;
        return 'r' . (int) round(max(0.5, min(3.0, $r)) * 100);
    }

    /**
     * @param array<string,mixed> $p
     * @param array{sizes?:string,maxw?:int,loading?:string,class?:string,priority?:bool,alt?:string} $o
     */
    public function picture(array $p, array $o = []): string
    {
        $sizes = $o['sizes'] ?? '100vw';
        $maxw = $o['maxw'] ?? 1600;
        $jpeg = array_filter($p['variants']['jpeg'], static fn (int $w): bool => true, ARRAY_FILTER_USE_KEY);
        $srcFor = static function (array $byW) use ($maxw): array {
            $ok = array_filter($byW, static fn (array $v, int $w): bool => $w <= $maxw, ARRAY_FILTER_USE_BOTH);
            return $ok === [] ? [array_key_first($byW) => $byW[array_key_first($byW)]] : $ok;
        };
        $html = '<picture>';
        foreach (['avif' => 'image/avif', 'webp' => 'image/webp'] as $fmt => $mime) {
            if (!empty($p['variants'][$fmt])) {
                $html .= '<source type="' . $mime . '" srcset="' . self::srcset($srcFor($p['variants'][$fmt])) . '" sizes="' . self::h($sizes) . '">';
            }
        }
        $set = $srcFor($jpeg);
        // Rückfallbild: größte Breite bis 960, sonst die kleinste
        $fallW = array_key_first($set);
        foreach ($set as $w => $_) {
            if ($w <= 960) {
                $fallW = $w;
            }
        }
        $fb = $set[$fallW];
        $alt = $o['alt'] ?? (string) $p['alt_text'];
        $html .= '<img src="/media/' . self::h($fb['path']) . '" srcset="' . self::srcset($set) . '" sizes="' . self::h($sizes) . '" width="' . $fallW . '" height="' . $fb['height'] . '" alt="' . self::h($alt) . '"'
            . ' loading="' . ($o['loading'] ?? 'lazy') . '" decoding="async"' . (!empty($o['priority']) ? ' fetchpriority="high"' : '')
            . (!empty($o['class']) ? ' class="' . self::h($o['class']) . '"' : '') . '></picture>';
        return $html;
    }

    /** @param array<int,array{path:string,height:int}> $byW */
    private static function srcset(array $byW): string
    {
        $parts = [];
        foreach ($byW as $w => $v) {
            $parts[] = '/media/' . self::h($v['path']) . ' ' . $w . 'w';
        }
        return implode(', ', $parts);
    }

    /** Größte JPEG-Datei bis 2400 px: Rückfall ohne Skript. @param array<string,mixed> $p */
    public function fullUrl(array $p): string
    {
        $best = null;
        foreach ($p['variants']['jpeg'] as $w => $v) {
            if ($w <= 2400) {
                $best = $v['path'];
            }
        }
        return '/media/' . ($best ?? $p['variants']['jpeg'][array_key_first($p['variants']['jpeg'])]['path']);
    }

    /** Für die Lightbox: srcset (WebP, sonst JPEG), nur ab 960 px. @param array<string,mixed> $p */
    public function lightboxSet(array $p): string
    {
        $fmt = !empty($p['variants']['webp']) ? 'webp' : 'jpeg';
        $set = array_filter($p['variants'][$fmt], static fn (int $w): bool => $w >= 960, ARRAY_FILTER_USE_KEY);
        return self::srcset($set === [] ? $p['variants'][$fmt] : $set);
    }

    /** Freigegebene EXIF-Angaben (nur bei show_exif). @param array<string,mixed> $g @param array<string,mixed> $p @return array{gear:string,settings:string,taken:string} */
    public function exif(array $g, array $p): array
    {
        $none = ['gear' => '', 'settings' => '', 'taken' => ''];
        if ((int) $g['show_exif'] !== 1) {
            return $none;
        }
        $raw = json_decode((string) $p['exif_json'], true);
        $f = [];
        foreach (self::EXIF_PUBLIC as $k) {
            if (is_array($raw) && isset($raw[$k]) && is_string($raw[$k]) && $raw[$k] !== '') {
                $f[$k] = $raw[$k];
            }
        }
        $taken = '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $p['taken_at'], $m) === 1) {
            $taken = $m[3] . '.' . $m[2] . '.' . $m[1];
        }
        return [
            'gear' => implode(' · ', array_filter([$f['camera'] ?? '', $f['lens'] ?? ''])),
            'settings' => implode(' · ', array_filter([$f['aperture'] ?? '', $f['exposure'] ?? '', isset($f['iso']) ? 'ISO ' . $f['iso'] : '', $f['focal_length'] ?? ''])),
            'taken' => $taken,
        ] + $none;
    }

    /**
     * Fotos mit dem Schalter „Startseite“ (fertig, nicht im Papierkorb), älteste zuerst. Alt-Text: Alt-Text des Fotos, sonst Titel, sonst „Foto“.
     *
     * @return list<array{id:int,alt:string}>
     */
    public function homePhotos(int $limit): array
    {
        $out = [];
        foreach ($this->db->all("SELECT id, alt_text, title FROM photos WHERE on_home = 1 AND deleted_at IS NULL AND status = 'ready' ORDER BY id LIMIT ?", [max(1, min(Blocks::MAX_PHOTOS, $limit))]) as $r) {
            if ($this->blockPhoto((int) $r['id']) === null) {
                continue;
            }
            $alt = trim((string) $r['alt_text']) !== '' ? (string) $r['alt_text'] : (trim((string) $r['title']) !== '' ? (string) $r['title'] : 'Foto');
            $out[] = ['id' => (int) $r['id'], 'alt' => $alt];
        }
        return $out;
    }

    /** Galerienkacheln als Block (Titel, Begrenzung; 0 = alle). */
    public function galleryCards(string $title, int $limit): string
    {
        $list = $this->data()['galleries'];
        if ($limit > 0) {
            $list = array_slice($list, 0, $limit);
        }
        if ($list === []) {
            return '';
        }
        $head = $title !== '' ? '<div class="block-head"><h2 id="gal-h">' . self::h($title) . '</h2><a class="more" href="' . self::h($this->url('/galerien/')) . '">Alle Galerien</a></div>' : '';
        $cards = '';
        foreach ($list as $g) {
            $cards .= $this->card($g);
        }
        return '<section class="bgalleries"' . ($title !== '' ? ' aria-labelledby="gal-h"' : ' aria-label="Galerien"') . '>' . $head . '<ul class="cards">' . $cards . "</ul></section>\n";
    }

    /** @param array<string,mixed> $g Kachel einer Galerie (Start, Übersicht) */
    public function card(array $g): string
    {
        return $this->view->fragment('site/card', ['h' => $this, 'g' => $g]);
    }

    // --- Seiten ---

    /**
     * @param array{title:string,desc?:string,body:string,path:string,robots?:string,image?:?array<string,mixed>} $page
     */
    private function shell(array $page): string
    {
        $d = $this->data();
        $nav = ['main' => [], 'footer' => []];
        foreach ($d['pages'] as $pg) {
            if (isset($nav[$pg['nav']])) {
                $nav[$pg['nav']][] = $pg;
            }
        }
        $site = (string) $d['settings']['site_name'];
        $assets = fn (string $p): string => $this->view->asset($p);
        $og = $page['image'] ?? null;
        return $this->view->fragment('site/layout', [
            'h' => $this, 'page' => $page, 'nav' => $nav, 'site' => $site, 'design' => (string) $d['settings']['design'], 'config' => $this->config, 'asset' => $assets,
            'canonical' => $this->config->baseUrl() !== '' && !$this->preview ? $this->config->baseUrl() . $page['path'] : '',
            'ogImage' => $og !== null && $this->config->baseUrl() !== '' ? $this->config->baseUrl() . '/media/' . ($og['variants']['jpeg'][array_key_last($og['variants']['jpeg'])]['path']) : '',
            'hasGalleries' => $d['galleries'] !== [],
        ]);
    }

    private function body(string $tpl, array $vars): string
    {
        return $this->view->fragment('site/' . $tpl, ['h' => $this] + $vars);
    }

    public function home(): string
    {
        $d = $this->data();
        $name = (string) $d['settings']['site_name'];
        $image = $d['hero'];
        if ($d['home'] !== null) {
            $image = null;
            foreach (Blocks::photoIds($d['home']) as $pid) {
                $image = $this->blockPhoto($pid);
                if ($image !== null) {
                    break;
                }
            }
        }
        return $this->shell([
            'title' => $name, 'desc' => (string) $d['settings']['tagline'], 'path' => '/', 'image' => $image, 'class' => 'home',
            'body' => $d['home'] !== null
                ? $this->body('page_home', ['html' => Blocks::render($d['home'], $this)])
                : $this->body('home', ['d' => $d]),
        ]);
    }

    public function galleries(): string
    {
        $d = $this->data();
        $years = $places = $people = [];
        foreach ($d['galleries'] as $g) {
            foreach ($g['years'] as $y) {
                $years[$y] = (string) $y;
            }
            $places += $g['places'];
            $people += $g['people'];
        }
        krsort($years);
        asort($places);
        asort($people);
        return $this->shell([
            'title' => 'Galerien – ' . $d['settings']['site_name'], 'desc' => 'Alle Galerien', 'path' => '/galerien/', 'class' => 'galleries',
            'body' => $this->body('galleries', ['d' => $d, 'years' => $years, 'places' => $places, 'people' => $people]),
        ]);
    }

    /** @param array<string,mixed> $g */
    public function gallery(array $g): string
    {
        $d = $this->data();
        return $this->shell([
            'title' => $g['title'] . ' – ' . $d['settings']['site_name'], 'desc' => Galleries::clip((string) $g['description'], 160),
            'path' => '/galerien/' . $g['slug'] . '/', 'image' => $g['cover'], 'class' => 'gallery',
            'body' => $this->body('gallery', ['g' => $g]),
        ]);
    }

    /** @param array<string,mixed> $pg */
    public function page(array $pg): string
    {
        $d = $this->data();
        return $this->shell([
            'title' => $pg['title'] . ' – ' . $d['settings']['site_name'], 'desc' => '', 'path' => '/' . $pg['slug'] . '/', 'class' => 'page',
            'body' => $this->body('page', ['pg' => $pg, 'wide' => $pg['block_list'] !== null,
                'html' => $pg['block_list'] !== null ? Blocks::render($pg['block_list'], $this) : Markdown::render((string) $pg['body'])]),
        ]);
    }

    public function notFound(): string
    {
        return $this->shell(['title' => 'Nicht gefunden – ' . $this->name(), 'path' => '/404.html', 'robots' => 'noindex', 'class' => 'page', 'body' => $this->body('notfound', [])]);
    }

    /** Eine Adresse für die Vorschau; null, wenn es sie nicht gibt. */
    public function route(string $path): ?string
    {
        $path = '/' . trim($path, '/');
        $d = $this->data();
        if ($path === '/') {
            return $this->home();
        }
        if ($path === '/galerien') {
            return $this->galleries();
        }
        if (preg_match('#^/galerien/([^/]+)$#', $path, $m) === 1) {
            foreach ($d['galleries'] as $g) {
                if ($g['slug'] === $m[1]) {
                    return $this->gallery($g);
                }
            }
            return null;
        }
        if (preg_match('#^/([^/]+)$#', $path, $m) === 1) {
            foreach ($d['pages'] as $pg) {
                if ($pg['slug'] === $m[1]) {
                    return $this->page($pg);
                }
            }
        }
        return null;
    }

    /**
     * Alle Dateien einer Fassung: Adresse → Inhalt. Adressen auf „/“ werden als <Adresse>index.html abgelegt.
     *
     * @return array<string,string>
     */
    public function all(): array
    {
        $d = $this->data();
        $files = ['/' => $this->home(), '/404.html' => $this->notFound()];
        $this->labels['/'] = 'Startseite';
        $this->labels['/404.html'] = 'Fehlerseite';
        if ($d['galleries'] !== []) {
            $files['/galerien/'] = $this->galleries();
            $this->labels['/galerien/'] = 'Galerienübersicht';
        }
        foreach ($d['galleries'] as $g) {
            $files['/galerien/' . $g['slug'] . '/'] = $this->gallery($g);
            $this->labels['/galerien/' . $g['slug'] . '/'] = 'Galerie „' . $g['title'] . '“';
        }
        foreach ($d['pages'] as $pg) {
            $files['/' . $pg['slug'] . '/'] = $this->page($pg);
            $this->labels['/' . $pg['slug'] . '/'] = 'Seite „' . $pg['title'] . '“';
        }
        $redirects = $this->redirects($files);
        if ($redirects !== []) {
            $files['/_redirects.json'] = (string) json_encode($redirects, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $this->labels['/_redirects.json'] = 'Weiterleitungen alter Adressen (' . count($redirects) . ')';
        }
        $base = $this->config->baseUrl();
        if ($this->config->noindex()) {
            $files['/robots.txt'] = "User-agent: *\nDisallow: /\n";
        } else {
            $files['/robots.txt'] = "User-agent: *\nAllow: /\n" . ($base !== '' ? "Sitemap: $base/sitemap.xml\n" : '');
            if ($base !== '') {
                $urls = '';
                foreach (array_keys($files) as $p) {
                    if (str_ends_with($p, '/')) {
                        $urls .= '<url><loc>' . self::h($base . $p) . "</loc></url>\n";
                    }
                }
                $files['/sitemap.xml'] = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" . $urls . "</urlset>\n";
            }
        }
        return $files;
    }

    /**
     * Weiterleitungen alter Adressen (Umzug von WordPress) auf die neuen Adressen. Nur Ziele, die in dieser Fassung
     * wirklich erscheinen (veröffentlicht); eine Adresse, unter der es jetzt eine Seite gibt, wird nie umgeleitet.
     *
     * @param array<string,string> $files bereits erzeugte Dateien (Adresse => Inhalt)
     * @return array<string,string> alter Pfad (klein, ohne / am Ende) => neuer Pfad
     */
    private function redirects(array $files): array
    {
        $d = $this->data();
        $gal = [];
        foreach ($d['galleries'] as $g) {
            $gal[(int) $g['id']] = '/galerien/' . $g['slug'] . '/';
        }
        $pg = [];
        foreach ($d['pages'] as $p) {
            $pg[(int) $p['id']] = '/' . $p['slug'] . '/';
        }
        $out = [];
        foreach ($this->db->all('SELECT from_path, kind, target_id, to_path FROM redirects ORDER BY from_path') as $r) {
            $to = match ($r['kind']) {
                'gallery' => $gal[(int) $r['target_id']] ?? null,
                'page' => $pg[(int) $r['target_id']] ?? null,
                'home' => '/',
                'galleries' => isset($files['/galerien/']) ? '/galerien/' : null,
                'path' => (string) $r['to_path'],
                default => null,
            };
            $from = strtolower((string) $r['from_path']);
            if ($to === null || $to === '' || $from === '' || $from[0] !== '/' || isset($files[$from . '/']) || isset($files[$from]) || $to === $from . '/' || $to === $from) {
                continue;
            }
            $out[$from] = $to;
        }
        ksort($out);
        return $out;
    }

    /** Kennungen der angezeigten Fotos (für Hinweise beim Löschen). @return list<int> */
    public function photoIds(): array
    {
        $ids = [];
        foreach ($this->data()['galleries'] as $g) {
            foreach ($g['photos'] as $p) {
                $ids[(int) $p['id']] = (int) $p['id'];
            }
        }
        foreach ($this->data()['pages'] as $pg) {
            foreach (Blocks::photoIds($pg['block_list'] ?? []) as $id) {
                $ids[$id] = $id;
            }
        }
        foreach (Blocks::photoIds($this->data()['home'] ?? []) as $id) {
            $ids[$id] = $id;
        }
        return array_values($ids);
    }
}
