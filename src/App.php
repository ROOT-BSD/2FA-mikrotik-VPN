<?php

declare(strict_types=1);

namespace VpnGw;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App as SlimApp;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use VpnGw\Http\Middleware\Csrf;
use VpnGw\Http\Middleware\InternalNetworkOnly;
use VpnGw\Http\Middleware\SecurityHeaders;

final class App
{
    /** @return SlimApp<\Psr\Container\ContainerInterface|null> */
    public static function create(Container $c): SlimApp
    {
        $cfg = $c->config();
        $factory = $c->responseFactory();
        AppFactory::setResponseFactory($factory);
        $app = AppFactory::create();
        $app->setBasePath($cfg->basePath());

        $login = $c->loginController(...);
        $mfa = $c->mfaController(...);
        $result = $c->resultController(...);
        $enroll = $c->enrollController(...);

        $app->get('/', fn ($rq, $rs) => $login()->show($rq, $rs));
        $app->post('/login', fn ($rq, $rs) => $login()->submit($rq, $rs));
        $app->get('/verify', fn ($rq, $rs) => $mfa()->show($rq, $rs));
        $app->post('/verify', fn ($rq, $rs) => $mfa()->submit($rq, $rs));
        $app->post('/verify/email', fn ($rq, $rs) => $mfa()->sendEmail($rq, $rs));
        $app->get('/result', fn ($rq, $rs) => $result()->show($rq, $rs));
        $app->post('/revoke', fn ($rq, $rs) => $result()->revoke($rq, $rs));
        $app->map(['GET', 'POST'], '/enroll', fn ($rq, $rs) => $enroll()->handle($rq, $rs));

        // Slim runs middleware LIFO (last added = outermost). Effective order per request:
        // error handler -> audit context -> security headers -> routing (404) -> internal-only /enroll -> CSRF -> route.
        $app->add(new Csrf($c->session(), $factory));
        $app->add(new InternalNetworkOnly($cfg->list('INTERNAL_NETWORKS'), $factory, $cfg->basePath() . '/enroll'));
        $app->addRoutingMiddleware();
        $app->add(new SecurityHeaders());
        $app->add(function (ServerRequestInterface $request, $handler) use ($c): ResponseInterface {
            $c->audit()->setRequestContext($request->getServerParams());
            return $handler->handle($request);
        });

        $errors = $app->addErrorMiddleware(false, false, false);
        $errors->setDefaultErrorHandler(function (ServerRequestInterface $request, \Throwable $e) use ($c, $factory): ResponseInterface {
            if ($e instanceof HttpNotFoundException || $e instanceof HttpMethodNotAllowedException) {
                $response = $factory->createResponse(404);
            } else {
                $c->audit()->event('unhandled', null, null, ['error' => $e::class . ': ' . $e->getMessage()]);
                $response = $factory->createResponse(500);
            }
            return (new SecurityHeaders())->process($request, new class ($response) implements \Psr\Http\Server\RequestHandlerInterface {
                public function __construct(private readonly ResponseInterface $r)
                {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->r;
                }
            });
        });

        return $app;
    }
}
