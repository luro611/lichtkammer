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
    public const MAX_BLOCKS = 100;
    public const MAX_JSON = 200000;
    public const MAX_PHOTOS = 12;
    /** Blöcke, die in Spalten stehen dürfen. */
    public const SIMPLE = ['heading', 'text', 'image', 'quote', 'button'];
    public const WIDTHS = ['schmal', 'normal', 'voll'];

    /**
     * Prüft Eingabe-JSON und gibt die bereinigten Blöcke zurück. Leere Text-, Überschrift- und Zitatblöcke werden entfernt.
     *
     * @return list<array<string,mixed>>
     * @throws MediaException mit verständlicher Meldung
     */
    public static function parse(string $json, ?Database $db = null): array
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
            $clean = self::norm($b, false, $db, 'Block ' . $n);
            if ($clean !== null) {
                $out[] = $clean;
            }
        }
        return $out;
    }

    /** Gespeicherte Form lesen (ohne Datenbankprüfung). @return list<array<string,mixed>> */
    public static function decode(string $stored): array
    {
        return self::parse($stored, null);
    }

    /** @param list<array<string,mixed>> $blocks */
    public static function encode(array $blocks): string
    {
        return (string) json_encode(['v' => 1, 'blocks' => $blocks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string,mixed>|null null = leerer Block, wird entfernt */
    private static function norm(mixed $b, bool $inColumn, ?Database $db, string $where): ?array
    {
        if (!is_array($b) || !isset($b['t']) || !is_string($b['t'])) {
            throw new MediaException("$where: unbekannter Block.");
        }
        $s = static fn (string $k, int $max): string => isset($b[$k]) && is_string($b[$k]) ? Galleries::clip($b[$k], $max) : '';
        $t = $b['t'];
        if ($inColumn && !in_array($t, self::SIMPLE, true)) {
            throw new MediaException("$where: In Spalten gehen nur Überschrift, Text, Bild, Zitat und Knopf.");
        }
        switch ($t) {
            case 'heading':
                $text = $s('text', 200);
                return $text === '' ? null : ['t' => 'heading', 'level' => ($b['level'] ?? 2) === 3 ? 3 : 2, 'text' => $text];
            case 'text':
                $text = $s('text', 20000);
                return trim($text) === '' ? null : ['t' => 'text', 'text' => $text];
            case 'quote':
                $text = $s('text', 1000);
                return $text === '' ? null : ['t' => 'quote', 'text' => $text, 'by' => $s('by', 200)];
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
                return ['t' => 'button', 'label' => $label, 'href' => $href];
            case 'image':
                $photo = self::photoId($b['photo'] ?? null, $db, $where);
                $href = $s('href', 500);
                if ($href !== '' && !Markdown::safeUrl($href)) {
                    throw new MediaException("$where: Die Adresse des Links ist ungültig.");
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
                        'alt' => isset($p['alt']) && is_string($p['alt']) ? Galleries::clip($p['alt'], 250) : '', 'decor' => ($p['decor'] ?? false) === true];
                }
                $cols = (int) ($b['cols'] ?? 3);
                return ['t' => 'gallery', 'photos' => $photos, 'cols' => in_array($cols, [2, 3, 4], true) ? $cols : 3];
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
            if ($b['t'] === 'image' && $b['alt'] === '' && !$b['decor']) {
                $n++;
            } elseif ($b['t'] === 'gallery') {
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
            if ($b['t'] === 'image') {
                $ids[(int) $b['photo']] = (int) $b['photo'];
            } elseif ($b['t'] === 'gallery') {
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
            $text = trim(implode("\n", $buf));
            if ($text !== '') {
                $out[] = ['t' => 'text', 'text' => $text];
            }
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
                $out[] = ['t' => 'heading', 'level' => strlen($m[1]) === 3 ? 3 : 2, 'text' => $m[2]];
            } elseif (preg_match('/^(-{3,}|\*{3,})$/', trim($t)) === 1) {
                $flush();
                $out[] = ['t' => 'rule'];
            } elseif (preg_match('/^>\s?(.*)$/', $t, $m) === 1) {
                if ($quote === []) {
                    $text = trim(implode("\n", $buf));
                    if ($text !== '') {
                        $out[] = ['t' => 'text', 'text' => $text];
                    }
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
                return "<h$l class=\"bh\">" . $h($b['text']) . "</h$l>\n";
            case 'text':
                return '<div class="prose bt-text">' . Markdown::render($b['text'], false) . "</div>\n";
            case 'quote':
                return '<blockquote class="bquote"><p>' . Markdown::inline($b['text']) . '</p>' . ($b['by'] !== '' ? '<footer>' . $h($b['by']) . '</footer>' : '') . "</blockquote>\n";
            case 'rule':
                return "<hr class=\"brule\">\n";
            case 'button':
                return '<p class="bbtn">' . self::link($b['href'], '<span>' . $h($b['label']) . '</span>', $r, 'knopf') . "</p>\n";
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
                    $items .= '<li class="bti"><a id="foto-' . (int) $p['id'] . '" href="' . $h($r->fullUrl($p)) . '" data-id="' . (int) $p['id'] . '" data-srcset="' . $h($r->lightboxSet($p))
                        . '" data-w="' . (int) $p['width'] . '" data-h="' . (int) $p['height'] . '" data-alt="' . $h($alt) . '" data-title="' . $h((string) $p['title']) . '" data-caption="' . $h((string) $p['caption'])
                        . '" data-meta="" data-photographer="" data-gear="" data-settings="" data-taken="">'
                        . $r->picture($p, ['sizes' => '(min-width:900px) ' . (int) (1000 / (int) $b['cols']) . 'px, 50vw', 'maxw' => 960, 'alt' => $alt]) . "</a></li>\n";
                }
                return '<ul class="bgrid c' . (int) $b['cols'] . '" data-lightbox aria-label="Fotos">' . "\n" . $items . "</ul>\n";
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

    private static function link(string $href, string $inner, Renderer $r, string $class): string
    {
        $internal = str_starts_with($href, '/');
        $url = $internal ? $r->url($href) : $href;
        $external = preg_match('#^https?://#i', $href) === 1;
        return '<a' . ($class !== '' ? ' class="' . $class . '"' : '') . ' href="' . Renderer::h($url) . '"' . ($external ? ' rel="noopener noreferrer"' : '') . '>' . $inner . '</a>';
    }
}
