<?php
declare(strict_types=1);

namespace Lichtkammer\Auth;

use Lichtkammer\Config;
use Lichtkammer\Import\Importer;
use Lichtkammer\Media\Branch;
use Lichtkammer\Media\Browser;
use Lichtkammer\Media\Capabilities;
use Lichtkammer\Media\Catalog;
use Lichtkammer\Media\Files;
use Lichtkammer\Media\Folders;
use Lichtkammer\Media\Galleries;
use Lichtkammer\Media\Library;
use Lichtkammer\Media\OriginalsMover;
use Lichtkammer\Media\Uploads;
use Lichtkammer\Site\Builder;
use Lichtkammer\Site\Pages;
use Lichtkammer\Site\Settings;
use Lichtkammer\View;

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
        public readonly Library $library,
        public readonly Uploads $uploads,
        public readonly Galleries $galleries,
        public readonly Catalog $catalog,
        public readonly Pages $pages,
        public readonly Settings $settings,
        public readonly Builder $builder,
        public readonly Importer $importer,
        public readonly Folders $folders,
        public readonly Files $files,
        public readonly Branch $branch,
        public readonly Browser $browser,
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
        OriginalsMover::run($config); // Originale aus dem alten Ordner (Webroot) an den eingestellten Ort, ohne Datenverlust
        $keys = new Keys($config->appKey(), $config->appKeyPrevious());
        $db = Database::open($dataDir . '/lichtkammer.sqlite', $config->sqliteWal());
        $passwords = new Passwords($config->appDir . '/resources/common-passwords.txt', $argonOptions);
        $limiter = new RateLimiter($db, $keys, $clock);
        $sessions = new SessionStore($db, $keys, $clock, $config->sessionIdleSeconds(), $config->sessionAbsoluteSeconds());
        $audit = new Audit($db, $keys, $clock);
        $recovery = new RecoveryCodes($db, $keys, $clock);
        $cookies = new Cookies($config);
        $preCsrf = new PreCsrf($keys, $clock);
        $auth = new Auth($config, $db, $keys, $passwords, $limiter, $sessions, $audit, $recovery, $clock);
        $enrollment = new Enrollment($config, $db, $keys, $passwords, $limiter, $sessions, $audit, $recovery, $clock);
        $accounts = new Accounts($db, $keys, $sessions, $audit, $clock);
        $maxBytes = max(1, $config->int('LK_MAX_UPLOAD_MB', 100)) * 1048576;
        $library = new Library($db, $clock, $config->originalsDir(), $config->mediaDir(), Capabilities::engine(), $maxBytes);
        $maxPdf = max(1, $config->int('LK_MAX_FILE_MB', 50)) * 1048576;
        $folders = new Folders($db, $clock);
        $files = new Files($db, $clock, $dataDir, $config->mediaDir(), $maxPdf, $config->get('LK_BASE_URL'));
        $uploads = new Uploads($db, $clock, $library, $dataDir, $maxBytes, $files, $folders);
        $galleries = new Galleries($db, $clock);
        $catalog = new Catalog($db, $clock, $library, $config->originalsDir(), $config->mediaDir());
        $pages = new Pages($db, $clock);
        $settings = new Settings($db, $config);
        $builder = new Builder($db, $config, $clock, new View($config->appDir . '/templates', $config), $settings, $dataDir);
        $importer = new Importer($db, $clock, $library, $galleries, $catalog, $pages, $settings, $config->importDir(), $config->mediaDir());
        $branch = new Branch($db, $clock, $folders, $catalog, $files);
        $browser = new Browser($db);
        return new self($config, $clock, $db, $keys, $passwords, $limiter, $sessions, $audit, $recovery, $cookies, $preCsrf, $auth, $enrollment, $accounts, $dataDir, $library, $uploads, $galleries, $catalog, $pages, $settings, $builder, $importer, $folders, $files, $branch, $browser);
    }
}
