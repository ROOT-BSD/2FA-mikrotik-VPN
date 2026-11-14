<?php

declare(strict_types=1);

namespace VpnGw\Http;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VpnGw\Config;
use VpnGw\Security\ClientIp;

abstract class Controller
{
    public function __construct(
        protected readonly Config $cfg,
        protected readonly Session $session,
        protected readonly View $view,
        protected readonly ClockInterface $clock,
    ) {
    }

    protected function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response->withHeader('Location', $this->cfg->basePath() . $path)->withStatus(303);
    }

    protected function clientIp(ServerRequestInterface $request): ?string
    {
        return ClientIp::resolve($request->getServerParams(), $this->cfg->list('TRUSTED_PROXIES'));
    }

    protected function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /** @return array<string, mixed> */
    protected function body(ServerRequestInterface $request): array
    {
        $b = $request->getParsedBody();
        return is_array($b) ? $b : [];
    }

    protected function field(ServerRequestInterface $request, string $name, int $maxLen = 256): string
    {
        $v = $this->body($request)[$name] ?? '';
        return is_string($v) ? mb_substr($v, 0, $maxLen) : '';
    }

    /** Session state machine guard: right stage, not expired, same client IP. */
    protected function inStage(string $stage, int $ttl, ?string $ip): bool
    {
        return $this->session->str('stage') === $stage
            && $this->now() - $this->session->int('stage_ts') <= $ttl
            && $ip !== null
            && hash_equals((string) $this->session->str('ip'), $ip);
    }
}
