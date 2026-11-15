<?php
declare(strict_types=1);

namespace Lichtkammer\Auth;

use Lichtkammer\Config;

/** Baut alle Dienste der Anmeldung zusammen (eine Datenbankverbindung). */
final class Services
{
    public function __construct(
        public readonly Config $config,
        public readonly Clock $clock,
        public readonly Database $db,
        public readonly Keys $keys,
        public readonly Passwords $passwords,
        public readonly RateLimiter $limiter,
        public readonly SessionStore $sessions,
        public readonly Audit $audit,
        public readonly RecoveryCodes $recovery,
        public readonly Cookies $cookies,
        public readonly PreCsrf $preCsrf,
        public readonly Auth $auth,
        public readonly Enrollment $enrollment,
        public readonly Accounts $accounts,
        public readonly string $dataDir,
    ) {
    }

    /**
     * @param array{memory_cost:int,time_cost:int,threads:int}|null $argonOptions nur für Tests
     * @throws ConfigError
     */
    public static function create(Config $config, ?Clock $clock = null, ?array $argonOptions = null): self
    {
        $clock ??= new Clock();
        $dataDir = Preflight::check($config);
        $keys = new Keys($config->appKey(), $config->appKeyPrevious());
        $db = Database::open($dataDir . '/lichtkammer.sqlite', $config->sqliteWal());
        $passwords = new Passwords($config->appDir . '/resources/common-passwords.txt', $argonOptions);
        $limiter = new RateLimiter($db, $keys, $clock);
        $sessions = new SessionStore($db, $clock, $config->sessionIdleSeconds(), $config->sessionAbsoluteSeconds());
        $audit = new Audit($db, $keys, $clock);
        $recovery = new RecoveryCodes($db, $keys, $clock);
        $cookies = new Cookies($config);
        $preCsrf = new PreCsrf($keys, $clock);
        $auth = new Auth($config, $db, $keys, $passwords, $limiter, $sessions, $audit, $recovery, $clock);
        $enrollment = new Enrollment($config, $db, $keys, $passwords, $limiter, $sessions, $audit, $recovery, $clock);
        $accounts = new Accounts($db, $sessions, $audit, $clock);
        return new self($config, $clock, $db, $keys, $passwords, $limiter, $sessions, $audit, $recovery, $cookies, $preCsrf, $auth, $enrollment, $accounts, $dataDir);
    }
}
