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

    /** „Alle Seiten“: flache Übersicht aller Seiten, ohne Filter (Quelle ist Pages::all()). */
    public function pages(Request $req, array $p, ?array $sess, ?string $error = null, int $status = 200): Response
    {
        $all = $this->s->pages->all();
        usort($all, static fn (array $x, array $y): int => strcasecmp((string) $x['title'], (string) $y['title']));
        return $this->page('admin/pages', ['title' => 'Alle Seiten', 'pages' => $all, 'error' => $error], $status, $sess);
    }

    /** „Neue Seite“: eigener Aufruf mit Titel oder Vorlage. */
    public function pageNew(Request $req, array $p, ?array $sess, ?string $error = null, int $status = 200): Response
    {
        $have = array_column($this->s->pages->all(), 'slug');
        return $this->page('admin/page_new', ['title' => 'Neue Seite', 'error' => $error, 'templates' => array_diff_key(self::PAGE_TEMPLATES, array_flip($have))], $status, $sess);
    }

    /** „Menü & Fußzeile“: Verlinkung und Reihenfolge (Entwurf 14, S5/S6; ausgelagert aus der Seitenliste). */
    public function menu(Request $req, array $p, ?array $sess): Response
    {
        return $this->page('admin/menu', ['title' => 'Menü & Fußzeile', 'pages' => $this->s->pages->all()], 200, $sess);
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
            $this->navPath = '/admin/seiten/neu'; // Fehlerseite ist „Neue Seite“, nicht „Alle Seiten“ (Aktiv-Markierung)
            return $this->pageNew($req, [], $sess, $e->getMessage(), 400);
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
        return $this->page('admin/page_edit', ['title' => (string) $pg['title'], 'pg' => $draft !== null ? $draft + $pg : $pg, 'error' => $error, 'editor' => $editor, 'blocksField' => $blocksField, 'also' => $this->alsoLive('Seite „' . (string) $pg['title'] . '“')], $status, $sess);
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
        $homePhotos = [];
        if ($home) { // markierte Startseiten-Fotos (Schalter in der Galerie), für den Ausschnitt je Foto
            foreach ($this->s->db->all('SELECT p.id, p.title, p.original_name, ' . Galleries::THUMB . " AS thumb FROM photos p WHERE p.on_home = 1 AND p.deleted_at IS NULL AND p.status = 'ready' ORDER BY p.id LIMIT 12") as $row) {
                if ($row['thumb']) {
                    $homePhotos[] = ['id' => (int) $row['id'], 't' => (string) ($row['title'] !== '' ? $row['title'] : $row['original_name']), 'u' => '/media/' . $row['thumb']];
                    $photos[(int) $row['id']] ??= ['t' => (string) $row['title'], 'a' => '', 'c' => '', 'u' => '/media/' . $row['thumb'], 'x' => 0];
                }
            }
        }
        $editor = json_encode(['blocks' => $data['blocks'], 'photos' => (object) $photos, 'home' => $home, 'homePhotos' => $homePhotos], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR);
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
                ['t' => 'hero', 'photo' => $set['hero_photo_id'], 'alt' => '', 'decor' => true, 'title' => $set['tagline'] !== '' ? $set['tagline'] : $set['site_name'], 'show' => $set['hero_show']],
                ['t' => 'galleries', 'title' => 'Galerien', 'limit' => 0],
            ]);
        }
        [$editor, $blocksField] = $this->editorPayload($raw, true);
        return $this->page('admin/home_edit', ['title' => 'Startseite', 'error' => $error, 'editor' => $editor, 'blocksField' => $blocksField, 'custom' => trim($set['home_blocks']) !== '', 'also' => $this->alsoLive('Startseite')], $status, $sess);
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
        return $this->saveThenPublish($req, $sess, '/admin/startseite', 'homesaved');
    }

    /** Verlinkung einer Seite (Menü, Fußzeile, nirgends) auf der Seite „Menü & Fußzeile“. */
    public function pageNav(Request $req, array $p, ?array $sess): Response
    {
        $this->s->pages->setNav((int) $p['id'], $req->input('nav'));
        $this->auditLog('page_saved', $sess, 'page=' . (int) $p['id'], $req);
        return $this->done('/admin/menue', 'pagenav');
    }

    // --- Logos ---

    private function logosView(?array $sess, ?string $error = null, int $status = 200): Response
    {
        $set = $this->s->settings;
        $logos = [];
        foreach ($set->logos() as $l) {
            $l['uri'] = $set->logoUri($l['id']);
            $logos[] = $l;
        }
        return $this->page('admin/logos', ['title' => 'Logos', 'logos' => $logos, 'choice' => $set->logoChoice(), 'roles' => \Lichtkammer\Site\Settings::LOGO_ROLES, 'error' => $error, 'also' => $this->alsoLive()], $status, $sess);
    }

    public function logosPage(Request $req, array $p, ?array $sess): Response
    {
        return $this->logosView($sess);
    }

    public function logoAdd(Request $req, array $p, ?array $sess): Response
    {
        try {
            $this->s->settings->addLogo($req->input('name'), $req->input('daten'));
        } catch (MediaException $e) {
            return $this->logosView($sess, $e->getMessage(), 400);
        }
        $this->auditLog('settings_saved', $sess, 'settings=logo_added', $req);
        return $this->done('/admin/logos', 'logoadded');
    }

    public function logoChoose(Request $req, array $p, ?array $sess): Response
    {
        $this->s->settings->chooseLogos(['light' => $req->input('light'), 'dark' => $req->input('dark'), 'favicon' => $req->input('favicon')]);
        $this->auditLog('settings_saved', $sess, 'settings=logo_chosen', $req);
        return $this->saveThenPublish($req, $sess, '/admin/logos', 'logochosen');
    }

    public function logoDelete(Request $req, array $p, ?array $sess): Response
    {
        $this->s->settings->deleteLogo((string) $p['id']);
        $this->auditLog('settings_saved', $sess, 'settings=logo_deleted', $req);
        return $this->done('/admin/logos', 'logodeleted');
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
        $n = $req->query('n') === '60' ? 60 : 24; // Seitenstreifen „Material“ holt 60 Kacheln, der Dialog 24
        $r = $this->s->catalog->pick($req->query('q'), (int) $req->query('seite'), $n);
        $rows = [];
        foreach ($r['rows'] as $row) {
            if ($row['thumb']) {
                $rows[] = $this->pickRow($row);
            }
        }
        return Response::json(['rows' => $rows, 'more' => $r['more'], 'page' => $r['page']]);
    }

    /** @param array<string,mixed> $row @return array{id:int,t:string,a:string,c:string,u:string} */
    private function pickRow(array $row): array
    {
        return ['id' => (int) $row['id'], 't' => (string) ($row['title'] !== '' ? $row['title'] : $row['original_name']), 'a' => (string) $row['alt_text'], 'c' => (string) ($row['caption'] ?? ''), 'u' => '/media/' . $row['thumb']];
    }

    /** Galerien für den Seitenstreifen „Material“ (JSON): Titel, Zahl der Fotos, Titelbild. Gestaffelt zu 60. */
    public function galleryPick(Request $req, array $p, ?array $sess): Response
    {
        $q = trim(mb_substr($req->query('q'), 0, 100));
        $page = max(1, min(100000, (int) $req->query('seite')));
        $args = [];
        $w = '1=1';
        if ($q !== '') {
            $w = "g.title LIKE :q ESCAPE '\\'";
            $args[':q'] = '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        }
        $rows = $this->s->db->all(
            "SELECT g.id, g.title, (SELECT COUNT(*) FROM gallery_photos gp JOIN photos p ON p.id = gp.photo_id WHERE gp.gallery_id = g.id AND p.deleted_at IS NULL AND p.status = 'ready') AS n FROM galleries g WHERE $w ORDER BY g.sort_order, g.id LIMIT 61 OFFSET " . (($page - 1) * 60),
            $args,
        );
        $more = count($rows) > 60;
        $out = [];
        foreach (array_slice($rows, 0, 60) as $g) {
            $cover = $this->s->galleries->effectiveCover((int) $g['id']);
            $thumb = $cover !== null ? $this->s->db->value('SELECT ' . \Lichtkammer\Media\Galleries::THUMB . ' FROM photos p WHERE p.id = ?', [$cover]) : null;
            $out[] = ['id' => (int) $g['id'], 't' => (string) $g['title'], 'n' => (int) $g['n'], 'u' => $thumb ? '/media/' . $thumb : ''];
        }
        return Response::json(['rows' => $out, 'more' => $more, 'page' => $page]);
    }

    /** Die Fotos einer Galerie (höchstens so viele, wie ein Galerie-Raster fasst) für das Ziehen einer Galerie auf die Seite. */
    public function galleryPickPhotos(Request $req, array $p, ?array $sess): Response
    {
        $id = (int) $p['id'];
        if ($this->s->galleries->get($id) === null) {
            return Response::json(['rows' => [], 'total' => 0], 404);
        }
        $rows = [];
        $total = 0;
        foreach ($this->s->galleries->photos($id) as $row) {
            if ($row['status'] !== 'ready' || !$row['thumb']) {
                continue;
            }
            $total++;
            if (count($rows) < Blocks::MAX_PHOTOS) {
                $rows[] = $this->pickRow($row);
            }
        }
        return Response::json(['rows' => $rows, 'total' => $total]);
    }

    public function pageEdit(Request $req, array $p, ?array $sess): Response
    {
        return $this->pageForm((int) $p['id'], $sess);
    }

    public function pageSave(Request $req, array $p, ?array $sess): Response
    {
        $id = (int) $p['id'];
        $f = ['title' => $req->input('title'), 'body' => $req->input('body'), 'status' => $req->input('status'), 'nav' => $req->input('nav'), 'slug' => $req->input('slug')];
        if ($req->input('titel_form') === '1') { // Häkchen „Seitentitel anzeigen“ (nicht angekreuzt wird gar nicht gesendet)
            $f['show_title'] = $req->input('titel_zeigen') === '1';
        }
        if ($req->input('online') === '1') { // „Speichern und online stellen“ bei einer Entwurfsseite
            $f['status'] = 'published';
        }
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
        $back = $req->input('weiter') === 'liste' ? '/admin/seiten' : '/admin/seiten/' . $id;
        return $this->saveThenPublish($req, $sess, $back, 'page', 'savedpub', $req->input('online') === '1');
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
        return Response::redirect('/admin/menue');
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
        return $this->page('admin/settings', ['title' => 'Einstellungen', 'set' => $this->s->settings->all(), 'heroes' => $heroes, 'error' => $error, 'also' => $this->alsoLive()], 200, $sess);
    }

    public function siteSettingsSave(Request $req, array $p, ?array $sess): Response
    {
        $design = $req->input('design');
        if ($design !== '' && !isset(\Lichtkammer\Site\Settings::DESIGNS[$design])) {
            return $this->siteSettings($req, $p, $sess, 'Dieses Design gibt es nicht. Es wurde nichts gespeichert.');
        }
        $this->s->settings->save($req->input('site_name'), $req->input('tagline'), (int) $req->input('hero_photo_id'), $req->input('hero_form') === '1' ? $req->input('hero_show') === '1' : null);
        if ($design !== '') {
            $this->s->settings->saveDesign($design);
        }
        $this->auditLog('settings_saved', $sess, 'settings=site', $req);
        return $this->saveThenPublish($req, $sess, '/admin/einstellungen', 'settings');
    }

    // --- Veröffentlichen ---

    private function publishView(?array $sess, ?string $error = null, ?string $ok = null, int $status = 200): Response
    {
        $b = $this->s->builder;
        $plan = $b->plan();
        // Zähler bleibt „Speichervorgänge“ (wie im Band); der Probelauf entscheidet nur, ob überhaupt etwas offen ist.
        $open = $plan['changes'] !== [] || $b->current() === null;
        $this->setSiteDirty($open, $open && $this->siteDirty() ? $this->dirtyCount() : 1);
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
        if ($req->input('entwuerfe') === '1') { // ausdrücklich gewählt: alle Entwürfe (Seiten und Galerien) mit veröffentlichen
            $this->s->db->tx(function (): void {
                $now = $this->s->clock->now();
                $this->s->db->run("UPDATE pages SET status = 'published', updated_at = ? WHERE status <> 'published'", [$now]);
                $this->s->db->run("UPDATE galleries SET status = 'published', updated_at = ? WHERE status <> 'published'", [$now]);
            });
            $this->auditLog('settings_saved', $sess, 'settings=drafts_published', $req);
        }
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

    /**
     * Was beim Veröffentlichen außer der eigenen Änderung mit online geht (aus dem Probelauf). Nur wenn es überhaupt noch nicht Veröffentlichtes
     * gibt, damit normale Seitenaufrufe nicht jedes Mal die ganze Seite durchrechnen.
     *
     * @return array{n:int,labels:list<string>,own:bool}
     */
    private function alsoLive(string $ownLabel = ''): array
    {
        if (!$this->siteDirty()) {
            return ['n' => 0, 'labels' => [], 'own' => $ownLabel !== ''];
        }
        try {
            $ch = $this->s->builder->plan()['changes'];
        } catch (\Throwable) {
            return ['n' => 0, 'labels' => [], 'own' => $ownLabel !== ''];
        }
        $labels = [];
        foreach ($ch as $c) {
            if ($ownLabel === '' || $c['label'] !== $ownLabel) {
                $labels[] = $c['label'];
            }
        }
        return ['n' => count($labels), 'labels' => array_slice($labels, 0, 4), 'own' => $ownLabel !== ''];
    }
}
