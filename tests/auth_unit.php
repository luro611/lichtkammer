<?php
declare(strict_types=1);

// Bausteine der Anmeldung (Schritt 2): TOTP, Schlüssel, Passwörter, Codes, Zähler, Protokoll, Schema, QR.

use Lichtkammer\Admin\AdminController;
use Lichtkammer\Auth\Audit;
use Lichtkammer\Auth\Base32;
use Lichtkammer\Auth\ClientIp;
use Lichtkammer\Auth\Clock;
use Lichtkammer\Auth\ConfigError;
use Lichtkammer\Auth\Database;
use Lichtkammer\Auth\Keys;
use Lichtkammer\Auth\Passwords;
use Lichtkammer\Auth\Preflight;
use Lichtkammer\Auth\Qr;
use Lichtkammer\Auth\RecoveryCodes;
use Lichtkammer\Auth\Schema;
use Lichtkammer\Auth\Services;
use Lichtkammer\Auth\Totp;
use Lichtkammer\Config;
use Lichtkammer\Request;

require_once __DIR__ . '/support.php';

$u = lk_new_tmp($tmp, 'unit');

// --- TOTP: Testvektoren aus RFC 6238 (SHA-1, 6 Stellen = die letzten 6 der 8-stelligen Werte) ---
$rfc = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // "12345678901234567890"
foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037', 20000000000 => '353130'] as $t => $code) {
    check("TOTP RFC 6238 bei $t", Totp::code($rfc, Totp::step($t)) === $code);
}
check('Base32 hin und zurück', Base32::decode(Base32::encode("\x00\xffabc\x10")) === "\x00\xffabc\x10");
$now = 1_700_000_015;
$st = Totp::step($now);
check('TOTP: aktueller Schritt', Totp::verify($rfc, Totp::code($rfc, $st), $now, 0) === $st);
check('TOTP: Schritt davor (Uhrabweichung)', Totp::verify($rfc, Totp::code($rfc, $st - 1), $now, 0) === $st - 1);
check('TOTP: Schritt danach', Totp::verify($rfc, Totp::code($rfc, $st + 1), $now, 0) === $st + 1);
check('TOTP: zwei Schritte davor abgelehnt', Totp::verify($rfc, Totp::code($rfc, $st - 2), $now, 0) === null);
check('TOTP: zwei Schritte danach abgelehnt', Totp::verify($rfc, Totp::code($rfc, $st + 2), $now, 0) === null);
check('TOTP: Wiederverwendung desselben Schritts abgelehnt', Totp::verify($rfc, Totp::code($rfc, $st), $now, $st) === null);
check('TOTP: älterer Schritt nach neuerem abgelehnt', Totp::verify($rfc, Totp::code($rfc, $st - 1), $now, $st) === null);
check('TOTP: Leerzeichen im Code erlaubt', Totp::verify($rfc, substr(Totp::code($rfc, $st), 0, 3) . ' ' . substr(Totp::code($rfc, $st), 3), $now, 0) === $st);
check('TOTP: Buchstaben abgelehnt', Totp::verify($rfc, 'abcdef', $now, 0) === null);
check('TOTP: URI ohne Leerzeichen und mit Aussteller', str_starts_with(Totp::uri('ABC', 'lutz', 'Lichtkammer'), 'otpauth://totp/Lichtkammer:lutz?secret=ABC&issuer=Lichtkammer'));

// --- Schlüssel ---
foreach (['leer' => '', 'kurz' => base64_encode('kurz'), 'keinBase64' => '%%%%', 'gleicheBytes' => base64_encode(str_repeat('A', 32))] as $name => $bad) {
    try {
        new Keys($bad);
        check("Schlüssel $name wird abgelehnt", false);
    } catch (ConfigError $e) {
        check("Schlüssel $name wird abgelehnt", !str_contains($e->getMessage(), $bad) || $bad === '');
    }
}
$k = new Keys(lk_key('a'));
$tok = $k->encrypt('GEHEIM', 'users:totp:1');
check('Verschlüsselung: versioniertes Format', str_starts_with($tok, 'v1:' . $k->kid() . ':'));
check('Verschlüsselung: Rundreise', ($k->decrypt($tok, 'users:totp:1')['plain'] ?? null) === 'GEHEIM');
check('Verschlüsselung: Zusatzdaten anderer user_id entschlüsseln nicht', $k->decrypt($tok, 'users:totp:2') === null);
check('Verschlüsselung: Geheimtext enthält Klartext nicht', !str_contains($tok, 'GEHEIM'));
check('Verschlüsselung: anderer Schlüssel scheitert', (new Keys(lk_key('b')))->decrypt($tok, 'users:totp:1') === null);
$rot = new Keys(lk_key('b'), lk_key('a'));
check('Rotation: alter Schlüssel (PREVIOUS) entschlüsselt', ($rot->decrypt($tok, 'users:totp:1')['plain'] ?? null) === 'GEHEIM');
check('Rotation: Kennung des alten Schlüssels bleibt erkennbar', Keys::kidOfToken($tok) === $k->kid() && $rot->kid() !== $k->kid());
check('Schlüssel: kaputter Geheimtext liefert null', $k->decrypt('v1:' . $k->kid() . ':xx', 'a') === null && $k->decrypt('müll', 'a') === null);
check('Schlüssel: Teilschlüssel sind getrennt', $k->derive('totp') !== $k->derive('recovery') && $k->derive('audit') !== $k->derive('csrf'));

// --- Passwörter ---
$pw = new Passwords(__DIR__ . '/../app/resources/common-passwords.txt', TEST_ARGON);
check('Passwort: zu kurz', $pw->validate('kurz1234', 'lutz') !== null);
check('Passwort: 12 Zeichen gut', $pw->validate('Kaffeetasse7!', 'lutz') === null);
check('Passwort: zu lang', $pw->validate(str_repeat('a1', 130), 'lutz') !== null);
check('Passwort: Benutzername enthalten', $pw->validate('MeinLutzPasswort99', 'Lutz') !== null);
check('Passwort: häufig mit Ziffern', $pw->validate('passwort123456', 'lutz') !== null);
check('Passwort: Folge 123456789012', $pw->validate('123456789012', 'lutz') !== null);
check('Passwort: gleiche Zeichen', $pw->validate('aaaaaaaaaaaaaa', 'lutz') !== null);
check('Passwort: Buchstabenfolge', $pw->validate('abcdefghijklmnop', 'lutz') !== null);
check('Passwort: Liste ohne Kompositionsregeln (nur Kleinbuchstaben-Satz geht)', $pw->validate('sonnenblume kaffee regen', 'lutz') === null);
$hash = $pw->hash('Käse-Straße-Ömer-2026');
check('Passwort: Argon2id', str_starts_with($hash, '$argon2id$'));
check('Passwort: NFC/NFD gelten gleich', $pw->verify("K\u{0061}\u{0308}se-Straße-Ömer-2026", $hash));
check('Passwort: falsches scheitert', !$pw->verify('anderes', $hash));
$pw2 = new Passwords(__DIR__ . '/../app/resources/common-passwords.txt', ['memory_cost' => 16, 'time_cost' => 2, 'threads' => 1]);
check('Passwort: Rehash bei geänderten Parametern', $pw2->needsRehash($hash) && !$pw->needsRehash($hash));
check('Passwort: Namen normalisieren (Groß, NFD)', Passwords::normalizeUsername(" LUTZ\u{0301} ") === Passwords::normalizeUsername('lutź'));
check('Passwort: Benutzernamen-Muster', Passwords::validUsername('lutz.r-1') && !Passwords::validUsername('Lutz') && !Passwords::validUsername('ab') && !Passwords::validUsername('a b c'));
$before = $pw->verifications;
$pw->verifyDummy('x');
check('Passwort: Scheinprüfung läuft durch dieselbe Prüfung', $pw->verifications === $before + 1);

// --- Adressen ---
check('IP: IPv4-in-IPv6 wird IPv4', ClientIp::forms('::ffff:203.0.113.9')['key'] === '203.0.113.9');
check('IP: IPv4 Protokoll als /24', ClientIp::forms('203.0.113.9')['prefix'] === '203.0.113.0/24');
check('IP: IPv6 Zähler als /64', ClientIp::forms('2001:db8:1:2:aaaa:bbbb:cccc:dddd')['key'] === ClientIp::forms('2001:db8:1:2::1')['key']);
check('IP: IPv6 unterschiedliche /64 getrennt', ClientIp::forms('2001:db8:1:2::1')['key'] !== ClientIp::forms('2001:db8:1:3::1')['key']);
check('IP: IPv6 Protokoll als /48', ClientIp::forms('2001:db8:1:2::1')['prefix'] === '2001:db8:1::/48');
check('IP: Müll wird unbekannt', ClientIp::forms('kein-ip')['key'] === 'unbekannt');

// --- Weiterleitungsziel ---
check('next: //evil abgelehnt', AdminController::safeNext('//evil.example') === '/admin');
check('next: /\\evil abgelehnt', AdminController::safeNext('/\\evil') === '/admin');
check('next: Schema abgelehnt', AdminController::safeNext('https://evil.example/admin') === '/admin');
check('next: außerhalb /admin abgelehnt', AdminController::safeNext('/etwas') === '/admin');
check('next: /admin/log erlaubt', AdminController::safeNext('/admin/log') === '/admin/log');

// --- Anfrage: X-Forwarded-Proto, letzter Eintrag ---
$cfgProxy = lk_config($u . '/p1', ['LK_TRUST_PROXY' => '1']);
$cfgPlain = lk_config($u . '/p2');
$mk = static fn (string $xfp, Config $c): bool => (new Request(['HTTP_X_FORWARDED_PROTO' => $xfp], $c))->isHttps();
check('XFP "http, https" gilt mit Vertrauen (letzter Eintrag)', $mk('http, https', $cfgProxy));
check('XFP "https, http" gilt nicht (Client-Eintrag vorn)', !$mk('https, http', $cfgProxy));
check('XFP ohne LK_TRUST_PROXY ignoriert', !$mk('http, https', $cfgPlain));

// --- Startprüfung ---
$pf = static function (array $env, ?string $sub = null) use ($u): ?string {
    try {
        Preflight::check(lk_config($u . '/pf' . bin2hex(random_bytes(2)), $env));
        return null;
    } catch (ConfigError $e) {
        return $e->getMessage();
    }
};
check('Start: gute Konfiguration', $pf([]) === null);
check('Start: Schlüssel fehlt → Fehler', $pf(['LK_APP_KEY' => '']) !== null);
check('Start: LK_BASE_URL fehlt → Fehler', $pf(['LK_BASE_URL' => '']) !== null);
check('Start: Proxy-Vertrauen ohne Proxy-Liste → Fehler', $pf(['LK_TRUST_PROXY' => '1']) !== null);
check('Start: Proxy-Vertrauen mit Liste ok', $pf(['LK_TRUST_PROXY' => '1', 'LK_TRUSTED_PROXIES' => '10.0.0.5']) === null);
check('Start: zu kurzes Setup-Token → Fehler', $pf(['LK_SETUP_TOKEN' => 'kurz']) !== null);
$pubDir = $u . '/web/public';
mkdir($pubDir . '/data-inside', 0777, true);
$inWeb = static fn (array $extra): ?string => (static function () use ($u, $extra): ?string {
    try {
        $c = Config::load(__DIR__ . '/../app', $u . '/web/public', $extra + ['LK_APP_KEY' => lk_key(), 'LK_BASE_URL' => 'https://lk.test']);
        Preflight::check($c);
        return null;
    } catch (ConfigError $e) {
        return $e->getMessage();
    }
})();
check('Datenordner im Webroot wird abgelehnt', $inWeb(['LK_DATA_DIR' => $u . '/web/public/data-inside']) !== null);
check('Datenordner im Webroot (noch nicht angelegt) wird abgelehnt', $inWeb(['LK_DATA_DIR' => $u . '/web/public/neu/data']) !== null);
check('Datenordner im Webroot mit Ausnahme erlaubt', $inWeb(['LK_DATA_DIR' => $u . '/web/public/data-inside', 'LK_ALLOW_DATA_IN_WEBROOT' => '1']) === null);
$cfgW = Config::load(__DIR__ . '/../app', $u . '/web/public', ['LK_DATA_DIR' => $u . '/web/public/data-inside', 'LK_ALLOW_DATA_IN_WEBROOT' => '1']);
check('Ausnahme zeigt dauerhafte Warnung', Preflight::warnings($cfgW, realpath($u . '/web/public/data-inside') ?: '') !== []);
check('Datenordner draußen: keine Warnung', Preflight::warnings($cfgW, $u) === []);
check('Datenordner außerhalb: Sperrdateien angelegt', is_file(Preflight::prepareDataDir(lk_config($u . '/p3')) . '/.htaccess'));
check('Datenordner außerhalb: Rechte 0700', (fileperms(Preflight::prepareDataDir(lk_config($u . '/p3'))) & 0777) === 0700);
// .env im Webroot (all-inkl-Aufbau: app/ liegt im Webroot) wird nicht gelesen und gemeldet
mkdir($u . '/aw/app', 0777, true);
file_put_contents($u . '/aw/.env', "LK_APP_KEY=" . lk_key() . "\n");
$cfgAw = Config::load($u . '/aw/app', $u . '/aw', []);
check('.env im Webroot wird nicht gelesen', $cfgAw->appKey() === '' && $cfgAw->webrootEnvFound);

// --- Datenbank, Schema ---
$dbFile = $u . '/schema.sqlite';
$db = Database::open($dbFile);
check('Schema: Version nach erstem Öffnen', Schema::version($db) === count(Schema::migrations()));
check('Schema: Datei hat Rechte 0600', (fileperms($dbFile) & 0777) === 0600);
$db->run("INSERT INTO meta(k,v) VALUES('probe','1')");
$db2 = Database::open($dbFile);
check('Schema: zweites Öffnen führt nichts erneut aus (Daten bleiben)', $db2->value("SELECT v FROM meta WHERE k='probe'") === '1' && Schema::version($db2) === count(Schema::migrations()));
check('Schema: Foreign Keys an', (int) $db->value('PRAGMA foreign_keys') === 1);
check('Schema: busy_timeout 5000', (int) $db->value('PRAGMA busy_timeout') === 5000);
check('Schema: recursive_triggers an (A5)', (int) $db->value('PRAGMA recursive_triggers') === 1);

// --- Protokoll ---
$clock = new Clock(TEST_START);
$keys = new Keys(lk_key());
$audit = new Audit($db, $keys, $clock);
$db->run("INSERT INTO users(username, role, password_hash, created_at, webauthn_handle) VALUES('u','admin','x',1,'h')");
$audit->log('login_ok', 1, '', '203.0.113.0/24');
$clock->advance(5);
$audit->log('login_failed', 1, 'reason=password', '203.0.113.0/24');
$clock->advance(5);
$audit->log('logout', 1);
check('Protokoll: Kette unversehrt', $audit->verify()['ok'] && $audit->verify()['count'] === 3);
check('Protokoll: UPDATE verweigert', (static function () use ($db): bool {
    try {
        $db->run("UPDATE audit_log SET event='logout' WHERE id=1");
        return false;
    } catch (\PDOException $e) {
        return true;
    }
})());
check('Protokoll: DELETE verweigert', (static function () use ($db): bool {
    try {
        $db->run('DELETE FROM audit_log WHERE id=2');
        return false;
    } catch (\PDOException $e) {
        return true;
    }
})());
check('Protokoll: unbekanntes Ereignis abgelehnt', (static function () use ($audit): bool {
    try {
        $audit->log('freitext', 1);
        return false;
    } catch (\InvalidArgumentException $e) {
        return true;
    }
})());
check('Protokoll: Details nur aus Kennungen (Leerzeichen/Passwort abgelehnt)', (static function () use ($audit): bool {
    try {
        $audit->log('login_failed', 1, 'name=Meine Katze!');
        return false;
    } catch (\InvalidArgumentException $e) {
        return true;
    }
})());
// Fremdeingriff (Trigger umgehen, wie es jemand mit Dateizugriff könnte)
$db->pdo->exec('DROP TRIGGER audit_log_no_update');
$db->run("UPDATE audit_log SET detail='reason=geaendert' WHERE id=2");
$v = $audit->verify();
check('Protokoll: geänderte Zeile wird erkannt', !$v['ok'] && $v['broken_at'] === 2);
$db->run("UPDATE audit_log SET detail='reason=password' WHERE id=2");
check('Protokoll: wiederhergestellt ist unversehrt', $audit->verify()['ok']);
$db->pdo->exec('DROP TRIGGER audit_log_no_delete');
$db->run('DELETE FROM audit_log WHERE id=2');
$v = $audit->verify();
check('Protokoll: entfernte Zeile in der Mitte wird erkannt', !$v['ok'] && $v['broken_at'] === 3);
// Rotation: neuer Schlüssel schreibt weiter, alter wird zum Prüfen genutzt
$db3 = Database::open($u . '/rot.sqlite');
$db3->run("INSERT INTO users(username, role, password_hash, created_at, webauthn_handle) VALUES('u','admin','x',1,'h')");
$a1 = new Audit($db3, new Keys(lk_key('a')), $clock);
$a1->log('login_ok', 1);
$a2 = new Audit($db3, new Keys(lk_key('b'), lk_key('a')), $clock);
$a2->log('key_rotated', null, 'kid=neu');
$a2->log('login_ok', 1);
check('Protokoll: Prüfung über Schlüsselrotation hinweg', $a2->verify()['ok'] && $a2->verify()['count'] === 3);
check('Protokoll: ohne alten Schlüssel fällt die Rotation auf', !(new Audit($db3, new Keys(lk_key('b')), $clock))->verify()['ok']);
// Wettlauf-Ersatz: viele Einträge hintereinander ergeben eine lückenlose Kette
for ($i = 0; $i < 20; $i++) {
    $a2->log('login_ok', 1);
}
check('Protokoll: 20 Einträge lückenlos', $a2->verify()['ok'] && $a2->verify()['count'] === 23);
$replaced = false;
try {
    $db3->run("INSERT OR REPLACE INTO audit_log(id, at, user_id, event, detail, ip_prefix, chain) VALUES(1, 1, NULL, 'logout', '', '', 'x')");
    $replaced = true;
} catch (\PDOException) {
}
check('Protokoll: INSERT OR REPLACE wird vom Trigger abgewiesen (A5)', !$replaced && $a2->verify()['ok']);

// --- Wiederherstellungscodes ---
$rc = new RecoveryCodes($db, $keys, $clock);
check('Codes: Eingabe normalisieren', RecoveryCodes::normalize('abcd-o1lI2 3') === 'ABCD011123');
$codes = $rc->regenerate(1);
check('Codes: zehn Stück im Format XXXXX-XXXXX', count($codes) === 10 && preg_match('/^[0-9A-Z]{5}-[0-9A-Z]{5}$/', $codes[0]) === 1);
check('Codes: nur als HMAC gespeichert', $db->value('SELECT COUNT(*) FROM recovery_codes WHERE code_hash LIKE ?', ['%' . substr($codes[0], 0, 5) . '%']) === 0);
check('Codes: gültiger Code wird akzeptiert (auch klein/ohne Strich)', $rc->consume(1, strtolower(str_replace('-', '', $codes[0]))));
check('Codes: derselbe Code nur einmal', !$rc->consume(1, $codes[0]));
check('Codes: falscher Code scheitert', !$rc->consume(1, 'ZZZZZ-ZZZZZ'));
check('Codes: Code eines anderen Kontos scheitert', !$rc->consume(2, $codes[1]));
$db->run("INSERT OR IGNORE INTO users(id, username, role, password_hash, created_at, webauthn_handle) VALUES(2, 'u2','editor','x',1,'h2')");
$db->run('INSERT INTO recovery_codes(user_id, code_hash) SELECT 2, code_hash FROM recovery_codes WHERE user_id = 1 AND used_at IS NULL LIMIT 1');
$anyCopied = false;
foreach (array_slice($codes, 1) as $cc) {
    $anyCopied = $anyCopied || $rc->consume(2, $cc);
}
check('Codes: in ein anderes Konto kopierter Hash gilt dort nicht (A6)', !$anyCopied && (int) $db->value('SELECT COUNT(*) FROM recovery_codes WHERE user_id = 2 AND used_at IS NULL') === 1);
$db->run('DELETE FROM recovery_codes WHERE user_id = 2');
check('Codes: ein Code verbraucht, neun übrig', $rc->remaining(1) === 9);
$new = $rc->regenerate(1);
check('Codes: Neuerzeugung entwertet alle alten', !$rc->consume(1, $codes[2]) && $rc->remaining(1) === 10 && $rc->consume(1, $new[0]));

// --- QR ---
$svg = Qr::svg(Totp::uri('JBSWY3DPEHPK3PXP', 'lutz', 'Lichtkammer'), 'Test');
check('QR: viewBox vorhanden', str_contains($svg, 'viewBox="0 0 '));
check('QR: keine feste Breite/Höhe am svg-Element', preg_match('/^<svg[^>]*\s(width|height)=/', $svg) !== 1);
check('QR: kein style-Attribut (CSP)', !str_contains($svg, 'style='));
check('QR: enthält das Geheimnis nicht im Klartext', !str_contains($svg, 'JBSWY3DPEHPK3PXP'));
$mtx = Qr::matrix('a');
check('QR: Version 1 hat 21 Module und Suchmuster', count($mtx) === 21 && $mtx[0][0] && $mtx[0][6] && !$mtx[1][1] && $mtx[2][2]);
check('QR: zu langer Text wirft', (static function (): bool {
    try {
        Qr::matrix(str_repeat('x', 300));
        return false;
    } catch (\InvalidArgumentException $e) {
        return true;
    }
})());
