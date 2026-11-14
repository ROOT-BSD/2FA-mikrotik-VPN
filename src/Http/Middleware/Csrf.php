<?php

declare(strict_types=1);

namespace VpnGw\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VpnGw\Http\Session;

/** Every POST must carry the session's CSRF token in the "csrf" field. */
final class Csrf implements MiddlewareInterface
{
    public function __construct(
        private readonly Session $session,
        private readonly ResponseFactoryInterface $responses,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $expected = $this->session->csrfToken();
        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $sent = is_array($body) && is_string($body['csrf'] ?? null) ? $body['csrf'] : '';
            if (!hash_equals($expected, $sent)) {
                return $this->responses->createResponse(403);
            }
        }
        return $handler->handle($request);
    }
}
