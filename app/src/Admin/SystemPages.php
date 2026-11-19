<?php
declare(strict_types=1);

namespace Lichtkammer\Admin;

use Lichtkammer\Request;
use Lichtkammer\Response;

/** Systemprüfung für Administratoren (Entwurf 20, 1.6). Nur Lesen. */
trait SystemPages
{
    public function systemPage(Request $req, array $p, ?array $sess): Response
    {
        $items = SystemCheck::run($this->config, $this->s->db, $this->s->clock, $this->s->dataDir);
        $st = $this->variants()->status(); // volle Prüfung der Dateien, nicht der Zwischenspeicher des Warnbands
        $items[] = ['level' => $st['ok'] === $st['total'] ? 'ok' : 'warn', 'title' => 'Bildvarianten', 'text' => $st['ok'] . ' von ' . $st['total'] . ' Fotos haben Bildvarianten'
            . ($st['ok'] === $st['total'] ? '' : '. Die übrigen erscheinen nicht auf der Seite: Verwaltung → Bildvarianten (/admin/varianten) erzeugt sie neu.')];
        return $this->page('admin/system', [
            'title' => 'Systemprüfung',
            'items' => $items,
        ], 200, $sess);
    }
}
