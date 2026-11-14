<?php

declare(strict_types=1);

namespace VpnGw\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Duplicates the Apache security headers in case the web-server config is wrong. */
final class SecurityHeaders implements MiddlewareInterface
{
    public const CSP = "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'none'; "
        . "frame-ancestors 'none'; form-action 'self'; base-uri 'none'";

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)
            ->withHeader('Content-Security-Policy', self::CSP)
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
