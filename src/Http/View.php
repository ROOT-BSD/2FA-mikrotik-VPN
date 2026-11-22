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
            'dt' => self::formatDate(...),
        ];
        $layout = is_string($vars['layout'] ?? null) && preg_match('/^[a-z_]+$/', $vars['layout']) ? $vars['layout'] : 'layout';
        $content = $this->capture($this->dir . '/' . $template . '.php', $vars);
        $page = $this->capture($this->dir . '/' . $layout . '.php', $vars + ['content' => $content]);

        $response->getBody()->write($page);
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Date and time as people here write them: 22.11.2026 09:06:47, in the server's time zone.
     * Accepts ISO 8601 / ATOM strings, "Y-m-d H:i:s" and Unix timestamps; anything else is
     * returned as it is.
     */
    public static function formatDate(mixed $value, bool $seconds = true): string
    {
        $format = $seconds ? 'd.m.Y H:i:s' : 'd.m.Y H:i';
        $tz = new \DateTimeZone(date_default_timezone_get());
        if (is_int($value)) {
            return (new \DateTimeImmutable('@' . $value))->setTimezone($tz)->format($format);
        }
        $s = is_string($value) ? trim($value) : '';
        if ($s === '' || preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?/', $s) !== 1) {
            return $s;
        }
        try {
            return (new \DateTimeImmutable($s, $tz))->setTimezone($tz)->format($format);
        } catch (\Exception) {
            return $s;
        }
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
