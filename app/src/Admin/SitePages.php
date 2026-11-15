<?php
declare(strict_types=1);

namespace Lichtkammer\Admin;

use Lichtkammer\Media\MediaException;
use Lichtkammer\Request;
use Lichtkammer\Response;

/** Öffentliche Seite (Schritt 5): einfache Seiten, Einstellungen, Veröffentlichen mit Rückgängig, Vorschau. */
trait SitePages
{
    public const PAGE_TEMPLATES = [
        'ueber-uns' => ['Über uns', "## Über uns\n\nHier stellt ihr euch vor."],
        'fotografen' => ['Fotografen', "## Fotografen\n\nWer hinter den Bildern steht."],
        'impressum' => ['Impressum', "## Angaben nach § 5 DDG\n\nName und Anschrift hier eintragen."],
        'datenschutz' => ['Datenschutz', "## Datenschutzerklärung\n\nText der Datenschutzerklärung hier eintragen."],
    ];

    // --- Seiten ---

    public function pages(Request $req, array $p, ?array $sess, ?string $error = null, int $status = 200): Response
    {
        $have = array_column($this->s->pages->all(), 'slug');
        return $this->page('admin/pages', [
            'title' => 'Seiten', 'pages' => $this->s->pages->all(), 'error' => $error,
            'templates' => array_diff_key(self::PAGE_TEMPLATES, array_flip($have)),
        ], $status, $sess);
    }

    public function pageCreate(Request $req, array $p, ?array $sess): Response
    {
        $tpl = self::PAGE_TEMPLATES[$req->input('vorlage')] ?? null;
        try {
            $id = $this->s->pages->save(null, [
                'title' => $tpl[0] ?? $req->input('title'), 'body' => $tpl[1] ?? '', 'status' => 'draft',
                'nav' => in_array($req->input('vorlage'), ['impressum', 'datenschutz'], true) ? 'footer' : ($tpl !== null ? 'main' : 'none'),
                'slug' => $tpl !== null ? $req->input('vorlage') : '',
            ]);
        } catch (MediaException $e) {
            return $this->pages($req, [], $sess, $e->getMessage(), 400);
        }
        $this->auditLog('page_saved', $sess, 'page=' . $id, $req);
        return $this->done('/admin/seiten/' . $id, 'page');
    }

    private function pageForm(int $id, ?array $sess, ?string $error = null, int $status = 200, ?array $draft = null): Response
    {
        $pg = $this->s->pages->get($id);
        if ($pg === null) {
            return $this->missing($sess);
        }
        return $this->page('admin/page_edit', ['title' => (string) $pg['title'], 'pg' => $draft !== null ? $draft + $pg : $pg, 'error' => $error], $status, $sess);
    }

    public function pageEdit(Request $req, array $p, ?array $sess): Response
    {
        return $this->pageForm((int) $p['id'], $sess);
    }

    public function pageSave(Request $req, array $p, ?array $sess): Response
    {
        $id = (int) $p['id'];
        $f = ['title' => $req->input('title'), 'body' => $req->input('body'), 'status' => $req->input('status'), 'nav' => $req->input('nav'), 'slug' => $req->input('slug')];
        try {
            $this->s->pages->save($id, $f);
        } catch (MediaException $e) {
            return $this->pageForm($id, $sess, $e->getMessage(), 400, $f);
        }
        $this->auditLog('page_saved', $sess, 'page=' . $id, $req);
        return $this->done('/admin/seiten/' . $id, 'page');
    }

    public function pageDelete(Request $req, array $p, ?array $sess): Response
    {
        $this->s->pages->delete((int) $p['id']);
        $this->auditLog('page_deleted', $sess, 'page=' . (int) $p['id'], $req);
        return $this->done('/admin/seiten', 'pagedeleted');
    }

    public function pageMove(Request $req, array $p, ?array $sess): Response
    {
        $this->s->pages->move((int) $p['id'], $req->input('richtung') === 'hoch' ? 'hoch' : 'runter');
        return Response::redirect('/admin/seiten');
    }

    // --- Einstellungen ---

    public function siteSettings(Request $req, array $p, ?array $sess, ?string $error = null): Response
    {
        $heroes = [];
        foreach ($this->s->galleries->all() as $g) {
            foreach ($this->s->galleries->photos((int) $g['id']) as $ph) {
                $heroes[(int) $ph['id']] = ['id' => (int) $ph['id'], 'label' => (string) ($ph['title'] !== '' ? $ph['title'] : $ph['original_name']), 'gallery' => (string) $g['title'], 'thumb' => $ph['thumb']];
            }
        }
        return $this->page('admin/settings', ['title' => 'Einstellungen', 'set' => $this->s->settings->all(), 'heroes' => $heroes, 'error' => $error], 200, $sess);
    }

    public function siteSettingsSave(Request $req, array $p, ?array $sess): Response
    {
        $this->s->settings->save($req->input('site_name'), $req->input('tagline'), (int) $req->input('hero_photo_id'));
        $this->auditLog('settings_saved', $sess, 'settings=site', $req);
        return $this->done('/admin/einstellungen', 'settings');
    }

    // --- Veröffentlichen ---

    private function publishView(?array $sess, ?string $error = null, ?string $ok = null, int $status = 200): Response
    {
        $b = $this->s->builder;
        return $this->page('admin/publish', [
            'title' => 'Veröffentlichen', 'plan' => $b->plan(), 'releases' => $b->releases(), 'current' => $b->current(), 'error' => $error, 'ok' => $ok,
        ], $status, $sess);
    }

    public function publishPage(Request $req, array $p, ?array $sess): Response
    {
        return $this->publishView($sess);
    }

    public function publishPost(Request $req, array $p, ?array $sess): Response
    {
        $res = $this->s->builder->publish((int) $sess['user_id']);
        if (!$res['ok']) {
            return $this->publishView($sess, $res['error'], null, 409);
        }
        $this->auditLog('site_published', $sess, 'release=' . $res['id'], $req);
        return $this->publishView($sess, null, 'Veröffentlicht. Die neue Fassung ist jetzt online.');
    }

    public function publishRollback(Request $req, array $p, ?array $sess): Response
    {
        $id = $req->input('fassung');
        if (!$this->s->builder->rollback($id)) {
            return $this->publishView($sess, 'Diese Fassung kann nicht aktiviert werden.', null, 400);
        }
        $this->auditLog('site_rolled_back', $sess, 'release=' . $id, $req);
        return $this->publishView($sess, null, 'Zurückgesetzt. Die gewählte Fassung ist jetzt online.');
    }

    // --- Vorschau ---

    public function preview(Request $req, array $p, ?array $sess): Response
    {
        $path = '/' . implode('/', array_filter([$p['a'] ?? '', $p['b'] ?? ''], 'strlen'));
        $r = $this->s->builder->renderer(true, '/admin/vorschau');
        $html = $r->route($path);
        $headers = ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'];
        if ($html === null) {
            return Response::html($r->notFound(), 404)->withHeaders($headers);
        }
        return Response::html($html)->withHeaders($headers);
    }
}
