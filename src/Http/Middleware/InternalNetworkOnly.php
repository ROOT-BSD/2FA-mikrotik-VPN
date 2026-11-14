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
 * Restricts a route to internal networks (TOTP enrollment). Uses REMOTE_ADDR only —
 * never X-Forwarded-For. Outsiders get a plain 404, as if the route did not exist.
 */
final class InternalNetworkOnly implements MiddlewareInterface
{
    /**
     * @param list<string> $networks
     * @param string|null $onlyPath restrict just this URI path (null = every request)
     */
    public function __construct(
        private readonly array $networks,
        private readonly ResponseFactoryInterface $responses,
        private readonly ?string $onlyPath = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->onlyPath !== null && rtrim($request->getUri()->getPath(), '/') !== $this->onlyPath) {
            return $handler->handle($request);
        }
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        if (!is_string($remote) || !Cidr::containsAny($this->networks, $remote)) {
            return $this->responses->createResponse(404);
        }
        return $handler->handle($request);
    }
}
