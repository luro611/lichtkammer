<?php
declare(strict_types=1);

namespace Lichtkammer\Backup;

/** Fehler beim Einspielen einer Sicherung. Die Meldung ist für Administratoren gedacht und enthält nie Schlüssel. */
final class RestoreException extends \RuntimeException
{
}
