<?php
declare(strict_types=1);

// Hilfen für die Anmeldetests: Umgebung, kleiner Browser mit Cookie-Topf, Einrichtungsablauf.

use Lichtkammer\App;
use Lichtkammer\Auth\Clock;
use Lichtkammer\Auth\Totp;
use Lichtkammer\Config;
use Lichtkammer\Request;
use Lichtkammer\Response;

const TEST_ARGON = ['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1]; // schnell; echte Parameter stehen in Passwords
const TEST_START = 1_800_000_000;
const TEST_PASSWORD = 'Kaffeetasse-Morgenlicht-77';
const TEST_SETUP_TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef';
const TEST_RESET_TOKEN = 'fedcba9876543210fedcba9876543210fedcba9876543210';

function lk_key(string $seed = 'testkey'): string
{
    return base64_encode(hash('sha256', $seed, true));
}

/** @param array<string,string> $env */
function lk_config(string $tmp, array $env = []): Config
{
    @mkdir($tmp . '/public', 0777, true);
    $env += [
        'LK_APP_KEY' => lk_key(),
        'LK_BASE_URL' => 'https://lk.test',
        'LK_DATA_DIR' => $tmp . '/data',
        'LK_ORIGINALS_DIR' => $tmp . '/originals',
        'LK_SETUP_TOKEN' => TEST_SETUP_TOKEN,
    ];
    foreach ($env as $k => $v) {
        if ($v === '') {
            unset($env[$k]);
        }
    }
    return Config::load(__DIR__ . '/../app', $tmp . '/public', $env);
}

/** @return array{app:App,config:Config,clock:Clock} */
function lk_make(string $tmp, array $env = []): array
{
    $config = lk_config($tmp, $env);
    $clock = new Clock(TEST_START);
    return ['app' => new App($config, $clock, TEST_ARGON), 'config' => $config, 'clock' => $clock];
}

final class TestBrowser
{
    /** @var array<string,string> */
    public array $jar = [];
    public ?Response $last = null;

    public function __construct(private readonly App $app, private readonly Config $config, public string $ip = '203.0.113.7', public bool $https = true)
    {
    }

    /** @param array<string,string> $post @param array<string,string> $server */
    public function request(string $method, string $path, array $post = [], array $server = [], ?string $body = null): Response
    {
        $server += ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'REMOTE_ADDR' => $this->ip, 'HTTP_USER_AGENT' => 'TestBrowser/1'];
        if ($this->https) {
            $server['HTTPS'] = 'on';
        }
        $req = new Request($server, $this->config, $post, $this->jar, $body);
        $resp = $this->app->handle($req);
        foreach ($resp->cookies as $c) {
            $first = explode(';', $c)[0];
            [$name, $value] = explode('=', $first, 2);
            if (str_contains($c, 'Max-Age=0')) {
                unset($this->jar[$name]);
            } else {
                $this->jar[$name] = $value;
            }
        }
        return $this->last = $resp;
    }

    public function get(string $path): Response
    {
        return $this->request('GET', $path);
    }

    /** @param array<string,string> $post */
    public function post(string $path, array $post): Response
    {
        return $this->request('POST', $path, $post);
    }

    public function cookie(string $base): string
    {
        return $this->jar['__Host-' . $base] ?? $this->jar[$base] ?? '';
    }

    public static function csrf(Response $r): string
    {
        return preg_match('/name="_csrf" value="([^"]+)"/', $r->body, $m) === 1 ? html_entity_decode($m[1]) : '';
    }

    public static function secret(Response $r): string
    {
        return preg_match('/class="mono secret">([A-Z2-7 ]+)</', $r->body, $m) === 1 ? str_replace(' ', '', $m[1]) : '';
    }

    /** @return list<string> */
    public static function codes(Response $r): array
    {
        preg_match_all('/<li>([0-9A-Z]{5}-[0-9A-Z]{5})<\/li>/', $r->body, $m);
        return $m[1];
    }
}

function lk_totp(string $secret, Clock $clock, int $offsetSteps = 0): string
{
    return Totp::code($secret, Totp::step($clock->now()) + $offsetSteps);
}

/**
 * Ersteinrichtung bis zur Anzeige der Codes.
 *
 * @return array{b:TestBrowser,secret:string,codes:list<string>}
 */
function lk_setup(array $m, string $user = 'lutz', string $pw = TEST_PASSWORD, string $ip = '203.0.113.7'): array
{
    $b = new TestBrowser($m['app'], $m['config'], $ip);
    $page = $b->get('/admin/setup');
    $r = $b->post('/admin/setup', ['_csrf' => TestBrowser::csrf($page), 'token' => TEST_SETUP_TOKEN, 'username' => $user, 'password' => $pw]);
    $enroll = $b->get('/admin/enroll');
    $secret = TestBrowser::secret($enroll);
    $done = $b->post('/admin/enroll', ['_csrf' => TestBrowser::csrf($enroll), 'code' => lk_totp($secret, $m['clock'])]);
    return ['b' => $b, 'secret' => $secret, 'codes' => TestBrowser::codes($done)];
}

/** Meldet in einem frischen Browser an (zwei Stufen). Gibt den Browser und das letzte Ergebnis. */
function lk_login(array $m, string $user, string $pw, string $secret, string $ip = '203.0.113.7', ?string $code = null): TestBrowser
{
    $m['clock']->advance(31); // jeder Anmeldung ihr eigener TOTP-Schritt (Wiederverwendungsschutz)
    $b = new TestBrowser($m['app'], $m['config'], $ip);
    $page = $b->get('/admin/login');
    $b->post('/admin/login', ['_csrf' => TestBrowser::csrf($page), 'username' => $user, 'password' => $pw]);
    $t = $b->get('/admin/login/totp');
    $b->post('/admin/login/totp', ['_csrf' => TestBrowser::csrf($t), 'code' => $code ?? lk_totp($secret, $m['clock'])]);
    return $b;
}

/** Rückgabe: ob die Seite /admin mit 200 antwortet (volle Sitzung). */
function lk_in(TestBrowser $b): bool
{
    return $b->get('/admin')->status === 200;
}

function lk_new_tmp(string $base, string $name): string
{
    $d = $base . '/' . $name . '-' . bin2hex(random_bytes(3));
    mkdir($d, 0777, true);
    return $d;
}
