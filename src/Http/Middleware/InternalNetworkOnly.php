<?php

declare(strict_types=1);

namespace VpnGw\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VpnGw\Security\Cidr;

/**
 * Restricts routes to internal networks (TOTP enrollment, admin panel). Uses REMOTE_ADDR
 * only — never X-Forwarded-For. Outsiders get a plain 404, as if the route did not exist.
 */
final class InternalNetworkOnly implements MiddlewareInterface
{
    /**
     * @param list<string> $networks
     * @param list<string>|null $paths restrict these URI paths and everything below them (null = every request)
     */
    public function __construct(
        private readonly array $networks,
        private readonly ResponseFactoryInterface $responses,
        private readonly ?array $paths = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->paths !== null && !$this->covers(rawurldecode($request->getUri()->getPath()))) {
            return $handler->handle($request);
        }
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        if (!is_string($remote) || !Cidr::containsAny($this->networks, $remote)) {
            return $this->responses->createResponse(404);
        }
        return $handler->handle($request);
    }

    private function covers(string $path): bool
    {
        $path = rtrim($path, '/');
        foreach ($this->paths ?? [] as $p) {
            if ($path === $p || str_starts_with($path, $p . '/')) {
                return true;
            }
        }
        return false;
    }
}
