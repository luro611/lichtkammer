<?php
declare(strict_types=1);

// Abläufe der Anmeldung über die ganze Anwendung (Router, Cookies, CSRF, Einrichtung, Einladung, Rücksetzung).

use Lichtkammer\Auth\Totp;

require_once __DIR__ . '/support.php';

$f = lk_new_tmp($tmp, 'flow');
$body = static fn (Lichtkammer\Response $r): string => $r->body;
$cnt = static fn (array $m, string $sql, array $p = []): int => (int) $m['app']->services()->db->value($sql, $p);

// --- Konfiguration fehlt: Admin startet nicht, Rest läuft ---
$m = lk_make($f . '/nokey', ['LK_APP_KEY' => '']);
$b = new TestBrowser($m['app'], $m['config']);
$r = $b->get('/admin/login');
check('Ohne Schlüssel: Admin 503 mit Hinweis', $r->status === 503 && str_contains($r->body, 'LK_APP_KEY'));
check('Ohne Schlüssel: öffentliche Startseite läuft (noch nicht veröffentlicht: 503)', $b->get('/')->status === 503 && $b->get('/health')->status === 200);

// --- Ersteinrichtung ---
$m = lk_make($f . '/a');
$b = new TestBrowser($m['app'], $m['config']);
check('Setup: GET ohne Token in .env wäre 404', lk_make($f . '/a0', ['LK_SETUP_TOKEN' => ''])['app']->handle(new Lichtkammer\Request(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/setup', 'HTTPS' => 'on'], $m['config']))->status === 404);
$m0 = lk_make($f . '/a1', ['LK_SETUP_TOKEN' => '']);
$b0 = new TestBrowser($m0['app'], $m0['config']);
check('Setup ohne Token in .env: GET 404', $b0->get('/admin/setup')->status === 404);
check('Setup ohne Token in .env: POST 404 (nicht 405)', $b0->post('/admin/setup', ['token' => 'x'])->status === 404);
check('Enroll ohne Zwischen-Cookie: 404', $b->get('/admin/enroll')->status === 404 && $b->post('/admin/enroll', ['code' => '123456'])->status === 404);
$page = $b->get('/admin/setup');
check('Setup: Formular 200 mit Cookie lk_pre', $page->status === 200 && $b->cookie('lk_pre') !== '');
$wrong = $b->post('/admin/setup', ['_csrf' => TestBrowser::csrf($page), 'token' => 'falsch', 'username' => 'lutz', 'password' => TEST_PASSWORD]);
check('Setup: falsches Token abgelehnt, kein Zwischenstand', $wrong->status === 400 && $cnt($m, 'SELECT COUNT(*) FROM pending_enrollments') === 0);
$weak = $b->post('/admin/setup', ['_csrf' => TestBrowser::csrf($b->get('/admin/setup')), 'token' => TEST_SETUP_TOKEN, 'username' => 'lutz', 'password' => 'passwort123456']);
check('Setup: schwaches Passwort abgelehnt', $weak->status === 400 && $cnt($m, 'SELECT COUNT(*) FROM users') === 0);
$ok = $b->post('/admin/setup', ['_csrf' => TestBrowser::csrf($b->get('/admin/setup')), 'token' => TEST_SETUP_TOKEN, 'username' => 'Lutz', 'password' => TEST_PASSWORD]);
check('Setup: Weiterleitung auf TOTP-Seite', $ok->status === 303 && $ok->headers['Location'] === '/admin/enroll');
check('Setup: bis hierher noch KEIN Konto', $cnt($m, 'SELECT COUNT(*) FROM users') === 0 && $cnt($m, 'SELECT COUNT(*) FROM pending_enrollments') === 1);
$en = $b->get('/admin/enroll');
$secret = TestBrowser::secret($en);
check('Enroll: Geheimnis und inline-QR mit viewBox', strlen($secret) === 32 && str_contains($en->body, '<svg') && str_contains($en->body, 'viewBox='));
check('Enroll: kein style-Attribut im QR, keine Adresse mit Geheimnis', !preg_match('/<svg[^>]*style=/', $en->body) && !preg_match('/(href|src|action)="[^"]*(secret|otpauth)/', $en->body));
check('Enroll: otpauth-Adresse steht nirgends im HTML', !str_contains($en->body, 'otpauth'));
check('Enroll: Serverzeit sichtbar, no-store, no-referrer', str_contains($en->body, 'UTC') && ($en->headers['Cache-Control'] ?? '') === 'no-store' && ($en->headers['Referrer-Policy'] ?? '') === 'no-referrer');
check('Enroll: Geheimnis in der Datenbank nur verschlüsselt', !str_contains((string) $m['app']->services()->db->value('SELECT totp_secret_enc FROM pending_enrollments'), $secret));
$bad = $b->post('/admin/enroll', ['_csrf' => TestBrowser::csrf($en), 'code' => '000000']);
check('Enroll: falscher Code 401, immer noch kein Konto', $bad->status === 401 && $cnt($m, 'SELECT COUNT(*) FROM users') === 0);
$noCsrf = $b->post('/admin/enroll', ['_csrf' => 'falsch', 'code' => lk_totp($secret, $m['clock'])]);
check('Enroll: falsches CSRF abgelehnt', $noCsrf->status === 400 && $cnt($m, 'SELECT COUNT(*) FROM users') === 0);
$done = $b->post('/admin/enroll', ['_csrf' => TestBrowser::csrf($b->get('/admin/enroll')), 'code' => lk_totp($secret, $m['clock'])]);
$codes = TestBrowser::codes($done);
check('Enroll: Konto entsteht nach gültigem Code, zehn Codes einmalig', $done->status === 200 && $cnt($m, 'SELECT COUNT(*) FROM users') === 1 && count($codes) === 10);
check('Enroll: danach sofort angemeldet, Zwischenzeile weg, Cookie weg', lk_in($b) && $cnt($m, 'SELECT COUNT(*) FROM pending_enrollments') === 0 && $b->cookie('lk_enr') === '');
check('Enroll: Codes-Seite no-store', ($done->headers['Cache-Control'] ?? '') === 'no-store');
check('Setup danach: GET und POST 404', $b->get('/admin/setup')->status === 404 && $b->post('/admin/setup', ['token' => TEST_SETUP_TOKEN])->status === 404);
check('Konto: Name klein, Rolle admin, Handle 32 Byte (hex)', $cnt($m, "SELECT COUNT(*) FROM users WHERE username='lutz' AND role='admin' AND length(webauthn_handle)=64") === 1);
check('Konto: Wiederherstellungscodes nur gehasht', $cnt($m, 'SELECT COUNT(*) FROM recovery_codes') === 10);
check('Dauerhaftes Warnband: Setup-Token steht noch in .env', str_contains($b->get('/admin')->body, 'LK_SETUP_TOKEN'));
check('Geheimnis ist im Konto an die user_id gebunden (Zusatzdaten)', $m['app']->services()->keys->decrypt((string) $m['app']->services()->db->value('SELECT totp_secret_enc FROM users WHERE id=1'), 'users:totp:1')['plain'] === $secret
    && $m['app']->services()->keys->decrypt((string) $m['app']->services()->db->value('SELECT totp_secret_enc FROM users WHERE id=1'), 'users:totp:2') === null);

// Zwei gleichzeitige Einrichtungen: genau ein Konto
$mc = lk_make($f . '/concurrent');
$b1 = new TestBrowser($mc['app'], $mc['config'], '203.0.113.10');
$b2 = new TestBrowser($mc['app'], $mc['config'], '203.0.113.11');
$secrets = [];
foreach ([[$b1, 'eins'], [$b2, 'zwei']] as [$bx, $name]) {
    $bx->post('/admin/setup', ['_csrf' => TestBrowser::csrf($bx->get('/admin/setup')), 'token' => TEST_SETUP_TOKEN, 'username' => $name, 'password' => TEST_PASSWORD]);
    $secrets[$name] = TestBrowser::secret($bx->get('/admin/enroll'));
}
$d1 = $b1->post('/admin/enroll', ['_csrf' => TestBrowser::csrf($b1->get('/admin/enroll')), 'code' => lk_totp($secrets['eins'], $mc['clock'])]);
$d2 = $b2->post('/admin/enroll', ['_csrf' => TestBrowser::csrf($b2->get('/admin/enroll')), 'code' => lk_totp($secrets['zwei'], $mc['clock'])]);
check('Zwei Einrichtungen: genau ein Konto, die zweite scheitert (404)', (int) $mc['app']->services()->db->value('SELECT COUNT(*) FROM users') === 1 && $d1->status === 200 && $d2->status === 404);

// Zwischenzeile läuft ab
$me = lk_make($f . '/expire');
$be = new TestBrowser($me['app'], $me['config']);
$be->post('/admin/setup', ['_csrf' => TestBrowser::csrf($be->get('/admin/setup')), 'token' => TEST_SETUP_TOKEN, 'username' => 'lutz', 'password' => TEST_PASSWORD]);
$me['clock']->advance(901);
check('Abgelaufene Zwischenzeile: 404 und Zeile weg', $be->get('/admin/enroll')->status === 404 && (int) $me['app']->services()->db->value('SELECT COUNT(*) FROM pending_enrollments') === 0);
// fünf falsche Codes verwerfen die Einrichtung
$be->post('/admin/setup', ['_csrf' => TestBrowser::csrf($be->get('/admin/setup')), 'token' => TEST_SETUP_TOKEN, 'username' => 'lutz', 'password' => TEST_PASSWORD]);
$last = null;
for ($i = 0; $i < 5; $i++) {
    $last = $be->post('/admin/enroll', ['_csrf' => TestBrowser::csrf($be->get('/admin/enroll')), 'code' => '000000']);
    if ($last->status === 404) {
        break;
    }
}
check('Fünf falsche Einrichtungs-Codes verwerfen die Zwischenzeile', $last->status === 404 && (int) $me['app']->services()->db->value('SELECT COUNT(*) FROM pending_enrollments') === 0);

// --- Anmeldung ---
// Wiederverwendung: der Code des Einrichtungsschritts (gleicher Zeitschritt) wird bei der Anmeldung abgelehnt
$bre = new TestBrowser($m['app'], $m['config']);
$bre->post('/admin/login', ['_csrf' => TestBrowser::csrf($bre->get('/admin/login')), 'username' => 'lutz', 'password' => TEST_PASSWORD]);
$tre = $bre->get('/admin/login/totp');
check('TOTP-Wiederverwendung: Code des Einrichtungsschritts abgelehnt', $bre->post('/admin/login/totp', ['_csrf' => TestBrowser::csrf($tre), 'code' => lk_totp($secret, $m['clock'])])->status === 401 && !lk_in($bre));
$m['clock']->advance(31);
$b = new TestBrowser($m['app'], $m['config']);
$lp = $b->get('/admin/login');
check('Login: Formular mit Siegel, Cookie lk_pre mit __Host-', $lp->status === 200 && str_contains($lp->body, 'luro-siegel') && isset($b->jar['__Host-lk_pre']));
check('Login: Cache-Control no-store', ($lp->headers['Cache-Control'] ?? '') === 'no-store');
check('Login: Felder mit autocomplete-Attributen', str_contains($lp->body, 'autocomplete="username"') && str_contains($lp->body, 'autocomplete="current-password"'));
$tok = TestBrowser::csrf($lp);
// Login-CSRF
$other = new TestBrowser($m['app'], $m['config']);
$other->get('/admin/login');
$foreign = $other->post('/admin/login', ['_csrf' => $tok, 'username' => 'lutz', 'password' => TEST_PASSWORD]);
check('Login-CSRF: Token aus fremdem Cookie abgelehnt', $foreign->status === 400 && $other->cookie('lk_sid') === '');
$nocookie = new TestBrowser($m['app'], $m['config']);
check('Login-CSRF: ganz ohne Cookie abgelehnt', $nocookie->post('/admin/login', ['_csrf' => $tok, 'username' => 'lutz', 'password' => TEST_PASSWORD])->status === 400);
check('Login-CSRF: ohne Token abgelehnt', $b->post('/admin/login', ['username' => 'lutz', 'password' => TEST_PASSWORD])->status === 400);
$m['clock']->advance(3601);
check('Login-CSRF: nach über einer Stunde abgelehnt (freundliche Seite)', ($old = $b->post('/admin/login', ['_csrf' => $tok, 'username' => 'lutz', 'password' => TEST_PASSWORD]))->status === 400 && str_contains($old->body, 'zu lange'));
$m['clock']->advance(31);

// Erfolgreiche Anmeldung, Sitzungswechsel
$b = new TestBrowser($m['app'], $m['config']);
$tok = TestBrowser::csrf($b->get('/admin/login'));
$s1 = $b->post('/admin/login', ['_csrf' => $tok, 'username' => ' LUTZ ', 'password' => TEST_PASSWORD, 'next' => '/admin/log']);
$sid1 = $b->cookie('lk_sid');
check('Login Stufe 1: Weiterleitung zur TOTP-Seite, Name normalisiert', $s1->status === 303 && str_starts_with($s1->headers['Location'], '/admin/login/totp') && strlen($sid1) === 64);
check('Login Stufe 1: Cookie-Flags (__Host-, Secure, HttpOnly, SameSite=Strict, Pfad /, ohne Domain)', (static function () use ($s1): bool {
    $c = implode("\n", $s1->cookies);
    return str_contains($c, '__Host-lk_sid=') && str_contains($c, 'HttpOnly') && str_contains($c, 'SameSite=Strict') && str_contains($c, '; Secure') && str_contains($c, 'Path=/') && !str_contains($c, 'Domain');
})());
check('Stufe password_ok hat keinen Zugriff auf /admin', $b->get('/admin')->status === 303 && $b->get('/admin/log')->status === 303);
check('Stufe password_ok: TOTP-Seite erreichbar', ($tp = $b->get('/admin/login/totp?next=/admin/log'))->status === 200);
$wrongTotp = $b->post('/admin/login/totp', ['_csrf' => TestBrowser::csrf($tp), 'code' => '000000']);
check('TOTP falsch: 401, weiter nicht angemeldet', $wrongTotp->status === 401 && !lk_in($b));
$secretLutz = $secret;
$m['clock']->advance(0);
// Der Schritt der Einrichtung ist verbraucht (Wiederverwendung), also einen Schritt weiter
$m['clock']->advance(60);
$tp = $b->get('/admin/login/totp?next=/admin/log');
$fin = $b->post('/admin/login/totp', ['_csrf' => TestBrowser::csrf($tp), 'code' => lk_totp($secretLutz, $m['clock']), 'next' => '/admin/log']);
$sid2 = $b->cookie('lk_sid');
check('TOTP richtig: Sitzungs-ID wechselt, Weiterleitung auf next', $fin->status === 303 && $fin->headers['Location'] === '/admin/log' && $sid2 !== $sid1 && strlen($sid2) === 64);
check('Alte password_ok-ID funktioniert nicht mehr', (static function () use ($m, $sid1): bool {
    $x = new TestBrowser($m['app'], $m['config']);
    $x->jar['__Host-lk_sid'] = $sid1;
    return $x->get('/admin')->status === 303;
})());
check('Volle Sitzung: /admin 200', lk_in($b));
check('Sitzung nur als Hash gespeichert', $cnt($m, 'SELECT COUNT(*) FROM sessions WHERE id_hash = ?', [$sid2]) === 0 && $cnt($m, 'SELECT COUNT(*) FROM sessions WHERE id_hash = ?', [$m['app']->services()->sessions->hashId($sid2)]) === 1 && $cnt($m, 'SELECT COUNT(*) FROM sessions WHERE id_hash = ?', [hash('sha256', $sid2)]) === 0);
check('Anmeldung erscheint im Protokoll, ohne Passwort/Code', $cnt($m, "SELECT COUNT(*) FROM audit_log WHERE event='login_ok'") === 1);
check('Login-Seite leitet bei vorhandener Sitzung weiter', $b->get('/admin/login')->status === 303);

// Abmelden
$noCsrfOut = $b->post('/admin/logout', []);
check('Abmelden ohne CSRF abgelehnt', $noCsrfOut->status === 403 && lk_in($b));
$dash = $b->get('/admin');
$out = $b->post('/admin/logout', ['_csrf' => TestBrowser::csrf($dash)]);
check('Abmelden: Cookie weg, Clear-Site-Data, Sitzung serverseitig gelöscht', ($out->headers['Clear-Site-Data'] ?? '') === '"cookies"' && $cnt($m, 'SELECT COUNT(*) FROM sessions WHERE id_hash = ?', [hash('sha256', $sid2)]) === 0);
check('Abgemeldete ID wirkt serverseitig nicht mehr', (static function () use ($m, $sid2): bool {
    $x = new TestBrowser($m['app'], $m['config']);
    $x->jar['__Host-lk_sid'] = $sid2;
    return $x->get('/admin')->status === 303;
})());
check('Logout per GET gibt es nicht', $b->get('/admin/logout')->status === 404);

// Leerlauf und absolute Dauer
$m['clock']->advance(31);
$b = lk_login($m, 'lutz', TEST_PASSWORD, $secretLutz);
check('Frisch angemeldet', lk_in($b));
$m['clock']->advance(1700);
check('Aktivität hält die Sitzung (unter Leerlauf)', lk_in($b));
$m['clock']->advance(1801);
check('Leerlauf 30 Minuten beendet die Sitzung', !lk_in($b));
$m['clock']->advance(31);
$b = lk_login($m, 'lutz', TEST_PASSWORD, $secretLutz);
for ($i = 0; $i < 13; $i++) {
    $m['clock']->advance(3000);
    lk_in($b);
}
check('Absolute Dauer von 12 Stunden beendet die Sitzung trotz Aktivität', !lk_in($b));
// password_ok verfällt nach 5 Minuten
$m['clock']->advance(31);
$b = new TestBrowser($m['app'], $m['config']);
$tok = TestBrowser::csrf($b->get('/admin/login'));
$b->post('/admin/login', ['_csrf' => $tok, 'username' => 'lutz', 'password' => TEST_PASSWORD]);
$m['clock']->advance(301);
check('password_ok verfällt nach 5 Minuten', $b->get('/admin/login/totp')->status === 303);
// fünf falsche Codes löschen die Sitzung
$b = new TestBrowser($m['app'], $m['config']);
$b->post('/admin/login', ['_csrf' => TestBrowser::csrf($b->get('/admin/login')), 'username' => 'lutz', 'password' => TEST_PASSWORD]);
$tp = $b->get('/admin/login/totp');
$res = null;
for ($i = 0; $i < 5; $i++) {
    $res = $b->post('/admin/login/totp', ['_csrf' => TestBrowser::csrf($tp), 'code' => '111111']);
}
check('Fünf falsche Codes beenden die password_ok-Sitzung', $res->status === 303 && $cnt($m, "SELECT COUNT(*) FROM sessions WHERE stage='password_ok'") === 0);

// --- Wiederherstellungscode-Anmeldung ---
$m['clock']->advance(31);
$b = new TestBrowser($m['app'], $m['config']);
$b->post('/admin/login', ['_csrf' => TestBrowser::csrf($b->get('/admin/login')), 'username' => 'lutz', 'password' => TEST_PASSWORD]);
$tp = $b->get('/admin/login/totp');
$stepBefore = $cnt($m, 'SELECT totp_last_step FROM users WHERE id=1');
$rr = $b->post('/admin/login/totp', ['_csrf' => TestBrowser::csrf($tp), 'code' => strtolower($codes[0])]);
check('Wiederherstellungscode: Anmeldung, Hinweis auf neue Codes, totp_last_step unverändert', $rr->status === 303 && str_contains($rr->headers['Location'], '/admin/account') && lk_in($b) && $cnt($m, 'SELECT totp_last_step FROM users WHERE id=1') === $stepBefore);
check('Wiederherstellungscode im Protokoll', $cnt($m, "SELECT COUNT(*) FROM audit_log WHERE event='recovery_used'") === 1);
$b2 = new TestBrowser($m['app'], $m['config']);
$b2->post('/admin/login', ['_csrf' => TestBrowser::csrf($b2->get('/admin/login')), 'username' => 'lutz', 'password' => TEST_PASSWORD]);
$again = $b2->post('/admin/login/totp', ['_csrf' => TestBrowser::csrf($b2->get('/admin/login/totp')), 'code' => $codes[0]]);
check('Wiederherstellungscode ein zweites Mal abgelehnt', $again->status === 401);

// --- Prüfung 08, A1: unbekannter Name kostet genau einen Argon2-Lauf (Scheinhash in meta) ---
$svcA1 = $m['app']->services();
$svcA1->db->run("DELETE FROM meta WHERE k='dummy_hash'");
$xa = new TestBrowser($m['app'], $m['config'], '198.51.100.77');
$xa->post('/admin/login', ['_csrf' => TestBrowser::csrf($xa->get('/admin/login')), 'username' => 'niemand', 'password' => 'x']);
$dummy1 = $svcA1->db->value("SELECT v FROM meta WHERE k='dummy_hash'");
check('A1: Scheinhash liegt nach dem ersten unbekannten Versuch in meta (Argon2id)', is_string($dummy1) && str_starts_with($dummy1, '$argon2id$'));
$svcA1->db->run('DELETE FROM login_attempts');
$v0 = $svcA1->passwords->verifications;
$xa->post('/admin/login', ['_csrf' => TestBrowser::csrf($xa->get('/admin/login')), 'username' => 'niemand', 'password' => 'x']);
check('A1: zweiter unbekannter Versuch: eine Prüfung, Scheinhash unverändert', $svcA1->passwords->verifications - $v0 === 1 && $svcA1->db->value("SELECT v FROM meta WHERE k='dummy_hash'") === $dummy1);
$svcA1->db->run("UPDATE meta SET v = ? WHERE k='dummy_hash'", [password_hash('x', PASSWORD_ARGON2ID, ['memory_cost' => 16, 'time_cost' => 1, 'threads' => 1])]);
$svcA1->db->run('DELETE FROM login_attempts');
$xa->post('/admin/login', ['_csrf' => TestBrowser::csrf($xa->get('/admin/login')), 'username' => 'niemand', 'password' => 'x']);
check('A1: Scheinhash mit alten Parametern wird erneuert', $svcA1->db->value("SELECT v FROM meta WHERE k='dummy_hash'") !== $dummy1 && password_needs_rehash((string) $svcA1->db->value("SELECT v FROM meta WHERE k='dummy_hash'"), PASSWORD_ARGON2ID, TEST_ARGON) === false);

// --- Gleichbehandlung: unbekannt / falsches Passwort / gesperrt / ohne TOTP ---
$m['clock']->advance(31);
$ctr = static fn (array $m): array => $m['app']->services()->db->all('SELECT kind, COUNT(*) n FROM login_attempts GROUP BY kind ORDER BY kind');
$try = static function (array $m, string $user, string $pwd, string $ip) use ($ctr): array {
    $x = new TestBrowser($m['app'], $m['config'], $ip);
    $m['app']->services()->db->run('DELETE FROM login_attempts');
    $r = $x->post('/admin/login', ['_csrf' => TestBrowser::csrf($x->get('/admin/login')), 'username' => $user, 'password' => $pwd]);
    return [$r->status, preg_replace('/value="[^"]*"/', 'value=""', $r->body), $ctr($m)];
};
$known = $try($m, 'lutz', 'falsches-Passwort-123', '198.51.100.1');
$unknown = $try($m, 'gibtsnicht', 'falsches-Passwort-123', '198.51.100.1');
check('Bekannt/unbekannt: gleicher Status', $known[0] === $unknown[0] && $known[0] === 401);
check('Bekannt/unbekannt: gleiche Seite (außer Namensfeld)', $known[1] === str_replace('gibtsnicht', 'lutz', $unknown[1]) || preg_replace('/lutz|gibtsnicht/', '', $known[1]) === preg_replace('/lutz|gibtsnicht/', '', $unknown[1]));
check('Bekannt/unbekannt: gleicher Zählerstand', $known[2] === $unknown[2] && $known[2] !== []);
$m['clock']->advance(40);
$svc = $m['app']->services();
// Konto ohne TOTP und gesperrtes Konto: gleiche Antwort auch mit richtigem Passwort
$svc->db->run("INSERT INTO users(username, role, password_hash, created_at, webauthn_handle) VALUES('ohnetotp','editor',?,1,'h')", [$svc->passwords->hash(TEST_PASSWORD)]);
$svc->db->run("INSERT INTO users(username, role, password_hash, totp_confirmed_at, totp_secret_enc, created_at, disabled_at, webauthn_handle) VALUES('gesperrt','editor',?,1,'x',1,1,'h')", [$svc->passwords->hash(TEST_PASSWORD)]);
$noTotp = $try($m, 'ohnetotp', TEST_PASSWORD, '198.51.100.2');
$disabled = $try($m, 'gesperrt', TEST_PASSWORD, '198.51.100.3');
$unk2 = $try($m, 'gibtsnicht', TEST_PASSWORD, '198.51.100.4');
check('Konto ohne TOTP (richtiges Passwort): wie unbekannt', $noTotp[0] === $unk2[0] && $noTotp[2] === $unk2[2]);
check('Gesperrtes Konto (richtiges Passwort): wie unbekannt', $disabled[0] === $unk2[0] && $disabled[2] === $unk2[2]);
check('Weder TOTP-loses noch gesperrtes Konto bekommt eine Sitzung', $cnt($m, "SELECT COUNT(*) FROM sessions s JOIN users u ON u.id=s.user_id WHERE u.username IN ('ohnetotp','gesperrt')") === 0);
check('Unbekannter Absender: nur zusammengefasst (login_failures), kein Protokollsatz', $cnt($m, 'SELECT SUM(count) FROM login_failures') >= 2 && $cnt($m, "SELECT COUNT(*) FROM audit_log WHERE event='login_failed' AND user_id IS NULL") === 0);
check('Rohe Namen/Passwörter stehen nirgends im Protokoll', (static function () use ($svc): bool {
    $all = json_encode($svc->db->all('SELECT * FROM audit_log')) . json_encode($svc->db->all('SELECT * FROM login_failures')) . json_encode($svc->db->all('SELECT * FROM login_attempts'));
    foreach (['gibtsnicht', 'falsches-Passwort', TEST_PASSWORD, 'ohnetotp'] as $secretText) {
        if (str_contains($all, $secretText)) {
            return false;
        }
    }
    return true;
})());

// --- Sperren: Aussperr-Falle, Verzögerung, Aufhebung ---
$m['clock']->advance(1000);
$svc->db->run('DELETE FROM login_attempts');
$att = static function (array $m, string $user, string $ip, string $pwd = 'falsches-Passwort-123'): int {
    $x = new TestBrowser($m['app'], $m['config'], $ip);
    return $x->post('/admin/login', ['_csrf' => TestBrowser::csrf($x->get('/admin/login')), 'username' => $user, 'password' => $pwd])->status;
};
$st = [];
for ($i = 0; $i < 3; $i++) {
    $st[] = $att($m, 'lutz', '192.0.2.50');
}
check('Verzögerung: drei Fehlversuche normal beantwortet', $st === [401, 401, 401]);
check('Verzögerung ab dem 3. Fehlversuch: sofortiger vierter Versuch → 429', $att($m, 'lutz', '192.0.2.50') === 429);
$m['clock']->advance(2);
$verBefore = $svc->passwords->verifications;
check('Verzögerung vorbei: weiter möglich (401)', $att($m, 'lutz', '192.0.2.50') === 401);
for ($i = 0; $i < 12; $i++) {
    $m['clock']->advance(31);
    $att($m, 'lutz', '192.0.2.50');
}
check('Sperre: nach 10 Fehlversuchen 429 auch mit RICHTIGEM Passwort', $att($m, 'lutz', '192.0.2.50', TEST_PASSWORD) === 429);
check('Sperre: kein Hash wird für gesperrte Versuche gerechnet', $svc->passwords->verifications - $verBefore <= 10);
check('Aussperr-Falle: echter Benutzer von anderer Adresse wird NICHT ausgesperrt', $att($m, 'lutz', '192.0.2.99', TEST_PASSWORD) === 303);
check('Sperre meldet sich im Protokoll', $cnt($m, "SELECT COUNT(*) FROM audit_log WHERE event='login_locked'") >= 1);
$m['clock']->advance(901);
check('Sperre nach 15 Minuten aufgehoben', $att($m, 'lutz', '192.0.2.50', TEST_PASSWORD) === 303);
// A2 (Entscheidung Lutz): 100 Fehlversuche auf einen Namen von 10 Adressen (je 10, unter der Sperre je Name+Adresse);
// der echte Benutzer kommt trotzdem sofort durch, es gibt genau ein Ereignis account_under_attack (gedrosselt).
$m['clock']->advance(1000);
$svc->db->run('DELETE FROM login_attempts');
$kU = $svc->limiter->key('user', 'lutz');
$t0 = $m['clock']->now();
for ($i = 0; $i < 99; $i++) {
    $svc->db->run('INSERT INTO login_attempts(kind, key_hash, at) VALUES(?,?,?)', ['user', $kU, $t0 - 800 + $i]);
}
$cntAttack = $cnt($m, "SELECT COUNT(*) FROM audit_log WHERE event='account_under_attack'");
check('A2: 100. Fehlversuch auf den Namen: Antwort 401 (keine Wartezeit), Ereignis account_under_attack', $att($m, 'lutz', '192.0.2.120') === 401 && $cnt($m, "SELECT COUNT(*) FROM audit_log WHERE event='account_under_attack'") === $cntAttack + 1);
check('A2: 101. Versuch: kein weiteres Ereignis (gedrosselt), weiter 401', $att($m, 'lutz', '192.0.2.121') === 401 && $cnt($m, "SELECT COUNT(*) FROM audit_log WHERE event='account_under_attack'") === $cntAttack + 1);
check('A2: echter Benutzer von neuer Adresse kommt sofort durch (keine Aussperrung über den Namen)', $att($m, 'lutz', '192.0.2.122', TEST_PASSWORD) === 303);
check('A2: Ereignis nennt keinen Namen, nur attempts=100', $cnt($m, "SELECT COUNT(*) FROM audit_log WHERE event='account_under_attack' AND detail='attempts=100' AND user_id=1") === $cntAttack + 1);
$svc->db->run('DELETE FROM login_attempts');
// B1: Die angezeigte Restzeit passt zum Ende der Sperre (ab dem ersten gezählten Versuch)
$svcB1 = $m['app']->services();
$svcB1->db->run('DELETE FROM login_attempts');
$kB1 = $svcB1->limiter->key('userip', 'b1');
$tB1 = $m['clock']->now();
for ($i = 0; $i < 10; $i++) {
    $svcB1->db->run('INSERT INTO login_attempts(kind, key_hash, at) VALUES(?,?,?)', ['userip', $kB1, $tB1 + $i * 10]);
}
$m['clock']->advance(200);
$gB1 = $svcB1->limiter->gate([['kind' => 'userip', 'key' => $kB1, 'lock' => Lichtkammer\Auth\RateLimiter::LOCK_AT, 'delay' => true]], false, 100, 10);
check('B1: Restzeit der Sperre = 900 s ab dem ersten Versuch', !$gB1['ok'] && $gB1['reason'] === 'locked' && $gB1['retry'] === 700);
$m['clock']->advance(701);
check('B1: nach der angezeigten Restzeit ist wieder ein Versuch möglich', $svcB1->limiter->gate([['kind' => 'userip', 'key' => $kB1, 'lock' => Lichtkammer\Auth\RateLimiter::LOCK_AT, 'delay' => true]], false, 100, 10)['ok']);
$svcB1->db->run('DELETE FROM login_attempts');

// --- Globale Bremse und Buchung vor dem Hash ---
$mg = lk_make($f . '/brake', ['LK_GLOBAL_BRAKE_LIMIT' => '5']);
$sg = $mg['app']->services();
$v0 = $sg->passwords->verifications;
$codesSeen = [];
for ($i = 0; $i < 20; $i++) {
    $x = new TestBrowser($mg['app'], $mg['config'], '198.51.100.' . (100 + $i));
    $codesSeen[] = $x->post('/admin/login', ['_csrf' => TestBrowser::csrf($x->get('/admin/login')), 'username' => 'name' . $i, 'password' => 'falsches-Passwort-123'])->status;
}
check('Globale Bremse: höchstens 5 Hash-Prüfungen in 10 s, danach 429', $sg->passwords->verifications - $v0 === 5 && count(array_filter($codesSeen, fn ($c) => $c === 429)) === 15);
check('Globale Bremse: eigenes Protokollereignis, höchstens einmal je Minute', (int) $sg->db->value("SELECT COUNT(*) FROM audit_log WHERE event='global_brake'") === 1);
$mg['clock']->advance(11);
$x = new TestBrowser($mg['app'], $mg['config'], '198.51.100.250');
check('Globale Bremse löst sich nach dem Fenster', $x->post('/admin/login', ['_csrf' => TestBrowser::csrf($x->get('/admin/login')), 'username' => 'a', 'password' => 'falsches-Passwort-123'])->status === 401);
// 20 gleichzeitige Versuche gegen EIN Konto/Adresse: höchstens 10 Hashes
$mh = lk_make($f . '/many');
$sh = $mh['app']->services();
$v0 = $sh->passwords->verifications;
for ($i = 0; $i < 20; $i++) {
    $x = new TestBrowser($mh['app'], $mh['config'], '198.51.100.5');
    $x->post('/admin/login', ['_csrf' => TestBrowser::csrf($x->get('/admin/login')), 'username' => 'ziel', 'password' => 'falsches-Passwort-123']);
}
check('20 Versuche gegen ein Konto: höchstens 10 Hash-Prüfungen (Buchung vor dem Hash)', $sh->passwords->verifications - $v0 <= 10);

// --- Zweite Stufe hat einen eigenen Zähler ---
$m['clock']->advance(2000);
$svc->db->run('DELETE FROM login_attempts');
$b = new TestBrowser($m['app'], $m['config'], '192.0.2.77');
$b->post('/admin/login', ['_csrf' => TestBrowser::csrf($b->get('/admin/login')), 'username' => 'lutz', 'password' => TEST_PASSWORD]);
$tpage = $b->get('/admin/login/totp');
$s2 = [];
for ($i = 0; $i < 4; $i++) {
    $svc->db->run("UPDATE sessions SET totp_failures = 0 WHERE stage='password_ok'");
    $s2[] = $b->post('/admin/login/totp', ['_csrf' => TestBrowser::csrf($tpage), 'code' => '222222'])->status;
}
for ($i = 0; $i < 8; $i++) {
    $svc->db->run("UPDATE sessions SET totp_failures = 0 WHERE stage='password_ok'");
    $b->post('/admin/login/totp', ['_csrf' => TestBrowser::csrf($tpage), 'code' => '222222']);
}
$svc->db->run("UPDATE sessions SET totp_failures = 0 WHERE stage='password_ok'");
$m['clock']->advance(0);
check('Zweite Stufe: nach 10 Fehlcodes gesperrt (429), Alarm im Protokoll', $b->post('/admin/login/totp', ['_csrf' => TestBrowser::csrf($tpage), 'code' => lk_totp($secretLutz, $m['clock'])])->status === 429 && $cnt($m, "SELECT COUNT(*) FROM audit_log WHERE event='twofa_locked'") >= 1);
$other = new TestBrowser($m['app'], $m['config'], '192.0.2.78');
$other->post('/admin/login', ['_csrf' => TestBrowser::csrf($other->get('/admin/login')), 'username' => 'lutz', 'password' => TEST_PASSWORD]);
check('Sperre der zweiten Stufe sperrt die Passwortstufe nicht', $other->get('/admin/login/totp')->status === 200);

// --- Cookie nach Konfiguration, HTTPS-Pflicht ---
$bh = new TestBrowser($m['app'], $m['config'], '203.0.113.7', false);
check('HTTPS konfiguriert, Anfrage ohne HTTPS: Anmeldung verweigert', $bh->get('/admin/login')->status === 400 && $bh->post('/admin/login', ['username' => 'lutz'])->status === 400);
$mdev = lk_make($f . '/dev', ['LK_BASE_URL' => 'http://localhost:8080', 'LK_ENV' => 'production']);
$bd = new TestBrowser($mdev['app'], $mdev['config'], '127.0.0.1', false);
$rd = $bd->get('/admin/login');
check('http:// konfiguriert: Cookie ohne __Host- und ohne Secure (unabhängig von LK_ENV)', $rd->status === 200 && isset($bd->jar['lk_pre']) && !str_contains(implode(';', $rd->cookies), 'Secure') && !str_contains(implode(';', $rd->cookies), '__Host-'));
$mprod = lk_make($f . '/prodenv', ['LK_ENV' => 'development']);
$bp = new TestBrowser($mprod['app'], $mprod['config']);
check('LK_ENV=development schwächt die Cookies nicht (https bleibt __Host-/Secure)', str_contains(implode(';', $bp->get('/admin/login')->cookies), '__Host-lk_pre') && str_contains(implode(';', $bp->last->cookies), 'Secure'));

// --- Einladung, Rollen, letzter Administrator ---
$m['clock']->advance(901); // Sperre der zweiten Stufe (oben absichtlich ausgelöst) ist vorbei
$admin = lk_login($m, 'lutz', TEST_PASSWORD, $secretLutz);
check('Admin angemeldet für Einladungstest', lk_in($admin));
$inv = $admin->post('/admin/users/invite', ['_csrf' => TestBrowser::csrf($admin->get('/admin/users')), 'role' => 'editor']);
preg_match('#https://lk\.test(/admin/invite/[0-9a-f]{64})#', $inv->body, $mm);
$link = $mm[1] ?? '';
check('Einladung: Link erzeugt, nur als Hash gespeichert', $link !== '' && $cnt($m, 'SELECT COUNT(*) FROM invites WHERE token_hash = ?', [$m['app']->services()->keys->hmac('ids', substr($link, strlen('/admin/invite/')))]) === 1 && $cnt($m, 'SELECT COUNT(*) FROM invites WHERE token_hash = ?', [hash('sha256', substr($link, strlen('/admin/invite/')))]) === 0);
$guest = new TestBrowser($m['app'], $m['config'], '203.0.113.50');
$gp = $guest->get($link);
check('Einladung: GET zeigt Formular, verbraucht nichts, Referrer-Policy no-referrer', $gp->status === 200 && str_contains($gp->body, 'name="username"') && ($gp->headers['Referrer-Policy'] ?? '') === 'no-referrer' && $cnt($m, 'SELECT COUNT(*) FROM invites WHERE used_at IS NOT NULL') === 0);
$invalidBody = $guest->get('/admin/invite/' . str_repeat('0', 64))->body;
$taken = $guest->post($link, ['_csrf' => TestBrowser::csrf($gp), 'username' => 'lutz', 'password' => TEST_PASSWORD . 'x']);
check('Einladung: vergebener Name → Meldung, kein Verbrauch', $taken->status === 400 && $cnt($m, 'SELECT COUNT(*) FROM invites WHERE used_at IS NOT NULL') === 0);
$ok = $guest->post($link, ['_csrf' => TestBrowser::csrf($guest->get($link)), 'username' => 'Sonja', 'password' => 'Wiesenblume-Abendrot-42']);
check('Einladung: weiter zur TOTP-Einrichtung, Konto noch nicht da', $ok->status === 303 && $cnt($m, "SELECT COUNT(*) FROM users WHERE username='sonja'") === 0);
$en = $guest->get('/admin/enroll');
$secSonja = TestBrowser::secret($en);
$dn = $guest->post('/admin/enroll', ['_csrf' => TestBrowser::csrf($en), 'code' => lk_totp($secSonja, $m['clock'])]);
check('Einladung: Konto (Redaktion) nach TOTP, Einladung verbraucht', $dn->status === 200 && $cnt($m, "SELECT COUNT(*) FROM users WHERE username='sonja' AND role='editor'") === 1 && $cnt($m, 'SELECT COUNT(*) FROM invites WHERE used_at IS NOT NULL') === 1);
check('Einladung: benutzter Link zeigt dieselbe Seite wie ein unbekannter Link', $guest->get($link)->body === $invalidBody);
$inv2 = $admin->post('/admin/users/invite', ['_csrf' => TestBrowser::csrf($admin->get('/admin/users')), 'role' => 'editor']);
preg_match('#(/admin/invite/[0-9a-f]{64})#', $inv2->body, $mm2);
$m['clock']->advance(86401 + 31);
check('Einladung: nach 24 Stunden ungültig', preg_match('/ungültig/', (new TestBrowser($m['app'], $m['config']))->get($mm2[1])->body) === 1);
$m['clock']->advance(31);
// Editor
$ed = lk_login($m, 'sonja', 'Wiesenblume-Abendrot-42', $secSonja);
check('Editor angemeldet, ohne Zugriff auf Konten (403)', lk_in($ed) && $ed->get('/admin/users')->status === 403 && $ed->post('/admin/users/invite', ['_csrf' => TestBrowser::csrf($ed->get('/admin')), 'role' => 'admin'])->status === 403);
check('Editor sieht nur eigene Protokollereignisse', (static function () use ($ed): bool {
    $t = $ed->get('/admin/log')->body;
    return !str_contains($t, '>lutz<') && !str_contains($t, 'Protokoll unversehrt');
})());
check('Authentifizierter POST ohne CSRF-Token → 403', $ed->post('/admin/account/codes', ['current_password' => 'Wiesenblume-Abendrot-42'])->status === 403);

// Letzter Administrator
$adm = lk_login($m, 'lutz', TEST_PASSWORD, $secretLutz);
$cs = TestBrowser::csrf($adm->get('/admin/users'));
check('Admin kann sich nicht selbst sperren', $adm->post('/admin/users/1/disable', ['_csrf' => $cs])->status === 400);
$svc = $m['app']->services();
$sonjaId = (int) $svc->db->value("SELECT id FROM users WHERE username='sonja'");
check('Rolle: letzten Administrator nicht herabstufen', $svc->accounts->setRole($sonjaId, 1, 'editor', '') === 'Das ist der letzte aktive Administrator.');
check('Editor sperren geht', $adm->post('/admin/users/' . $sonjaId . '/disable', ['_csrf' => $cs])->status === 200 && !lk_in($ed));
check('Gesperrtes Konto: laufende Sitzung sofort ungültig, Anmeldung scheitert', !lk_in($ed) && $att($m, 'sonja', '203.0.113.60', 'Wiesenblume-Abendrot-42') === 401);
$adm->post('/admin/users/' . $sonjaId . '/enable', ['_csrf' => $cs]);

// Prüfung 08: A3 fremder POST ohne Cookie darf das Sitzungs-Cookie nicht löschen (erzwungene Abmeldung)
$foreign = (new TestBrowser($m['app'], $m['config']))->post('/admin/login/totp', ['code' => '000000']);
check('A3: POST /admin/login/totp ohne Cookie setzt kein Lösch-Cookie', $foreign->status === 303 && $foreign->cookies === []);
// A4: Sitzungen eines unbekannten Kontos beenden → 404, kein Fehler 500
check('A4: Sitzungen beenden für Konto 999 → 404', $adm->post('/admin/users/999/sessions', ['_csrf' => $cs])->status === 404);
// A7: Einladungen eines gesperrten Administrators werden ungültig
$svc->db->run("INSERT INTO users(username, role, password_hash, totp_confirmed_at, totp_secret_enc, created_at, webauthn_handle) VALUES('zweitadmin','admin',?,1,'x',1,'h')", [$svc->passwords->hash(TEST_PASSWORD)]);
$zweitId = (int) $svc->db->value("SELECT id FROM users WHERE username='zweitadmin'");
$tokZ = $svc->accounts->createInvite($zweitId, 'admin', '');
check('A7: Einladung des zweiten Admins gilt', $svc->enrollment->invite($tokZ) !== null);
check('A7: zweiten Admin sperren geht (Lutz bleibt)', $svc->accounts->setDisabled(1, $zweitId, true, '') === null);
check('A7: seine offene Einladung ist danach ungültig', $svc->enrollment->invite($tokZ) === null);
$svc->accounts->setDisabled(1, $zweitId, false, '');
$tokZ2 = $svc->accounts->createInvite($zweitId, 'editor', '');
check('A7: Herabstufen entwertet Einladungen ebenso', $svc->accounts->setRole(1, $zweitId, 'editor', '') === null && $svc->enrollment->invite($tokZ2) === null);
$svc->accounts->setRole(1, $zweitId, 'admin', '');
// A8: ein Admin ohne bestätigtes TOTP zählt nicht als Ersatz
$svc->db->run('UPDATE users SET totp_confirmed_at = NULL, totp_secret_enc = NULL WHERE id = ?', [$zweitId]);
check('A8: letzter anmeldefähiger Admin bleibt geschützt, obwohl ein zweiter (ohne TOTP) existiert', $svc->accounts->setRole($zweitId, 1, 'editor', '') === 'Das ist der letzte aktive Administrator.' && $svc->accounts->setDisabled($zweitId, 1, true, '') === 'Das ist der letzte aktive Administrator.');
$svc->db->run('UPDATE users SET disabled_at = 1 WHERE id = ?', [$zweitId]);

// --- Rücksetz-Link ---
$m['clock']->advance(31);
$ed = lk_login($m, 'sonja', 'Wiesenblume-Abendrot-42', $secSonja);
check('Editor wieder angemeldet', lk_in($ed));
$rs = $adm->post('/admin/users/' . $sonjaId . '/reset', ['_csrf' => $cs]);
preg_match('#(/admin/invite/[0-9a-f]{64})#', $rs->body, $mr);
check('Rücksetzen: Link erzeugt, Sitzungen/TOTP/Codes sofort ungültig', isset($mr[1]) && !lk_in($ed) && $cnt($m, "SELECT COUNT(*) FROM users WHERE id=$sonjaId AND totp_secret_enc IS NULL AND totp_confirmed_at IS NULL") === 1 && $cnt($m, "SELECT COUNT(*) FROM recovery_codes WHERE user_id=$sonjaId") === 0);
$m['clock']->advance(31);
check('Rücksetzen: bis zur Neueinrichtung keine Anmeldung möglich', $att($m, 'sonja', '203.0.113.61', 'Wiesenblume-Abendrot-42') === 401);
check('Eigenes Konto zurücksetzen ist über die Oberfläche nicht möglich', $adm->post('/admin/users/1/reset', ['_csrf' => $cs])->status === 400);
$rg = new TestBrowser($m['app'], $m['config'], '203.0.113.62');
$rp = $rg->get($mr[1]);
check('Rücksetzen: Formular zeigt festen Namen, kein Namensfeld', str_contains($rp->body, 'sonja') && !str_contains($rp->body, 'name="username"'));
$rg->post($mr[1], ['_csrf' => TestBrowser::csrf($rp), 'password' => 'Neues-Passwort-Gartenzaun-9']);
$en = $rg->get('/admin/enroll');
$secNew = TestBrowser::secret($en);
$rd = $rg->post('/admin/enroll', ['_csrf' => TestBrowser::csrf($en), 'code' => lk_totp($secNew, $m['clock'])]);
check('Rücksetzen: nach neuem TOTP angemeldet, neue Codes', $rd->status === 200 && count(TestBrowser::codes($rd)) === 10 && lk_in($rg) && $secNew !== $secSonja);
check('Rücksetzen: Link danach verbraucht', str_contains((new TestBrowser($m['app'], $m['config']))->get($mr[1])->body, 'ungültig'));
$m['clock']->advance(31);
check('Rücksetzen: altes Passwort gilt nicht mehr, neues schon', $att($m, 'sonja', '203.0.113.63', 'Wiesenblume-Abendrot-42') === 401 && lk_in(lk_login($m, 'sonja', 'Neues-Passwort-Gartenzaun-9', $secNew)));

// --- Konto: Passwort ändern, Codes neu ---
$m['clock']->advance(31);
$me2 = lk_login($m, 'sonja', 'Neues-Passwort-Gartenzaun-9', $secNew);
$m['clock']->advance(31);
$meB = lk_login($m, 'sonja', 'Neues-Passwort-Gartenzaun-9', $secNew); // zweite Sitzung
$acc = $me2->get('/admin/account');
$cc = TestBrowser::csrf($acc);
$m['clock']->advance(31);
$chg = ['_csrf' => $cc, 'current_password' => 'falsch-falsch-falsch', 'code' => lk_totp($secNew, $m['clock']), 'new_password' => 'Allerneuestes-Passwort-55x', 'new_password2' => 'Allerneuestes-Passwort-55x'];
check('Passwort ändern: falsches aktuelles Passwort abgelehnt', $me2->post('/admin/account/password', $chg)->status === 400);
$chg['current_password'] = 'Neues-Passwort-Gartenzaun-9';
$chg['code'] = '000000';
check('Passwort ändern: falscher TOTP-Code abgelehnt', $me2->post('/admin/account/password', $chg)->status === 400);
$chg['code'] = lk_totp($secNew, $m['clock']);
$chg['new_password'] = $chg['new_password2'] = 'kurz';
check('Passwort ändern: schwaches neues Passwort abgelehnt', $me2->post('/admin/account/password', $chg)->status === 400);
$chg['new_password'] = $chg['new_password2'] = 'Allerneuestes-Passwort-55x';
$okc = $me2->post('/admin/account/password', $chg);
check('Passwort ändern: mit Passwort und TOTP erfolgreich, andere Sitzungen beendet', $okc->status === 200 && lk_in($me2) && !lk_in($meB));
check('Passwort ändern: Wiederverwendung desselben TOTP-Schritts abgelehnt', $me2->post('/admin/account/password', $chg)->status === 400);
$cc2 = TestBrowser::csrf($me2->get('/admin/account'));
$m['clock']->advance(31);
check('Codes neu erzeugen: falsches Passwort abgelehnt', $me2->post('/admin/account/codes', ['_csrf' => $cc2, 'current_password' => 'falsch-falsch-falsch', 'code' => lk_totp($secNew, $m['clock'])])->status === 400);
check('Codes neu erzeugen: ohne oder mit falschem TOTP-Code abgelehnt', $me2->post('/admin/account/codes', ['_csrf' => $cc2, 'current_password' => 'Allerneuestes-Passwort-55x'])->status === 400
    && $me2->post('/admin/account/codes', ['_csrf' => $cc2, 'current_password' => 'Allerneuestes-Passwort-55x', 'code' => '000000'])->status === 400);
$newc = $me2->post('/admin/account/codes', ['_csrf' => $cc2, 'current_password' => 'Allerneuestes-Passwort-55x', 'code' => lk_totp($secNew, $m['clock'])]);
check('Codes neu erzeugen: mit Passwort und TOTP, no-store, zehn Codes', $newc->status === 200 && count(TestBrowser::codes($newc)) === 10 && ($newc->headers['Cache-Control'] ?? '') === 'no-store');

// --- Notfall-Rücksetzung für den Administrator ---
$mr0 = lk_make($f . '/reset0');
check('Notfallroute ohne LK_RESET_TOKEN: GET/POST 404', (new TestBrowser($mr0['app'], $mr0['config']))->get('/admin/reset')->status === 404 && (new TestBrowser($mr0['app'], $mr0['config']))->post('/admin/reset', ['token' => 'x'])->status === 404);
$mr1 = lk_make($f . '/reset1', ['LK_RESET_TOKEN' => TEST_RESET_TOKEN]);
$su = lk_setup($mr1);
$mr1['clock']->advance(31);
$g = new TestBrowser($mr1['app'], $mr1['config'], '203.0.113.90');
$rpg = $g->get('/admin/reset');
check('Notfallroute mit Token: Formular', $rpg->status === 200);
$bad = $g->post('/admin/reset', ['_csrf' => TestBrowser::csrf($rpg), 'token' => 'falsch', 'username' => 'lutz']);
check('Notfallroute: falsches Token abgelehnt, nichts passiert', $bad->status === 400 && (int) $mr1['app']->services()->db->value('SELECT COUNT(*) FROM invites') === 0);
$unk = $g->post('/admin/reset', ['_csrf' => TestBrowser::csrf($g->get('/admin/reset')), 'token' => TEST_RESET_TOKEN, 'username' => 'gibtsnicht']);
check('Notfallroute: unbekannter Name → gleiche Seite mit Link, aber keine Einladung, Token nicht verbraucht', $unk->status === 200 && preg_match('#/admin/invite/[0-9a-f]{64}#', $unk->body) === 1 && (int) $mr1['app']->services()->db->value('SELECT COUNT(*) FROM invites') === 0 && $g->get('/admin/reset')->status === 200);
$known = $g->post('/admin/reset', ['_csrf' => TestBrowser::csrf($g->get('/admin/reset')), 'token' => TEST_RESET_TOKEN, 'username' => 'lutz']);
check('Notfallroute: bekannter Name → Link, gleiche Seitenform', $known->status === 200 && preg_match('#/admin/invite/[0-9a-f]{64}#', $known->body) === 1 && (int) $mr1['app']->services()->db->value('SELECT COUNT(*) FROM invites') === 1);
check('Notfallroute: Konto sofort zurückgesetzt, Sitzungen weg', !lk_in($su['b']) && (int) $mr1['app']->services()->db->value('SELECT COUNT(*) FROM users WHERE totp_confirmed_at IS NULL') === 1);
check('Notfallroute: danach wieder 404 (Token-Hash in meta)', $g->get('/admin/reset')->status === 404 && $g->post('/admin/reset', ['token' => TEST_RESET_TOKEN, 'username' => 'lutz'])->status === 404);
check('Notfall-Rücksetzung steht im Protokoll', (int) $mr1['app']->services()->db->value("SELECT COUNT(*) FROM audit_log WHERE event='emergency_reset'") === 1);
$mrRot = ['app' => new Lichtkammer\App(lk_config($f . '/reset1', ['LK_RESET_TOKEN' => TEST_RESET_TOKEN, 'LK_APP_KEY' => lk_key('neu'), 'LK_APP_KEY_PREVIOUS' => lk_key()]), new Lichtkammer\Auth\Clock(TEST_START + 1000), TEST_ARGON)];
check('Notfallroute: verbrauchtes Token bleibt nach Schlüsselrotation verbraucht (404)', (new TestBrowser($mrRot['app'], lk_config($f . '/reset1', ['LK_RESET_TOKEN' => TEST_RESET_TOKEN, 'LK_APP_KEY' => lk_key('neu'), 'LK_APP_KEY_PREVIOUS' => lk_key()])))->get('/admin/reset')->status === 404);
preg_match('#(/admin/invite/[0-9a-f]{64})#', $known->body, $ml);
$gl = new TestBrowser($mr1['app'], $mr1['config'], '203.0.113.91');
$lp = $gl->get($ml[1]);
$gl->post($ml[1], ['_csrf' => TestBrowser::csrf($lp), 'password' => 'Ganz-Neues-Admin-Passwort-31']);
$en = $gl->get('/admin/enroll');
$sn = TestBrowser::secret($en);
$gl->post('/admin/enroll', ['_csrf' => TestBrowser::csrf($en), 'code' => lk_totp($sn, $mr1['clock'])]);
$dsh = $gl->get('/admin');
check('Notfall: nach Neueinrichtung angemeldet, Banner zeigt Notfall-Rücksetzung und das benutzte Token', $dsh->status === 200 && str_contains($dsh->body, 'Notfall-Token') && str_contains($dsh->body, 'LK_RESET_TOKEN'));

// --- Datenordner im Webroot nur mit Ausnahme ---
$inRoot = lk_config($f . '/inroot', ['LK_DATA_DIR' => $f . '/inroot/public/data']);
$bad = new Lichtkammer\App($inRoot, new Lichtkammer\Auth\Clock(TEST_START), TEST_ARGON);
check('Datenordner im Webroot: Admin 503', (new TestBrowser($bad, $inRoot))->get('/admin/login')->status === 503);
$okRoot = lk_config($f . '/inroot2', ['LK_DATA_DIR' => $f . '/inroot2/public/data', 'LK_ALLOW_DATA_IN_WEBROOT' => '1']);
$mw = ['app' => new Lichtkammer\App($okRoot, new Lichtkammer\Auth\Clock(TEST_START), TEST_ARGON), 'config' => $okRoot, 'clock' => new Lichtkammer\Auth\Clock(TEST_START)];
$sw = lk_setup($mw);
check('Datenordner im Webroot mit Ausnahme: Warnung auf jeder Admin-Seite', str_contains($sw['b']->get('/admin')->body, 'LK_ALLOW_DATA_IN_WEBROOT'));

// --- Verteilung (Entwurf 15): Hilfe zur Schlüssel-Erzeugung auf der Seite „Admin nicht verfügbar“ ---
$mk = lk_make($f . '/nokey2', ['LK_APP_KEY' => '']);
$k1 = (new TestBrowser($mk['app'], $mk['config']))->get('/admin/login');
$k2 = (new TestBrowser($mk['app'], $mk['config']))->get('/admin/login');
preg_match('~LK_APP_KEY=([A-Za-z0-9+/=]+)\s+LK_SETUP_TOKEN=([0-9a-f]+)~', $k1->body, $km1);
preg_match('~LK_APP_KEY=([A-Za-z0-9+/=]+)\s+LK_SETUP_TOKEN=([0-9a-f]+)~', $k2->body, $km2);
check('Ohne Schlüssel: Seite bietet fertige Zeilen (32-Byte-Schlüssel, Token mit 48 Zeichen) zum Kopieren an', count($km1) === 3 && strlen((string) base64_decode($km1[1], true)) === 32 && strlen($km1[2]) === 48);
check('Ohne Schlüssel: die Werte sind bei jedem Aufruf neu und werden nicht gespeichert', count($km2) === 3 && $km1[1] !== $km2[1] && $km1[2] !== $km2[2]);

// --- Gerät 24 Stunden vertrauen (TOTP nur einmal am Tag) ---
$md = lk_make($f . '/device');
$sd = lk_setup($md);
$dbd = $md['app']->services()->db;
$ev = static fn (string $e): int => (int) $dbd->value('SELECT COUNT(*) FROM audit_log WHERE event = ?', [$e]);
$lastTotp = null;
$trustLogin = static function (array $m, string $secret, bool $trust, string $ip = '203.0.113.7') use (&$lastTotp): TestBrowser {
    $m['clock']->advance(31);
    $b = new TestBrowser($m['app'], $m['config'], $ip);
    $b->post('/admin/login', ['_csrf' => TestBrowser::csrf($b->get('/admin/login')), 'username' => 'lutz', 'password' => TEST_PASSWORD]);
    $t = $b->get('/admin/login/totp');
    $post = ['_csrf' => TestBrowser::csrf($t), 'code' => lk_totp($secret, $m['clock'])];
    if ($trust) {
        $post['trust'] = '1';
    }
    $lastTotp = $b->post('/admin/login/totp', $post);
    return $b;
};
$bn = $trustLogin($md, $sd['secret'], false);
check('Ohne Haken: volle Sitzung, aber kein Gerätecookie, kein Eintrag', lk_in($bn) && $bn->cookie('lk_dev') === '' && (int) $dbd->value('SELECT COUNT(*) FROM trusted_devices') === 0);
$md['clock']->advance(31);
$bn2 = new TestBrowser($md['app'], $md['config']);
$bn2->post('/admin/login', ['_csrf' => TestBrowser::csrf($bn2->get('/admin/login')), 'username' => 'lutz', 'password' => TEST_PASSWORD]);
$tf = $bn2->get('/admin/login/totp');
check('Ohne Haken: das Formular enthält das Feld „trust“, Vorgabe aus', str_contains($tf->body, 'name="trust" value="1">') && !str_contains($tf->body, 'value="1" checked'));
$tw = $bn2->post('/admin/login/totp', ['_csrf' => TestBrowser::csrf($tf), 'code' => '000000', 'trust' => '1']);
check('Falscher Code: der Haken bleibt gesetzt, kein Gerät vertraut', $tw->status === 401 && str_contains($tw->body, 'value="1" checked') && (int) $dbd->value('SELECT COUNT(*) FROM trusted_devices') === 0);

$bt = $trustLogin($md, $sd['secret'], true);
$devHdr = '';
foreach ($lastTotp->cookies as $c) {
    if (str_contains($c, 'lk_dev')) {
        $devHdr = $c;
    }
}
$dev = $bt->cookie('lk_dev');
check('Mit Haken: volle Sitzung, Cookie lk_dev mit 64 Hex-Zeichen, 24 Stunden, HttpOnly/Strict/Secure/__Host-', lk_in($bt) && preg_match('/^[0-9a-f]{64}$/', $dev) === 1
    && (static function () use ($bt): bool {
        foreach ($bt->jar as $k => $v) {
            if ($k === '__Host-lk_dev') {
                return true;
            }
        }
        return false;
    })());
check('Cookie-Attribute: Max-Age=86400, HttpOnly, SameSite=Strict, Secure', str_contains($devHdr, 'Max-Age=86400') && str_contains($devHdr, 'HttpOnly') && str_contains($devHdr, 'SameSite=Strict') && str_contains($devHdr, 'Secure') && str_starts_with($devHdr, '__Host-lk_dev='));
check('Mit Haken: nur der keyed Hash steht in der Datenbank, mit Ablauf in 24 Stunden', (int) $dbd->value('SELECT COUNT(*) FROM trusted_devices WHERE id_hash = ?', [$dev]) === 0
    && (int) $dbd->value('SELECT COUNT(*) FROM trusted_devices WHERE id_hash = ?', [$md['app']->services()->keys->hmac('ids', 'device:' . $dev)]) === 1
    && (int) $dbd->value('SELECT MIN(expires_at - created_at) FROM trusted_devices') === 86400 && $ev('device_trusted') >= 1);

// Anmeldung mit vertrautem Gerät: nur Passwort, ohne Code
$md['clock']->advance(60);
$bd = new TestBrowser($md['app'], $md['config']);
$bd->jar['__Host-lk_dev'] = $dev;
$rl = $bd->post('/admin/login', ['_csrf' => TestBrowser::csrf($bd->get('/admin/login')), 'username' => 'lutz', 'password' => TEST_PASSWORD]);
check('Vertrautes Gerät: Passwort genügt, direkt volle Sitzung', $rl->status === 303 && $rl->headers['Location'] === '/admin' && lk_in($bd) && $ev('device_trust_used') === 1);
check('Vertrautes Gerät: neue Wiederherstellungscodes gibt es nicht mit Passwort allein (kein dauerhafter Ersatz für den zweiten Faktor)',
    $bd->post('/admin/account/codes', ['_csrf' => TestBrowser::csrf($bd->get('/admin/account')), 'current_password' => TEST_PASSWORD])->status === 400
    && $bd->post('/admin/account/codes', ['_csrf' => TestBrowser::csrf($bd->get('/admin/account')), 'current_password' => TEST_PASSWORD, 'code' => lk_totp($sd['secret'], $md['clock'])])->status === 200);
$bw = new TestBrowser($md['app'], $md['config']);
$bw->jar['__Host-lk_dev'] = $dev;
$bw->post('/admin/login', ['_csrf' => TestBrowser::csrf($bw->get('/admin/login')), 'username' => 'lutz', 'password' => 'falsch-falsch-falsch-1']);
check('Vertrautes Gerät: falsches Passwort bleibt abgelehnt, keine Sitzung', !lk_in($bw));
$bx = new TestBrowser($md['app'], $md['config'], '203.0.113.7');
$bx->jar['__Host-lk_dev'] = str_repeat('a', 64);
$rx = $bx->post('/admin/login', ['_csrf' => TestBrowser::csrf($bx->get('/admin/login')), 'username' => 'lutz', 'password' => TEST_PASSWORD]);
$clearsDev = static fn (Lichtkammer\Response $r): bool => count(array_filter($r->cookies, static fn (string $c): bool => str_starts_with($c, '__Host-lk_dev=;') && str_contains($c, 'Max-Age=0'))) === 1;
check('Unbekanntes Gerätetoken: zweiter Faktor wird verlangt, das tote Cookie wird gelöscht, kein Protokolleintrag', !lk_in($bx) && $bx->get('/admin/login/totp')->status === 200
    && $clearsDev($rx) && $bx->cookie('lk_dev') === '' && $ev('device_trust_rejected') === 0);
$bk = new TestBrowser($md['app'], $md['config']);
$rk = $bk->post('/admin/login', ['_csrf' => TestBrowser::csrf($bk->get('/admin/login')), 'username' => 'lutz', 'password' => TEST_PASSWORD]);
check('Ohne Gerätecookie: kein Lösch-Cookie in der Antwort', !$clearsDev($rk));

// Anderes Konto und anderer Browser (User-Agent) erben nichts
$ms = lk_make($f . '/device2');
$s1 = lk_setup($ms);
$bt3 = $trustLogin($ms, $s1['secret'], true);
$dev3 = $bt3->cookie('lk_dev');
$ms['app']->services()->db->run("INSERT INTO users(username, display_name, role, password_hash, totp_secret_enc, totp_confirmed_at, created_at, webauthn_handle) SELECT 'zweite', '', 'editor', password_hash, totp_secret_enc, totp_confirmed_at, created_at, 'ab' || substr(webauthn_handle, 3) FROM users WHERE id = 1");
$ms['clock']->advance(31);
$bo = new TestBrowser($ms['app'], $ms['config']);
$bo->jar['__Host-lk_dev'] = $dev3;
$ro = $bo->post('/admin/login', ['_csrf' => TestBrowser::csrf($bo->get('/admin/login')), 'username' => 'zweite', 'password' => TEST_PASSWORD]);
$evs = static fn (string $detail): int => $cnt($ms, "SELECT COUNT(*) FROM audit_log WHERE event = 'device_trust_rejected' AND detail = ?", [$detail]);
check('Gerätetoken eines anderen Kontos gilt nicht: zweiter Faktor, Protokoll reason=user, Cookie gelöscht', !lk_in($bo) && $bo->get('/admin/login/totp')->status === 200
    && $evs('reason=user') === 1 && $cnt($ms, "SELECT COUNT(*) FROM audit_log WHERE event = 'device_trust_rejected' AND user_id = 2") === 1 && $clearsDev($ro));
$req2 = new Lichtkammer\Request(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/login', 'REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'Anderer/2', 'HTTPS' => 'on'], $ms['config'], [], ['__Host-lk_dev' => $dev3]);
$r2 = $ms['app']->services()->auth->login($req2, 'lutz', TEST_PASSWORD, $dev3);
check('Gerätetoken in einem anderen Browser (anderer User-Agent) gilt nicht, Protokoll reason=ua', $r2['trusted'] === false && $r2['device_rejected'] === true && $evs('reason=ua') === 1);
check('Zeile bleibt bei Ablehnung bestehen (Konto und Browser passen beim nächsten Mal wieder)', $cnt($ms, 'SELECT COUNT(*) FROM trusted_devices WHERE user_id = 1') === 1);
// Sperren eines Kontos widerruft dessen Geräte (hier eine direkt eingetragene Zeile für „zweite“)
$ms['app']->services()->db->run('INSERT INTO trusted_devices(id_hash, user_id, created_at, expires_at, ua_hash) VALUES(?, 2, ?, ?, ?)', ['x', $ms['clock']->now(), $ms['clock']->now() + 86400, 'y']);
$badm = $trustLogin($ms, $s1['secret'], false);
check('Konto sperren widerruft dessen vertraute Geräte', $badm->post('/admin/users/2/disable', ['_csrf' => TestBrowser::csrf($badm->get('/admin/users'))])->status === 200
    && $cnt($ms, 'SELECT COUNT(*) FROM trusted_devices WHERE user_id = 2') === 0 && $cnt($ms, 'SELECT COUNT(*) FROM trusted_devices WHERE user_id = 1') === 1);

// Ablauf nach 24 Stunden (fest, nicht verlängert)
$ms['clock']->advance(86400 - 100);
$bm = new TestBrowser($ms['app'], $ms['config']);
$bm->jar['__Host-lk_dev'] = $dev3;
$bm->post('/admin/login', ['_csrf' => TestBrowser::csrf($bm->get('/admin/login')), 'username' => 'lutz', 'password' => TEST_PASSWORD]);
check('Kurz vor Ablauf: noch vertraut', lk_in($bm));
$ms['clock']->advance(200);
$bm = new TestBrowser($ms['app'], $ms['config']);
$bm->jar['__Host-lk_dev'] = $dev3;
$bm->post('/admin/login', ['_csrf' => TestBrowser::csrf($bm->get('/admin/login')), 'username' => 'lutz', 'password' => TEST_PASSWORD]);
check('Nach 24 Stunden: zweiter Faktor wieder nötig, abgelaufene Zeile wird bei der nächsten Anmeldung entfernt', !lk_in($bm) && (int) $ms['app']->services()->db->value('SELECT COUNT(*) FROM trusted_devices WHERE user_id = 1') === 0);

// Wiederherstellungscode vertraut nicht
$mr = lk_make($f . '/device3');
$r1 = lk_setup($mr);
$mr['clock']->advance(31);
$br = new TestBrowser($mr['app'], $mr['config']);
$br->post('/admin/login', ['_csrf' => TestBrowser::csrf($br->get('/admin/login')), 'username' => 'lutz', 'password' => TEST_PASSWORD]);
$tt = $br->get('/admin/login/totp');
$br->post('/admin/login/totp', ['_csrf' => TestBrowser::csrf($tt), 'code' => $r1['codes'][0], 'trust' => '1']);
check('Wiederherstellungscode: angemeldet, aber kein Gerät vertraut', lk_in($br) && $br->cookie('lk_dev') === '' && (int) $mr['app']->services()->db->value('SELECT COUNT(*) FROM trusted_devices') === 0);

// Widerruf: Abmelden (nur dieses Gerät), Passwortänderung (alle), „Sitzungen beenden“ (alle)
$bl = $trustLogin($mr, $r1['secret'], true);
$bl2 = $trustLogin($mr, $r1['secret'], true, '203.0.113.9');
$bl->post('/admin/logout', ['_csrf' => TestBrowser::csrf($bl->get('/admin'))]);
check('Abmelden: Gerätecookie gelöscht, nur die eigene Zeile entfernt', $bl->cookie('lk_dev') === '' && $cnt($mr, 'SELECT COUNT(*) FROM trusted_devices') === 1
    && $cnt($mr, 'SELECT COUNT(*) FROM trusted_devices WHERE id_hash = ?', [$mr['app']->services()->keys->hmac('ids', 'device:' . $bl2->cookie('lk_dev'))]) === 1);
$ba = $trustLogin($mr, $r1['secret'], true);
$bb = $trustLogin($mr, $r1['secret'], true, '203.0.113.8');
check('Drei vertraute Geräte', $cnt($mr, 'SELECT COUNT(*) FROM trusted_devices') === 3);
$mr['clock']->advance(31);
$acc = $ba->get('/admin/account');
$chg = ['_csrf' => TestBrowser::csrf($acc), 'current_password' => TEST_PASSWORD, 'code' => lk_totp($r1['secret'], $mr['clock']), 'new_password' => 'Allerneuestes-Passwort-55x', 'new_password2' => 'Allerneuestes-Passwort-55x'];
$rp = $ba->post('/admin/account/password', $chg);
check('Passwortänderung: erfolgreich, eigenes Gerätecookie gelöscht, Hinweis nennt die Geräte', $rp->status === 200 && $clearsDev($rp) && $ba->cookie('lk_dev') === '' && str_contains($rp->body, 'vertraute Geräte'));
check('Passwortänderung widerruft alle vertrauten Geräte (auch das eigene)', $cnt($mr, 'SELECT COUNT(*) FROM trusted_devices') === 0);
$bz = new TestBrowser($mr['app'], $mr['config']);
$bz->jar['__Host-lk_dev'] = $bb->cookie('lk_dev');
$bz->post('/admin/login', ['_csrf' => TestBrowser::csrf($bz->get('/admin/login')), 'username' => 'lutz', 'password' => 'Allerneuestes-Passwort-55x']);
check('Altes Gerätetoken nach Passwortänderung wirkungslos', !lk_in($bz));

// Höchstzahl der Geräte je Konto
$mq = lk_make($f . '/device4');
$q1 = lk_setup($mq);
for ($i = 0; $i < 12; $i++) {
    $trustLogin($mq, $q1['secret'], true, '203.0.113.' . (20 + $i));
}
check('Höchstens 10 vertraute Geräte je Konto', $cnt($mq, 'SELECT COUNT(*) FROM trusted_devices WHERE user_id = 1') === 10);
check('Protokoll: Geräte-Ereignisse ohne Token', $cnt($mq, "SELECT COUNT(*) FROM audit_log WHERE event = 'device_trusted' AND detail = 'hours=24'") === 12 && $mq['app']->services()->audit->verify()['ok']);
$bq = $trustLogin($mq, $q1['secret'], true);
check('„Sitzungen beenden“ widerruft alle vertrauten Geräte des Kontos', $bq->post('/admin/users/1/sessions', ['_csrf' => TestBrowser::csrf($bq->get('/admin'))])->status === 200
    && $cnt($mq, 'SELECT COUNT(*) FROM trusted_devices WHERE user_id = 1') === 0 && !lk_in($bq));
