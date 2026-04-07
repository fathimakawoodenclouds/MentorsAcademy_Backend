<?php

use Illuminate\Support\Facades\Route;

/**
 * SUPER ADMIN ROUTES
 */
Route::prefix('v1/super-admin')->middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
    Route::get('/admins', [\App\Http\Controllers\SuperAdmin\AdminManagerController::class, 'index']);
    Route::post('/admins', [\App\Http\Controllers\SuperAdmin\AdminManagerController::class, 'store']);
    Route::get('/admins/{id}', [\App\Http\Controllers\SuperAdmin\AdminManagerController::class, 'show']);
    Route::put('/admins/{id}', [\App\Http\Controllers\SuperAdmin\AdminManagerController::class, 'update']);
    Route::delete('/admins/{id}', [\App\Http\Controllers\SuperAdmin\AdminManagerController::class, 'destroy']);
    
    // Roles dynamically for UI
    Route::get('/roles', [\App\Http\Controllers\SuperAdmin\AdminManagerController::class, 'getRoles']);
});

/**
 * PUBLIC/AUTH ROUTES
 */
Route::post('/login', [\App\Http\Controllers\AuthController::class, 'login']);
