<?php
declare(strict_types=1);

namespace Lichtkammer\Admin;

use Lichtkammer\Backup\Restore;
use Lichtkammer\Backup\RestoreException;
use Lichtkammer\Request;
use Lichtkammer\Response;

/**
 * Sicherung einspielen (Entwurf 20, H2/E12).
 * Schritt 1 bei der Ersteinrichtung (Token, keine Konten): Datenbank und Dateien.
 * Schritt 2 für angemeldete Administratoren: Originale aus dem Bereitstellungsordner zurückholen.
 */
trait RestorePages
{
    public function restoreSetupForm(Request $req, array $p, ?array $sess): Response
    {
        if (!$this->s->enrollment->setupOpen()) {
            return Response::notFound();
        }
        $r = new Restore($this->config);
        $r->prepareDir();
        return $this->prePage($req, 'admin/restore_setup', ['title' => 'Sicherung einspielen', 'error' => null, 'info' => $r->inspect(), 'dir' => $r->dir()]);
    }

    public function restoreSetupPost(Request $req, array $p, ?array $sess): Response
    {
        if (!$this->s->enrollment->setupOpen()) {
            return Response::notFound();
        }
        $r = new Restore($this->config);
        $show = fn (string $error, int $status) => $this->prePage($req, 'admin/restore_setup', ['title' => 'Sicherung einspielen', 'error' => $error, 'info' => $r->inspect(), 'dir' => $r->dir()], $status);
        if (!$this->preOk($req)) {
            return $show(self::PRE_EXPIRED, 400);
        }
        $gate = $this->s->enrollment->tokenGate($req);
        if (!$gate['ok']) {
            return $show('Bitte später noch einmal versuchen.', 400);
        }
        if (!hash_equals($this->config->setupToken(), $req->input('token'))) {
            return $show('Das Einrichtungs-Token stimmt nicht.', 400);
        }
        $this->s->enrollment->release($gate['ids']);
        try {
            $res = $r->restoreDatabase($this->s->db, $this->s->keys, $this->s->clock, $this->ipPrefix($req));
        } catch (RestoreException $e) {
            return $show($e->getMessage(), 400);
        }
        return $this->page('admin/restore_done', ['title' => 'Sicherung eingespielt', 'res' => $res, 'pending' => $r->pendingOriginals()], 200);
    }

    public function restorePage(Request $req, array $p, ?array $sess): Response
    {
        return $this->restoreView($sess, null, null, 200);
    }

    public function restoreStep(Request $req, array $p, ?array $sess): Response
    {
        $r = new Restore($this->config);
        try {
            $res = $r->moveOriginals();
        } catch (RestoreException $e) {
            return $this->restoreView($sess, null, $e->getMessage(), 400);
        }
        $this->auditLog('originals_restored', $sess, 'moved=' . $res['moved'] . ',left=' . $res['remaining'] . ',bad=' . $res['bad'] . ',missing=' . $res['missing'], $req);
        return $this->restoreView($sess, $res, null, 200);
    }

    /** @param array<string,int>|null $res */
    private function restoreView(?array $sess, ?array $res, ?string $error, int $status): Response
    {
        $r = new Restore($this->config);
        return $this->page('admin/restore', [
            'title' => 'Sicherung: Originale', 'info' => $r->inspect(), 'dir' => $r->dir(), 'pending' => $r->pendingOriginals(),
            'res' => $res, 'error' => $error, 'absent' => Restore::missingOriginals($this->s->db, $this->config->originalsDir()),
        ], $status, $sess);
    }
}
