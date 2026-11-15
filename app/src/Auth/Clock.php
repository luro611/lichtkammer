<?php
declare(strict_types=1);

namespace Lichtkammer\Auth;

/** Zeitquelle; Tests setzen eine feste Zeit und schieben sie vor. */
class Clock
{
    public function __construct(private ?int $fixed = null)
    {
    }

    public function now(): int
    {
        return $this->fixed ?? time();
    }

    public function advance(int $seconds): void
    {
        $this->fixed = $this->now() + $seconds;
    }
}
