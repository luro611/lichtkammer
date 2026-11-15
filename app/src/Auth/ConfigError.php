<?php
declare(strict_types=1);

namespace Lichtkammer\Auth;

/** Konfigurationsfehler, der den Admin am Start hindert. Die Meldung enthält nie Geheimnisse. */
final class ConfigError extends \RuntimeException
{
}
