<?php

declare(strict_types=1);

namespace VpnGw\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Minimal PHP template renderer. Templates receive $e (HTML escaper), $csrf, $base
 * and the given variables; every value must be printed through $e().
 */
final class View
{
    public function __construct(
        private readonly string $dir,
        private readonly string $basePath,
        private readonly Session $session,
    ) {
    }

    /** @param array<string, mixed> $vars */
    public function render(ResponseInterface $response, string $template, array $vars = [], int $status = 200): ResponseInterface
    {
        if (!preg_match('/^[a-z_]+$/', $template)) {
            throw new \InvalidArgumentException('Bad template name');
        }
        $vars += [
            'title' => 'VPN-GW',
            'csrf' => $this->session->csrfToken(),
            'base' => $this->basePath,
            'e' => static fn (mixed $v): string => htmlspecialchars((string) (is_scalar($v) ? $v : ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ];
        $content = $this->capture($this->dir . '/' . $template . '.php', $vars);
        $page = $this->capture($this->dir . '/layout.php', $vars + ['content' => $content]);

        $response->getBody()->write($page);
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /** @param array<string, mixed> $vars */
    private function capture(string $file, array $vars): string
    {
        $render = static function (string $__file, array $__vars): void {
            extract($__vars, EXTR_SKIP);
            require $__file;
        };
        ob_start();
        try {
            $render($file, $vars);
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
