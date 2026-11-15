<?php
declare(strict_types=1);

// Kindprozess für den Wettlauftest: wartet auf die gemeinsame Startzeit und versucht dann die zweite Stufe.
require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/../support.php';

use Lichtkammer\Auth\Clock;
use Lichtkammer\Auth\Services;
use Lichtkammer\Request;

$a = json_decode((string) base64_decode($argv[1] ?? ''), true);
$config = lk_config($a['tmp']);
$s = Services::create($config, new Clock((int) $a['now']), TEST_ARGON);
$req = new Request(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'race'], $config);
while (microtime(true) < (float) $a['start']) {
    usleep(200);
}
echo $s->auth->secondFactor($req, (string) $a['sid'], (string) $a['code'])['status'];
