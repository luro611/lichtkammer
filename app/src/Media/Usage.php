<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

use Lichtkammer\Auth\Database;
use Lichtkammer\Site\Blocks;
use Lichtkammer\Site\Builder;
use Lichtkammer\Site\Settings;

/**
 * „Verwendet in“ (Entwurf 44, Stufe 2). Nur lesend, eine Quelle der Wahrheit: ein Durchlauf über alle Seiten und die Startseite.
 * Gefunden wird eine Datei (a) über ihre Nummer im Block (`file`, mit Blocknummer) und (b) als Altverweis: die öffentliche Adresse steht
 * als Zeichenfolge im Block oder im Seitentext (nötig für die übernommenen PDFs aus dem WordPress-Import). Ein Regex je Seite, kein Suchlauf je Datei.
 */
final class Usage
{
    public function __construct(private readonly Database $db, private readonly ?Builder $builder = null)
    {
    }

    /**
     * Verwendung je Datei in Seiten und Startseite.
     *
     * @param list<int>|null $ids nur diese Dateien (null = alle)
     * @return array<int,list<array{label:string,link:string,pos:string,linked:bool}>>
     */
    public function files(?array $ids = null): array
    {
        $want = $ids === null ? null : array_flip(array_map('intval', $ids));
        if ($want === [] ) {
            return [];
        }
        // Adresse → Datei-Nummer (für Altverweise); nur Dateien mit öffentlicher Adresse haben eine
        $byUrl = [];
        foreach ($this->db->all("SELECT id, public_name, legacy_public_path FROM files WHERE public_name <> '' OR legacy_public_path <> ''") as $f) {
            $legacy = (string) $f['legacy_public_path'];
            $url = $legacy !== '' ? '/media/' . $legacy : '/media/dateien/' . $f['public_name'];
            if ($want === null || isset($want[(int) $f['id']])) {
                $byUrl[$url] = (int) $f['id'];
            }
        }
        $out = [];
        $scan = function (string $label, string $link, string $blocksJson, string $body) use (&$out, $want, $byUrl): void {
            $linked = [];
            if ($blocksJson !== '') {
                try {
                    foreach (Blocks::fileRefs(Blocks::decode($blocksJson)) as $r) {
                        if ($want === null || isset($want[$r['file']])) {
                            $out[$r['file']][] = ['label' => $label, 'link' => $link, 'pos' => $r['pos'], 'linked' => true];
                            $linked[$r['file']] = true;
                        }
                    }
                } catch (MediaException) {
                    // unlesbare Blöcke meldet der Probelauf
                }
            }
            $text = str_replace('\\/', '/', $blocksJson !== '' ? $blocksJson : $body); // Seitentext zählt nur, wenn er die Seite ist (Blockmodus: body ist nur Sicherung); JSON-Schrägstriche „\/“ gleichgestellt
            if ($byUrl !== [] && $text !== '' && preg_match_all('#/media/(?:dateien|dokumente)/[A-Za-z0-9._-]+#', $text, $m) > 0) {
                foreach (array_unique($m[0]) as $url) {
                    $id = $byUrl[$url] ?? $byUrl[rtrim($url, '.')] ?? null; // „… siehe /media/dokumente/x.pdf.“ am Satzende (Prüfung 46)
                    if ($id === null && preg_match('#^(/media/(?:dateien|dokumente)/[A-Za-z0-9._-]*?[A-Za-z0-9])[._-]*$#', $url, $t) === 1) {
                        $id = $byUrl[$t[1]] ?? null;
                    }
                    if ($id !== null && !isset($linked[$id])) {
                        $out[$id][] = ['label' => $label, 'link' => $link, 'pos' => '', 'linked' => false];
                    }
                }
            }
        };
        foreach ($this->db->all('SELECT id, title, blocks, body FROM pages ORDER BY title, id') as $pg) {
            $scan('Seite „' . $pg['title'] . '“', '/admin/seiten/' . (int) $pg['id'], (string) $pg['blocks'], (string) $pg['body']);
        }
        // Weitere Stellen, die auf eine Datei zeigen können: Galerietexte und Weiterleitungen alter WordPress-Adressen (Importer: /wp-content/… → /media/dokumente/…).
        // Eine Falschmeldung „nicht verwendet“ wäre gefährlich (keine Warnung, Datei gelöscht), darum lieber eine Stelle zu viel nennen.
        foreach ($this->db->all("SELECT id, title, description FROM galleries WHERE description LIKE '%/media/%'") as $g) {
            $scan('Galerie „' . $g['title'] . '“', '/admin/galerien/' . (int) $g['id'], '', (string) $g['description']);
        }
        foreach ($this->db->all("SELECT from_path, to_path FROM redirects WHERE to_path LIKE '/media/%'") as $rd) {
            $scan('Weiterleitung von ' . $rd['from_path'], '/admin/import', '', (string) $rd['to_path']);
        }
        $home = (string) ($this->db->value("SELECT v FROM site_settings WHERE k = 'home_blocks'") ?? '');
        if (trim($home) !== '') {
            $scan('Startseite', '/admin/startseite', $home, '');
        }
        foreach (Settings::LOGO_ROLES as $role => $label) { // Logo aus Medien (Stufe 5): „f<Nummer>“ in der Einstellung
            $v = (string) ($this->db->value('SELECT v FROM site_settings WHERE k = ?', ['logo_' . $role]) ?? '');
            if (preg_match('/^f([1-9]\d{0,8})$/D', $v, $lm) === 1 && ($want === null || isset($want[(int) $lm[1]]))) {
                $out[(int) $lm[1]][] = ['label' => $label, 'link' => '/admin/logos', 'pos' => '', 'linked' => true];
            }
        }
        return $out;
    }

    /** @return array<int,true> Nummern aller in Seiten benutzten Dateien (ein Durchlauf) */
    public function usedFileIds(): array
    {
        return array_fill_keys(array_keys($this->files()), true);
    }

    /** @return list<array{label:string,link:string,pos:string,linked:bool}> */
    public function forFile(int $id): array
    {
        return $this->files([$id])[$id] ?? [];
    }

    /**
     * Wo ein Foto steht: Galerien (auch Titelbild), Seiten und Startseite (Blöcke), Startseiten-Titelbild, Startseiten-Fotos. Ein Durchlauf über die Seiten.
     *
     * @return list<array{label:string,link:string,pos:string}>
     */
    public function forPhoto(int $id): array
    {
        $out = [];
        foreach ($this->db->all('SELECT g.id, g.title, g.status, g.cover_photo_id FROM galleries g WHERE g.id IN (SELECT gallery_id FROM gallery_photos WHERE photo_id = ?) OR g.cover_photo_id = ? ORDER BY g.title, g.id', [$id, $id]) as $g) {
            $out[] = ['label' => 'Galerie „' . $g['title'] . '“ (' . ($g['status'] === 'published' ? 'veröffentlicht' : 'Entwurf') . ')' . ((int) $g['cover_photo_id'] === $id ? ', Titelbild' : ''), 'link' => '/admin/galerien/' . (int) $g['id'], 'pos' => ''];
        }
        $scan = function (string $label, string $link, string $json) use (&$out, $id): void {
            if ($json === '' || !str_contains($json, (string) $id)) {
                return;
            }
            try {
                $list = Blocks::decode($json);
            } catch (MediaException) {
                return;
            }
            if (in_array($id, Blocks::photoIds($list), true)) {
                $out[] = ['label' => $label, 'link' => $link, 'pos' => ''];
            }
        };
        foreach ($this->db->all("SELECT id, title, blocks FROM pages WHERE blocks <> '' ORDER BY title, id") as $pg) {
            $scan('Seite „' . $pg['title'] . '“', '/admin/seiten/' . (int) $pg['id'], (string) $pg['blocks']);
        }
        $scan('Startseite', '/admin/startseite', (string) ($this->db->value("SELECT v FROM site_settings WHERE k = 'home_blocks'") ?? ''));
        if ((int) ($this->db->value("SELECT v FROM site_settings WHERE k = 'hero_photo_id'") ?? 0) === $id) {
            $out[] = ['label' => 'Startseite: Titelbild', 'link' => '/admin/einstellungen', 'pos' => ''];
        }
        if ((int) $this->db->value('SELECT on_home FROM photos WHERE id = ?', [$id]) === 1) {
            $out[] = ['label' => 'Startseiten-Fotos (Schalter „Startseite“)', 'link' => '/admin/fotos/' . $id, 'pos' => ''];
        }
        return $out;
    }

    /** Dateien (Nummern), die auf der aktiven Fassung verlinkt sind. @return array<int,true> */
    public function liveFileIds(): array
    {
        $cur = $this->builder?->current();
        $m = $cur === null ? null : $this->builder->manifest($cur);
        return array_fill_keys(array_map('intval', $m['file_ids'] ?? []), true);
    }

    /** Nummern aller Dateien (ohne Papierkorb) in den Ordnern und deren Unterordnern. @param list<int> $folderIds @return list<int> */
    public function fileIdsInFolders(Folders $folders, array $folderIds): array
    {
        $all = [];
        foreach ($folderIds as $f) {
            foreach ($folders->subtree($f) as $x) {
                $all[(int) $x] = true;
            }
        }
        if ($all === []) {
            return [];
        }
        $in = implode(',', array_keys($all));
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->all("SELECT id FROM files WHERE deleted_at IS NULL AND folder_id IN ($in)"));
    }
}
