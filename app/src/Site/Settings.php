<?php
declare(strict_types=1);

namespace Lichtkammer\Site;

use Lichtkammer\Auth\Database;
use Lichtkammer\Config;
use Lichtkammer\Media\Galleries;
use Lichtkammer\Media\MediaException;
use Lichtkammer\Media\SvgCheck;

/** Seiteneinstellungen (Seitenname, Hero-Text, Hero-Foto, Design). Vorgabe für den Namen ist LK_SITE_NAME. */
final class Settings
{
    /** Designs der öffentlichen Seite (Entwurf 25); Vorgabe und heutiges br-foto-Aussehen ist „warm“. */
    public const DESIGNS = ['warm' => 'Warm', 'klar' => 'Klar', 'studio' => 'Studio', 'blau' => 'Blau', 'rot' => 'Rot', 'gruen' => 'Grün'];
    public const DESIGN_DEFAULT = 'warm';

    public function __construct(private readonly Database $db, private readonly Config $config)
    {
    }

    /** @return array{site_name:string,tagline:string,hero_photo_id:int,home_blocks:string,hero_show:bool,design:string} */
    public function all(): array
    {
        $rows = $this->db->all('SELECT k, v FROM site_settings');
        $m = array_column($rows, 'v', 'k');
        $name = trim((string) ($m['site_name'] ?? ''));
        return [
            'site_name' => $name !== '' ? $name : $this->config->siteName(),
            'tagline' => (string) ($m['tagline'] ?? ''),
            'hero_photo_id' => (int) ($m['hero_photo_id'] ?? 0),
            'home_blocks' => (string) ($m['home_blocks'] ?? ''),
            'hero_show' => ($m['hero_show'] ?? '1') !== '0',
            'design' => self::design((string) ($m['public_design'] ?? '')),
        ];
    }

    /** Nur bekannte Designs; alles andere (leer, alt, unbekannt) ergibt die Vorgabe. */
    public static function design(string $v): string
    {
        return isset(self::DESIGNS[$v]) ? $v : self::DESIGN_DEFAULT;
    }

    /** Design der öffentlichen Seite speichern. Ein unbekannter Wert wird nicht übernommen (false). */
    public function saveDesign(string $design): bool
    {
        if (!isset(self::DESIGNS[$design])) {
            return false;
        }
        $this->put('public_design', $design);
        return true;
    }

    public function save(string $siteName, string $tagline, int $heroPhotoId, ?bool $heroShow = null): void
    {
        $vals = ['site_name' => Galleries::clip($siteName, 60), 'tagline' => Galleries::clip($tagline, 200), 'hero_photo_id' => (string) max(0, $heroPhotoId)];
        if ($heroShow !== null) {
            $vals['hero_show'] = $heroShow ? '1' : '0';
        }
        $this->db->tx(function () use ($vals): void {
            foreach ($vals as $k => $v) {
                $this->db->run('INSERT INTO site_settings(k, v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v', [$k, $v]);
            }
        });
    }

    /** Startseite aus Blöcken (Entwurf 14). Leer = bisherige feste Startseite. */
    public function saveHome(string $encoded): void
    {
        $this->db->run('INSERT INTO site_settings(k, v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v', ['home_blocks', $encoded]);
    }

    // --- Logos (Entwurf 14): mehrere hochladen, je eines für hell, dunkel und Favicon wählen ---

    public const LOGO_ROLES = ['light' => 'Logo für den hellen Modus', 'dark' => 'Logo für den dunklen Modus', 'favicon' => 'Favicon (Browser-Symbol)'];
    public const LOGO_MAX = 300000;
    public const LOGO_MAX_COUNT = 20;

    private function logoDir(): string
    {
        return $this->config->dataDir() . '/brand';
    }

    /** @return list<array{id:string,name:string,mime:string}> */
    public function logos(): array
    {
        $raw = json_decode((string) ($this->db->value("SELECT v FROM site_settings WHERE k = 'logos'") ?? ''), true);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $l) {
            if (is_array($l) && isset($l['id'], $l['name'], $l['mime']) && preg_match('/^[a-f0-9]{16}$/', (string) $l['id']) === 1 && in_array($l['mime'], ['image/svg+xml', 'image/png', 'image/webp', 'image/jpeg'], true)) {
                $out[] = ['id' => (string) $l['id'], 'name' => (string) $l['name'], 'mime' => (string) $l['mime']];
            }
        }
        return $out;
    }

    /** @return array{light:string,dark:string,favicon:string} Logo-Kennungen je Zweck (leer = keines) */
    public function logoChoice(): array
    {
        $ids = array_column($this->logos(), 'id');
        $r = [];
        foreach (array_keys(self::LOGO_ROLES) as $role) {
            $v = (string) ($this->db->value('SELECT v FROM site_settings WHERE k = ?', ['logo_' . $role]) ?? '');
            $r[$role] = in_array($v, $ids, true) ? $v : '';
        }
        return $r;
    }

    private function put(string $k, string $v): void
    {
        $this->db->run('INSERT INTO site_settings(k, v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v', [$k, $v]);
    }

    /**
     * Logo aus einer Data-URL (aus dem Browser gelesen) übernehmen. SVG nur ohne Skripte und fremde Adressen.
     *
     * @throws MediaException
     */
    public function addLogo(string $name, string $dataUrl): string
    {
        if (preg_match('#^data:(image/(?:svg\+xml|png|webp|jpeg));base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m) !== 1) {
            throw new MediaException('Bitte eine SVG-, PNG-, WebP- oder JPEG-Datei wählen.');
        }
        $bytes = base64_decode(preg_replace('/\s+/', '', $m[2]) ?? '', true);
        if ($bytes === false || $bytes === '') {
            throw new MediaException('Die Datei konnte nicht gelesen werden.');
        }
        if (strlen($bytes) > self::LOGO_MAX) {
            throw new MediaException('Die Datei ist zu groß (höchstens ' . (int) (self::LOGO_MAX / 1000) . ' KB). Ein Logo sollte klein sein.');
        }
        $mime = $m[1];
        if ($mime === 'image/svg+xml') {
            $bytes = SvgCheck::sanitize($bytes)['svg']; // gemeinsame Prüfung mit der Medienverwaltung; gespeichert wird die bereinigte Fassung
        } else {
            $info = @getimagesizeFromString($bytes);
            $real = is_array($info) ? (string) ($info['mime'] ?? '') : '';
            if ($real !== $mime) {
                throw new MediaException('Der Inhalt der Datei passt nicht zum Dateityp.');
            }
        }
        $list = $this->logos();
        if (count($list) >= self::LOGO_MAX_COUNT) {
            throw new MediaException('Es sind schon ' . self::LOGO_MAX_COUNT . ' Logos gespeichert. Bitte erst eines löschen.');
        }
        $name = Galleries::clip(preg_replace('/\.(svg|png|webp|jpe?g)$/i', '', $name) ?? '', 80);
        $id = bin2hex(random_bytes(8));
        $dir = $this->logoDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new MediaException('Der Ordner für Logos ist nicht beschreibbar.');
        }
        if (@file_put_contents($dir . '/' . $id, $bytes) === false) {
            throw new MediaException('Das Logo konnte nicht gespeichert werden.');
        }
        $list[] = ['id' => $id, 'name' => $name !== '' ? $name : 'Logo', 'mime' => $mime];
        $this->put('logos', (string) json_encode($list, JSON_UNESCAPED_UNICODE));
        return $id;
    }

    public function deleteLogo(string $id): void
    {
        $list = array_values(array_filter($this->logos(), static fn (array $l): bool => $l['id'] !== $id));
        if (preg_match('/^[a-f0-9]{16}$/', $id) !== 1) {
            return;
        }
        @unlink($this->logoDir() . '/' . $id);
        $this->put('logos', (string) json_encode($list, JSON_UNESCAPED_UNICODE));
        foreach (array_keys(self::LOGO_ROLES) as $role) {
            if ((string) $this->db->value('SELECT v FROM site_settings WHERE k = ?', ['logo_' . $role]) === $id) {
                $this->put('logo_' . $role, '');
            }
        }
    }

    /** @param array<string,string> $choice Zweck => Logo-Kennung (leer = keines) */
    public function chooseLogos(array $choice): void
    {
        $ids = array_column($this->logos(), 'id');
        foreach (array_keys(self::LOGO_ROLES) as $role) {
            $v = (string) ($choice[$role] ?? '');
            $this->put('logo_' . $role, in_array($v, $ids, true) ? $v : '');
        }
    }

    /** Data-URL eines Logos für <img src>, null wenn nicht vorhanden. */
    public function logoUri(string $id): ?string
    {
        foreach ($this->logos() as $l) {
            if ($l['id'] === $id) {
                $bytes = @file_get_contents($this->logoDir() . '/' . $id);
                return $bytes === false ? null : 'data:' . $l['mime'] . ';base64,' . base64_encode($bytes);
            }
        }
        return null;
    }

    /** Hell- und Dunkel-Fassung des Logos (je nach Wahl; ist nur eines gewählt, gilt es für beide). @return array{light:?string,dark:?string} */
    public function logoPair(): array
    {
        $c = $this->logoChoice();
        $light = $c['light'] !== '' ? $this->logoUri($c['light']) : null;
        $dark = $c['dark'] !== '' ? $this->logoUri($c['dark']) : null;
        return ['light' => $light ?? $dark, 'dark' => $dark ?? $light];
    }
}
