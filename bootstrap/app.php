<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api:__DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'supabase.auth' => \App\Http\Middleware\VerifySupabaseToken::class,
        ]);

            $middleware->web(append: [
        \Illuminate\Http\Middleware\HandleCors::class,
    ]);
    $middleware->api(prepend: [
        \Illuminate\Http\Middleware\HandleCors::class,
    ]);


    })
    ->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
        if ($request->is('api/*')) {
            return response()->json([
                'message' => 'Unauthenticated. Token tidak valid atau tidak ada.'
            ], 401);
        }
    });
    
    })->create();