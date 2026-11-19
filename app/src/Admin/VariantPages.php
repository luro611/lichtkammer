<?php
declare(strict_types=1);

namespace Lichtkammer\Admin;

use Lichtkammer\Media\VariantRebuild;
use Lichtkammer\Request;
use Lichtkammer\Response;

/**
 * Bildvarianten für alle Fotos neu erzeugen (nach dem Einspielen einer Sicherung). Nur Administratoren, nur POST ändert etwas
 * (mit CSRF-Prüfung des Routers). Jeder Aufruf arbeitet höchstens etwa 20 Sekunden; der Stand liegt in der Datenbank.
 * Mit Skript läuft die Seite selbst Stück für Stück weiter, ohne Skript kommt je Klick ein Stück (Knopf „Weiter“).
 */
trait VariantPages
{
    private function variants(): VariantRebuild
    {
        return new VariantRebuild($this->s->db, $this->s->clock, $this->s->library, $this->config->originalsDir());
    }

    /** @param array<string,mixed>|null $res @param array<string,mixed> $sess */
    private function variantsView(array $sess, ?array $res = null, ?string $error = null, int $status = 200): Response
    {
        $v = $this->variants();
        return $this->page('admin/variants', [
            'title' => 'Bildvarianten', 'st' => $v->status(), 'res' => $res, 'run' => $v->runStats(), 'error' => $error,
            'engine' => $this->s->library->engine() !== null,
        ], $status, $sess);
    }

    /**
     * Ein Stück arbeiten (und vorher auf Wunsch neu beginnen). Schreibt die Protokolleinträge mit Zahlen.
     *
     * @return array<string,mixed>
     */
    private function variantsRun(Request $req, array $sess): array
    {
        $v = $this->variants();
        $fresh = $req->input('neu') === '1' || $req->query('neu') === '1';
        $force = $req->input('erzwingen') === '1' || $req->query('erzwingen') === '1';
        $started = false;
        if ($fresh) {
            $n = $v->start($force);
            $this->auditLog('variants_start', $sess, 'force=' . (int) $force . ',photos=' . $n, $req);
            $started = true;
        }
        $res = $v->step();
        if ($res['started'] && !$started) {
            $this->auditLog('variants_start', $sess, 'force=0,photos=' . $res['total'], $req);
        }
        if ($res['done']) {
            $r = $res['run'];
            $this->auditLog('variants_done', $sess, 'force=' . (int) $r['force'] . ',photos=' . $r['photos'] . ',created=' . $r['created'] . ',skipped=' . $r['skipped'] . ',failed=' . $r['failed'] . ',noorig=' . $r['noorig'], $req);
        }
        return $res;
    }

    public function variantsPage(Request $req, array $p, ?array $sess): Response
    {
        return $this->variantsView($sess);
    }

    /** Für das Skript auf der Seite: eine Runde, Antwort als JSON (nur Zahlen). */
    public function variantsStep(Request $req, array $p, ?array $sess): Response
    {
        if ($this->s->library->engine() === null) {
            return Response::json(['error' => 'Auf diesem Server ist keine Bildbibliothek (Imagick oder GD) verfügbar.'], 400);
        }
        $r = $this->variantsRun($req, $sess);
        unset($r['run']);
        return Response::json($r);
    }

    /** Ohne Skript: eine Runde, danach wieder die Seite. */
    public function variantsNext(Request $req, array $p, ?array $sess): Response
    {
        if ($this->s->library->engine() === null) {
            return $this->variantsView($sess, null, 'Auf diesem Server ist keine Bildbibliothek (Imagick oder GD) verfügbar.', 400);
        }
        return $this->variantsView($sess, $this->variantsRun($req, $sess));
    }
}
