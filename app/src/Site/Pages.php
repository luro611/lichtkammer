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
     * @param array{title:string,body:string,status:string,nav:string,slug?:string,blocks?:?string} $f
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
                'INSERT INTO pages(slug, title, body, blocks, status, nav, sort_order, created_at, updated_at) VALUES(?,?,?,?,?,?,(SELECT COALESCE(MAX(sort_order),0)+1 FROM pages),?,?)',
                [$slug, $title, $body, $blocks ?? '', $status, $nav, $now, $now],
            );
            return $this->db->lastId();
        }
        $cur = $this->get($id) ?? throw new MediaException('Die Seite gibt es nicht (mehr).');
        $slug = (string) ($f['slug'] ?? '') !== '' ? $this->uniqueSlug((string) $f['slug'], $id) : (string) $cur['slug'];
        // Im Blockmodus bleibt body unberührt (Sicherung des alten Textes); beim Rückweg zu Text gilt der neue body.
        $keepBody = $blocks !== null && $blocks !== '';
        $this->db->run(
            'UPDATE pages SET slug=?, title=?, body=?, blocks=?, status=?, nav=?, updated_at=? WHERE id=?',
            [$slug, $title, $keepBody ? (string) $cur['body'] : $body, $blocks ?? (string) $cur['blocks'], $status, $nav, $now, $id],
        );
        return $id;
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM pages WHERE id = ?', [$id]);
    }

    public function move(int $id, string $direction): void
    {
        $ids = array_map('intval', array_column($this->all(), 'id'));
        $i = array_search($id, $ids, true);
        if ($i === false) {
            return;
        }
        $j = $direction === 'hoch' ? $i - 1 : $i + 1;
        if ($j < 0 || $j >= count($ids)) {
            return;
        }
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        $this->db->tx(function () use ($ids): void {
            foreach ($ids as $pos => $pid) {
                $this->db->run('UPDATE pages SET sort_order = ? WHERE id = ?', [$pos + 1, $pid]);
            }
        });
    }
}
