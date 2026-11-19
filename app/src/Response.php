<?php
declare(strict_types=1);

namespace Lichtkammer;

final class Response
{
    /**
     * @param array<string,string> $headers
     * @param list<string> $cookies fertige Set-Cookie-Werte
     */
    public function __construct(
        public readonly string $body,
        public readonly int $status = 200,
        public readonly array $headers = [],
        public readonly array $cookies = [],
        /** Datei, die beim Senden gestreamt wird (statt body): [Pfad, Beginn, Länge] */
        public readonly ?array $stream = null,
        /** Erzeugt den Inhalt beim Senden (z. B. ein TAR-Strom); die Länge steht dann im Kopf Content-Length. */
        public readonly ?\Closure $emit = null,
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public static function notFound(): self
    {
        return new self('Nicht gefunden', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    /**
     * Datei streamen (große PDFs), optional nur ein Bereich. Der Inhalt wird beim Senden in Blöcken gelesen, nicht in den Speicher geladen.
     *
     * @param array<string,string> $headers
     */
    public static function file(string $path, int $from, int $length, int $status, array $headers): self
    {
        return new self('', $status, $headers + ['Content-Length' => (string) $length], [], [$path, $from, $length]);
    }

    /**
     * Inhalt, der beim Senden Stück für Stück erzeugt wird (Sicherung). $emit bekommt eine Funktion, der es Blöcke übergibt.
     *
     * @param array<string,string> $headers
     * @param \Closure(callable(string):void):void $emit
     */
    public static function generated(int $length, array $headers, \Closure $emit): self
    {
        return new self('', 200, $headers + ['Content-Length' => (string) $length], [], null, $emit);
    }

    /** @param array<string,string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->body, $this->status, $headers + $this->headers, $this->cookies, $this->stream, $this->emit);
    }

    /** Standard-Kopfzeilen ergänzen; bereits gesetzte Kopfzeilen der Antwort bleiben. @param array<string,string> $defaults */
    public function withDefaults(array $defaults): self
    {
        return new self($this->body, $this->status, $this->headers + $defaults, $this->cookies, $this->stream, $this->emit);
    }

    public function withCookie(string $setCookie): self
    {
        return new self($this->body, $this->status, $this->headers, [...$this->cookies, $setCookie], $this->stream, $this->emit);
    }

    public function send(bool $headOnly = false): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        foreach ($this->cookies as $cookie) {
            header('Set-Cookie: ' . $cookie, false);
        }
        if ($headOnly) {
            return;
        }
        if ($this->emit !== null) {
            @set_time_limit(0);
            // Bricht der Browser ab, läuft das Skript weiter, bis emit den Abbruch bemerkt und aufräumt (die Datenbankkopie
            // der Sicherung löscht). Ohne das beendet PHP das Skript sofort und die Kopie bleibt liegen (Prüfung 34, B2).
            ignore_user_abort(true);
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            try {
                ($this->emit)(static function (string $chunk): void {
                    echo $chunk;
                    flush();
                });
            } catch (\Throwable $t) { // Kopfzeilen sind schon gesendet: nur noch protokollieren
                error_log('Lichtkammer: Download beendet: ' . $t->getMessage());
            }
            return;
        }
        if ($this->stream === null) {
            echo $this->body;
            return;
        }
        [$path, $from, $len] = $this->stream;
        @set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return;
        }
        fseek($fh, $from);
        while ($len > 0 && !feof($fh)) {
            $chunk = (string) fread($fh, min(65536, $len));
            if ($chunk === '') {
                break;
            }
            echo $chunk;
            $len -= strlen($chunk);
            flush();
        }
        fclose($fh);
    }
}
