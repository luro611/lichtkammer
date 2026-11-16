<?php
declare(strict_types=1);

namespace Lichtkammer\Admin;

use Lichtkammer\Media\MediaException;
use Lichtkammer\Request;
use Lichtkammer\Response;

/** Umzug von WordPress (Schritt 7): Importpaket prüfen, Foto für Foto übernehmen, Bericht. Nur für Administratoren. */
trait ImportPages
{
    public function importPage(Request $req, array $p, ?array $sess): Response
    {
        $imp = $this->s->importer;
        $imp->prepareDir();
        $pack = $imp->load();
        $counts = $pack['m'] !== null ? $imp->counts($pack['m']) : null;
        $showReport = $counts !== null && $counts['done'] > 0;
        return $this->page('admin/import', [
            'title' => 'Import', 'pack' => $pack, 'counts' => $counts, 'dir' => $imp->dir(),
            'report' => $showReport || $this->s->db->value("SELECT 1 FROM import_map LIMIT 1") !== null ? $imp->report() : null,
            'error' => null,
        ], 200, $sess);
    }

    /** Nimmt das nächste Foto auf; Antwort als JSON für die Fortschrittsanzeige. */
    public function importStep(Request $req, array $p, ?array $sess): Response
    {
        $pack = $this->s->importer->load();
        if ($pack['m'] === null) {
            return Response::json(['error' => $pack['error'] !== '' ? $pack['error'] : 'Kein Importpaket gefunden.'], 400);
        }
        if ($req->query('retry') === '1') {
            $this->s->importer->retryFailed();
        }
        try {
            $r = $this->s->importer->nextPhoto((int) $sess['user_id'], $pack['m']);
        } catch (MediaException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
        if (!$r['done'] && $r['index'] === 1) {
            $this->auditLog('import_run', $sess, 'photos=' . $r['total'], $req);
        }
        return Response::json($r);
    }

    /** Galerien, Seiten, Dateien und Weiterleitungen anlegen, wenn alle Fotos da sind. */
    public function importFinish(Request $req, array $p, ?array $sess): Response
    {
        $pack = $this->s->importer->load();
        if ($pack['m'] === null) {
            return Response::json(['error' => 'Kein Importpaket gefunden.'], 400);
        }
        try {
            $r = $this->s->importer->finish((int) $sess['user_id'], $pack['m']);
        } catch (MediaException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
        $this->auditLog('import_finished', $sess, 'galleries=' . $r['galleries'] . ',pages=' . $r['pages'], $req);
        return Response::json($r);
    }

    public function importPurge(Request $req, array $p, ?array $sess): Response
    {
        $pack = $this->s->importer->load();
        if ($pack['m'] === null) {
            return $this->done('/admin/import', 'nothing');
        }
        try {
            $n = $this->s->importer->purge($pack['m']);
        } catch (MediaException $e) {
            $this->flash = null;
            $imp = $this->s->importer;
            return $this->page('admin/import', [
                'title' => 'Import', 'pack' => $pack, 'counts' => $imp->counts($pack['m']), 'dir' => $imp->dir(), 'report' => $imp->report(), 'error' => $e->getMessage(),
            ], 400, $sess);
        }
        $this->auditLog('import_purged', $sess, 'files=' . $n, $req);
        return $this->done('/admin/import', 'imported', $n);
    }
}
