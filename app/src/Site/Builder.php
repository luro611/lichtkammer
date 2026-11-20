<?php
declare(strict_types=1);

namespace Lichtkammer\Site;

use Lichtkammer\Auth\Clock;
use Lichtkammer\Auth\Database;
use Lichtkammer\Config;
use Lichtkammer\Media\Files;
use Lichtkammer\View;

/**
 * Veröffentlichen mit Rückgängig. Jede Fassung ist ein Ordner data/site/releases/<ID>/ mit fertigem HTML und manifest.json.
 * Die aktive Fassung steht in data/site/current (atomar per rename geschrieben). Bilder liegen gemeinsam in media/.
 */
final class Builder
{
    /** Fassungen, die zurückgeholt werden können (nur HTML, wenige MB je Fassung; die aktive bleibt immer, auch wenn älter). */
    public const KEEP = 20;
    /** Datenbankkopien vor dem Bau: so viele wie Fassungen, denn zu jeder Fassung gehört der Datenstand davor. */
    public const KEEP_BACKUPS = 20;
    public const MAX_FILES = 2000;

    /** Nur für Tests: wird nach dem Bau und vor der Dateiprüfung aufgerufen (Rennen „Datei wird während des Veröffentlichens privat“). @var (\Closure():void)|null */
    public ?\Closure $beforeSwitch = null;

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly View $view,
        private readonly Settings $settings,
        private readonly string $dataDir,
        private readonly ?Files $files = null,
    ) {
    }

    public function siteDir(): string
    {
        return $this->dataDir . '/site';
    }

    public function renderer(bool $preview = false, string $prefix = ''): Renderer
    {
        return new Renderer($this->db, $this->config, $this->view, $this->settings, $preview, $prefix);
    }

    public static function validId(string $id): bool
    {
        return preg_match('/^\d{8}-\d{6}-[0-9a-f]{4}$/', $id) === 1;
    }

    public function current(): ?string
    {
        $id = trim((string) @file_get_contents($this->siteDir() . '/current'));
        return self::validId($id) && is_dir($this->siteDir() . '/releases/' . $id) ? $id : null;
    }

    /** @return array<string,mixed>|null */
    public function manifest(string $id): ?array
    {
        if (!self::validId($id)) {
            return null;
        }
        $m = json_decode((string) @file_get_contents($this->siteDir() . '/releases/' . $id . '/manifest.json'), true);
        return is_array($m) ? $m : null;
    }

    /** Gründe, warum ein Bau scheitern kann (Schlüssel = Code, auch für die Meldung nach dem Speichern). */
    public const FAIL_TEXT = [
        'ordner' => 'Der Ordner für die Fassungen konnte nicht angelegt werden.',
        'sperre' => 'Es läuft gerade ein anderes Veröffentlichen. Bitte gleich noch einmal versuchen.',
        'fehler' => 'Vor dem Veröffentlichen sind Fehler zu beheben (Einzelheiten unter „Veröffentlichen“).',
        'zuviele' => 'Die Seite hat mehr als 2000 Dateien; das ist nicht vorgesehen.',
        'datei' => 'Eine Datei wurde gerade privat gestellt oder gelöscht. Bitte noch einmal veröffentlichen.',
        'technisch' => 'Das Veröffentlichen ist fehlgeschlagen; die bisherige Seite bleibt unverändert.',
    ];

    /** Neueste zuerst. @return list<array<string,mixed>> */
    public function releases(): array
    {
        $out = [];
        $cur = $this->current();
        foreach (glob($this->siteDir() . '/releases/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            $m = $this->manifest($id);
            if ($m !== null) {
                $out[] = ['id' => $id, 'at' => (int) ($m['at'] ?? 0), 'user' => (int) ($m['user'] ?? 0), 'galleries' => (int) ($m['galleries'] ?? 0),
                    'photos' => (int) ($m['photos'] ?? 0), 'files' => count($m['files'] ?? []), 'active' => $id === $cur, 'gone_files' => $this->goneFiles($m)];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp($b['id'], $a['id']));
        return $out;
    }

    /** Dateien dieser Fassung, deren Adresse heute nicht mehr ausgeliefert wird (privat gestellt, gelöscht, andere Adresse). Alte Fassungen ohne file_urls: 0. @param array<string,mixed> $m */
    private function goneFiles(array $m): int
    {
        $n = 0;
        foreach (is_array($m['file_urls'] ?? null) ? $m['file_urls'] : [] as $url) {
            if (!is_string($url) || preg_match('#^/media/(dateien/[0-9a-f]{8}-[a-z0-9-]*\.[a-z]{3,4}|dokumente/[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.pdf)$#D', $url) !== 1
                || !is_file($this->config->mediaDir() . substr($url, 6))) {
                $n++;
            }
        }
        return $n;
    }

    /** Ohne Versionsnummern der Dateien (?v=…), damit ein neues Design nicht jede Seite „geändert“ meldet. */
    private static function fingerprint(string $content): string
    {
        return sha1(preg_replace('/\?v=\d+/', '', $content) ?? $content);
    }

    /**
     * Probelauf im Speicher: Fehler, Hinweise und Änderungen gegenüber der aktiven Fassung.
     *
     * @return array{redirects:list<array{from:string,to:string}>,errors:list<string>,warnings:list<string>,changes:list<array{kind:string,label:string}>,files:int,galleries:int,photos:int}
     */
    public function plan(): array
    {
        $r = $this->renderer();
        $files = $r->all();
        $cur = $this->current();
        $old = $cur !== null ? ($this->manifest($cur) ?? ['files' => [], 'labels' => []]) : ['files' => [], 'labels' => []];
        $changes = [];
        foreach ($files as $path => $content) {
            $label = $r->labels[$path] ?? $path;
            if (!isset($old['files'][$path])) {
                $changes[] = ['kind' => 'neu', 'label' => $label];
            } elseif ($old['files'][$path] !== self::fingerprint($content)) {
                $changes[] = ['kind' => 'geändert', 'label' => $label];
            }
        }
        foreach ($old['files'] as $path => $_) {
            if (!isset($files[$path])) {
                $changes[] = ['kind' => 'entfernt', 'label' => (string) ($old['labels'][$path] ?? $path)];
            }
        }
        $d = $r->data();
        $oldMap = $cur !== null ? json_decode((string) @file_get_contents($this->siteDir() . '/releases/' . $cur . '/_redirects.json'), true) : [];
        $newRedirects = [];
        foreach ($r->redirectMap as $from => $to) {
            if (!is_array($oldMap) || ($oldMap[$from] ?? null) !== $to) {
                $newRedirects[] = ['from' => $from . '/', 'to' => $to];
            }
        }
        return [
            'redirects' => $newRedirects,
            'errors' => $r->errors, 'warnings' => $r->warnings, 'changes' => $changes, 'files' => count($files),
            'galleries' => count($d['galleries']), 'photos' => count($r->photoIds()),
        ];
    }

    public function hasChanges(): bool
    {
        return $this->plan()['changes'] !== [];
    }

    /** Ob ein Foto auf der aktiven Fassung steht (Hinweis beim Löschen). @param list<int> $photoIds */
    public function isLive(array $photoIds): bool
    {
        $cur = $this->current();
        $m = $cur === null ? null : $this->manifest($cur);
        return $m !== null && array_intersect($photoIds, array_map('intval', $m['photo_ids'] ?? [])) !== [];
    }

    /**
     * @return array{ok:bool,id:string,error:string,code:string}
     */
    public function publish(int $userId): array
    {
        $fail = static fn (string $c): array => ['ok' => false, 'id' => '', 'error' => self::FAIL_TEXT[$c] ?? $c, 'code' => $c];
        $site = $this->siteDir();
        if (!is_dir($site . '/releases') && !@mkdir($site . '/releases', 0700, true) && !is_dir($site . '/releases')) {
            return $fail('ordner');
        }
        $lock = @fopen($site . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return $fail('sperre');
        }
        try {
            // Reste eines abgebrochenen Laufs (Zeitüberschreitung, Absturz); wir halten die Sperre, also läuft kein anderer Bau.
            foreach (glob($site . '/building-*', GLOB_ONLYDIR) ?: [] as $stale) {
                self::removeDir($stale);
            }
            $this->files?->repairPublicCopies(200); // fehlende öffentliche Kopien (z. B. nach einer Wiederherstellung) selbst nachziehen, bevor geprüft wird
            $r = $this->renderer();
            $files = $r->all();
            if ($r->errors !== []) {
                return $fail('fehler');
            }
            if (count($files) > self::MAX_FILES) {
                return $fail('zuviele');
            }
            @set_time_limit(120);
            $this->backupDatabase();
            $id = gmdate('Ymd-His', $this->clock->now()) . '-' . bin2hex(random_bytes(2));
            $tmp = $site . '/building-' . $id;
            $hashes = [];
            foreach ($files as $path => $content) {
                $rel = str_ends_with($path, '/') ? $path . 'index.html' : $path;
                $abs = $tmp . $rel;
                if (!is_dir(dirname($abs))) {
                    mkdir(dirname($abs), 0700, true);
                }
                if (file_put_contents($abs, $content) === false) {
                    throw new \RuntimeException('Schreiben fehlgeschlagen');
                }
                $hashes[$path] = self::fingerprint($content);
            }
            $d = $r->data();
            $manifest = ['id' => $id, 'at' => $this->clock->now(), 'user' => $userId, 'files' => $hashes, 'labels' => $r->labels,
                'galleries' => count($d['galleries']), 'photos' => count($r->photoIds()), 'photo_ids' => $r->photoIds(),
                'file_ids' => $r->fileIds(), 'file_urls' => $r->fileUrls()];
            file_put_contents($tmp . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($this->beforeSwitch !== null) {
                ($this->beforeSwitch)();
            }
            // Frische Prüfung der Dateien VOR dem Umbenennen: bei Abbruch bleibt keine wählbare Fassung zurück (Prüfung 45, B3).
            if ($r->recheckFiles() !== []) {
                self::removeDir($tmp);
                return $fail('datei');
            }
            if (!rename($tmp, $site . '/releases/' . $id)) {
                throw new \RuntimeException('Umbenennen fehlgeschlagen');
            }
            $this->activate($id);
            $this->prune();
            return ['ok' => true, 'id' => $id, 'error' => '', 'code' => ''];
        } catch (\Throwable $t) {
            error_log('Lichtkammer: Veröffentlichen: ' . $t::class . ': ' . $t->getMessage());
            foreach (glob($site . '/building-*', GLOB_ONLYDIR) ?: [] as $d) {
                self::removeDir($d);
            }
            return $fail('technisch');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function rollback(string $id): bool
    {
        if (!self::validId($id) || $this->manifest($id) === null || $id === $this->current()) {
            return false;
        }
        $lock = @fopen($this->siteDir() . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return false;
        }
        try {
            $this->activate($id);
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function activate(string $id): void
    {
        $site = $this->siteDir();
        $tmp = $site . '/current.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $id . "\n") === false || !rename($tmp, $site . '/current')) {
            @unlink($tmp);
            throw new \RuntimeException('Umschalten fehlgeschlagen');
        }
    }

    /** Die letzten KEEP Fassungen und die aktive bleiben. */
    private function prune(): void
    {
        $cur = $this->current();
        foreach (array_slice($this->releases(), self::KEEP) as $r) {
            if ($r['id'] !== $cur) {
                self::removeDir($this->siteDir() . '/releases/' . $r['id']);
            }
        }
    }

    /** Datenbankkopie vor dem Veröffentlichen; die letzten KEEP_BACKUPS bleiben. */
    private function backupDatabase(): void
    {
        $dir = $this->dataDir . '/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Sicherungsordner fehlt');
        }
        $file = $dir . '/lichtkammer-' . gmdate('Ymd-His', $this->clock->now()) . '-' . bin2hex(random_bytes(2)) . '.sqlite';
        $tmp = $file . '.tmp';
        $old = umask(0077);
        try {
            $this->db->pdo->exec('VACUUM INTO ' . $this->db->pdo->quote($tmp));
        } finally {
            umask($old);
        }
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Sicherung fehlgeschlagen');
        }
        $all = glob($dir . '/lichtkammer-*.sqlite') ?: [];
        rsort($all);
        foreach (array_slice($all, self::KEEP_BACKUPS) as $f) {
            @unlink($f);
        }
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
