<?php

use App\Exceptions\DomainConflict;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // Sem proxy confiável por padrão: X-Forwarded-For é ignorado (o IP do login não pode ser forjado). Atrás de um
        // proxy/balanceador, TRUSTED_PROXIES lista os IPs/CIDRs dele, separados por vírgula ("*" só se o proxy
        // sobrescrever o cabeçalho). Como o env() vale aqui, com config:cache a variável precisa ser real (não só .env).
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(DomainConflict::class);
        $exceptions->render(fn (DomainConflict $e) => response()->json(
            ['message' => $e->getMessage(), 'code' => $e->errorCode()] + ($e->details() ? ['details' => $e->details()] : []),
            409,
        ));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
