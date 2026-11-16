<?php
declare(strict_types=1);

namespace Lichtkammer\Admin;

use Lichtkammer\Media\Galleries;
use Lichtkammer\Media\MediaException;
use Lichtkammer\Site\Blocks;
use Lichtkammer\Request;
use Lichtkammer\Response;

/** Öffentliche Seite (Schritt 5): einfache Seiten, Einstellungen, Veröffentlichen mit Rückgängig, Vorschau. */
trait SitePages
{
    public const PAGE_TEMPLATES = [
        'ueber-uns' => ['Über uns', "## Über uns\n\nHier stellt ihr euch vor."],
        'fotografen' => ['Fotografen', "## Fotografen\n\nWer hinter den Bildern steht."],
        'impressum' => ['Impressum', "## Angaben gemäß § 5 Digitale-Dienste-Gesetz (DDG)\n\nVorname Nachname  \nStraße Hausnummer  \nPLZ Ort  \nDeutschland\n\n## Kontakt\n\nE-Mail: name(at)beispiel.de\n\n## Verantwortlich für den Inhalt\n\nVorname Nachname, Anschrift wie oben\n\n## Haftung für Links\n\nDiese Seite enthält Links zu Websites Dritter. Auf deren Inhalte haben wir keinen Einfluss; dafür ist der jeweilige Anbieter verantwortlich. Zum Zeitpunkt der Verlinkung waren keine Rechtsverstöße erkennbar. Werden uns Rechtsverletzungen bekannt, entfernen wir den betreffenden Link umgehend.\n\n## Urheberrecht\n\nAlle Fotos und Texte auf dieser Seite sind urheberrechtlich geschützt. Eine Verwendung ist nur mit ausdrücklicher Erlaubnis der Urheber gestattet."],
        'datenschutz' => ['Datenschutz', "## Kurz gesagt\n\nAuf dieser Seite werden durch uns **keine personenbezogenen Daten erhoben**. Es gibt **keine Cookies**, kein Tracking, keine Statistik- oder Analysewerkzeuge, keine Kontaktformulare, keine Kommentare und keine eingebundenen Inhalte fremder Anbieter. Auch die Schriften werden direkt von diesem Server geladen.\n\n## Verantwortlicher\n\nVorname Nachname, Straße Hausnummer, PLZ Ort, E-Mail: name(at)beispiel.de\n\n## Server-Protokolle des Webhosters\n\nDiese Seite liegt bei ALL-INKL.COM. Wie bei jedem Webserver kann der Hoster beim Aufruf technisch notwendige Angaben vorübergehend in Protokolldateien speichern, zum Beispiel IP-Adresse, Datum und Uhrzeit, aufgerufene Datei, Browser und Betriebssystem. Das dient allein dem sicheren und stabilen Betrieb des Servers. Wir werten diese Daten nicht aus und führen sie nicht mit anderen Daten zusammen. Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse am sicheren Betrieb).\n\n## Einstellungen im Browser\n\nWenn du das Farbschema (hell/dunkel) umschaltest, merkt sich dein Browser diese Wahl im lokalen Speicher (localStorage) deines Geräts. Das sind keine Cookies; die Angaben werden nicht an den Server übertragen. Löschen kannst du sie jederzeit über die Einstellungen deines Browsers.\n\n## Links zu anderen Seiten\n\nKlickst du auf einen Link zu einer anderen Website, verlässt du diese Seite. Dort gelten die Datenschutzbestimmungen des jeweiligen Anbieters.\n\n## Deine Rechte\n\nDu hast nach der DSGVO das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit und Widerspruch. Wende dich dafür an die oben genannte E-Mail-Adresse. Außerdem kannst du dich bei einer Datenschutz-Aufsichtsbehörde beschweren.\n\nStand: Monat Jahr"],
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
        return $this->done('/admin/seiten/' . $id, 'pagecreated');
    }

    private function pageForm(int $id, ?array $sess, ?string $error = null, int $status = 200, ?array $draft = null): Response
    {
        $pg = $this->s->pages->get($id);
        if ($pg === null) {
            return $this->missing($sess);
        }
        $raw = isset($draft['blocks']) && $draft['blocks'] !== '' ? (string) $draft['blocks']
            : ($pg['blocks'] !== '' ? (string) $pg['blocks'] : Blocks::encode(Blocks::fromMarkdown((string) $pg['body'])));
        [$editor, $blocksField] = $this->editorPayload($raw, false);
        return $this->page('admin/page_edit', ['title' => (string) $pg['title'], 'pg' => $draft !== null ? $draft + $pg : $pg, 'error' => $error, 'editor' => $editor, 'blocksField' => $blocksField], $status, $sess);
    }

    /**
     * Daten für den Editor (Blöcke samt Vorschaubildern der Fotos) und der Inhalt des versteckten Feldes.
     *
     * @return array{0:string,1:string} [Editor-JSON, Block-JSON]
     */
    private function editorPayload(string $raw, bool $home): array
    {
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['blocks']) || !is_array($data['blocks'])) {
            $data = ['blocks' => []];
        }
        $ids = [];
        array_walk_recursive($data, static function ($v, $k) use (&$ids): void {
            if ($k === 'photo' && (is_int($v) || (is_string($v) && ctype_digit($v) && strlen($v) < 10)) && (int) $v > 0) {
                $ids[(int) $v] = (int) $v;
            }
        });
        $photos = [];
        foreach ($ids as $pid) {
            $row = $this->s->db->one('SELECT p.id, p.title, p.alt_text, p.caption, p.deleted_at, ' . Galleries::THUMB . ' AS thumb FROM photos p WHERE p.id = ?', [$pid]);
            $photos[$pid] = $row === null || $row['deleted_at'] !== null
                ? ['t' => '', 'a' => '', 'c' => '', 'u' => '', 'x' => 1]
                : ['t' => (string) $row['title'], 'a' => (string) $row['alt_text'], 'c' => (string) $row['caption'], 'u' => $row['thumb'] ? '/media/' . $row['thumb'] : '', 'x' => 0];
        }
        $editor = json_encode(['blocks' => $data['blocks'], 'photos' => (object) $photos, 'home' => $home], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return [(string) $editor, Blocks::encode($data['blocks'])];
    }

    // --- Startseite aus Blöcken (Entwurf 14) ---

    private function homeForm(?array $sess, ?string $error = null, int $status = 200, ?string $draft = null): Response
    {
        $set = $this->s->settings->all();
        if ($draft !== null && $draft !== '') {
            $raw = $draft;
        } elseif (trim($set['home_blocks']) !== '') {
            $raw = $set['home_blocks'];
        } else { // erstes Öffnen: so vorbefüllt, dass sie wie die bisherige feste Startseite aussieht
            $raw = Blocks::encode([
                ['t' => 'hero', 'photo' => $set['hero_photo_id'], 'alt' => '', 'decor' => true, 'title' => $set['tagline'] !== '' ? $set['tagline'] : $set['site_name']],
                ['t' => 'galleries', 'title' => 'Galerien', 'limit' => 0],
            ]);
        }
        [$editor, $blocksField] = $this->editorPayload($raw, true);
        return $this->page('admin/home_edit', ['title' => 'Startseite', 'error' => $error, 'editor' => $editor, 'blocksField' => $blocksField, 'custom' => trim($set['home_blocks']) !== ''], $status, $sess);
    }

    public function homeEdit(Request $req, array $p, ?array $sess): Response
    {
        return $this->homeForm($sess);
    }

    public function homeSave(Request $req, array $p, ?array $sess): Response
    {
        if ($req->input('zuruecksetzen') === '1') {
            $this->s->settings->saveHome('');
            $this->auditLog('settings_saved', $sess, 'settings=home_reset', $req);
            return $this->done('/admin/startseite', 'homereset');
        }
        $raw = $req->input('blocks');
        try {
            $blocks = Blocks::parse($raw, $this->s->db, true);
            if ($blocks === []) {
                throw new MediaException('Die Startseite ist leer. Bitte mindestens einen Block füllen, oder unten auf die feste Startseite zurückstellen.');
            }
            $this->s->settings->saveHome(Blocks::encode($blocks));
        } catch (MediaException $e) {
            return $this->homeForm($sess, $e->getMessage(), 400, $raw);
        }
        $this->auditLog('settings_saved', $sess, 'settings=home', $req);
        return $this->done('/admin/startseite', 'homesaved');
    }

    /** Verlinkung einer Seite (Menü, Fußzeile, nirgends) direkt in der Liste. */
    public function pageNav(Request $req, array $p, ?array $sess): Response
    {
        $this->s->pages->setNav((int) $p['id'], $req->input('nav'));
        $this->auditLog('page_saved', $sess, 'page=' . (int) $p['id'], $req);
        return $this->done('/admin/seiten', 'pagenav');
    }

    // --- Sammelzuordnung nach Galerie (Fotograf und Alt-Text) ---

    private function assignView(?array $sess, string $rules, bool $alt, bool $over, ?string $error = null, ?array $plan = null, ?string $ok = null, int $status = 200): Response
    {
        return $this->page('admin/assign', ['title' => 'Zuordnung nach Galerie', 'rules' => $rules, 'setAlt' => $alt, 'overwriteAlt' => $over, 'error' => $error, 'plan' => $plan, 'ok' => $ok], $status, $sess);
    }

    public function assignPage(Request $req, array $p, ?array $sess): Response
    {
        return $this->assignView($sess, '', true, false);
    }

    public function assignPost(Request $req, array $p, ?array $sess): Response
    {
        $rules = $req->input('regeln');
        $alt = $req->input('alt') === '1';
        $over = $req->input('alt_ueberschreiben') === '1';
        $assign = new \Lichtkammer\Media\Assign($this->s->db, $this->s->catalog);
        if ($req->input('aktion') !== 'anwenden') {
            return $this->assignView($sess, $rules, $alt, $over, null, $assign->plan($rules));
        }
        try {
            $r = $assign->apply($rules, true, $alt, $over);
        } catch (MediaException $e) {
            return $this->assignView($sess, $rules, $alt, $over, $e->getMessage(), null, null, 400);
        }
        $this->auditLog('photos_saved', $sess, 'assign=gallery,n=' . $r['photographers'], $req);
        return $this->assignView($sess, $rules, $alt, $over, null, null, 'Fertig: Fotograf bei ' . $r['photographers'] . ' Foto(s) gesetzt, Alt-Text bei ' . $r['alts'] . ' Foto(s), ' . $r['created'] . ' Fotograf(en) neu angelegt.');
    }

    /** Fotoauswahl für den Seiten-Editor (JSON). */
    public function photoPick(Request $req, array $p, ?array $sess): Response
    {
        $r = $this->s->catalog->pick($req->query('q'), (int) $req->query('seite'));
        $rows = [];
        foreach ($r['rows'] as $row) {
            if ($row['thumb']) {
                $rows[] = ['id' => (int) $row['id'], 't' => (string) ($row['title'] !== '' ? $row['title'] : $row['original_name']), 'a' => (string) $row['alt_text'], 'c' => (string) $row['caption'], 'u' => '/media/' . $row['thumb']];
            }
        }
        return Response::json(['rows' => $rows, 'more' => $r['more'], 'page' => $r['page']]);
    }

    public function pageEdit(Request $req, array $p, ?array $sess): Response
    {
        return $this->pageForm((int) $p['id'], $sess);
    }

    public function pageSave(Request $req, array $p, ?array $sess): Response
    {
        $id = (int) $p['id'];
        $f = ['title' => $req->input('title'), 'body' => $req->input('body'), 'status' => $req->input('status'), 'nav' => $req->input('nav'), 'slug' => $req->input('slug')];
        $raw = $req->input('blocks');
        if ($raw !== '') {
            $f['blocks'] = $raw;
            if ($req->input('modus') === 'text') {
                try {
                    $md = Blocks::toMarkdown(Blocks::parse($raw, $this->s->db));
                } catch (MediaException $e) {
                    return $this->pageForm($id, $sess, $e->getMessage(), 400, $f);
                }
                if ($md === null) {
                    return $this->pageForm($id, $sess, 'Diese Seite enthält Bilder, Knöpfe oder Spalten und lässt sich nicht als einfacher Text speichern.', 400, $f);
                }
                $f['body'] = $md;
                $f['blocks'] = '';
            }
        }
        try {
            $this->s->pages->save($id, $f);
        } catch (MediaException $e) {
            return $this->pageForm($id, $sess, $e->getMessage(), 400, $raw !== '' ? ['blocks' => $raw] + $f : $f);
        }
        $this->auditLog('page_saved', $sess, 'page=' . $id, $req);
        return $this->done($req->input('weiter') === 'liste' ? '/admin/seiten' : '/admin/seiten/' . $id, 'page');
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
        $plan = $b->plan();
        $this->setSiteDirty($plan['changes'] !== [] || $b->current() === null);
        return $this->page('admin/publish', [
            'title' => 'Veröffentlichen', 'plan' => $plan, 'releases' => $b->releases(), 'current' => $b->current(), 'error' => $error, 'ok' => $ok,
            'drafts' => $this->s->db->all("SELECT id, title FROM pages WHERE status <> 'published' ORDER BY title"),
            'draftGalleries' => $this->s->db->all("SELECT id, title FROM galleries WHERE status <> 'published' ORDER BY title"),
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
