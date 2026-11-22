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
        $adminAuth = $c->adminAuthController(...);
        $authenticators = $c->authenticatorsController(...);
        $dashboard = $c->dashboardController(...);
        $settings = $c->settingsController(...);
        $logs = $c->logsController(...);
        $maint = $c->maintenanceController(...);

        $app->get('/', fn ($rq, $rs) => $login()->show($rq, $rs));
        $app->post('/login', fn ($rq, $rs) => $login()->submit($rq, $rs));
        $app->get('/verify', fn ($rq, $rs) => $mfa()->show($rq, $rs));
        $app->post('/verify', fn ($rq, $rs) => $mfa()->submit($rq, $rs));
        $app->post('/verify/email', fn ($rq, $rs) => $mfa()->sendEmail($rq, $rs));
        $app->get('/result', fn ($rq, $rs) => $result()->show($rq, $rs));
        $app->post('/revoke', fn ($rq, $rs) => $result()->revoke($rq, $rs));
        $app->map(['GET', 'POST'], '/enroll', fn ($rq, $rs) => $enroll()->handle($rq, $rs));

        // Admin panel: internal networks only (middleware below) + local administrator login.
        $app->get('/admin', fn ($rq, $rs) => $dashboard()->show($rq, $rs));
        $app->post('/admin/grants/revoke', fn ($rq, $rs) => $dashboard()->revoke($rq, $rs));
        $app->get('/admin/authenticators', fn ($rq, $rs) => $authenticators()->show($rq, $rs));
        $app->post('/admin/authenticators/delete', fn ($rq, $rs) => $authenticators()->delete($rq, $rs));
        $app->get('/admin/login', fn ($rq, $rs) => $adminAuth()->form($rq, $rs));
        $app->post('/admin/login', fn ($rq, $rs) => $adminAuth()->login($rq, $rs));
        $app->post('/admin/logout', fn ($rq, $rs) => $adminAuth()->logout($rq, $rs));
        $app->map(['GET', 'POST'], '/admin/password', fn ($rq, $rs) => $adminAuth()->password($rq, $rs));
        $app->map(['GET', 'POST'], '/admin/routers/add', fn ($rq, $rs) => $settings()->router($rq, $rs, null));
        $app->map(['GET', 'POST'], '/admin/routers/edit/{name:[A-Za-z0-9_-]{1,32}}', fn ($rq, $rs, array $a) => $settings()->router($rq, $rs, (string) $a['name']));
        $app->map(['GET', 'POST'], '/admin/settings/{section:[a-z]+}', fn ($rq, $rs, array $a) => $settings()->handle($rq, $rs, (string) $a['section']));
        $app->get('/admin/logs', fn ($rq, $rs) => $logs()->show($rq, $rs));
        $app->map(['GET', 'POST'], '/admin/backups', fn ($rq, $rs) => $maint()->backups($rq, $rs));
        $app->get('/admin/backups/{name:[A-Za-z0-9._-]+}', fn ($rq, $rs, array $a) => $maint()->download($rq, $rs, (string) $a['name']));
        $app->map(['GET', 'POST'], '/admin/update', fn ($rq, $rs) => $maint()->update($rq, $rs));
        $app->get('/admin/jobs/{id:[0-9a-f-]+}', fn ($rq, $rs, array $a) => $maint()->job($rq, $rs, (string) $a['id']));

        // Slim runs middleware LIFO (last added = outermost). Effective order per request:
        // error handler -> audit context -> security headers -> routing (404) -> internal-only /enroll and /admin -> CSRF -> route.
        $app->add(new Csrf($c->session(), $factory));
        $app->add(new InternalNetworkOnly($cfg->list('INTERNAL_NETWORKS'), $factory, [$cfg->basePath() . '/enroll', $cfg->basePath() . '/admin']));
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
