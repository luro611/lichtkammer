<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

use Lichtkammer\Auth\Database;
use Lichtkammer\Site\Blocks;
use Lichtkammer\Site\Builder;

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
            $text = $blocksJson !== '' ? $blocksJson : $body; // Seitentext zählt nur, wenn er die Seite ist (Blockmodus: body ist nur Sicherung)
            if ($byUrl !== [] && $text !== '' && preg_match_all('#/media/(?:dateien|dokumente)/[A-Za-z0-9._-]+#', $text, $m) > 0) {
                foreach (array_unique($m[0]) as $url) {
                    $id = $byUrl[$url] ?? null;
                    if ($id !== null && !isset($linked[$id])) {
                        $out[$id][] = ['label' => $label, 'link' => $link, 'pos' => '', 'linked' => false];
                    }
                }
            }
        };
        foreach ($this->db->all('SELECT id, title, blocks, body FROM pages ORDER BY title, id') as $pg) {
            $scan('Seite „' . $pg['title'] . '“', '/admin/seiten/' . (int) $pg['id'], (string) $pg['blocks'], (string) $pg['body']);
        }
        $home = (string) ($this->db->value("SELECT v FROM site_settings WHERE k = 'home_blocks'") ?? '');
        if (trim($home) !== '') {
            $scan('Startseite', '/admin/startseite', $home, '');
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
