<?php
declare(strict_types=1);

namespace Lichtkammer\Site;

use Lichtkammer\Auth\Database;
use Lichtkammer\Config;
use Lichtkammer\Media\Galleries;

/** Seiteneinstellungen (Seitenname, Hero-Text, Hero-Foto). Vorgabe für den Namen ist LK_SITE_NAME. */
final class Settings
{
    public function __construct(private readonly Database $db, private readonly Config $config)
    {
    }

    /** @return array{site_name:string,tagline:string,hero_photo_id:int} */
    public function all(): array
    {
        $rows = $this->db->all('SELECT k, v FROM site_settings');
        $m = array_column($rows, 'v', 'k');
        $name = trim((string) ($m['site_name'] ?? ''));
        return [
            'site_name' => $name !== '' ? $name : $this->config->siteName(),
            'tagline' => (string) ($m['tagline'] ?? ''),
            'hero_photo_id' => (int) ($m['hero_photo_id'] ?? 0),
        ];
    }

    public function save(string $siteName, string $tagline, int $heroPhotoId): void
    {
        $vals = ['site_name' => Galleries::clip($siteName, 60), 'tagline' => Galleries::clip($tagline, 200), 'hero_photo_id' => (string) max(0, $heroPhotoId)];
        $this->db->tx(function () use ($vals): void {
            foreach ($vals as $k => $v) {
                $this->db->run('INSERT INTO site_settings(k, v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v', [$k, $v]);
            }
        });
    }
}
