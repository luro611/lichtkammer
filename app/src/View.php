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

    /** @param array<string,mixed> $data */
    private function include(string $template, array $data): string
    {
        $file = $this->templateDir . '/' . $template . '.php';
        $config = $this->config;
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
