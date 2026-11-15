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
