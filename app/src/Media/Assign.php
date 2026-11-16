<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

use Lichtkammer\Auth\Database;

/**
 * Sammelzuordnung nach Galerie: Fotograf aus Regeln, Alt-Text aus dem Galerietitel.
 * Regeln, eine je Zeile: „Fotograf: Stichwort, Stichwort“; die erste passende Zeile gilt (Groß-/Kleinschreibung egal, Teilwort im Galerietitel).
 * Eine Zeile „Rest: Fotograf“ gilt für alles, was keine Zeile trifft (auch Fotos ohne Galerie).
 * Ein Foto in mehreren Galerien richtet sich nach der ersten Galerie in der Reihenfolge der Galerien.
 */
final class Assign
{
    public function __construct(private readonly Database $db, private readonly Catalog $catalog)
    {
    }

    /**
     * @return array{rules:list<array{name:string,words:list<string>}>,rest:?string,errors:list<string>}
     */
    public static function parseRules(string $text): array
    {
        $rules = [];
        $rest = null;
        $errors = [];
        foreach (preg_split('/\R/', $text) ?: [] as $n => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!str_contains($line, ':')) {
                $errors[] = 'Zeile ' . ($n + 1) . ': Es fehlt der Doppelpunkt („Fotograf: Stichwort, Stichwort“).';
                continue;
            }
            [$name, $words] = array_map('trim', explode(':', $line, 2));
            if ($name === '') {
                $errors[] = 'Zeile ' . ($n + 1) . ': Es fehlt der Name des Fotografen.';
            } elseif (mb_strtolower($name) === 'rest') {
                $rest = trim($words);
                if ($rest === '') {
                    $errors[] = 'Zeile ' . ($n + 1) . ': „Rest:“ braucht einen Fotografen.';
                    $rest = null;
                }
            } else {
                $w = array_values(array_filter(array_map('trim', explode(',', $words)), static fn (string $x): bool => $x !== ''));
                if ($w === []) {
                    $errors[] = 'Zeile ' . ($n + 1) . ': Es fehlen Stichwörter.';
                } else {
                    $rules[] = ['name' => $name, 'words' => $w];
                }
            }
        }
        return ['rules' => $rules, 'rest' => $rest, 'errors' => $errors];
    }

    /**
     * Was würde geschehen? Ändert nichts.
     *
     * @return array{rows:list<array{gallery:string,photographer:?string,photos:int,isNew:bool}>,restPhotos:int,errors:list<string>,newNames:list<string>}
     */
    public function plan(string $rulesText): array
    {
        $p = self::parseRules($rulesText);
        $have = [];
        foreach ($this->catalog->photographers() as $ph) {
            $have[mb_strtolower((string) $ph['name'])] = true;
        }
        $rows = [];
        $new = [];
        foreach ($this->db->all('SELECT g.id, g.title, (SELECT COUNT(*) FROM gallery_photos gp JOIN photos p ON p.id = gp.photo_id WHERE gp.gallery_id = g.id AND p.deleted_at IS NULL) AS n FROM galleries g ORDER BY g.sort_order, g.id') as $g) {
            $name = $this->match($p, (string) $g['title']);
            $isNew = $name !== null && !isset($have[mb_strtolower($name)]);
            if ($isNew) {
                $new[mb_strtolower($name)] = $name;
            }
            $rows[] = ['gallery' => (string) $g['title'], 'photographer' => $name, 'photos' => (int) $g['n'], 'isNew' => $isNew];
        }
        $loose = (int) $this->db->value('SELECT COUNT(*) FROM photos p WHERE p.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM gallery_photos gp WHERE gp.photo_id = p.id)');
        if ($p['rest'] !== null && $loose > 0 && !isset($have[mb_strtolower($p['rest'])])) {
            $new[mb_strtolower($p['rest'])] = $p['rest'];
        }
        return ['rows' => $rows, 'restPhotos' => $loose, 'errors' => $p['errors'], 'newNames' => array_values($new)];
    }

    /** @param array{rules:list<array{name:string,words:list<string>}>,rest:?string,errors:list<string>} $p */
    private function match(array $p, string $title): ?string
    {
        $t = mb_strtolower($title);
        foreach ($p['rules'] as $r) {
            foreach ($r['words'] as $w) {
                if (str_contains($t, mb_strtolower($w))) {
                    return $r['name'];
                }
            }
        }
        return $p['rest'];
    }

    /**
     * Führt aus (alles oder nichts). Fehlende Fotografen werden angelegt.
     *
     * @return array{photographers:int,alts:int,created:int}
     * @throws MediaException bei Fehlern in den Regeln
     */
    public function apply(string $rulesText, bool $setPhotographer, bool $setAlt, bool $overwriteAlt): array
    {
        $p = self::parseRules($rulesText);
        if ($p['errors'] !== []) {
            throw new MediaException(implode(' ', $p['errors']));
        }
        if ($p['rules'] === [] && $p['rest'] === null && $setPhotographer) {
            throw new MediaException('Bitte mindestens eine Regel eintragen.');
        }
        return $this->db->tx(function () use ($p, $setPhotographer, $setAlt, $overwriteAlt): array {
            $ids = [];
            $created = 0;
            foreach ($this->catalog->photographers() as $ph) {
                $ids[mb_strtolower((string) $ph['name'])] = (int) $ph['id'];
            }
            $photographer = function (?string $name) use (&$ids, &$created): ?int {
                if ($name === null) {
                    return null;
                }
                $k = mb_strtolower($name);
                if (!isset($ids[$k])) {
                    $ids[$k] = $this->catalog->addPhotographer($name);
                    $created++;
                }
                return $ids[$k];
            };
            $done = [];
            $np = 0;
            $na = 0;
            foreach ($this->db->all('SELECT id, title FROM galleries ORDER BY sort_order, id') as $g) {
                $name = $this->match($p, (string) $g['title']);
                $pid = $setPhotographer ? $photographer($name) : null;
                $altText = Galleries::clip('Foto aus der Galerie „' . $g['title'] . '“', 500);
                foreach ($this->db->all('SELECT p.id, p.alt_text FROM gallery_photos gp JOIN photos p ON p.id = gp.photo_id WHERE gp.gallery_id = ? AND p.deleted_at IS NULL ORDER BY gp.position, p.id', [$g['id']]) as $ph) {
                    $id = (int) $ph['id'];
                    if (isset($done[$id])) {
                        continue; // schon über die erste Galerie erledigt
                    }
                    $done[$id] = true;
                    $f = [];
                    if ($pid !== null) {
                        $f['photographer_id'] = (string) $pid;
                        $np++;
                    }
                    if ($setAlt && ($overwriteAlt || trim((string) $ph['alt_text']) === '')) {
                        $f['alt_text'] = $altText;
                        $na++;
                    }
                    if ($f !== []) {
                        $this->catalog->update($id, $f);
                    }
                }
            }
            if ($setPhotographer && $p['rest'] !== null) {
                $pid = null;
                foreach ($this->db->all('SELECT p.id FROM photos p WHERE p.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM gallery_photos gp WHERE gp.photo_id = p.id)') as $row) {
                    $pid ??= $photographer($p['rest']);
                    $this->catalog->update((int) $row['id'], ['photographer_id' => (string) $pid]);
                    $np++;
                }
            }
            return ['photographers' => $np, 'alts' => $na, 'created' => $created];
        });
    }
}
