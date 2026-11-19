<?php
declare(strict_types=1);

namespace Lichtkammer\Site;

use Lichtkammer\Auth\Clock;
use Lichtkammer\Auth\Database;
use Lichtkammer\Media\Galleries;
use Lichtkammer\Media\MediaException;

/** Einfache Seiten (Über uns, Impressum, …). Text als Markdown-Teilmenge, nie als rohes HTML. */
final class Pages
{
    public const RESERVED = ['admin', 'galerien', 'assets', 'media', 'brand', 'health', 'sitemap', 'robots', '404', 'vorschau', 'index'];

    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->all('SELECT * FROM pages ORDER BY sort_order, id');
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        return $this->db->one('SELECT * FROM pages WHERE id = ?', [$id]);
    }

    private function uniqueSlug(string $base, ?int $except): string
    {
        $base = Galleries::slugify($base);
        if ($base === 'galerie' || in_array($base, self::RESERVED, true)) {
            $base = $base . '-seite';
        }
        $slug = $base;
        for ($i = 2; $this->db->value('SELECT 1 FROM pages WHERE slug = ? AND id IS NOT ?', [$slug, $except]) !== null; $i++) {
            $slug = $base . '-' . $i;
        }
        return $slug;
    }

    /**
     * blocks: nicht gesetzt = Text/Blöcke unverändert lassen; '' = einfacher Text (Markdown in body); sonst Block-JSON (wird geprüft, body bleibt unberührt).
     *
     * @param array{title:string,body:string,status:string,nav:string,slug?:string,blocks?:?string,show_title?:?bool} $f
     */
    public function save(?int $id, array $f): int
    {
        $title = Galleries::clip($f['title'], 120);
        if ($title === '') {
            throw new MediaException('Bitte einen Titel eingeben.');
        }
        $body = str_replace("\r\n", "\n", $f['body']);
        if (mb_strlen($body) > 50000) {
            throw new MediaException('Der Text ist zu lang (höchstens 50.000 Zeichen).');
        }
        $status = $f['status'] === 'published' ? 'published' : 'draft';
        $nav = in_array($f['nav'], ['main', 'footer', 'none'], true) ? $f['nav'] : 'none';
        $blocks = null; // null = unverändert
        if (isset($f['blocks']) && $f['blocks'] !== '') {
            $blocks = Blocks::encode(Blocks::parse($f['blocks'], $this->db));
        } elseif (($f['blocks'] ?? null) === '') {
            $blocks = '';
        }
        $now = $this->clock->now();
        if ($id === null) {
            $slug = $this->uniqueSlug((string) ($f['slug'] ?? '') !== '' ? (string) $f['slug'] : $title, null);
            $this->db->run(
                'INSERT INTO pages(slug, title, body, blocks, status, nav, show_title, sort_order, created_at, updated_at) VALUES(?,?,?,?,?,?,?,(SELECT COALESCE(MAX(sort_order),0)+1 FROM pages),?,?)',
                [$slug, $title, $body, $blocks ?? '', $status, $nav, ($f['show_title'] ?? true) ? 1 : 0, $now, $now],
            );
            $newId = $this->db->lastId();
            $this->claimSlug($slug);
            return $newId;
        }
        $cur = $this->get($id) ?? throw new MediaException('Die Seite gibt es nicht (mehr).');
        $slug = (string) ($f['slug'] ?? '') !== '' ? $this->uniqueSlug((string) $f['slug'], $id) : (string) $cur['slug'];
        // Im Blockmodus bleibt body unberührt (Sicherung des alten Textes); beim Rückweg zu Text gilt der neue body.
        $keepBody = $blocks !== null && $blocks !== '';
        $this->db->run(
            'UPDATE pages SET slug=?, title=?, body=?, blocks=?, status=?, nav=?, show_title=?, updated_at=? WHERE id=?',
            [$slug, $title, $keepBody ? (string) $cur['body'] : $body, $blocks ?? (string) $cur['blocks'], $status, $nav, isset($f['show_title']) ? ($f['show_title'] ? 1 : 0) : (int) $cur['show_title'], $now, $id],
        );
        if ($slug !== (string) $cur['slug']) {
            $this->claimSlug($slug);
            if ((string) $cur['status'] === 'published') { // nur was online stand, braucht eine Weiterleitung
                $this->db->run("INSERT OR IGNORE INTO slug_history(kind, target_id, old_slug, created_at) VALUES('page',?,?,?)", [$id, (string) $cur['slug'], $now]);
            }
        }
        return $id;
    }

    /** Wer eine Adresse jetzt benutzt, hat Vorrang: eine alte Weiterleitung auf diese Adresse entfällt. */
    private function claimSlug(string $slug): void
    {
        $this->db->run("DELETE FROM slug_history WHERE kind = 'page' AND old_slug = ?", [$slug]);
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM pages WHERE id = ?', [$id]);
        $this->db->run("DELETE FROM slug_history WHERE kind = 'page' AND target_id = ?", [$id]); // Kennung könnte wiederverwendet werden
    }

    public function setNav(int $id, string $nav): void
    {
        if (!in_array($nav, ['main', 'footer', 'none'], true) || $this->get($id) === null) {
            return;
        }
        $this->db->run('UPDATE pages SET nav = ?, updated_at = ? WHERE id = ?', [$nav, $this->clock->now(), $id]);
    }

    /** Gleiche sort_order-Werte (Altbestand, Import) werden einmal sauber durchnummeriert. */
    private function normalize(): void
    {
        $rows = $this->all();
        $orders = array_map(static fn (array $p): int => (int) $p['sort_order'], $rows);
        if (count(array_unique($orders)) === count($orders)) {
            return;
        }
        $this->db->tx(function () use ($rows): void {
            foreach ($rows as $n => $p) {
                $this->db->run('UPDATE pages SET sort_order = ? WHERE id = ?', [$n + 1, $p['id']]);
            }
        });
    }

    /** Reihenfolge nur innerhalb der Gruppe (Menü, Fußzeile, nicht verlinkt): Menü und Fußzeile haben so getrennte Reihenfolgen. */
    public function move(int $id, string $direction): void
    {
        $this->normalize();
        $cur = $this->get($id);
        if ($cur === null) {
            return;
        }
        $group = array_values(array_filter($this->all(), static fn (array $p): bool => $p['nav'] === $cur['nav']));
        $i = array_search($id, array_map(static fn (array $p): int => (int) $p['id'], $group), true);
        $j = $i === false ? -1 : ($direction === 'hoch' ? $i - 1 : $i + 1);
        if ($i === false || $j < 0 || $j >= count($group)) {
            return;
        }
        $a = $group[$i];
        $b = $group[$j];
        $this->db->tx(function () use ($a, $b): void { // die beiden tauschen ihre Plätze, alle anderen bleiben
            $this->db->run('UPDATE pages SET sort_order = ? WHERE id = ?', [(int) $b['sort_order'], $a['id']]);
            $this->db->run('UPDATE pages SET sort_order = ? WHERE id = ?', [(int) $a['sort_order'], $b['id']]);
        });
    }
}
