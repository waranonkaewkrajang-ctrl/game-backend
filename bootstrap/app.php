<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        // Internal READ-ONLY admin API for the unified admin (separate file, additive)
        then: function () {
            Route::middleware('api')->prefix('api')->group(base_path('routes/internal-admin.php'));
        },
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum']],
    )
        ->withMiddleware(function (Middleware $middleware): void {
        // เชื่อ header จาก nginx เพื่อให้ $request->ip() เห็น IP จริงของผู้เรียก
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'amb.ip' => \App\Http\Middleware\VerifyAmbCallbackIp::class,
            'truewallet.sig' => \App\Http\Middleware\VerifyTrueWalletSignature::class,
            'internal.service' => \App\Http\Middleware\AuthenticateInternalService::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
