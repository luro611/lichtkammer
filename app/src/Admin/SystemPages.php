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
        return $this->page('admin/system', [
            'title' => 'Systemprüfung',
            'items' => SystemCheck::run($this->config, $this->s->db, $this->s->clock, $this->s->dataDir),
        ], 200, $sess);
    }
}
