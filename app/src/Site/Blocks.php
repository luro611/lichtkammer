<?php
declare(strict_types=1);

namespace Lichtkammer\Site;

use Lichtkammer\Auth\Database;
use Lichtkammer\Media\Galleries;
use Lichtkammer\Media\MediaException;

/**
 * Seiten aus Blöcken (Entwurf 12). Einzige Stelle, die Block-JSON annimmt: Positivliste der Typen, Längen- und Anzahlgrenzen,
 * Fotos nur als Id, Adressen nur aus der erlaubten Liste. Die Ausgabe geht nur über feste Vorlagen in render().
 * Gespeichert wird immer die geprüfte, neu aufgebaute Form (nie das Eingegebene).
 */
final class Blocks
{
    /** @var array<int,true> Fotos mit schon vergebener Anker-Id (je Seite nur einmal). */
    private static array $seen = [];
    public const MAX_BLOCKS = 100;
    public const MAX_JSON = 200000;
    public const MAX_PHOTOS = 12;
    /** Blöcke, die in Spalten stehen dürfen. */
    public const SIMPLE = ['heading', 'text', 'image', 'quote', 'button'];
    public const WIDTHS = ['schmal', 'normal', 'voll'];
    /** Stile je Blocktyp (Entwurf 14): erster Eintrag ist die Vorgabe, Unbekanntes wird zur Vorgabe. */
    public const STYLES = [
        'heading' => ['normal', 'mitte'],
        'text' => ['normal', 'gross', 'mitte', 'flaeche'],
        'quote' => ['normal', 'gross', 'flaeche'],
        'button' => ['normal', 'mitte'],
        'imagetext' => ['normal', 'flaeche'],
    ];
    public const RATIOS = ['3-2', '16-9', '21-9', '2-1', '3-1'];
    /** Typen, die nur auf der Startseite erlaubt sind. */
    public const HOME_ONLY = ['hero'];
    public const HERO_HEIGHTS = ['normal', 'niedrig', 'hoch'];
    public const SPAN = ['box', 'voll'];

    /**
     * Prüft Eingabe-JSON und gibt die bereinigten Blöcke zurück. Leere Text-, Überschrift- und Zitatblöcke werden entfernt.
     *
     * @return list<array<string,mixed>>
     * @throws MediaException mit verständlicher Meldung
     */
    public static function parse(string $json, ?Database $db = null, bool $home = false): array
    {
        if (strlen($json) > self::MAX_JSON) {
            throw new MediaException('Die Seite ist zu groß (höchstens 200 KB Inhalt).');
        }
        $data = json_decode($json, true, 16);
        if (!is_array($data) || !isset($data['blocks']) || !is_array($data['blocks']) || !array_is_list($data['blocks'])) {
            throw new MediaException('Die Blöcke konnten nicht gelesen werden. Bitte die Seite neu laden.');
        }
        if (count($data['blocks']) > self::MAX_BLOCKS) {
            throw new MediaException('Zu viele Blöcke (höchstens ' . self::MAX_BLOCKS . ').');
        }
        $out = [];
        $n = 0;
        foreach ($data['blocks'] as $b) {
            $n++;
            $clean = self::norm($b, false, $db, 'Block ' . $n, $home);
            if ($clean !== null) {
                $out[] = $clean;
            }
        }
        return $out;
    }

    /** Gespeicherte Form lesen (ohne Datenbankprüfung). @return list<array<string,mixed>> */
    public static function decode(string $stored, bool $home = false): array
    {
        return self::parse($stored, null, $home);
    }

    /** @param list<array<string,mixed>> $blocks */
    public static function encode(array $blocks): string
    {
        return (string) json_encode(['v' => 1, 'blocks' => $blocks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string,mixed>|null null = leerer Block, wird entfernt */
    private static function norm(mixed $b, bool $inColumn, ?Database $db, string $where, bool $home = false): ?array
    {
        if (!is_array($b) || !isset($b['t']) || !is_string($b['t'])) {
            throw new MediaException("$where: unbekannter Block.");
        }
        $s = static fn (string $k, int $max): string => isset($b[$k]) && is_string($b[$k]) ? Galleries::clip($b[$k], $max) : '';
        $t = $b['t'];
        $style = static function (string $type) use ($b): string {
            $list = self::STYLES[$type];
            return isset($b['style']) && is_string($b['style']) && in_array($b['style'], $list, true) ? $b['style'] : $list[0];
        };
        if (in_array($t, self::HOME_ONLY, true) && !$home) {
            throw new MediaException("$where: Dieser Block geht nur auf der Startseite.");
        }
        if ($inColumn && !in_array($t, self::SIMPLE, true)) {
            throw new MediaException("$where: In Spalten gehen nur Überschrift, Text, Bild, Zitat und Knopf.");
        }
        switch ($t) {
            case 'heading':
                $text = $s('text', 200);
                return $text === '' ? null : ['t' => 'heading', 'level' => ($b['level'] ?? 2) === 3 ? 3 : 2, 'text' => $text, 'style' => $style('heading')];
            case 'text':
                $text = $s('text', 20000);
                return trim($text) === '' ? null : ['t' => 'text', 'text' => $text, 'style' => $style('text')];
            case 'quote':
                $text = $s('text', 1000);
                return $text === '' ? null : ['t' => 'quote', 'text' => $text, 'by' => $s('by', 200), 'style' => $style('quote')];
            case 'rule':
                return ['t' => 'rule'];
            case 'button':
                $label = $s('label', 80);
                $href = $s('href', 500);
                if ($label === '' && $href === '') {
                    return null;
                }
                if ($label === '' || !Markdown::safeUrl($href)) {
                    throw new MediaException("$where: Der Knopf braucht eine Beschriftung und eine gültige Adresse (/seite/, https://… oder mailto:…).");
                }
                return ['t' => 'button', 'label' => $label, 'href' => $href, 'style' => $style('button')];
            case 'image':
                $photo = self::photoId($b['photo'] ?? null, $db, $where);
                $href = $s('href', 500);
                if ($href !== '' && !Markdown::safeUrl($href)) {
                    throw new MediaException("$where: Die Adresse des Links ist ungültig.");
                }
                if (($b['decor'] ?? false) === true && $href !== '') {
                    throw new MediaException("$where: Ein Bild mit Link braucht einen Alt-Text (der Link sonst keinen Namen hat). „Nur Dekoration“ geht nur ohne Link.");
                }
                $w = $b['width'] ?? 'normal';
                return ['t' => 'image', 'photo' => $photo, 'alt' => $s('alt', 250), 'decor' => ($b['decor'] ?? false) === true,
                    'caption' => $s('caption', 300), 'width' => in_array($w, self::WIDTHS, true) ? $w : 'normal', 'href' => $href];
            case 'gallery':
                $list = $b['photos'] ?? null;
                if (!is_array($list) || !array_is_list($list) || $list === []) {
                    throw new MediaException("$where: Das Raster braucht mindestens ein Foto.");
                }
                if (count($list) > self::MAX_PHOTOS) {
                    throw new MediaException("$where: Ein Raster hat höchstens " . self::MAX_PHOTOS . ' Fotos.');
                }
                $photos = [];
                foreach ($list as $p) {
                    if (!is_array($p)) {
                        throw new MediaException("$where: ungültiges Foto im Raster.");
                    }
                    $photos[] = ['photo' => self::photoId($p['photo'] ?? null, $db, $where),
                        'alt' => isset($p['alt']) && is_string($p['alt']) ? Galleries::clip($p['alt'], 250) : '', 'decor' => false]; // jedes Rasterbild ist ein Link in die Lightbox und braucht einen Namen
                }
                $cols = (int) ($b['cols'] ?? 3);
                return ['t' => 'gallery', 'photos' => $photos, 'cols' => in_array($cols, [2, 3, 4], true) ? $cols : 3];
            case 'imagetext':
                $text = $s('text', 20000);
                $photo = self::photoId($b['photo'] ?? null, $db, $where);
                return ['t' => 'imagetext', 'photo' => $photo, 'alt' => $s('alt', 250), 'decor' => ($b['decor'] ?? false) === true, 'caption' => $s('caption', 300),
                    'text' => $text, 'side' => ($b['side'] ?? 'links') === 'rechts' ? 'rechts' : 'links', 'style' => $style('imagetext')];
            case 'hero':
                $hp = $b['photo'] ?? 0;
                $title = $s('title', 200);
                $hasPhoto = !($hp === 0 || $hp === '' || $hp === '0' || $hp === null);
                if (!$hasPhoto && $title === '') {
                    return null;
                }
                return ['t' => 'hero', 'photo' => $hasPhoto ? self::photoId($hp, $db, $where) : 0, 'alt' => $s('alt', 250), 'decor' => ($b['decor'] ?? true) !== false, 'title' => $title,
                    'span' => self::pick($b['span'] ?? null, self::SPAN), 'height' => self::pick($b['height'] ?? null, self::HERO_HEIGHTS),
                    'fx' => self::focus($b['fx'] ?? 50), 'fy' => self::focus($b['fy'] ?? 50)];
            case 'carousel':
                $list = $b['photos'] ?? null;
                if (!is_array($list) || !array_is_list($list) || $list === []) {
                    throw new MediaException("$where: Das Karussell braucht mindestens ein Foto.");
                }
                if (count($list) > self::MAX_PHOTOS) {
                    throw new MediaException("$where: Ein Karussell hat höchstens " . self::MAX_PHOTOS . ' Fotos.');
                }
                $photos = [];
                foreach ($list as $p) {
                    if (!is_array($p)) {
                        throw new MediaException("$where: ungültiges Foto im Karussell.");
                    }
                    $photos[] = ['photo' => self::photoId($p['photo'] ?? null, $db, $where),
                        'alt' => isset($p['alt']) && is_string($p['alt']) ? Galleries::clip($p['alt'], 250) : '', 'decor' => ($p['decor'] ?? false) === true,
                        'fx' => self::focus($p['fx'] ?? 50), 'fy' => self::focus($p['fy'] ?? 50)];
                }
                $iv = (int) ($b['interval'] ?? 6);
                $ratio = $b['ratio'] ?? '3-2';
                return ['t' => 'carousel', 'photos' => $photos, 'auto' => ($b['auto'] ?? false) === true, 'interval' => max(3, min(15, $iv)),
                    'ratio' => in_array($ratio, self::RATIOS, true) ? $ratio : '3-2', 'span' => self::pick($b['span'] ?? null, self::SPAN)];
            case 'homephotos':
                $mode = self::pick($b['mode'] ?? null, ['karussell', 'raster', 'titelbild']);
                $iv = (int) ($b['interval'] ?? 6);
                $ratio = $b['ratio'] ?? '3-2';
                return ['t' => 'homephotos', 'mode' => $mode, 'span' => self::pick($b['span'] ?? null, self::SPAN), 'ratio' => in_array($ratio, self::RATIOS, true) ? $ratio : '3-2',
                    'auto' => ($b['auto'] ?? false) === true, 'interval' => max(3, min(15, $iv)), 'limit' => max(1, min(self::MAX_PHOTOS, (int) ($b['limit'] ?? 8))),
                    'height' => self::pick($b['height'] ?? null, self::HERO_HEIGHTS)];
            case 'galleries':
                return ['t' => 'galleries', 'title' => $s('title', 100), 'limit' => max(0, min(24, (int) ($b['limit'] ?? 0)))];
            case 'columns':
                $sides = [];
                foreach (['left', 'right'] as $side) {
                    $items = $b[$side] ?? [];
                    if (!is_array($items) || !array_is_list($items) || count($items) > 20) {
                        throw new MediaException("$where: ungültige Spalte.");
                    }
                    $sides[$side] = [];
                    foreach ($items as $i => $it) {
                        $c = self::norm($it, true, $db, $where . ($side === 'left' ? ' (links' : ' (rechts') . ', Eintrag ' . ($i + 1) . ')');
                        if ($c !== null) {
                            $sides[$side][] = $c;
                        }
                    }
                }
                return $sides['left'] === [] && $sides['right'] === [] ? null : ['t' => 'columns'] + $sides;
        }
        throw new MediaException("$where: unbekannter Blocktyp.");
    }

    /** Wert aus der Liste, sonst der erste Eintrag (Vorgabe). @param list<string> $list */
    private static function pick(mixed $v, array $list): string
    {
        return is_string($v) && in_array($v, $list, true) ? $v : $list[0];
    }

    /** Bildausschnitt (Schwerpunkt) in Zehnerschritten 0 bis 100, Vorgabe 50. */
    private static function focus(mixed $v): int
    {
        return is_numeric($v) ? (int) (round(max(0, min(100, (float) $v)) / 10) * 10) : 50;
    }

    private static function photoId(mixed $v, ?Database $db, string $where): int
    {
        $id = is_int($v) ? $v : (is_string($v) && ctype_digit($v) && strlen($v) < 10 ? (int) $v : 0);
        if ($id <= 0) {
            throw new MediaException("$where: Bitte ein Foto auswählen.");
        }
        if ($db !== null && $db->value('SELECT 1 FROM photos WHERE id = ? AND deleted_at IS NULL', [$id]) === null) {
            throw new MediaException("$where: Das Foto gibt es nicht (mehr).");
        }
        return $id;
    }

    /** Anzahl Bilder ohne Alt-Text (ohne ausdrückliche „Nur Dekoration“). @param list<array<string,mixed>> $blocks */
    public static function missingAlt(array $blocks): int
    {
        $n = 0;
        foreach ($blocks as $b) {
            if (in_array($b['t'], ['image', 'imagetext'], true) && $b['alt'] === '' && !$b['decor']) {
                $n++;
            } elseif ($b['t'] === 'hero') {
                $n += $b['photo'] > 0 && $b['alt'] === '' && !$b['decor'] ? 1 : 0;
            } elseif ($b['t'] === 'gallery' || $b['t'] === 'carousel') {
                foreach ($b['photos'] as $p) {
                    $n += $p['alt'] === '' && !$p['decor'] ? 1 : 0;
                }
            } elseif ($b['t'] === 'columns') {
                $n += self::missingAlt($b['left']) + self::missingAlt($b['right']);
            }
        }
        return $n;
    }

    /** @param list<array<string,mixed>> $blocks @return list<int> */
    public static function photoIds(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $b) {
            if (in_array($b['t'], ['image', 'imagetext', 'hero'], true)) {
                if ((int) $b['photo'] > 0) {
                    $ids[(int) $b['photo']] = (int) $b['photo'];
                }
            } elseif ($b['t'] === 'gallery' || $b['t'] === 'carousel') {
                foreach ($b['photos'] as $p) {
                    $ids[(int) $p['photo']] = (int) $p['photo'];
                }
            } elseif ($b['t'] === 'columns') {
                foreach (array_merge(self::photoIds($b['left']), self::photoIds($b['right'])) as $id) {
                    $ids[$id] = $id;
                }
            }
        }
        return array_values($ids);
    }

    // --- Umwandlung von und nach Markdown ---

    /** Einmalige Übernahme alter Seiten: Überschriften, Zitate, Trennlinien erkannt, alles andere bleibt Text. @return list<array<string,mixed>> */
    public static function fromMarkdown(string $body): array
    {
        $out = [];
        $buf = [];
        $quote = [];
        $flush = static function () use (&$buf, &$quote, &$out): void {
            self::pushText($out, trim(implode("\n", $buf)));
            $buf = [];
            if ($quote !== []) {
                $out[] = ['t' => 'quote', 'text' => trim(implode("\n", $quote)), 'by' => ''];
                $quote = [];
            }
        };
        foreach (preg_split('/\R/', str_replace("\0", '', $body)) ?: [] as $line) {
            $t = rtrim($line);
            if (preg_match('/^(#{1,3})\s+(.+)$/', $t, $m) === 1) {
                $flush();
                if (mb_strlen($m[2]) > 200) { // zu lang für eine Überschrift: als Text übernehmen, nichts abschneiden
                    self::pushText($out, $t);
                } else {
                    $out[] = ['t' => 'heading', 'level' => strlen($m[1]) === 3 ? 3 : 2, 'text' => $m[2]];
                }
            } elseif (preg_match('/^(-{3,}|\*{3,})$/', trim($t)) === 1) {
                $flush();
                $out[] = ['t' => 'rule'];
            } elseif (preg_match('/^>\s?(.*)$/', $t, $m) === 1) {
                if ($quote === []) {
                    self::pushText($out, trim(implode("\n", $buf)));
                    $buf = [];
                }
                $quote[] = $m[1];
            } else {
                if ($quote !== []) {
                    $flush();
                }
                $buf[] = $t;
            }
        }
        $flush();
        return $out;
    }

    /** Text als Block(s) anhängen; über 20.000 Zeichen an Leerzeilen (zur Not hart) geteilt, damit nichts abgeschnitten wird. @param list<array<string,mixed>> $out */
    private static function pushText(array &$out, string $text): void
    {
        if ($text === '') {
            return;
        }
        $cur = '';
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $para) {
            while (mb_strlen($para) > 20000) {
                if ($cur !== '') {
                    $out[] = ['t' => 'text', 'text' => $cur];
                    $cur = '';
                }
                $out[] = ['t' => 'text', 'text' => mb_substr($para, 0, 20000)];
                $para = mb_substr($para, 20000);
            }
            if ($cur !== '' && mb_strlen($cur) + mb_strlen($para) + 2 > 20000) {
                $out[] = ['t' => 'text', 'text' => $cur];
                $cur = '';
            }
            $cur .= ($cur === '' ? '' : "\n\n") . $para;
        }
        if (trim($cur) !== '') {
            $out[] = ['t' => 'text', 'text' => $cur];
        }
    }

    /** Rückweg nach Markdown; null, wenn die Seite Bilder, Knöpfe oder Spalten enthält. @param list<array<string,mixed>> $blocks */
    public static function toMarkdown(array $blocks): ?string
    {
        $parts = [];
        foreach ($blocks as $b) {
            switch ($b['t']) {
                case 'heading':
                    $parts[] = str_repeat('#', (int) $b['level']) . ' ' . $b['text'];
                    break;
                case 'text':
                    $parts[] = $b['text'];
                    break;
                case 'quote':
                    $parts[] = implode("\n", array_map(static fn (string $l): string => '> ' . $l, explode("\n", $b['text'])));
                    break;
                case 'rule':
                    $parts[] = '---';
                    break;
                default:
                    return null;
            }
        }
        return implode("\n\n", $parts);
    }

    // --- Ausgabe ---

    /** @param list<array<string,mixed>> $blocks */
    public static function render(array $blocks, Renderer $r): string
    {
        self::$seen = [];
        $html = '';
        foreach ($blocks as $b) {
            $html .= self::one($b, $r, false);
        }
        return $html;
    }

    /** @param array<string,mixed> $b */
    private static function one(array $b, Renderer $r, bool $inColumn): string
    {
        $h = static fn (?string $s): string => Renderer::h($s);
        switch ($b['t']) {
            case 'heading':
                $l = (int) $b['level'] === 3 ? 3 : 2;
                return "<h$l class=\"bh" . self::cls($b) . "\">" . Markdown::inline($b['text']) . "</h$l>\n";
            case 'text':
                return '<div class="prose bt-text' . self::cls($b) . '">' . Markdown::render($b['text'], false) . "</div>\n";
            case 'quote':
                return '<blockquote class="bquote' . self::cls($b) . '"><p>' . Markdown::inline($b['text']) . '</p>' . ($b['by'] !== '' ? '<footer>' . $h($b['by']) . '</footer>' : '') . "</blockquote>\n";
            case 'rule':
                return "<hr class=\"brule\">\n";
            case 'button':
                return '<p class="bbtn' . self::cls($b) . '">' . self::link($b['href'], '<span>' . $h($b['label']) . '</span>', $r, 'knopf') . "</p>\n";
            case 'image':
                $p = $r->blockPhoto((int) $b['photo']);
                if ($p === null) {
                    return '';
                }
                $sizes = ['schmal' => '(min-width:700px) 420px, 100vw', 'normal' => '(min-width:800px) 720px, 100vw', 'voll' => '100vw'][$b['width']];
                $pic = $r->picture($p, ['sizes' => $inColumn ? '(min-width:800px) 50vw, 100vw' : $sizes, 'maxw' => $b['width'] === 'voll' ? 2400 : 1600, 'alt' => $b['decor'] ? '' : $b['alt']]);
                $fig = $b['href'] !== '' ? self::link($b['href'], $pic, $r, '') : $pic;
                return '<figure class="bimg w-' . ($inColumn ? 'normal' : $h($b['width'])) . '">' . $fig
                    . ($b['caption'] !== '' ? '<figcaption>' . $h($b['caption']) . '</figcaption>' : '') . "</figure>\n";
            case 'gallery':
                $items = '';
                foreach ($b['photos'] as $e) {
                    $p = $r->blockPhoto((int) $e['photo']);
                    if ($p === null) {
                        continue;
                    }
                    $alt = $e['decor'] ? '' : $e['alt'];
                    $anchor = isset(self::$seen[(int) $p['id']]) ? '' : ' id="foto-' . (int) $p['id'] . '"';
                    self::$seen[(int) $p['id']] = true;
                    $items .= '<li class="bti"><a' . $anchor . ' href=""' . $h($r->fullUrl($p)) . '" data-id="' . (int) $p['id'] . '" data-srcset="' . $h($r->lightboxSet($p))
                        . '" data-w="' . (int) $p['width'] . '" data-h="' . (int) $p['height'] . '" data-alt="' . $h($alt) . '" data-title="' . $h((string) $p['title']) . '" data-caption="' . $h((string) $p['caption'])
                        . '" data-meta="" data-photographer="" data-gear="" data-settings="" data-taken="">'
                        . $r->picture($p, ['sizes' => '(min-width:900px) ' . (int) (1000 / (int) $b['cols']) . 'px, 50vw', 'maxw' => 960, 'alt' => $alt]) . "</a></li>\n";
                }
                return '<ul class="bgrid c' . (int) $b['cols'] . '" data-lightbox aria-label="Fotos">' . "\n" . $items . "</ul>\n";
            case 'imagetext':
                $p = $r->blockPhoto((int) $b['photo']);
                if ($p === null) {
                    return '';
                }
                $pic = $r->picture($p, ['sizes' => '(min-width:800px) 480px, 100vw', 'maxw' => 1600, 'alt' => $b['decor'] ? '' : $b['alt']]);
                $fig = '<figure class="bit-img">' . $pic . ($b['caption'] !== '' ? '<figcaption>' . $h($b['caption']) . '</figcaption>' : '') . '</figure>';
                $txt = '<div class="bit-text prose">' . Markdown::render($b['text'], false) . '</div>';
                return '<div class="bit bit-' . $h($b['side']) . self::cls($b) . '">' . $fig . $txt . "</div>\n";
            case 'hero':
                $p = $b['photo'] > 0 ? $r->blockPhoto((int) $b['photo']) : null;
                $title = $b['title'] !== '' ? '<h1>' . $h($b['title']) . '</h1>' : '';
                if ($p === null) {
                    return '<section class="hero nohero"><div class="hero-text">' . $title . "</div></section>\n";
                }
                $hc = 'hero hh-' . $h($b['height'] ?? 'normal') . (($b['span'] ?? 'box') === 'voll' ? ' w-voll' : '');
                $blur = (string) ($p['blur_data_uri'] ?? '') !== '' ? '<img class="blur" src="' . $h((string) $p['blur_data_uri']) . '" alt="" aria-hidden="true">' : '';
                return '<section class="' . $hc . '"><div class="hero-img">' . $blur
                    . $r->picture($p, ['sizes' => ($b['span'] ?? 'box') === 'voll' ? '100vw' : '(min-width:1200px) 1160px, 100vw', 'maxw' => 2400, 'loading' => 'eager', 'priority' => true, 'alt' => $b['decor'] ? '' : $b['alt'], 'class' => 'hero-pic ' . self::fcls($b)])
                    . '</div><div class="hero-text">' . $title . "</div></section>\n";
            case 'carousel':
                $slides = '';
                $n = 0;
                $total = count($b['photos']);
                foreach ($b['photos'] as $e) {
                    $p = $r->blockPhoto((int) $e['photo']);
                    if ($p === null) {
                        continue;
                    }
                    $n++;
                    $slides .= '<figure class="bcar-slide" role="group" aria-roledescription="Folie" aria-label="Folie ' . $n . ' von %TOTAL%">'
                        . $r->picture($p, ['sizes' => ($b['span'] ?? 'box') === 'voll' ? '100vw' : '(min-width:1200px) 1160px, 100vw', 'maxw' => ($b['span'] ?? 'box') === 'voll' ? 2400 : 2000, 'alt' => $e['decor'] ? '' : $e['alt'], 'loading' => $n === 1 ? 'eager' : 'lazy', 'class' => self::fcls($e)]) . "</figure>\n";
                }
                if ($n === 0) {
                    return '';
                }
                $slides = str_replace('%TOTAL%', (string) $n, $slides);
                return '<section class="bcar r-' . $h($b['ratio']) . (($b['span'] ?? 'box') === 'voll' ? ' w-voll' : '') . '" data-carousel' . ($b['auto'] ? ' data-auto="' . (int) $b['interval'] . '"' : '')
                    . ' role="group" aria-roledescription="Karussell" aria-label="Bildergalerie mit ' . $n . ' Fotos">'
                    . '<div class="bcar-track" tabindex="0" aria-label="Fotos, mit den Pfeiltasten blättern">' . "\n" . $slides . "</div></section>\n";
            case 'homephotos':
                $list = $r->homePhotos((int) $b['limit']);
                if ($list === []) {
                    return '';
                }
                $ph = array_map(static fn (array $x): array => ['photo' => $x['id'], 'alt' => $x['alt'], 'decor' => false, 'fx' => 50, 'fy' => 50], $list);
                return match ($b['mode']) {
                    'raster' => self::one(['t' => 'gallery', 'photos' => $ph, 'cols' => 3], $r, false),
                    'titelbild' => self::one(['t' => 'hero', 'photo' => $ph[0]['photo'], 'alt' => $ph[0]['alt'], 'decor' => false, 'title' => '', 'span' => $b['span'], 'height' => $b['height'], 'fx' => 50, 'fy' => 50], $r, false),
                    default => self::one(['t' => 'carousel', 'photos' => $ph, 'auto' => $b['auto'], 'interval' => $b['interval'], 'ratio' => $b['ratio'], 'span' => $b['span']], $r, false),
                };
            case 'galleries':
                return $r->galleryCards((string) $b['title'], (int) $b['limit']);
            case 'columns':
                $side = static function (array $list) use ($r): string {
                    $o = '';
                    foreach ($list as $x) {
                        $o .= self::one($x, $r, true);
                    }
                    return $o;
                };
                return '<div class="bcols"><div>' . "\n" . $side($b['left']) . '</div><div>' . "\n" . $side($b['right']) . "</div></div>\n";
        }
        return '';
    }

    /** Klassen für den Bildausschnitt (px0 bis px100, py0 bis py100). @param array<string,mixed> $o */
    private static function fcls(array $o): string
    {
        return 'px' . self::focus($o['fx'] ?? 50) . ' py' . self::focus($o['fy'] ?? 50);
    }

    /** CSS-Klasse für den Stil (nur bei Abweichung von „normal“); nur Werte aus der Positivliste. @param array<string,mixed> $b */
    private static function cls(array $b): string
    {
        $st = (string) ($b['style'] ?? 'normal');
        return $st !== 'normal' && in_array($st, self::STYLES[$b['t']] ?? [], true) ? ' s-' . $st : '';
    }

    private static function link(string $href, string $inner, Renderer $r, string $class): string
    {
        $internal = str_starts_with($href, '/');
        $url = $internal ? $r->url($href) : $href;
        $external = preg_match('#^https?://#i', $href) === 1;
        return '<a' . ($class !== '' ? ' class="' . $class . '"' : '') . ' href="' . Renderer::h($url) . '"' . ($external ? ' rel="noopener noreferrer"' : '') . '>' . $inner . '</a>';
    }
}
