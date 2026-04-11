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

    // Unit Management
    Route::get('/units/potential-heads', [\App\Http\Controllers\Admin\UnitController::class, 'getPotentialUnitHeads']);
    Route::apiResource('units', \App\Http\Controllers\Admin\UnitController::class);

    // School Management
    Route::apiResource('schools', \App\Http\Controllers\Admin\SchoolController::class);

    // Coach Management
    Route::apiResource('coaches', \App\Http\Controllers\Admin\CoachController::class);
    Route::get('/activities', [\App\Http\Controllers\Admin\ActivityController::class, 'index']);
    Route::apiResource('activity-heads', \App\Http\Controllers\Admin\ActivityHeadController::class);
    Route::apiResource('coordinators', \App\Http\Controllers\Admin\CoordinatorController::class);

    // Location Data (India focus)
    Route::prefix('locations')->group(function () {
        Route::get('/states', [\App\Http\Controllers\LocationController::class, 'getStates']);
        Route::get('/cities/{stateId}', [\App\Http\Controllers\LocationController::class, 'getCities']);
    });
});

/**
 * PUBLIC/AUTH ROUTES
 */
Route::post('/login', [\App\Http\Controllers\AuthController::class, 'login']);
