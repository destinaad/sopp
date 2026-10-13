<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 1. Aktifkan Sanctum Stateful API untuk mendukung Cookie Session (Auth::guard('web'))
        $middleware->statefulApi();

        // 2. Kecualikan endpoint Sanctum dan Auth dari validasi CSRF wajib
        // Ini mencegah error 419 saat React meminta cookie atau melakukan login/SSO pertamawaktu
        $middleware->validateCsrfTokens(except: [
            'sanctum/csrf-cookie',
            'api/v1/sso/verify',
            'api/v1/auth/login',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Reformat SEMUA exception jadi envelope {status, message, errors}
        // Hanya berlaku untuk route /api/*
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validasi gagal.',
                    'errors' => $e->errors(),
                ], 422);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Belum login atau sesi berakhir.',
                    'errors' => (object) [],
                ], 401);
            }

            if ($e instanceof ModelNotFoundException || ($e instanceof NotFoundHttpException && $e->getPrevious() instanceof ModelNotFoundException)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Data tidak ditemukan.',
                    'errors' => (object) [],
                ], 404);
            }

            if ($e instanceof ThrottleRequestsException) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Terlalu banyak percobaan. Coba lagi sebentar lagi.',
                    'errors' => (object) [],
                ], 429);
            }

            if ($e instanceof AccessDeniedHttpException) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Anda tidak memiliki akses untuk melakukan aksi ini.',
                    'errors' => (object) [],
                ], 403);
            }

            if ($e instanceof HttpExceptionInterface) {
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage() ?: 'Request tidak dapat diproses.',
                    'errors' => (object) [],
                ], $e->getStatusCode());
            }

            // Fallback -- error tak terduga
            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan pada server.',
                'errors' => (object) [],
            ], 500);
        });
    })->create();