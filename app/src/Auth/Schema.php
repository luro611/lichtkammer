<?php
declare(strict_types=1);

namespace Lichtkammer\Auth;

/**
 * Migrationsläufer: nummerierte SQL-Schritte, jeder in einer Transaktion,
 * Stand in meta.schema_version. Neue Schritte nur hinten anhängen, alte nie ändern.
 */
final class Schema
{
    /** @return list<string> */
    public static function migrations(): array
    {
        return [
            // 1: Anmeldung (Schritt 2)
            <<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL DEFAULT '',
    role TEXT NOT NULL CHECK (role IN ('admin','editor')),
    password_hash TEXT NOT NULL,
    totp_secret_enc TEXT,
    totp_confirmed_at INTEGER,
    totp_last_step INTEGER NOT NULL DEFAULT 0,
    disabled_at INTEGER,
    created_at INTEGER NOT NULL,
    webauthn_handle TEXT NOT NULL
);
CREATE TABLE recovery_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id),
    code_hash TEXT NOT NULL,
    used_at INTEGER,
    UNIQUE (user_id, code_hash)
);
CREATE TABLE sessions (
    id_hash TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id),
    stage TEXT NOT NULL CHECK (stage IN ('password_ok','full')),
    csrf_token TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    last_seen_at INTEGER NOT NULL,
    ip_prefix TEXT NOT NULL DEFAULT '',
    ua_hash TEXT NOT NULL DEFAULT '',
    totp_failures INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX sessions_user ON sessions(user_id);
CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL,
    key_hash TEXT NOT NULL,
    at INTEGER NOT NULL
);
CREATE INDEX login_attempts_key ON login_attempts(kind, key_hash, at);
CREATE TABLE invites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token_hash TEXT NOT NULL UNIQUE,
    purpose TEXT NOT NULL CHECK (purpose IN ('new_account','reenroll')),
    user_id INTEGER REFERENCES users(id),
    role TEXT NOT NULL CHECK (role IN ('admin','editor')),
    expires_at INTEGER NOT NULL,
    used_at INTEGER,
    created_by INTEGER REFERENCES users(id),
    created_at INTEGER NOT NULL
);
CREATE TABLE meta (
    k TEXT PRIMARY KEY,
    v TEXT NOT NULL
);
CREATE TABLE audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    at INTEGER NOT NULL,
    user_id INTEGER REFERENCES users(id),
    event TEXT NOT NULL,
    detail TEXT NOT NULL DEFAULT '',
    ip_prefix TEXT NOT NULL DEFAULT '',
    chain TEXT NOT NULL
);
CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log
BEGIN SELECT RAISE(ABORT, 'audit_log ist nur zum Anhängen'); END;
CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log
BEGIN SELECT RAISE(ABORT, 'audit_log ist nur zum Anhängen'); END;
CREATE TABLE login_failures (
    ip_hash TEXT NOT NULL,
    minute INTEGER NOT NULL,
    count INTEGER NOT NULL,
    PRIMARY KEY (ip_hash, minute)
);
CREATE TABLE pending_enrollments (
    id_hash TEXT PRIMARY KEY,
    purpose TEXT NOT NULL CHECK (purpose IN ('setup','new_account','reenroll')),
    invite_id INTEGER REFERENCES invites(id),
    username TEXT NOT NULL,
    role TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    totp_secret_enc TEXT NOT NULL,
    csrf_token TEXT NOT NULL,
    failures INTEGER NOT NULL DEFAULT 0,
    expires_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);
SQL,
            // 2: Fotos, Galerien, Varianten, Uploads (Schritt 3)
            <<<'SQL'
CREATE TABLE galleries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    cover_photo_id INTEGER,
    sort_order INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published')),
    show_exif INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE TABLE photos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    gallery_id INTEGER REFERENCES galleries(id) ON DELETE SET NULL,
    token TEXT NOT NULL UNIQUE,
    original_name TEXT NOT NULL DEFAULT '',
    original_path TEXT NOT NULL UNIQUE,
    sha256 TEXT NOT NULL UNIQUE,
    bytes INTEGER NOT NULL,
    mime TEXT NOT NULL,
    width INTEGER NOT NULL DEFAULT 0,
    height INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','ready','failed')),
    error TEXT NOT NULL DEFAULT '',
    pipeline_version INTEGER NOT NULL DEFAULT 0,
    title TEXT NOT NULL DEFAULT '',
    caption TEXT NOT NULL DEFAULT '',
    alt_text TEXT NOT NULL DEFAULT '',
    photographer TEXT NOT NULL DEFAULT '',
    place TEXT NOT NULL DEFAULT '',
    year INTEGER,
    taken_at TEXT,
    exif_json TEXT NOT NULL DEFAULT '{}',
    has_gps INTEGER NOT NULL DEFAULT 0,
    blur_data_uri TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    rotation INTEGER NOT NULL DEFAULT 0,
    focus_x REAL,
    focus_y REAL,
    uploaded_by INTEGER REFERENCES users(id),
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    deleted_at INTEGER
);
CREATE INDEX photos_gallery ON photos(gallery_id, sort_order);
CREATE INDEX photos_status ON photos(status);
CREATE TABLE photo_variants (
    photo_id INTEGER NOT NULL REFERENCES photos(id) ON DELETE CASCADE,
    width INTEGER NOT NULL,
    height INTEGER NOT NULL,
    format TEXT NOT NULL CHECK (format IN ('avif','webp','jpeg')),
    path TEXT NOT NULL,
    bytes INTEGER NOT NULL,
    PRIMARY KEY (photo_id, width, format)
);
CREATE TABLE uploads (
    id TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id),
    original_name TEXT NOT NULL,
    size INTEGER NOT NULL,
    received INTEGER NOT NULL DEFAULT 0,
    gallery_id INTEGER REFERENCES galleries(id) ON DELETE SET NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
SQL,
            // 3: Galerie-Zuordnung n:m, Fotografen, Indizes (Schritt 4). Nur additiv.
            <<<'SQL'
CREATE TABLE gallery_photos (
    gallery_id INTEGER NOT NULL REFERENCES galleries(id) ON DELETE CASCADE,
    photo_id INTEGER NOT NULL REFERENCES photos(id) ON DELETE CASCADE,
    position INTEGER NOT NULL DEFAULT 0,
    added_at INTEGER NOT NULL,
    PRIMARY KEY (gallery_id, photo_id)
);
CREATE INDEX gallery_photos_order ON gallery_photos(gallery_id, position);
CREATE INDEX gallery_photos_photo ON gallery_photos(photo_id);
INSERT INTO gallery_photos(gallery_id, photo_id, position, added_at)
    SELECT gallery_id, id, sort_order, created_at FROM photos WHERE gallery_id IS NOT NULL;
CREATE TABLE photographers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    sort_order INTEGER NOT NULL DEFAULT 0
);
ALTER TABLE photos ADD COLUMN photographer_id INTEGER REFERENCES photographers(id) ON DELETE SET NULL;
INSERT OR IGNORE INTO photographers(name) SELECT DISTINCT photographer FROM photos WHERE photographer <> '';
UPDATE photos SET photographer_id = (SELECT id FROM photographers WHERE name = photos.photographer) WHERE photographer <> '';
CREATE INDEX photos_deleted ON photos(deleted_at);
CREATE INDEX photos_year ON photos(year);
SQL,
            // 4: einfache Seiten und Seiteneinstellungen (Schritt 5). Nur additiv.
            <<<'SQL'
CREATE TABLE pages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    body TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published')),
    nav TEXT NOT NULL DEFAULT 'none' CHECK (nav IN ('main','footer','none')),
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE TABLE site_settings (
    k TEXT PRIMARY KEY,
    v TEXT NOT NULL
);
SQL,
            // 5: Seiten aus Blöcken (Entwurf 12). Nur additiv: body bleibt unverändert erhalten, leer = einfacher Text (Markdown).
            <<<'SQL'
ALTER TABLE pages ADD COLUMN blocks TEXT NOT NULL DEFAULT '';
SQL,
            // 6: Umzug von WordPress (Schritt 7): Weiterleitungen alter Adressen und Zuordnung Quelle -> Lichtkammer. Nur additiv.
            <<<'SQL'
CREATE TABLE redirects (
    from_path TEXT PRIMARY KEY,
    kind TEXT NOT NULL CHECK (kind IN ('gallery','page','home','galleries','path')),
    target_id INTEGER,
    to_path TEXT NOT NULL DEFAULT '',
    created_at INTEGER NOT NULL
);
CREATE TABLE import_map (
    kind TEXT NOT NULL CHECK (kind IN ('photo','gallery','page','file')),
    wp_id INTEGER NOT NULL,
    local_id INTEGER NOT NULL DEFAULT 0,
    note TEXT NOT NULL DEFAULT '',
    PRIMARY KEY (kind, wp_id)
);
SQL,
            // 7: Schalter „Startseite“ je Foto (Entwurf 14): Fotos, die der Block „Startseiten-Fotos“ zeigt. Nur additiv.
            <<<'SQL'
ALTER TABLE photos ADD COLUMN on_home INTEGER NOT NULL DEFAULT 0;
CREATE INDEX photos_on_home ON photos(on_home) WHERE on_home = 1;
SQL,
            // 8: Schalter „Seitentitel anzeigen“ je Seite. Nur additiv, Vorgabe an.
            <<<'SQL'
ALTER TABLE pages ADD COLUMN show_title INTEGER NOT NULL DEFAULT 1;
SQL,
            // 9: „Dieses Gerät 24 Stunden vertrauen“: der zweite Faktor entfällt nur beim Anmelden mit Passwort. Nur additiv.
            <<<'SQL'
CREATE TABLE trusted_devices (
    id_hash TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id),
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    ua_hash TEXT NOT NULL DEFAULT ''
);
CREATE INDEX trusted_devices_user ON trusted_devices(user_id);
SQL,
        ];
    }

    public static function version(Database $db): int
    {
        $has = $db->value("SELECT 1 FROM sqlite_master WHERE type='table' AND name='meta'");
        if ($has === null) {
            return 0;
        }
        return (int) ($db->value("SELECT v FROM meta WHERE k='schema_version'") ?? 0);
    }

    public static function migrate(Database $db): void
    {
        $migrations = self::migrations();
        if (self::version($db) >= count($migrations)) {
            return;
        }
        foreach ($migrations as $i => $sql) {
            $target = $i + 1;
            $db->tx(static function () use ($db, $sql, $target): void {
                if (Schema::version($db) >= $target) { // anderer Prozess war schneller
                    return;
                }
                $db->pdo->exec($sql);
                $db->run("INSERT INTO meta(k,v) VALUES('schema_version', ?) ON CONFLICT(k) DO UPDATE SET v=excluded.v", [(string) $target]);
            });
        }
    }
}
