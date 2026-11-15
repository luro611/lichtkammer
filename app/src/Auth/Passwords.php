<?php
declare(strict_types=1);

namespace Lichtkammer\Auth;

/**
 * Passwortregeln und Argon2id. Länge zählt, keine Kompositionsregeln.
 * Passwörter werden immer NFC-normalisiert (intl ist Pflicht), damit ein Umlaut überall gleich gilt.
 */
final class Passwords
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 256;
    public const MAX_BYTES = 1024;

    /** @var array{memory_cost:int,time_cost:int,threads:int} */
    private array $options;
    private ?string $dummy = null;
    /** Anzahl der Hash-Prüfungen in diesem Prozess (Tests, Bremse). */
    public int $verifications = 0;
    /** @var array<string,true>|null */
    private ?array $common = null;

    /** @param array{memory_cost:int,time_cost:int,threads:int}|null $options */
    public function __construct(private readonly string $commonFile, ?array $options = null)
    {
        $this->options = $options ?? ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1];
    }

    public static function normalize(string $s): string
    {
        $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
        return $n === false ? $s : $n;
    }

    /** Benutzername: klein, NFC, ohne Leerraum am Rand. */
    public static function normalizeUsername(string $name): string
    {
        return mb_strtolower(self::normalize(trim($name)), 'UTF-8');
    }

    public static function validUsername(string $normalized): bool
    {
        return preg_match('/^[a-z0-9._-]{3,32}$/', $normalized) === 1;
    }

    /** @return string|null Fehlermeldung oder null, wenn das Passwort zulässig ist */
    public function validate(string $password, string $username = ''): ?string
    {
        if (strlen($password) > self::MAX_BYTES || mb_strlen($password, 'UTF-8') > self::MAX_LENGTH) {
            return 'Das Passwort ist zu lang (höchstens ' . self::MAX_LENGTH . ' Zeichen).';
        }
        $pw = self::normalize($password);
        if (mb_strlen($pw, 'UTF-8') < self::MIN_LENGTH) {
            return 'Das Passwort muss mindestens ' . self::MIN_LENGTH . ' Zeichen lang sein.';
        }
        $lower = mb_strtolower($pw, 'UTF-8');
        $user = self::normalizeUsername($username);
        if ($user !== '' && str_contains($lower, $user)) {
            return 'Das Passwort darf den Benutzernamen nicht enthalten.';
        }
        if ($this->isWeak($lower)) {
            return 'Dieses Passwort ist zu leicht zu erraten. Besser ist ein Satz aus mehreren Wörtern.';
        }
        return null;
    }

    private function isWeak(string $lower): bool
    {
        $chars = mb_str_split($lower, 1, 'UTF-8');
        if (count(array_unique($chars)) <= 4) {
            return true;
        }
        if ($this->isRun($chars)) {
            return true;
        }
        $this->common ??= $this->loadCommon();
        if (isset($this->common[$lower])) {
            return true;
        }
        $letters = preg_replace('/[^\p{L}]+/u', '', $lower) ?? '';
        if ($letters === '') {
            return true; // nur Ziffern/Zeichen und keine Folge: trotzdem zu schwach ohne Buchstaben und Länge < 16
        }
        return isset($this->common[$letters]);
    }

    /** @param list<string> $chars Aufsteigende oder absteigende Zeichenfolge (abcdef…, 123456…) */
    private function isRun(array $chars): bool
    {
        $codes = array_map(static fn (string $c): int => mb_ord($c, 'UTF-8'), $chars);
        $diffs = [];
        for ($i = 1; $i < count($codes); $i++) {
            $diffs[$codes[$i] - $codes[$i - 1]] = true;
        }
        return count($diffs) === 1 && (isset($diffs[1]) || isset($diffs[-1]));
    }

    /** @return array<string,true> */
    private function loadCommon(): array
    {
        $out = [];
        $content = is_file($this->commonFile) ? (string) file_get_contents($this->commonFile) : '';
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = mb_strtolower(trim($line), 'UTF-8');
            if ($line !== '' && $line[0] !== '#') {
                $out[$line] = true;
            }
        }
        return $out;
    }

    public function hash(string $password): string
    {
        return password_hash(self::normalize($password), PASSWORD_ARGON2ID, $this->options);
    }

    public function verify(string $password, string $hash): bool
    {
        $this->verifications++;
        if (strlen($password) > self::MAX_BYTES) {
            password_verify('', $hash); // ähnliche Laufzeit, nie ein Treffer
            return false;
        }
        return password_verify(self::normalize($password), $hash);
    }

    /** Scheinprüfung für unbekannte Konten mit denselben Parametern wie echte Hashes. */
    public function verifyDummy(string $password): void
    {
        $this->dummy ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID, $this->options);
        $this->verify($password, $this->dummy);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->options);
    }
}
