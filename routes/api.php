<?php
use App\Http\Controllers\AuthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Supabase Auth
|--------------------------------------------------------------------------
| Semua autentikasi sekarang menggunakan Supabase Auth (bukan Laravel Sanctum).
| Flutter login via supabase.auth.signInWithPassword() dan kirim JWT sebagai
| Bearer token ke Laravel API.
*/

// ============================================================
// PUBLIC ROUTES - Tidak butuh autentikasi
// ============================================================
// Tidak ada public routes untuk auth karena semua auth handle oleh Supabase


// ============================================================
// PROTECTED ROUTES - Butuh JWT Supabase
// ============================================================
Route::middleware('supabase.auth')->group(function () {

    // --- Auth (hanya profile yang tersisa) ---
    Route::get('/auth/profile', [AuthController::class, 'profile']);

    // --- User Management (admin only) ---
    Route::middleware('admin')->group(function () {
        Route::get('/users',               [UserController::class, 'index']);
        Route::get('/users/helpdesk', [UserController::class, 'getHelpdesk']);
        Route::get('/users/{id}',         [UserController::class, 'show']);
        Route::post('/users',             [UserController::class, 'store']);
        Route::put('/users/{id}',         [UserController::class, 'update']);
        Route::delete('/users/{id}',     [UserController::class, 'destroy']);
        Route::patch('/users/{id}/role',  [UserController::class, 'updateRole']);
    });


    // --- Notifications ---
    Route::get('/notifications',                    [NotificationController::class, 'index']);
    Route::get('/notifications/{id}',               [NotificationController::class, 'show']);
    Route::patch('/notifications/read-all',          [NotificationController::class, 'markAllAsRead']);
    Route::patch('/notifications/{id}/read',          [NotificationController::class, 'markAsRead']);

    // --- Tickets ---
    Route::get('/tickets/stats',               [TicketController::class, 'stats']);
    Route::get('/tickets',                   [TicketController::class, 'index']);
    Route::post('/tickets',                  [TicketController::class, 'store']);
    Route::get('/tickets/{id}',              [TicketController::class, 'show']);
    Route::patch('/tickets/{id}/assign',      [TicketController::class, 'assignTicket']);
    Route::patch('/tickets/{id}/finish',     [TicketController::class, 'finish']);
    Route::delete('/tickets/{id}',            [TicketController::class, 'destroy']);
    Route::post('/tickets/{id}/comments',    [TicketController::class, 'addComment']);
    Route::get('/tickets/{id}/history',      [TicketController::class, 'getHistory']);
    Route::get('/tickets/{id}/comments',     [TicketController::class, 'getComments']);
});

// ============================================================
// REGISTER ROUTE - Butuh JWT Supabase (dari supabase.auth.signUp)
// ============================================================
Route::middleware('supabase.auth')->post('/auth/register', [AuthController::class, 'register']);

// ============================================================
// REGISTER FULL - 1 langkah auto create di Supabase + Laravel
// ============================================================
Route::post('/auth/register-full', [AuthController::class, 'registerFull']);

// ============================================================
// RESET PASSWORD - Tidak butuh autentikasi
// ============================================================
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/auth/verify-reset-code', [AuthController::class, 'verifyResetCode']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

// ============================================================
// ADMIN CREATE USER - Create di Supabase Auth + Laravel DB
// ============================================================
Route::middleware(['supabase.auth', 'admin'])->post('/users/create-with-auth', [UserController::class, 'createWithAuth']);
