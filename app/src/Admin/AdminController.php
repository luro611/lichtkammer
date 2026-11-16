<?php
declare(strict_types=1);

namespace Lichtkammer\Admin;

use Lichtkammer\Auth\Auth;
use Lichtkammer\Auth\ClientIp;
use Lichtkammer\Auth\Preflight;
use Lichtkammer\Auth\Qr;
use Lichtkammer\Auth\Services;
use Lichtkammer\Auth\Totp;
use Lichtkammer\Config;
use Lichtkammer\Media\MediaException;
use Lichtkammer\Media\Uploads;
use Lichtkammer\Request;
use Lichtkammer\Response;
use Lichtkammer\Router;
use Lichtkammer\View;

/**
 * Alle Wege unter /admin/. Zugriff ist verboten, solange er nicht ausdrücklich erlaubt ist:
 * nur die Routen mit public=true (Anmeldung, Einrichtung, Einladung, Rücksetzen) brauchen keine volle Sitzung.
 */
final class AdminController
{
    use MediaPages;
    use SitePages;
    use ImportPages;

    private readonly Router $router;

    public function __construct(private readonly Services $s, private readonly View $view, private readonly Config $config)
    {
        $this->router = new Router();
        $r = fn (string $m, string $path, string $handler, bool $public = false, ?string $role = null) => $this->add($m, $path, $handler, $public, $role);
        // Ausgenommen von der Sitzungsprüfung (feste Liste):
        // Unbekannte Adressen unter /admin: mit Sitzung die normale Fehlerseite im Verwaltungsrahmen, sonst schlichtes 404.
        // Die Sitzung wird dabei nie angefasst (kein Abmelden bei 404).
        $this->router->fallback(function (Request $req): Response {
            $sess = $this->transportOk($req) ? $this->fullSession($req) : null;
            if ($sess === null) {
                return Response::notFound();
            }
            $this->flash = null;
            $this->navPath = $req->path();
            return $this->missing($sess, 'Seite nicht gefunden');
        });
        $r('GET', '/admin/login', 'loginForm', true);
        $r('POST', '/admin/login', 'loginPost', true);
        $r('GET', '/admin/login/totp', 'totpForm', true);
        $r('POST', '/admin/login/totp', 'totpPost', true);
        $r('GET', '/admin/setup', 'setupForm', true);
        $r('POST', '/admin/setup', 'setupPost', true);
        $r('GET', '/admin/enroll', 'enrollForm', true);
        $r('POST', '/admin/enroll', 'enrollPost', true);
        $r('GET', '/admin/invite/{token}', 'inviteForm', true);
        $r('POST', '/admin/invite/{token}', 'invitePost', true);
        $r('GET', '/admin/reset', 'resetForm', true);
        $r('POST', '/admin/reset', 'resetPost', true);
        // Alles andere braucht eine volle Sitzung:
        $r('GET', '/admin', 'dashboard');
        $r('POST', '/admin/logout', 'logout');
        $r('GET', '/admin/account', 'account');
        $r('POST', '/admin/account/password', 'accountPassword');
        $r('POST', '/admin/account/codes', 'accountCodes');
        $r('GET', '/admin/log', 'log');
        $r('GET', '/admin/fotos', 'library');
        $r('POST', '/admin/fotos/mehrfach', 'photosBulk');
        $r('GET', '/admin/fotos/auswahl', 'photoPick');
        $r('GET', '/admin/fotos/{id}', 'photo');
        $r('POST', '/admin/fotos/{id}/speichern', 'photoSave');
        $r('GET', '/admin/galerien/zuordnung', 'assignPage');
        $r('POST', '/admin/galerien/zuordnung', 'assignPost');
        $r('GET', '/admin/galerien', 'galleries');
        $r('POST', '/admin/galerien', 'galleryCreate');
        $r('POST', '/admin/galerien/reihenfolge', 'galleriesOrder');
        $r('GET', '/admin/galerien/{id}', 'gallery');
        $r('POST', '/admin/galerien/{id}/bewegen', 'galleriesMove');
        $r('POST', '/admin/galerien/{id}/speichern', 'gallerySave');
        $r('POST', '/admin/galerien/{id}/status', 'galleryStatus');
        $r('POST', '/admin/galerien/{id}/loeschen', 'galleryDelete');
        $r('GET', '/admin/galerien/{id}/hinzufuegen', 'galleryAddForm');
        $r('POST', '/admin/galerien/{id}/hinzufuegen', 'galleryAdd');
        $r('POST', '/admin/galerien/{id}/entfernen', 'galleryRemove');
        $r('POST', '/admin/galerien/{id}/reihenfolge', 'galleryOrder');
        $r('POST', '/admin/galerien/{id}/foto-bewegen', 'galleryPhotoMove');
        $r('POST', '/admin/galerien/{id}/sortieren', 'gallerySort');
        $r('POST', '/admin/galerien/{id}/titelbild', 'galleryCover');
        $r('GET', '/admin/seiten', 'pages');
        $r('POST', '/admin/seiten', 'pageCreate');
        $r('GET', '/admin/seiten/{id}', 'pageEdit');
        $r('POST', '/admin/seiten/{id}/speichern', 'pageSave');
        $r('POST', '/admin/seiten/{id}/loeschen', 'pageDelete');
        $r('POST', '/admin/seiten/{id}/bewegen', 'pageMove');
        $r('POST', '/admin/seiten/{id}/verlinken', 'pageNav');
        $r('GET', '/admin/startseite', 'homeEdit');
        $r('POST', '/admin/startseite', 'homeSave');
        $r('GET', '/admin/einstellungen', 'siteSettings');
        $r('POST', '/admin/einstellungen', 'siteSettingsSave');
        $r('GET', '/admin/veroeffentlichen', 'publishPage');
        $r('POST', '/admin/veroeffentlichen', 'publishPost');
        $r('POST', '/admin/veroeffentlichen/zuruecksetzen', 'publishRollback');
        $r('GET', '/admin/vorschau', 'preview');
        $r('GET', '/admin/vorschau/{a}', 'preview');
        $r('GET', '/admin/vorschau/{a}/{b}', 'preview');
        $r('GET', '/admin/papierkorb', 'trash');
        $r('POST', '/admin/papierkorb', 'trashAction');
        $r('GET', '/admin/fotografen', 'photographers');
        $r('POST', '/admin/fotografen', 'photographersPost');
        $r('POST', '/admin/upload/start', 'uploadStart');
        $r('POST', '/admin/upload/{id}/chunk', 'uploadChunk');
        $r('POST', '/admin/upload/{id}/finish', 'uploadFinish');
        $r('POST', '/admin/fotos/{id}/verarbeiten', 'photoProcess');
        $r('GET', '/admin/import', 'importPage', false, 'admin');
        $r('POST', '/admin/import/schritt', 'importStep', false, 'admin');
        $r('POST', '/admin/import/abschluss', 'importFinish', false, 'admin');
        $r('POST', '/admin/import/loeschen', 'importPurge', false, 'admin');
        $r('GET', '/admin/users', 'users', false, 'admin');
        $r('POST', '/admin/users/invite', 'usersInvite', false, 'admin');
        $r('POST', '/admin/users/{id}/{action}', 'usersAction', false, 'admin');
    }

    private function add(string $method, string $path, string $handler, bool $public, ?string $role): void
    {
        $wrapped = function (Request $req, array $params) use ($handler, $public, $role): Response {
            if (!$this->transportOk($req)) {
                return $this->page('admin/message', ['title' => 'Nur über HTTPS', 'heading' => 'Nur über HTTPS',
                    'text' => 'Die Seite wurde nicht über eine verschlüsselte Verbindung aufgerufen, obwohl LK_BASE_URL https:// verlangt. Bitte die Adresse mit https:// öffnen. Hinter einem Reverse-Proxy muss LK_TRUST_PROXY=1 gesetzt sein.'], 400);
            }
            $sess = $this->fullSession($req);
            if (!$public) {
                if ($sess === null) {
                    $next = $req->method() === 'GET' ? '?next=' . rawurlencode($req->path()) : '';
                    return Response::redirect('/admin/login' . $next);
                }
                if ($role === 'admin' && $sess['role'] !== 'admin') {
                    return $this->page('admin/message', ['title' => 'Kein Zugriff', 'heading' => 'Kein Zugriff', 'text' => 'Dafür fehlt dir die Berechtigung.'], 403, $sess);
                }
                if ($req->method() === 'POST' && !hash_equals((string) $sess['csrf_token'], $req->input('_csrf') ?: $req->header('X-CSRF-Token'))) {
                    return $this->page('admin/message', ['title' => 'Abgelehnt', 'heading' => 'Anfrage abgelehnt', 'text' => 'Die Seite war zu lange offen oder die Anfrage kam nicht von dieser Seite. Bitte neu laden und noch einmal versuchen.'], 403, $sess);
                }
            }
            $this->flash = $this->flashFrom($req);
            $this->navPath = $req->path();
            $res = $this->{$handler}($req, $params, $sess);
            if ($req->method() === 'POST' && $res->status < 400 && preg_match('~^/admin/(fotos|galerien|seiten|startseite|einstellungen|fotografen|papierkorb|import)(/|$)~', $req->path()) === 1) {
                $this->setSiteDirty(true); // Inhalt kann sich geändert haben; genau wird es beim Öffnen von „Veröffentlichen“ geprüft
            }
            return $res;
        };
        $method === 'GET' ? $this->router->get($path, $wrapped) : $this->router->post($path, $wrapped);
    }

    public function handle(Request $request): Response
    {
        return $this->router->dispatch($request);
    }

    // --- Hilfen ---

    /** Gibt es Änderungen, die noch nicht veröffentlicht sind? Unbekannt (noch nie geprüft) zählt als ja. */
    private function siteDirty(): bool
    {
        return (string) ($this->s->db->value("SELECT v FROM meta WHERE k = 'site_dirty'") ?? '1') !== '0';
    }

    private function setSiteDirty(bool $dirty): void
    {
        $this->s->db->run("INSERT INTO meta(k, v) VALUES('site_dirty', ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v", [$dirty ? '1' : '0']);
    }

    private function transportOk(Request $req): bool
    {
        return !$this->s->cookies->secure() || $req->isHttps();
    }

    /** @return array<string,mixed>|null volle Sitzung samt Konto */
    private function fullSession(Request $req): ?array
    {
        $raw = $req->cookie($this->s->cookies->name('lk_sid'));
        $row = $raw === '' ? null : $this->s->sessions->find($raw);
        return ($row !== null && $row['stage'] === 'full') ? $row : null;
    }

    /** @return array<string,mixed>|null Sitzung nach dem Passwort (Stufe password_ok) */
    private function passwordSession(Request $req): ?array
    {
        $raw = $req->cookie($this->s->cookies->name('lk_sid'));
        $row = $raw === '' ? null : $this->s->sessions->find($raw);
        return ($row !== null && $row['stage'] === 'password_ok') ? $row : null;
    }

    private function ipPrefix(Request $req): string
    {
        return ClientIp::forms($req->remoteAddr())['prefix'];
    }

    /** Nur lokale Pfade unter /admin (kein //, kein \, kein Schema). */
    public static function safeNext(string $next): string
    {
        if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, '\\')
            || preg_match('/[\x00-\x1f]/', $next) === 1 || !preg_match('#^/admin(/|$|\?)#', $next) || strlen($next) > 200) {
            return '/admin';
        }
        return $next;
    }

    /** @return list<string> */
    private function banners(?array $sess): array
    {
        if ($sess === null) {
            return []; // Hinweise zur Konfiguration nur für Angemeldete
        }
        $b = Preflight::warnings($this->config, $this->s->dataDir);
        $b = array_merge($b, $this->s->enrollment->tokenWarnings());
        if ($sess['role'] === 'admin') {
            $recent = $this->s->db->value("SELECT 1 FROM audit_log WHERE event = 'emergency_reset' AND at > ?", [$this->s->clock->now() - 7 * 86400]);
            if ($recent !== null) {
                $b[] = 'Ein Konto wurde in den letzten 7 Tagen über das Notfall-Token zurückgesetzt. Bitte im Protokoll prüfen, ob du das warst.';
            }
        }
        return $b;
    }

    /** @param array<string,mixed> $data @param array<string,mixed>|null $sess */
    private function page(string $template, array $data, int $status = 200, ?array $sess = null): Response
    {
        $data += ['siteName' => $this->s->settings->all()['site_name'], 'publishDirty' => $sess !== null && $this->siteDirty(), 'flash' => $this->flash, 'banners' => $this->banners($sess), 'user' => $sess, 'navPath' => $this->navPath, 'csrf' => $sess['csrf_token'] ?? ''];
        return Response::html($this->view->render($template, $data), $status)->withHeaders([
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /** Formular vor der Anmeldung: Cookie lk_pre (Zufallswert r) und gebundenes Token. @return array{token:string,cookie:?string} */
    private function preForm(Request $req): array
    {
        $c = $this->s->cookies;
        $r = $req->cookie($c->name('lk_pre'));
        $cookie = null;
        if (!$this->s->preCsrf::validR($r)) {
            $r = $this->s->preCsrf->newR();
            $cookie = $c->set('lk_pre', $r, 7200);
        }
        return ['token' => $this->s->preCsrf->token($r), 'cookie' => $cookie];
    }

    /** @param array<string,mixed> $data */
    private function prePage(Request $req, string $template, array $data, int $status = 200): Response
    {
        $pre = $this->preForm($req);
        $resp = $this->page($template, $data + ['preToken' => $pre['token']], $status);
        return $pre['cookie'] !== null ? $resp->withCookie($pre['cookie']) : $resp;
    }

    private function preOk(Request $req): bool
    {
        return $this->s->preCsrf->check($req->cookie($this->s->cookies->name('lk_pre')), $req->input('_csrf'));
    }

    private const PRE_EXPIRED = 'Die Seite war zu lange geöffnet oder die Anfrage passte nicht dazu. Bitte noch einmal absenden.';

    private function sessionCookie(string $raw): string
    {
        return $this->s->cookies->set('lk_sid', $raw);
    }

    // --- Anmeldung ---

    public function loginForm(Request $req, array $p, ?array $sess): Response
    {
        if ($sess !== null) {
            return Response::redirect('/admin');
        }
        return $this->prePage($req, 'admin/login', ['title' => 'Anmelden', 'error' => null, 'username' => '', 'next' => self::safeNext($req->query('next'))]);
    }

    public function loginPost(Request $req, array $p, ?array $sess): Response
    {
        $next = self::safeNext($req->input('next'));
        $show = fn (string $err, int $status, string $name = ''): Response => $this->prePage($req, 'admin/login', ['title' => 'Anmelden', 'error' => $err, 'username' => $name, 'next' => $next], $status);
        if (!$this->preOk($req)) {
            return $show(self::PRE_EXPIRED, 400);
        }
        $res = $this->s->auth->login($req, $req->input('username'), $req->input('password'));
        if ($res['status'] === 'blocked') {
            return $show('Die Anmeldung ist gerade nicht möglich. Bitte in ein paar Minuten noch einmal versuchen.', 429);
        }
        if ($res['status'] !== 'ok') {
            return $show('Anmeldung nicht möglich. Bitte Angaben prüfen.', 401);
        }
        // Ein mitgeschicktes altes Cookie wird nie übernommen: seine Zeile wird gelöscht.
        $old = $req->cookie($this->s->cookies->name('lk_sid'));
        if ($old !== '') {
            $this->s->sessions->destroy($old);
        }
        return Response::redirect('/admin/login/totp?next=' . rawurlencode($next))->withCookie($this->sessionCookie((string) $res['sid']));
    }

    public function totpForm(Request $req, array $p, ?array $sess): Response
    {
        if ($sess !== null) {
            return Response::redirect('/admin');
        }
        $ps = $this->passwordSession($req);
        if ($ps === null) {
            return Response::redirect('/admin/login');
        }
        return $this->page('admin/totp', ['title' => 'Zweiter Schritt', 'error' => null, 'next' => self::safeNext($req->query('next')), 'csrf' => $ps['csrf_token']]);
    }

    public function totpPost(Request $req, array $p, ?array $sess): Response
    {
        $ps = $this->passwordSession($req);
        $sidName = $this->s->cookies->name('lk_sid');
        $clearSid = fn (Response $r): Response => $req->cookie($sidName) !== '' ? $r->withCookie($this->s->cookies->clear('lk_sid')) : $r;
        if ($ps === null) {
            return $clearSid(Response::redirect('/admin/login')); // ohne Cookie nichts löschen (fremdes POST könnte sonst abmelden)
        }
        $next = self::safeNext($req->input('next'));
        $show = fn (string $err, int $status): Response => $this->page('admin/totp', ['title' => 'Zweiter Schritt', 'error' => $err, 'next' => $next, 'csrf' => $ps['csrf_token']], $status);
        if (!hash_equals((string) $ps['csrf_token'], $req->input('_csrf'))) {
            return $show(self::PRE_EXPIRED, 400);
        }
        $res = $this->s->auth->secondFactor($req, $req->cookie($sidName), $req->input('code'));
        return match ($res['status']) {
            'ok' => Response::redirect($res['recovery'] ? '/admin/account?neu=1' : $next)->withCookie($this->sessionCookie((string) $res['sid'])),
            'blocked' => $show('Die Anmeldung ist gerade nicht möglich. Bitte in ein paar Minuten noch einmal versuchen.', 429),
            'expired' => $clearSid(Response::redirect('/admin/login')),
            default => $show('Der Code stimmt nicht. Bitte noch einmal versuchen.', 401),
        };
    }

    public function logout(Request $req, array $p, ?array $sess): Response
    {
        $this->s->auth->logout($req->cookie($this->s->cookies->name('lk_sid')), (int) $sess['user_id'], $this->ipPrefix($req));
        return Response::redirect('/admin/login')
            ->withCookie($this->s->cookies->clear('lk_sid'))
            ->withHeaders(['Clear-Site-Data' => '"cookies"']);
    }

    // --- Fotos (Schritt 3): Übersicht, Upload in Stücken, Verarbeitung ---

    public function uploadStart(Request $req, array $p, ?array $sess): Response
    {
        try {
            $gallery = ctype_digit($req->input('gallery')) ? (int) $req->input('gallery') : null;
            $id = $this->s->uploads->start((int) $sess['user_id'], $req->input('name'), (int) $req->input('size'), $gallery);
            return Response::json(['id' => $id, 'chunk' => 2 * 1024 * 1024]);
        } catch (MediaException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    }

    public function uploadChunk(Request $req, array $p, ?array $sess): Response
    {
        $body = $req->body(Uploads::MAX_CHUNK);
        try {
            $r = $this->s->uploads->chunk((int) $sess['user_id'], (string) $p['id'], (int) $req->query('offset'), $body);
            return Response::json($r);
        } catch (MediaException $e) {
            if ($e->getCode() === 409) {
                return Response::json(['error' => 'Versatz passt nicht', 'received' => (int) $e->getMessage()], 409);
            }
            return Response::json(['error' => $e->getMessage()], $e->getCode() === 404 ? 404 : 400);
        }
    }

    public function uploadFinish(Request $req, array $p, ?array $sess): Response
    {
        try {
            $r = $this->s->uploads->finish((int) $sess['user_id'], (string) $p['id']);
        } catch (MediaException $e) {
            return Response::json(['error' => $e->getMessage()], $e->getCode() === 404 ? 404 : 400);
        }
        if (!$r['duplicate']) {
            $this->s->audit->log('photo_uploaded', (int) $sess['user_id'], 'photo=' . $r['photo_id'], $this->ipPrefix($req));
        }
        return Response::json($r);
    }

    public function photoProcess(Request $req, array $p, ?array $sess): Response
    {
        try {
            $r = $this->s->library->process((int) $p['id']);
        } catch (MediaException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }
        return Response::json($r);
    }

    // --- Ersteinrichtung ---

    public function setupForm(Request $req, array $p, ?array $sess): Response
    {
        if (!$this->s->enrollment->setupOpen()) {
            return Response::notFound();
        }
        return $this->prePage($req, 'admin/setup', ['title' => 'Einrichtung', 'error' => null, 'username' => '']);
    }

    public function setupPost(Request $req, array $p, ?array $sess): Response
    {
        if (!$this->s->enrollment->setupOpen()) {
            return Response::notFound();
        }
        if (!$this->preOk($req)) {
            return $this->prePage($req, 'admin/setup', ['title' => 'Einrichtung', 'error' => self::PRE_EXPIRED, 'username' => ''], 400);
        }
        $res = $this->s->enrollment->startSetup($req, $req->input('token'), $req->input('username'), $req->input('password'));
        if ($res['enr'] === null) {
            return $this->prePage($req, 'admin/setup', ['title' => 'Einrichtung', 'error' => $res['error'], 'username' => $req->input('username')], 400);
        }
        return Response::redirect('/admin/enroll')->withCookie($this->s->cookies->set('lk_enr', $res['enr'], 900));
    }

    // --- Gemeinsame TOTP-Einrichtung (Ersteinrichtung, Einladung, Rücksetzung) ---

    private function pendingFrom(Request $req): ?array
    {
        $raw = $req->cookie($this->s->cookies->name('lk_enr'));
        return $raw === '' ? null : $this->s->enrollment->pending($raw);
    }

    /** @param array<string,mixed> $pending */
    private function enrollPage(array $pending, ?string $error, int $status = 200): Response
    {
        $secret = $this->s->enrollment->pendingSecret($pending);
        if ($secret === null) {
            return Response::notFound();
        }
        $uri = Totp::uri($secret, (string) $pending['username'], $this->config->siteName());
        return $this->page('admin/enroll', [
            'title' => 'Zweiten Faktor einrichten',
            'error' => $error,
            'qr' => Qr::svg($uri, 'QR-Code für die Authenticator-App'),
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'serverTime' => gmdate('Y-m-d H:i:s', $this->s->clock->now()) . ' UTC',
            'csrf' => $pending['csrf_token'],
            'username' => $pending['username'],
        ], $status);
    }

    public function enrollForm(Request $req, array $p, ?array $sess): Response
    {
        $pending = $this->pendingFrom($req);
        return $pending === null ? Response::notFound() : $this->enrollPage($pending, null);
    }

    public function enrollPost(Request $req, array $p, ?array $sess): Response
    {
        $pending = $this->pendingFrom($req);
        if ($pending === null) {
            return Response::notFound();
        }
        if (!hash_equals((string) $pending['csrf_token'], $req->input('_csrf'))) {
            return $this->enrollPage($pending, self::PRE_EXPIRED, 400);
        }
        $res = $this->s->enrollment->confirm($req, $req->cookie($this->s->cookies->name('lk_enr')), $req->input('code'));
        switch ($res['status']) {
            case 'wrong':
                return $this->enrollPage($pending, 'Der Code stimmt nicht. Stimmt die Uhrzeit auf dem Handy? Die Serverzeit steht unten.', 401);
            case 'gone':
                return Response::notFound()->withCookie($this->s->cookies->clear('lk_enr'));
            case 'ok':
                $sessionRow = $this->s->sessions->find((string) $res['sid']);
                return $this->page('admin/codes', [
                    'title' => 'Wiederherstellungscodes',
                    'codes' => $res['codes'],
                    'setup' => $pending['purpose'] === 'setup',
                ], 200, $sessionRow)
                    ->withCookie($this->sessionCookie((string) $res['sid']))
                    ->withCookie($this->s->cookies->clear('lk_enr'));
            default:
                $text = $res['status'] === 'taken'
                    ? 'Der Benutzername ist inzwischen vergeben. Der Link gilt weiter: bitte noch einmal mit einem anderen Namen versuchen.'
                    : 'Dieser Link ist nicht mehr gültig.';
                return $this->page('admin/message', ['title' => 'Nicht möglich', 'heading' => 'Nicht möglich', 'text' => $text], 400)
                    ->withCookie($this->s->cookies->clear('lk_enr'));
        }
    }

    // --- Einladung / Rücksetz-Link ---

    private function invalidInvite(): Response
    {
        return $this->page('admin/message', ['title' => 'Link ungültig', 'heading' => 'Link ungültig', 'text' => 'Dieser Link ist ungültig, abgelaufen oder schon benutzt. Bitte beim Administrator einen neuen anfordern.'], 200);
    }

    public function inviteForm(Request $req, array $p, ?array $sess): Response
    {
        $inv = $this->s->enrollment->invite((string) ($p['token'] ?? ''));
        if ($inv === null) {
            return $this->invalidInvite();
        }
        return $this->prePage($req, 'admin/invite', ['title' => $inv['purpose'] === 'reenroll' ? 'Konto zurücksetzen' : 'Einladung', 'error' => null, 'inv' => $inv, 'username' => '']);
    }

    public function invitePost(Request $req, array $p, ?array $sess): Response
    {
        $token = (string) ($p['token'] ?? '');
        $inv = $this->s->enrollment->invite($token);
        if ($inv === null) {
            return $this->invalidInvite();
        }
        $again = fn (string $err, int $status): Response => $this->prePage($req, 'admin/invite', ['title' => 'Einladung', 'error' => $err, 'inv' => $inv, 'username' => $req->input('username')], $status);
        if (!$this->preOk($req)) {
            return $again(self::PRE_EXPIRED, 400);
        }
        $res = $this->s->enrollment->startInvite($req, $token, $req->input('username'), $req->input('password'));
        if ($res['enr'] === null) {
            return $res['error'] === 'invalid' ? $this->invalidInvite() : $again((string) $res['error'], 400);
        }
        return Response::redirect('/admin/enroll')->withCookie($this->s->cookies->set('lk_enr', $res['enr'], 900));
    }

    // --- Notfall-Rücksetzung ---

    public function resetForm(Request $req, array $p, ?array $sess): Response
    {
        if (!$this->s->enrollment->resetOpen()) {
            return Response::notFound();
        }
        return $this->prePage($req, 'admin/reset', ['title' => 'Notfall-Rücksetzung', 'error' => null, 'link' => null]);
    }

    public function resetPost(Request $req, array $p, ?array $sess): Response
    {
        if (!$this->s->enrollment->resetOpen()) {
            return Response::notFound();
        }
        if (!$this->preOk($req)) {
            return $this->prePage($req, 'admin/reset', ['title' => 'Notfall-Rücksetzung', 'error' => self::PRE_EXPIRED, 'link' => null], 400);
        }
        $res = $this->s->enrollment->emergencyReset($req, $req->input('token'), $req->input('username'));
        return $this->prePage($req, 'admin/reset', ['title' => 'Notfall-Rücksetzung', 'error' => $res['error'], 'link' => $res['link']], $res['error'] === null ? 200 : 400);
    }

    // --- Angemeldeter Bereich ---

    public function dashboard(Request $req, array $p, ?array $sess): Response
    {
        $uid = (int) $sess['user_id'];
        $chain = $sess['role'] === 'admin' ? $this->s->audit->verify() : null;
        return $this->page('admin/dashboard', [
            'title' => 'Übersicht',
            'remaining' => $this->s->recovery->remaining($uid),
            'chain' => $chain,
            'live' => $this->s->builder->current() !== null,
            'changes' => count($this->s->builder->plan()['changes']),
        ], 200, $sess);
    }

    public function account(Request $req, array $p, ?array $sess): Response
    {
        return $this->page('admin/account', [
            'title' => 'Mein Konto',
            'error' => null,
            'ok' => null,
            'notice' => $req->query('neu') === '1' ? 'Du hast dich mit einem Wiederherstellungscode angemeldet. Bitte erzeuge neue Codes und richte bei Bedarf ein neues Gerät ein.' : null,
            'remaining' => $this->s->recovery->remaining((int) $sess['user_id']),
        ], 200, $sess);
    }

    public function accountPassword(Request $req, array $p, ?array $sess): Response
    {
        $uid = (int) $sess['user_id'];
        $new = $req->input('new_password');
        if ($new !== $req->input('new_password2')) {
            $err = 'Die beiden neuen Passwörter sind nicht gleich.';
        } else {
            $err = $this->s->auth->changePassword($uid, $req->cookie($this->s->cookies->name('lk_sid')), $req->input('current_password'), $req->input('code'), $new, $this->ipPrefix($req));
        }
        return $this->page('admin/account', [
            'title' => 'Mein Konto',
            'error' => $err,
            'ok' => $err === null ? 'Das Passwort wurde geändert. Alle anderen Sitzungen sind beendet.' : null,
            'notice' => null,
            'remaining' => $this->s->recovery->remaining($uid),
        ], $err === null ? 200 : 400, $sess);
    }

    public function accountCodes(Request $req, array $p, ?array $sess): Response
    {
        $uid = (int) $sess['user_id'];
        $res = $this->s->auth->regenerateCodes($uid, $req->input('current_password'), $this->ipPrefix($req));
        if ($res['error'] !== null) {
            return $this->page('admin/account', ['title' => 'Mein Konto', 'error' => $res['error'], 'ok' => null, 'notice' => null,
                'remaining' => $this->s->recovery->remaining($uid)], 400, $sess);
        }
        return $this->page('admin/codes', ['title' => 'Wiederherstellungscodes', 'codes' => $res['codes'], 'setup' => false], 200, $sess);
    }

    public function log(Request $req, array $p, ?array $sess): Response
    {
        $isAdmin = $sess['role'] === 'admin';
        $rows = $isAdmin
            ? $this->s->db->all('SELECT l.*, u.username FROM audit_log l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 200')
            : $this->s->db->all('SELECT l.*, u.username FROM audit_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.user_id = ? ORDER BY l.id DESC LIMIT 200', [(int) $sess['user_id']]);
        return $this->page('admin/log', [
            'title' => 'Protokoll',
            'rows' => $rows,
            'chain' => $isAdmin ? $this->s->audit->verify() : null,
        ], 200, $sess);
    }

    public function users(Request $req, array $p, ?array $sess): Response
    {
        return $this->usersPage($sess, null, null);
    }

    private function usersPage(array $sess, ?string $link, ?string $message, int $status = 200): Response
    {
        return $this->page('admin/users', [
            'title' => 'Konten',
            'accounts' => $this->s->accounts->all(),
            'link' => $link,
            'message' => $message,
        ], $status, $sess);
    }

    public function usersInvite(Request $req, array $p, ?array $sess): Response
    {
        $role = $req->input('role') === 'admin' ? 'admin' : 'editor';
        $token = $this->s->accounts->createInvite((int) $sess['user_id'], $role, $this->ipPrefix($req));
        return $this->usersPage($sess, $this->config->baseUrl() . '/admin/invite/' . $token, 'Einladung erzeugt. Der Link gilt 24 Stunden und einmal.');
    }

    public function usersAction(Request $req, array $p, ?array $sess): Response
    {
        $admin = (int) $sess['user_id'];
        $target = (int) ($p['id'] ?? 0);
        $ip = $this->ipPrefix($req);
        switch ($p['action'] ?? '') {
            case 'disable':
            case 'enable':
                $err = $this->s->accounts->setDisabled($admin, $target, $p['action'] === 'disable', $ip);
                return $this->usersPage($sess, null, $err ?? 'Gespeichert.', $err === null ? 200 : 400);
            case 'reset':
                $token = $this->s->accounts->createReenroll($admin, $target, $ip);
                if ($token === null) {
                    return $this->usersPage($sess, null, 'Für dieses Konto nicht möglich. (Das eigene Konto setzt du über das Passwort zurück, im Notfall über das Notfall-Token.)', 400);
                }
                return $this->usersPage($sess, $this->config->baseUrl() . '/admin/invite/' . $token, 'Konto zurückgesetzt: TOTP, Wiederherstellungscodes und Sitzungen sind ungültig. Der Link gilt 24 Stunden und einmal.');
            case 'sessions':
                $n = $this->s->accounts->endSessions($admin, $target, $ip);
                if ($n === null) {
                    return Response::notFound();
                }
                return $this->usersPage($sess, null, $n . ' Sitzung(en) beendet.');
            case 'role':
                $err = $this->s->accounts->setRole($admin, $target, $req->input('role'), $ip);
                return $this->usersPage($sess, null, $err ?? 'Gespeichert.', $err === null ? 200 : 400);
            default:
                return Response::notFound();
        }
    }
}
