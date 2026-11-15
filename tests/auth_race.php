<?php
declare(strict_types=1);

// Wettlauf: zwei PHP-Prozesse gleichzeitig mit demselben TOTP-Code bzw. Wiederherstellungscode – genau einer gewinnt.

use Lichtkammer\Request;

require_once __DIR__ . '/support.php';

$race = lk_new_tmp($tmp, 'race');
$m = lk_make($race);
$su = lk_setup($m);
$svc = $m['app']->services();
$req = new Request(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'race'], $m['config']);

/** @param list<array{sid:string,code:string}> $jobs @return list<string> */
$runRace = static function (array $jobs) use ($race, $m): array {
    $start = microtime(true) + 1.5;
    $procs = [];
    foreach ($jobs as $job) {
        $arg = base64_encode(json_encode($job + ['tmp' => $race, 'now' => $m['clock']->now(), 'start' => $start]));
        $p = proc_open([PHP_BINARY, __DIR__ . '/helpers/race_child.php', $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$p, $pipes];
    }
    $out = [];
    foreach ($procs as [$p, $pipes]) {
        $out[] = trim((string) stream_get_contents($pipes[1])) . trim((string) stream_get_contents($pipes[2]));
        proc_close($p);
    }
    return $out;
};

$sid = static function () use ($svc, $req): string {
    $r = $svc->auth->login($req, 'lutz', TEST_PASSWORD);
    return (string) $r['sid'];
};

$totpOk = true;
$recoveryOk = true;
for ($round = 0; $round < 3; $round++) {
    $m['clock']->advance(31);
    $a = $sid();
    $b = $sid();
    $code = lk_totp($su['secret'], $m['clock']);
    $res = $runRace([['sid' => $a, 'code' => $code], ['sid' => $b, 'code' => $code]]);
    sort($res);
    $totpOk = $totpOk && $res === ['fail', 'ok'];
    if ($res !== ['fail', 'ok']) {
        fwrite(STDERR, 'Wettlauf TOTP Runde ' . $round . ': ' . implode(',', $res) . "\n");
    }

    $m['clock']->advance(31);
    $a = $sid();
    $b = $sid();
    $res = $runRace([['sid' => $a, 'code' => $su['codes'][$round]], ['sid' => $b, 'code' => $su['codes'][$round]]]);
    sort($res);
    $recoveryOk = $recoveryOk && $res === ['fail', 'ok'];
    if ($res !== ['fail', 'ok']) {
        fwrite(STDERR, 'Wettlauf Code Runde ' . $round . ': ' . implode(',', $res) . "\n");
    }
}
check('Wettlauf: zwei Prozesse, derselbe TOTP-Code → genau einer gewinnt (3 Runden)', $totpOk);
check('Wettlauf: zwei Prozesse, derselbe Wiederherstellungscode → genau einer gewinnt (3 Runden)', $recoveryOk);
check('Wettlauf: Protokollkette danach unversehrt', $svc->audit->verify()['ok']);
