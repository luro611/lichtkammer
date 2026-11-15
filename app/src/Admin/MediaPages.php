<?php
declare(strict_types=1);

namespace Lichtkammer\Admin;

use Lichtkammer\Media\Catalog;
use Lichtkammer\Media\MediaException;
use Lichtkammer\Request;
use Lichtkammer\Response;

/**
 * Bildverwaltung (Schritt 4): Galerien, Mediathek, Mehrfachbearbeitung, Papierkorb, Fotografen.
 * Wird vom AdminController eingebunden; alle Wege verlangen eine volle Sitzung und (bei POST) das CSRF-Token.
 * Meldungen nach dem Speichern kommen aus einer festen Liste (Parameter m), nie aus freiem Text der Adresse.
 */
trait MediaPages
{
    private ?string $flash = null;

    private const FLASH = [
        'saved' => 'Gespeichert.',
        'created' => 'Galerie angelegt.',
        'deleted' => 'Galerie gelöscht. Die Fotos bleiben in der Mediathek.',
        'trashed' => '{n} Foto(s) in den Papierkorb gelegt. Dort sind sie 30 Tage wiederherstellbar.',
        'restored' => '{n} Foto(s) wiederhergestellt.',
        'purged' => '{n} Foto(s) endgültig gelöscht.',
        'added' => '{n} Foto(s) zur Galerie hinzugefügt.',
        'removed' => '{n} Foto(s) aus der Galerie entfernt. Sie bleiben in der Mediathek.',
        'sorted' => 'Sortiert.',
        'cover' => 'Titelbild gesetzt.',
        'published' => 'Die Galerie ist als veröffentlicht markiert. Sichtbar wird sie auf der Seite mit dem Veröffentlichen.',
        'draft' => 'Die Galerie ist wieder ein Entwurf.',
        'bulk' => 'Änderungen an {n} Foto(s) gespeichert.',
        'nothing' => 'Es war nichts ausgewählt.',
        'person' => 'Fotografen gespeichert.',
    ];

    private function flashFrom(Request $req): ?string
    {
        $m = $req->query('m');
        if (!isset(self::FLASH[$m])) {
            return null;
        }
        return str_replace('{n}', (string) min(99999, max(0, (int) $req->query('n'))), self::FLASH[$m]);
    }

    private function done(string $path, string $code, int $n = 0): Response
    {
        return Response::redirect($path . (str_contains($path, '?') ? '&' : '?') . 'm=' . $code . ($n > 0 ? '&n=' . $n : ''));
    }

    /** @return list<int> */
    private function idList(Request $req, string $key = 'ids'): array
    {
        $out = [];
        foreach ($req->inputList($key) as $v) {
            if (ctype_digit($v) && strlen($v) < 10) {
                $out[(int) $v] = (int) $v;
            }
        }
        return array_values($out);
    }

    private function auditLog(string $event, ?array $sess, string $detail, Request $req): void
    {
        $this->s->audit->log($event, (int) $sess['user_id'], $detail, $this->ipPrefix($req));
    }

    private function missing(?array $sess, string $heading = 'Nicht gefunden'): Response
    {
        return $this->page('admin/message', ['title' => $heading, 'heading' => $heading, 'text' => 'Das gibt es nicht (mehr).'], 404, $sess);
    }

    // --- Galerien ---

    public function galleries(Request $req, array $p, ?array $sess, ?string $error = null, int $status = 200, string $oldTitle = ''): Response
    {
        return $this->page('admin/galleries', [
            'title' => 'Galerien', 'galleries' => $this->s->galleries->all(), 'error' => $error, 'oldTitle' => $oldTitle,
        ], $status, $sess);
    }

    public function galleryCreate(Request $req, array $p, ?array $sess): Response
    {
        try {
            $id = $this->s->galleries->create($req->input('title'), $req->input('description'));
        } catch (MediaException $e) {
            return $this->galleries($req, [], $sess, $e->getMessage(), 400, $req->input('title'));
        }
        $this->auditLog('gallery_saved', $sess, 'gallery=' . $id, $req);
        return $this->done('/admin/galerien/' . $id, 'created');
    }

    public function galleriesMove(Request $req, array $p, ?array $sess): Response
    {
        $this->s->galleries->moveGallery((int) $p['id'], $req->input('richtung'));
        return $this->done('/admin/galerien', 'sorted');
    }

    public function galleriesOrder(Request $req, array $p, ?array $sess): Response
    {
        $this->s->galleries->reorderGalleries($this->idList($req));
        return Response::json(['ok' => true]);
    }

    private function galleryPage(int $id, ?array $sess, ?string $error = null, int $status = 200, array $blockers = [], ?array $draft = null): Response
    {
        $g = $this->s->galleries->get($id);
        if ($g === null) {
            return $this->missing($sess);
        }
        $engine = $this->s->library->engine();
        return $this->page('admin/gallery', [
            'title' => (string) $g['title'], 'g' => $g, 'photos' => $this->s->galleries->photos($id), 'error' => $error, 'blockers' => $blockers, 'draft' => $draft,
            'engine' => $engine === null ? null : $engine->name(),
            'formats' => $engine === null ? [] : $engine->formats(),
            'maxMb' => max(1, $this->config->int('LK_MAX_UPLOAD_MB', 100)),
        ], $status, $sess);
    }

    public function gallery(Request $req, array $p, ?array $sess): Response
    {
        return $this->galleryPage((int) $p['id'], $sess);
    }

    public function gallerySave(Request $req, array $p, ?array $sess): Response
    {
        $id = (int) $p['id'];
        try {
            $this->s->galleries->update($id, $req->input('title'), $req->input('description'), $req->input('show_exif') === '1', $req->input('slug'));
        } catch (MediaException $e) {
            return $this->galleryPage($id, $sess, $e->getMessage(), 400, [], ['title' => $req->input('title'), 'description' => $req->input('description')]);
        }
        $this->auditLog('gallery_saved', $sess, 'gallery=' . $id, $req);
        return $this->done('/admin/galerien/' . $id, 'saved');
    }

    public function galleryStatus(Request $req, array $p, ?array $sess): Response
    {
        $id = (int) $p['id'];
        $to = $req->input('status') === 'published' ? 'published' : 'draft';
        try {
            $r = $this->s->galleries->setStatus($id, $to);
        } catch (MediaException $e) {
            return $this->missing($sess);
        }
        if (!$r['ok']) {
            return $this->galleryPage($id, $sess, $r['reason'], 400, $r['blockers']);
        }
        $this->auditLog($to === 'published' ? 'gallery_published' : 'gallery_saved', $sess, 'gallery=' . $id, $req);
        return $this->done('/admin/galerien/' . $id, $to === 'published' ? 'published' : 'draft');
    }

    public function galleryDelete(Request $req, array $p, ?array $sess): Response
    {
        $id = (int) $p['id'];
        try {
            $this->s->galleries->delete($id);
        } catch (MediaException) {
            return $this->missing($sess);
        }
        $this->auditLog('gallery_deleted', $sess, 'gallery=' . $id, $req);
        return $this->done('/admin/galerien', 'deleted');
    }

    public function galleryAddForm(Request $req, array $p, ?array $sess): Response
    {
        $id = (int) $p['id'];
        $g = $this->s->galleries->get($id);
        if ($g === null) {
            return $this->missing($sess);
        }
        $r = $this->s->catalog->searchNotIn($id, $req->query('q'), max(1, (int) $req->query('seite')));
        return $this->page('admin/gallery_add', ['title' => 'Fotos hinzufügen', 'g' => $g, 'result' => $r, 'q' => $req->query('q')], 200, $sess);
    }

    public function galleryAdd(Request $req, array $p, ?array $sess): Response
    {
        $id = (int) $p['id'];
        try {
            $n = $this->s->galleries->addPhotos($id, $this->idList($req));
        } catch (MediaException) {
            return $this->missing($sess);
        }
        if ($n > 0) {
            $this->auditLog('gallery_saved', $sess, 'gallery=' . $id . ',added=' . $n, $req);
        }
        return $this->done('/admin/galerien/' . $id, $n > 0 ? 'added' : 'nothing', $n);
    }

    public function galleryRemove(Request $req, array $p, ?array $sess): Response
    {
        $id = (int) $p['id'];
        try {
            $n = $this->s->galleries->removePhotos($id, $this->idList($req));
        } catch (MediaException) {
            return $this->missing($sess);
        }
        if ($n > 0) {
            $this->auditLog('gallery_saved', $sess, 'gallery=' . $id . ',removed=' . $n, $req);
        }
        $back = $req->input('zurueck') !== '' ? self::safeNext($req->input('zurueck')) : '/admin/galerien/' . $id;
        return $this->done($back, $n > 0 ? 'removed' : 'nothing', $n);
    }

    public function galleryOrder(Request $req, array $p, ?array $sess): Response
    {
        try {
            $this->s->galleries->reorderPhotos((int) $p['id'], $this->idList($req));
        } catch (MediaException) {
            return Response::json(['error' => 'Die Galerie gibt es nicht (mehr).'], 404);
        }
        return Response::json(['ok' => true]);
    }

    public function galleryPhotoMove(Request $req, array $p, ?array $sess): Response
    {
        $id = (int) $p['id'];
        $this->s->galleries->movePhoto($id, (int) $req->input('foto'), $req->input('richtung') === 'hoch' ? 'hoch' : 'runter');
        return Response::redirect('/admin/galerien/' . $id . '#foto-' . (int) $req->input('foto'));
    }

    public function gallerySort(Request $req, array $p, ?array $sess): Response
    {
        $id = (int) $p['id'];
        try {
            $this->s->galleries->sortBy($id, $req->input('modus'));
        } catch (MediaException $e) {
            return $this->galleryPage($id, $sess, $e->getMessage(), 400);
        }
        return $this->done('/admin/galerien/' . $id, 'sorted');
    }

    public function galleryCover(Request $req, array $p, ?array $sess): Response
    {
        $id = (int) $p['id'];
        try {
            $this->s->galleries->setCover($id, (int) $req->input('foto'));
        } catch (MediaException $e) {
            return $this->galleryPage($id, $sess, $e->getMessage(), 400);
        }
        return $this->done('/admin/galerien/' . $id, 'cover');
    }

    // --- Mediathek ---

    public function library(Request $req, array $p, ?array $sess): Response
    {
        $this->purgeExpired($req, $sess);
        $filters = [
            'q' => $req->query('q'), 'filter' => $req->query('filter'), 'photographer' => (int) $req->query('fotograf'),
            'year' => (int) $req->query('jahr'), 'gallery' => (int) $req->query('galerie'), 'page' => max(1, (int) $req->query('seite')),
        ];
        $r = $this->s->catalog->search($filters);
        $engine = $this->s->library->engine();
        return $this->page('admin/photos', [
            'title' => 'Fotos', 'result' => $r, 'f' => $filters,
            'back' => '/admin/fotos' . (($qs = http_build_query(array_filter(['q' => $filters['q'], 'filter' => $filters['filter'], 'fotograf' => $filters['photographer'], 'jahr' => $filters['year'], 'galerie' => $filters['gallery'], 'seite' => $filters['page'] > 1 ? $filters['page'] : 0]))) !== '' ? '?' . $qs : ''),
            'total' => (int) $this->s->db->value('SELECT COUNT(*) FROM photos WHERE deleted_at IS NULL'),
            'photographers' => $this->s->catalog->photographers(), 'years' => $this->s->catalog->years(), 'galleries' => $this->s->galleries->all(),
            'engine' => $engine === null ? null : $engine->name(),
            'formats' => $engine === null ? [] : $engine->formats(),
            'maxMb' => max(1, $this->config->int('LK_MAX_UPLOAD_MB', 100)),
            'trashCount' => (int) $this->s->db->value('SELECT COUNT(*) FROM photos WHERE deleted_at IS NOT NULL'),
        ], 200, $sess);
    }

    private function purgeExpired(Request $req, ?array $sess): void
    {
        $n = $this->s->catalog->purgeExpired();
        if ($n > 0) {
            $this->auditLog('photos_purged', $sess, 'count=' . $n, $req);
        }
    }

    private function photoPage(int $id, ?array $sess, ?string $error = null, int $status = 200, ?array $draft = null): Response
    {
        $ph = $this->s->catalog->get($id);
        if ($ph === null) {
            return $this->missing($sess);
        }
        return $this->page('admin/photo', [
            'title' => (string) ($ph['title'] !== '' ? $ph['title'] : $ph['original_name']), 'ph' => $ph, 'error' => $error, 'draft' => $draft,
            'photographers' => $this->s->catalog->photographers(), 'inGalleries' => $this->s->galleries->ofPhoto($id), 'galleries' => $this->s->galleries->all(),
        ], $status, $sess);
    }

    public function photo(Request $req, array $p, ?array $sess): Response
    {
        return $this->photoPage((int) $p['id'], $sess);
    }

    public function photoSave(Request $req, array $p, ?array $sess): Response
    {
        $id = (int) $p['id'];
        $fields = [];
        foreach (['title', 'caption', 'alt_text', 'place', 'year', 'photographer_id'] as $k) {
            $fields[$k] = $req->input($k);
        }
        try {
            $this->s->catalog->update($id, $fields);
        } catch (MediaException $e) {
            return $this->photoPage($id, $sess, $e->getMessage(), 400, $fields);
        }
        $this->auditLog('photos_saved', $sess, 'photo=' . $id, $req);
        return $this->done('/admin/fotos/' . $id, 'saved');
    }

    /** Mehrere Fotos: aktion = bearbeiten (Formular zeigen) | speichern | papierkorb | zur_galerie. */
    public function photosBulk(Request $req, array $p, ?array $sess): Response
    {
        $ids = $this->idList($req);
        $back = self::safeNext($req->input('zurueck'));
        $action = $req->input('aktion');
        if ($ids === [] || $this->s->catalog->countExisting($ids) === 0) {
            return $this->done($back === '/admin' ? '/admin/fotos' : $back, 'nothing');
        }
        switch ($action) {
            case 'bearbeiten':
                return $this->bulkForm($ids, $back, $sess);
            case 'speichern':
                $fields = [];
                foreach (['title', 'caption', 'alt_text', 'place', 'year', 'photographer_id'] as $k) {
                    if ($req->input('set_' . $k) === '1') {
                        $fields[$k] = $req->input($k);
                    }
                }
                try {
                    $n = $fields === [] ? 0 : $this->s->catalog->bulkUpdate($ids, $fields);
                    $gal = (int) $req->input('galerie_add');
                    if ($gal > 0) {
                        $n = max($n, $this->s->galleries->addPhotos($gal, $ids));
                    }
                } catch (MediaException $e) {
                    return $this->bulkForm($ids, $back, $sess, $e->getMessage(), 400, $req);
                }
                $this->auditLog('photos_saved', $sess, 'count=' . count($ids), $req);
                return $this->done($back, 'bulk', max($n, 1));
            case 'zur_galerie':
                $gal = (int) $req->input('galerie_add');
                try {
                    $n = $gal > 0 ? $this->s->galleries->addPhotos($gal, $ids) : 0;
                } catch (MediaException) {
                    $n = 0;
                }
                if ($n > 0) {
                    $this->auditLog('gallery_saved', $sess, 'gallery=' . $gal . ',added=' . $n, $req);
                }
                return $this->done($back, $n > 0 ? 'added' : 'nothing', $n);
            case 'papierkorb':
                $n = $this->s->catalog->trash($ids);
                if ($n > 0) {
                    $this->auditLog('photos_trashed', $sess, 'count=' . $n, $req);
                }
                return $this->done($back, 'trashed', $n);
        }
        return $this->done($back, 'nothing');
    }

    /** @param list<int> $ids */
    private function bulkForm(array $ids, string $back, ?array $sess, ?string $error = null, int $status = 200, ?Request $old = null): Response
    {
        return $this->page('admin/photos_bulk', [
            'title' => 'Mehrere Fotos bearbeiten', 'ids' => $ids, 'count' => $this->s->catalog->countExisting($ids), 'back' => $back, 'error' => $error,
            'photographers' => $this->s->catalog->photographers(), 'galleries' => $this->s->galleries->all(), 'old' => $old,
        ], $status, $sess);
    }

    // --- Papierkorb ---

    public function trash(Request $req, array $p, ?array $sess): Response
    {
        $this->purgeExpired($req, $sess);
        return $this->page('admin/trash', ['title' => 'Papierkorb', 'rows' => $this->s->catalog->trashed(), 'days' => Catalog::TRASH_DAYS], 200, $sess);
    }

    public function trashAction(Request $req, array $p, ?array $sess): Response
    {
        $ids = $this->idList($req);
        if ($ids === []) {
            return $this->done('/admin/papierkorb', 'nothing');
        }
        if ($req->input('aktion') === 'loeschen') {
            $n = $this->s->catalog->purge($ids);
            if ($n > 0) {
                $this->auditLog('photos_purged', $sess, 'count=' . $n, $req);
            }
            return $this->done('/admin/papierkorb', 'purged', $n);
        }
        $r = $this->s->catalog->restore($ids);
        if ($r['restored'] > 0) {
            $this->auditLog('photos_restored', $sess, 'count=' . $r['restored'], $req);
        }
        return $this->done('/admin/papierkorb', 'restored', $r['restored']);
    }

    // --- Fotografen ---

    public function photographers(Request $req, array $p, ?array $sess, ?string $error = null, int $status = 200): Response
    {
        return $this->page('admin/photographers', ['title' => 'Fotografen', 'rows' => $this->s->catalog->photographers(), 'error' => $error], $status, $sess);
    }

    public function photographersPost(Request $req, array $p, ?array $sess): Response
    {
        try {
            switch ($req->input('aktion')) {
                case 'neu':
                    $this->s->catalog->addPhotographer($req->input('name'));
                    break;
                case 'umbenennen':
                    $this->s->catalog->renamePhotographer((int) $req->input('id'), $req->input('name'));
                    break;
                case 'loeschen':
                    $this->s->catalog->deletePhotographer((int) $req->input('id'));
                    break;
            }
        } catch (MediaException $e) {
            return $this->photographers($req, [], $sess, $e->getMessage(), 400);
        }
        $this->auditLog('photos_saved', $sess, 'photographers=1', $req);
        return $this->done('/admin/fotografen', 'person');
    }
}
