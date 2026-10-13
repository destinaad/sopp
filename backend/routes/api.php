<?php

use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\LoketController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SsoController;
use App\Http\Controllers\Api\DashboardTellerController;
use App\Http\Controllers\Api\SetoranEntryController;
use App\Http\Controllers\Api\BuktiSetoranController;
use App\Http\Controllers\Api\PelunasanController;
use App\Http\Controllers\Api\RiwayatSetoranController;
use App\Http\Controllers\Api\DetailSetoranController;
use App\Http\Controllers\Api\PengaturanController;
use App\Http\Controllers\Api\HakAksesController;
use App\Http\Controllers\Api\LogAktivitasController;
use App\Http\Controllers\Api\DashboardAdminController;
use App\Http\Controllers\Api\LaporanController;
use App\Http\Controllers\Api\LaporanExportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (v1)
|--------------------------------------------------------------------------
|
| Grouping rules used below:
| 1. One prefix per domain (auth / teller / admin), declared once via
|    Route::prefix(), not repeated in every path string.
| 2. Inside a resource group, literal segments (kalender, riwayat,
|    pelunasan, draft, submit) are declared BEFORE the {id} wildcard --
|    Laravel matches routes in declaration order, so a literal after a
|    wildcard of the same HTTP verb would silently get swallowed by {id}.
| 3. Every {id} is constrained with whereNumber() so it can never
|    ambiguously match a literal segment even if ordering slips later.
| 4. "Command" endpoints (draft, submit) are POST-to-a-sub-path, not new
|    top-level nouns -- they represent a status transition on the same
|    setoran resource, which is a legitimate REST pattern for actions
|    that don't map cleanly to plain CRUD.
*/

Route::prefix('v1')->group(function () {

    // ---- Entry points BEFORE a session exists ----
    Route::post('/sso/verify', [SsoController::class, 'verify']);
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {

        // ---- Session / own account ----
        Route::get('/me', [MeController::class, 'show']);

        // ---- Reference data (lintas chunk: Card A + Detail Setoran) ----
        Route::get('/lokets', [LoketController::class, 'index']);
        Route::put('/me/password', [PengaturanController::class, 'updatePassword'])
            ->middleware('throttle:5,1');
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // ---- Teller: Dashboard (read-only) ----
        Route::get('/teller/dashboard', [DashboardTellerController::class, 'index']);

        // ---- Teller: Setoran + all its sub-resources, nested consistently ----
        Route::prefix('teller/setoran')->group(function () {
            Route::get('/', [SetoranEntryController::class, 'index']);
            Route::post('/draft', [SetoranEntryController::class, 'simpanDraft']);
            Route::post('/submit', [SetoranEntryController::class, 'submit']);
            Route::get('/kalender', [SetoranEntryController::class, 'kalenderSetoran']);
            Route::get('/riwayat', [RiwayatSetoranController::class, 'index'])
                ->middleware('throttle:60,1');
            Route::delete('/riwayat/{id}', [RiwayatSetoranController::class, 'destroy']);
            Route::get('/riwayat/export', [RiwayatSetoranController::class, 'export'])
                ->middleware('throttle:10,1');
            Route::get('/pelunasan', [PelunasanController::class, 'index']);
            Route::post('/pelunasan', [PelunasanController::class, 'store']);

            // Wildcard LAST -- everything above is a reserved literal segment.
            Route::get('/{id}', [DetailSetoranController::class, 'show'])->whereNumber('id');
            Route::put('/{id}', [DetailSetoranController::class, 'update'])->whereNumber('id');
        });

        Route::get('/bukti-setoran/{path}', [BuktiSetoranController::class, 'show'])
            ->where('path', '.*')
            ->name('bukti-setoran.show');

        // ---- Admin: everything admin-only lives under one prefix ----
        Route::prefix('admin')->group(function () {
            Route::get('/dashboard', [DashboardAdminController::class, 'index']);
            Route::get('/laporan', [LaporanController::class, 'index']);
            Route::get('/laporan/export', [LaporanExportController::class, 'export']);
            Route::get('/laporan/download', [LaporanExportController::class, 'download']);
            Route::get('/hak-akses', [HakAksesController::class, 'index']);
            Route::put('/hak-akses', [HakAksesController::class, 'update']);
            Route::get('/log-aktivitas', [LogAktivitasController::class, 'index']);
        });
    });
});
