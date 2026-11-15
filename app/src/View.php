<?php
declare(strict_types=1);

namespace Lichtkammer;

final class View
{
    public function __construct(private readonly string $templateDir, private readonly Config $config)
    {
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $content = $this->include($template, $data);
        return $this->include('layout', $data + ['content' => $content]);
    }

    /** Nur die Vorlage, ohne Rahmen (für die öffentlichen Seiten, die ihren eigenen Rahmen haben). @param array<string,mixed> $data */
    public function fragment(string $template, array $data = []): string
    {
        return $this->include($template, $data);
    }

    /** Adresse einer Datei aus public/ mit Änderungszeit als Version, damit Browser nach einem Upload die neue Datei holen. */
    public function asset(string $path): string
    {
        $t = @filemtime($this->config->publicDir . $path);
        return $path . ($t !== false ? '?v=' . $t : '');
    }

    /** @param array<string,mixed> $data */
    private function include(string $template, array $data): string
    {
        $file = $this->templateDir . '/' . $template . '.php';
        $config = $this->config;
        $asset = fn (string $path): string => $this->asset($path);
        $e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $t) {
            ob_end_clean();
            throw $t;
        }
        return (string) ob_get_clean();
    }
}
