<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Proxies de confiance : config/trustedproxy.php (TRUSTED_PROXIES), lu
        // par le middleware TrustProxies du framework à chaque requête.
        // Hôtes acceptés : config('app.trusted_hosts') (TRUSTED_HOSTS) ; liste
        // vide = aucune restriction, sans ajout implicite de l'hôte d'APP_URL.
        $middleware->trustHosts(
            at: fn (): array => array_map(fn (string $host): string => '^'.preg_quote($host).'$', config('app.trusted_hosts')),
            subdomains: false,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
