<?php
declare(strict_types=1);

namespace Lichtkammer\Admin;

use Lichtkammer\Media\Browser;
use Lichtkammer\Media\Catalog;
use Lichtkammer\Media\Folders;
use Lichtkammer\Media\Galleries;
use Lichtkammer\Media\MediaException;
use Lichtkammer\Media\Names;
use Lichtkammer\Request;
use Lichtkammer\Response;

/**
 * Medienverwaltung (Entwurf 16): Ordner wie im Finder, Fotos und Dateien, Papierkorb, Ordner-Upload (Plan), Auslieferung privater Dateien.
 * Alle Wege verlangen eine volle Sitzung; POST braucht das CSRF-Token (AdminController::add). Nie ein Pfad aus Eingaben: nur Kennungen.
 */
trait MedienPages
{
    private const MEDIEN_FLASH = [
        'ordner' => 'Ordner angelegt.',
        'umbenannt' => 'Umbenannt.',
        'verschoben' => '{n} Eintrag/Einträge verschoben.',
        'papierkorb' => '{n} Eintrag/Einträge in den Papierkorb gelegt. Dort sind sie 30 Tage wiederherstellbar.',
        'zurueck' => 'Zurückgeholt ({n} Einträge). Fotos werden gleich neu aufbereitet.',
        'geloescht' => '{n} Eintrag/Einträge endgültig gelöscht.',
        'oeffentlich' => 'Die Datei ist öffentlich. Sichtbar auf der Seite wird sie, wo du sie verlinkt hast.',
        'privat' => 'Die Datei ist wieder privat, die öffentliche Kopie ist gelöscht.',
        'galerie' => 'Galerie angelegt (Entwurf): {n} Foto(s) hinzugefügt.',
        'aufbereitet' => 'Fotos aufbereitet.',
        'bestand' => 'Bestand übernommen: {n} PDF(s) aus dem alten Ordner „dokumente“ eingetragen.',
    ];

    private function idParam(string $v): ?int
    {
        return ctype_digit($v) && strlen($v) < 10 && (int) $v > 0 ? (int) $v : null;
    }

    private function folderParam(Request $req, string $key = 'ordner'): int
    {
        $v = $req->input($key) !== '' ? $req->input($key) : $req->query($key);
        return ctype_digit($v) && strlen($v) < 10 ? (int) $v : 0;
    }

    private function medienBack(int $folder, string $code, int $n = 0, string $extra = ''): Response
    {
        return $this->done('/admin/medien' . ($folder > 0 ? '?ordner=' . $folder : ''), $code, $n);
    }

    /** Baum aller Ordner als Liste mit Einrückungstiefe für Auswahlfelder. @return list<array{id:int,label:string,depth:int}> */
    private function folderOptions(): array
    {
        $all = $this->s->folders->all();
        $by = [];
        foreach ($all as $f) {
            $by[(int) $f['parent_id']][] = $f;
        }
        $out = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$out, $by): void {
            foreach ($by[$parent] ?? [] as $f) {
                $out[] = ['id' => (int) $f['id'], 'label' => (string) $f['name'], 'depth' => $depth];
                if ($depth < Folders::MAX_DEPTH) {
                    $walk((int) $f['id'], $depth + 1);
                }
            }
        };
        $walk(0, 1);
        return $out;
    }

    public function medien(Request $req, array $p, ?array $sess): Response
    {
        $folder = $this->folderParam($req);
        if ($folder > 0 && $this->s->folders->get($folder) === null) {
            return $this->missing($sess, 'Ordner nicht gefunden');
        }
        $this->medienHousekeeping($req, $sess);
        $sort = in_array($req->query('sort'), ['name', 'datum', 'groesse', 'art'], true) ? $req->query('sort') : 'name';
        $desc = $req->query('dir') === 'ab';
        $view = $req->query('ansicht') === 'symbole' ? 'symbole' : 'liste';
        $q = trim($req->query('q'));
        $contents = $this->s->browser->contents($folder, $q, $sort, $desc, max(1, (int) $req->query('seite')));
        $edit = preg_match('/^([od])(\d{1,9})$/', $req->query('bearbeiten'), $em) === 1 ? [$em[1], (int) $em[2]] : null;
        $usage = $this->s->browser->usage();
        $engine = $this->s->library->engine();
        $folderRow = $folder > 0 ? $this->s->folders->get($folder) : null;
        $linkedGallery = $folderRow !== null && $folderRow['gallery_id'] !== null ? $this->s->galleries->get((int) $folderRow['gallery_id']) : null;
        return $this->page('admin/medien', [
            'title' => 'Medien', 'folder' => $folder, 'folderRow' => $folderRow, 'linkedGallery' => $linkedGallery,
            'crumbs' => $this->s->folders->breadcrumb($folder),
            'folders' => $q === '' ? $this->s->folders->children($folder) : [],
            'contents' => $contents, 'tree' => $this->s->folders->all(), 'options' => $this->folderOptions(),
            'sort' => $sort, 'desc' => $desc, 'view' => $view, 'q' => $q, 'edit' => $edit,
            'trashCount' => count($this->s->folders->trashedRoots()) + count($this->s->files->trashedSingles()),
            'pending' => $this->s->browser->pendingCount(), 'usage' => $usage,
            'folderNames' => $q !== '' ? $this->folderNamesFor($contents['rows']) : [],
            'engine' => $engine === null ? null : $engine->name(),
            'maxMb' => max(1, $this->config->int('LK_MAX_UPLOAD_MB', 100)), 'maxFileMb' => max(1, $this->config->int('LK_MAX_FILE_MB', 50)),
            'files' => $this->s->files, 'isAdmin' => ($sess['role'] ?? '') === 'admin',
            'legacyOpen' => $this->legacyPdfCount() > 0,
        ], 200, $sess);
    }

    /** @param list<array<string,mixed>> $rows @return array<int,string> */
    private function folderNamesFor(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $fid = (int) $r['folder_id'];
            $out[$fid] ??= implode(' / ', array_column($this->s->folders->breadcrumb($fid), 'name')) ?: 'Medien';
        }
        return $out;
    }

    private function legacyPdfCount(): int
    {
        $dir = $this->config->mediaDir() . '/dokumente';
        return is_dir($dir) ? count(glob($dir . '/*.pdf') ?: []) - (int) $this->s->db->value("SELECT COUNT(*) FROM files WHERE legacy_public_path <> ''") : 0;
    }

    /** Aufräumen beim Aufruf, in Stapeln (kein Cron): abgelaufener Papierkorb, Bildvarianten gelöschter Zweige, alte Plan-Reste. */
    private function medienHousekeeping(Request $req, ?array $sess): void
    {
        $n = $this->s->branch->purgeExpired() + $this->s->catalog->purgeExpired();
        if ($n > 0) {
            $this->auditLog('media_purged', $sess, 'count=' . $n, $req);
        }
        $this->s->folders->finishTrash($this->config->mediaDir());
    }

    // --- Ordner und Einträge ---

    public function medienFolderCreate(Request $req, array $p, ?array $sess): Response
    {
        $parent = $this->folderParam($req);
        try {
            $id = $this->s->folders->create($parent, $req->input('name'));
        } catch (MediaException $e) {
            return $this->medienError($req, $sess, $e->getMessage());
        }
        $this->auditLog('folder_created', $sess, 'folder=' . $id . ',parent=' . $parent, $req);
        return $this->medienBack($parent, 'ordner');
    }

    private function medienError(Request $req, ?array $sess, string $message): Response
    {
        $_ = $req;
        return $this->page('admin/message', ['title' => 'Das geht so nicht', 'heading' => 'Das geht so nicht', 'text' => $message], 400, $sess);
    }

    public function medienRename(Request $req, array $p, ?array $sess): Response
    {
        $folder = $this->folderParam($req);
        $kind = $req->input('art');
        $id = $this->idParam($req->input('id'));
        if ($id === null || !in_array($kind, ['ordner', 'datei'], true)) {
            return $this->medienBack($folder, 'umbenannt');
        }
        try {
            if ($kind === 'ordner') {
                $this->s->folders->rename($id, $req->input('name'));
                $this->auditLog('folder_renamed', $sess, 'folder=' . $id, $req);
            } else {
                $this->s->files->rename($id, $req->input('name'));
            }
        } catch (MediaException $e) {
            return $this->medienError($req, $sess, $e->getMessage());
        }
        return $this->medienBack($folder, 'umbenannt');
    }

    /** @return array{0:list<int>,1:list<int>,2:list<int>} Ordner, Fotos, Dateien aus der Auswahl */
    private function selection(Request $req): array
    {
        return [$this->idList($req, 'ordner_id'), $this->idList($req, 'foto'), $this->idList($req, 'datei')];
    }

    public function medienMove(Request $req, array $p, ?array $sess): Response
    {
        $from = $this->folderParam($req);
        [$fo, $ph, $fi] = $this->selection($req);
        $target = ctype_digit($req->input('ziel')) ? (int) $req->input('ziel') : -1;
        if ($target < 0 || ($fo === [] && $ph === [] && $fi === [])) {
            return $this->medienBack($from, 'nothing');
        }
        try {
            $n = $this->s->folders->move($fo, $ph, $fi, $target);
        } catch (MediaException $e) {
            return $this->medienError($req, $sess, $e->getMessage());
        }
        $this->auditLog('media_moved', $sess, 'to=' . $target . ',count=' . $n, $req);
        return $this->medienBack($from, 'verschoben', $n);
    }

    public function medienTrash(Request $req, array $p, ?array $sess): Response
    {
        $from = $this->folderParam($req);
        [$fo, $ph, $fi] = $this->selection($req);
        if ($fo === [] && $ph === [] && $fi === []) {
            return $this->medienBack($from, 'nothing');
        }
        $n = 0;
        $galleries = [];
        foreach ($fo as $id) {
            try {
                $r = $this->s->folders->trash($id);
                $n += $r['folders'] + $r['photos'] + $r['files'];
                $galleries = array_merge($galleries, $r['galleries']);
                $this->auditLog('media_trashed', $sess, 'folder=' . $id . ',count=' . ($r['folders'] + $r['photos'] + $r['files']), $req);
            } catch (MediaException) {
                // Ordner schon weg: überspringen
            }
        }
        $single = 0;
        if ($ph !== []) {
            $single += $this->s->catalog->trash($ph);
        }
        if ($fi !== []) {
            $single += $this->s->files->trash($fi);
        }
        if ($single > 0) {
            $this->auditLog('media_trashed', $sess, 'count=' . $single, $req);
        }
        $this->s->folders->finishTrash($this->config->mediaDir());
        return $this->medienBack($from, 'papierkorb', $n + $single);
    }

    // --- Papierkorb ---

    public function medienTrashPage(Request $req, array $p, ?array $sess): Response
    {
        $this->medienHousekeeping($req, $sess);
        return $this->page('admin/medien_trash', [
            'title' => 'Medien-Papierkorb', 'roots' => $this->s->folders->trashedRoots(), 'files' => $this->s->files->trashedSingles(), 'days' => Catalog::TRASH_DAYS,
            'photoTrash' => (int) $this->s->db->value('SELECT COUNT(*) FROM photos WHERE deleted_at IS NOT NULL AND trash_root_id IS NULL'),
        ], 200, $sess);
    }

    public function medienTrashAction(Request $req, array $p, ?array $sess): Response
    {
        $rootIds = $this->idList($req, 'ordner_id');
        $fileIds = $this->idList($req, 'datei');
        $purge = $req->input('aktion') === 'loeschen';
        $count = 0;
        if ($purge) {
            foreach ($rootIds as $id) {
                $r = $this->s->branch->purgeRoot($id, 200);
                $count += $r['purged'];
            }
            $count += $this->s->files->purge($fileIds);
            if ($count > 0) {
                $this->auditLog('media_purged', $sess, 'count=' . $count, $req);
            }
            return $this->done('/admin/medien/papierkorb', 'geloescht', $count);
        }
        foreach ($rootIds as $id) {
            try {
                $r = $this->s->folders->restore($id);
                $count += $r['folders'] + $r['photos'] + $r['files'];
                $this->auditLog('media_restored', $sess, 'folder=' . $id . ',count=' . ($r['folders'] + $r['photos'] + $r['files']), $req);
            } catch (MediaException) {
                // schon zurückgeholt
            }
        }
        $n = $this->s->files->restore($fileIds);
        if ($n > 0) {
            $this->auditLog('media_restored', $sess, 'count=' . $n, $req);
        }
        return $this->done('/admin/medien/papierkorb', 'zurueck', $count + $n);
    }

    // --- Dateien: Auslieferung und öffentlich ---

    /** Private Datei, nur angemeldet. Standard: Download. ?ansicht=1 öffnet im Browser (SVG und PDF dann mit Schutzköpfen). */
    public function mediaFile(Request $req, array $p, ?array $sess): Response
    {
        $id = $this->idParam((string) ($p['id'] ?? ''));
        $f = $id === null ? null : $this->s->files->get($id);
        $abs = $f === null ? null : $this->s->files->absolute($f);
        if ($f === null || $abs === null) {
            return Response::notFound();
        }
        $size = (int) filesize($abs);
        $inline = $req->query('ansicht') === '1';
        $name = Names::headerName((string) $f['name']);
        $ext = pathinfo((string) $f['path'], PATHINFO_EXTENSION);
        if ($ext !== '' && !str_ends_with(strtolower($name['ascii']), '.' . $ext)) {
            $name['ascii'] .= '.' . $ext;
            $name['utf8'] .= '.' . $ext;
        }
        $headers = [
            'Content-Type' => (string) $f['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $name['ascii'] . "\"; filename*=UTF-8''" . rawurlencode($name['utf8']),
            'Referrer-Policy' => 'no-referrer',
        ];
        if ($f['kind'] === 'svg') { // direkt aufgerufen führt eine SVG nichts aus: nur ein CSP-Kopf, ersetzt den allgemeinen
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
        }
        $from = 0;
        $len = $size;
        $status = 200;
        $range = trim($req->header('Range'));
        if ($range !== '') {
            if (preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) !== 1 || ($m[1] === '' && $m[2] === '')) {
                return new Response('', 416, ['Content-Range' => 'bytes */' . $size]);
            }
            if ($m[1] === '') { // letzte n Bytes
                $n = min($size, (int) $m[2]);
                $from = $size - $n;
                $end = $size - 1;
            } else {
                $from = (int) $m[1];
                $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
            }
            if ($from >= $size || $end < $from) {
                return new Response('', 416, ['Content-Range' => 'bytes */' . $size]);
            }
            $len = $end - $from + 1;
            $status = 206;
            $headers['Content-Range'] = 'bytes ' . $from . '-' . $end . '/' . $size;
        }
        return Response::file($abs, $from, $len, $status, $headers);
    }

    public function mediaFilePublic(Request $req, array $p, ?array $sess): Response
    {
        $id = $this->idParam((string) ($p['id'] ?? ''));
        $on = $req->input('an') === '1';
        $folder = $this->folderParam($req);
        if ($id === null || $this->s->files->get($id) === null) {
            return $this->missing($sess);
        }
        try {
            $this->s->files->setPublic($id, $on);
        } catch (MediaException $e) {
            return $this->medienError($req, $sess, $e->getMessage());
        }
        $this->auditLog('file_public_changed', $sess, 'file=' . $id . ',public=' . ($on ? '1' : '0'), $req);
        return $this->medienBack($folder, $on ? 'oeffentlich' : 'privat');
    }

    public function mediaAdopt(Request $req, array $p, ?array $sess): Response
    {
        $dir = $this->s->folders->ensurePath(0, ['Dokumente']);
        $r = $this->s->files->adoptLegacy((int) $sess['user_id'], $dir['id']);
        $this->auditLog('media_upload_run', $sess, 'folders=' . count($dir['created']) . ',files=' . $r['adopted'], $req);
        return $this->medienBack($dir['id'], 'bestand', $r['adopted']);
    }

    // --- Fotos aufbereiten (nach dem Wiederherstellen) ---

    public function mediaProcess(Request $req, array $p, ?array $sess): Response
    {
        $deadline = microtime(true) + 20;
        foreach ($this->s->browser->pendingPhotos(25) as $id) {
            if (microtime(true) > $deadline) {
                break;
            }
            try {
                $this->s->library->process($id);
            } catch (MediaException) {
                // Foto inzwischen gelöscht
            }
        }
        $left = $this->s->browser->pendingCount();
        if ($req->header('X-Requested-With') === 'fetch') {
            return Response::json(['remaining' => $left]);
        }
        return $this->medienBack($this->folderParam($req), 'aufbereitet');
    }

    // --- Galerie aus Ordner (nachträglich) ---

    public function mediaFolderGallery(Request $req, array $p, ?array $sess): Response
    {
        $folder = $this->folderParam($req);
        $f = $this->s->folders->get($folder);
        if ($f === null) {
            return $this->missing($sess);
        }
        $all = $req->input('modus') === 'alle';
        $ids = $all ? $this->s->folders->subtree($folder) : [$folder];
        $in = implode(',', array_map('intval', $ids));
        $rows = $this->s->db->all("SELECT p.id, p.original_name, p.folder_id FROM photos p WHERE p.folder_id IN ($in) AND p.deleted_at IS NULL");
        $pathOf = [];
        foreach ($ids as $fid) {
            $pathOf[$fid] = implode('/', array_column($this->s->folders->breadcrumb($fid), 'name'));
        }
        usort($rows, static fn (array $a, array $b): int => Names::natural($pathOf[(int) $a['folder_id']] ?? '', $pathOf[(int) $b['folder_id']] ?? '') ?: Names::natural((string) $a['original_name'], (string) $b['original_name']));
        try {
            $title = $req->input('titel') !== '' ? Names::clean($req->input('titel'))['name'] : (string) $f['name'];
            $gid = $this->s->galleries->create($title);
            $n = $this->s->galleries->addPhotos($gid, array_map(static fn (array $r): int => (int) $r['id'], $rows));
        } catch (MediaException $e) {
            return $this->medienError($req, $sess, $e->getMessage());
        }
        $this->s->folders->setGallery($folder, $gid);
        $this->auditLog('folder_gallery_created', $sess, 'folder=' . $folder . ',gallery=' . $gid . ',count=' . $n, $req);
        return $this->done('/admin/galerien/' . $gid, 'galerie', $n);
    }

    // --- Ordner-Upload: Plan ---

    private function planError(string $msg, int $status = 400): Response
    {
        return Response::json(['error' => $msg], $status);
    }

    /**
     * Prüft einen Ordnerplan und legt Ordner (zusammengeführt) und gewünschte Entwurfs-Galerien an. Der Browser schickt nie Pfade zu Dateien,
     * nur Segmentlisten; hier wird jedes Segment noch einmal geprüft (der Browser ist nie die Sicherheitsgrenze).
     */
    public function mediaPlan(Request $req, array $p, ?array $sess): Response
    {
        if ($req->contentType() !== 'application/json') {
            return $this->planError('Falscher Inhaltstyp.', 415);
        }
        $limit = 2 * 1024 * 1024;
        $raw = $req->body($limit);
        if (strlen($raw) > $limit) {
            return $this->planError('Der Plan ist zu groß. Bitte den Ordner in kleineren Teilen hochladen.', 413);
        }
        $in = json_decode($raw, true, 8);
        if (!is_array($in) || !is_array($in['folders'] ?? null) || !is_array($in['files'] ?? null)) {
            return $this->planError('Der Plan ist ungültig.');
        }
        $uid = (int) $sess['user_id'];
        $now = $this->s->clock->now();
        $this->planCleanup($now);
        if ((int) $this->s->db->value('SELECT COUNT(DISTINCT plan) FROM plan_items WHERE user_id = ? AND created_at > ?', [$uid, $now - 3600]) >= 20) {
            return $this->planError('Zu viele Ordner-Uploads in kurzer Zeit. Bitte etwas warten.', 429);
        }
        if (count($in['files']) > 5000) {
            return $this->planError('Höchstens 5 000 Dateien je Ordner-Upload. Bitte in Teilen hochladen.');
        }
        if (count($in['folders']) > Folders::MAX_FOLDERS) {
            return $this->planError('Zu viele Ordner.');
        }
        $target = isset($in['target']) && is_int($in['target']) ? max(0, $in['target']) : 0;
        $token = bin2hex(random_bytes(8));
        try {
            $out = $this->s->db->tx(function () use ($in, $target, $token, $uid, $now): array {
                if (!$this->s->folders->isUsable($target)) {
                    throw new MediaException('Der Zielordner gibt es nicht (mehr) oder er liegt im Papierkorb.');
                }
                $folders = [];
                $changed = 0;
                $merged = [];
                $newGalleries = 0;
                $newFolders = 0;
                foreach ($in['folders'] as $f) {
                    if (!is_array($f) || !is_array($f['path'] ?? null)) {
                        throw new MediaException('Der Plan ist ungültig.');
                    }
                    $seg = Names::segments($f['path']);
                    if ($seg['segments'] === []) { // Wurzel des Ziels selbst
                        $folders[] = ['id' => $target, 'created' => false, 'gallery_id' => null, 'warn' => ''];
                        continue;
                    }
                    $changed += $seg['changed'] ? 1 : 0;
                    $r = $this->s->folders->ensurePath($target, $seg['segments']);
                    foreach ($r['created'] as $cid) {
                        $this->s->db->run("INSERT INTO plan_items(plan, user_id, kind, ref_id, created_at) VALUES(?,?,'folder',?,?)", [$token, $uid, $cid, $now]);
                        $newFolders++;
                    }
                    $existed = $r['created'] === [] || !in_array($r['id'], $r['created'], true);
                    $entry = ['id' => $r['id'], 'created' => !$existed, 'gallery_id' => null, 'warn' => ''];
                    if ($existed) {
                        $merged[] = end($seg['segments']);
                    }
                    if (!empty($f['gallery']) && $f['gallery'] === true) {
                        $row = $this->s->folders->get($r['id']);
                        $linked = $row !== null && $row['gallery_id'] !== null ? $this->s->galleries->get((int) $row['gallery_id']) : null;
                        if ($linked !== null && $linked['status'] === 'draft') {
                            $entry['gallery_id'] = (int) $linked['id']; // Entwurfsgalerie des Ordners wird weiterbenutzt
                        } else {
                            $title = isset($f['title']) && is_string($f['title']) && trim($f['title']) !== '' ? Names::clean($f['title'])['name'] : (string) end($seg['segments']);
                            if ($this->s->db->value('SELECT 1 FROM galleries WHERE title = ? COLLATE NOCASE', [Galleries::clip($title, 200)]) !== null) {
                                $entry['warn'] = 'Es gibt schon eine Galerie mit diesem Titel. Es wird trotzdem eine neue angelegt (die Adresse ist eindeutig).';
                            }
                            $gid = $this->s->galleries->create($title);
                            $this->s->folders->setGallery($r['id'], $gid);
                            $this->s->db->run("INSERT INTO plan_items(plan, user_id, kind, ref_id, created_at) VALUES(?,?,'gallery',?,?)", [$token, $uid, $gid, $now]);
                            $entry['gallery_id'] = $gid;
                            $newGalleries++;
                        }
                    }
                    $folders[] = $entry;
                }
                $files = [];
                $skipped = 0;
                foreach ($in['files'] as $fl) {
                    if (!is_array($fl) || !isset($fl['f']) || !is_int($fl['f']) || !isset($folders[$fl['f']]) || !is_string($fl['name'] ?? null)) {
                        throw new MediaException('Der Plan ist ungültig.');
                    }
                    $fid = $folders[$fl['f']]['id'];
                    $name = Names::fileName($fl['name']);
                    $size = is_int($fl['size'] ?? null) ? $fl['size'] : 0;
                    $fp = is_string($fl['fp'] ?? null) && preg_match('/^[a-f0-9]{64}$/', $fl['fp']) === 1 ? $fl['fp'] : '';
                    $skip = false;
                    if ($fp !== '') { // schon vorhanden nur bei gleichem Ordner, Namen, Größe UND Fingerabdruck (Prüfung 17, H2)
                        $skip = $this->s->db->value('SELECT 1 FROM photos WHERE folder_id IS ? AND original_name = ? AND bytes = ? AND fingerprint = ? AND deleted_at IS NULL', [$fid > 0 ? $fid : null, $name, $size, $fp]) !== null
                            || $this->s->db->value('SELECT 1 FROM files WHERE folder_id = ? AND name = ? AND bytes = ? AND fingerprint = ? AND deleted_at IS NULL', [$fid, $name, $size, $fp]) !== null;
                    }
                    $skipped += $skip ? 1 : 0;
                    $files[] = ['folder_id' => $fid, 'skip' => $skip];
                }
                return ['folders' => $folders, 'files' => $files, 'summary' => ['new_folders' => $newFolders, 'merged' => array_slice($merged, 0, 20), 'galleries' => $newGalleries, 'names_changed' => $changed, 'skipped' => $skipped]];
            });
        } catch (MediaException $e) {
            return $this->planError($e->getMessage());
        }
        $s = $out['summary'];
        if ($s['new_folders'] > 0 || $s['galleries'] > 0) {
            $this->auditLog('media_upload_run', $sess, 'folders=' . $s['new_folders'] . ',files=' . count($out['files']) . ',galleries=' . $s['galleries'], $req);
        }
        return Response::json($out + ['plan' => $token]);
    }

    public function mediaPlanCancel(Request $req, array $p, ?array $sess): Response
    {
        $token = $req->input('plan');
        if (preg_match('/^[a-f0-9]{16}$/', $token) !== 1) {
            return Response::json(['ok' => true]);
        }
        $n = $this->planRemove($token, (int) $sess['user_id']);
        return Response::json(['ok' => true, 'removed' => $n]);
    }

    /** Entfernt die von einem Plan angelegten Ordner und Entwurfs-Galerien, soweit sie leer geblieben sind. */
    private function planRemove(string $token, ?int $userId): int
    {
        $items = $this->s->db->all('SELECT id, kind, ref_id FROM plan_items WHERE plan = ?' . ($userId !== null ? ' AND user_id = ?' : '') . ' ORDER BY id DESC', $userId !== null ? [$token, $userId] : [$token]);
        $n = 0;
        foreach ($items as $it) {
            $ref = (int) $it['ref_id'];
            if ($it['kind'] === 'gallery') {
                if ($this->s->db->value('SELECT 1 FROM gallery_photos WHERE gallery_id = ?', [$ref]) === null && $this->s->db->value("SELECT 1 FROM galleries WHERE id = ? AND status = 'draft'", [$ref]) !== null) {
                    $this->s->db->run('DELETE FROM galleries WHERE id = ?', [$ref]);
                    $n++;
                }
            } elseif ($this->s->db->value('SELECT 1 FROM photos WHERE folder_id = ? UNION ALL SELECT 1 FROM files WHERE folder_id = ? UNION ALL SELECT 1 FROM folders WHERE parent_id = ? LIMIT 1', [$ref, $ref, $ref]) === null) {
                $n += $this->s->db->run('DELETE FROM folders WHERE id = ?', [$ref])->rowCount();
            }
            $this->s->db->run('DELETE FROM plan_items WHERE id = ?', [$it['id']]);
        }
        return $n;
    }

    /** Pläne, die niemand zu Ende geführt hat (Tab geschlossen), nach 24 Stunden aufräumen. */
    private function planCleanup(int $now): void
    {
        foreach ($this->s->db->all('SELECT DISTINCT plan FROM plan_items WHERE created_at < ? LIMIT 20', [$now - 86400]) as $r) {
            $this->planRemove((string) $r['plan'], null);
        }
    }
}
