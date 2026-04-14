<?php

use App\Http\Controllers\Admin\AttendanceDirectoryController;
use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\ActivityHeadController;
use App\Http\Controllers\Admin\ChatController;
use App\Http\Controllers\Admin\CoachController;
use App\Http\Controllers\Admin\CoordinatorController;
use App\Http\Controllers\Admin\Ecom\BrandController;
use App\Http\Controllers\Admin\Ecom\CategoryController;
use App\Http\Controllers\Admin\Ecom\ProductController;
use App\Http\Controllers\Admin\MediaUploadController;
use App\Http\Controllers\Admin\OfficeStaffController;
use App\Http\Controllers\Admin\SalesExecutiveController;
use App\Http\Controllers\Admin\SalesExecutiveTrackingController;
use App\Http\Controllers\Admin\SchoolController;
use App\Http\Controllers\Admin\UnitController;
use App\Http\Controllers\Admin\UnitHeadController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\OfficeStaff\OfficeStaffDashboardController;
use App\Http\Controllers\OfficeStaff\OfficeStaffAttendanceController;
use App\Http\Controllers\SuperAdmin\AdminManagerController;
use App\Http\Controllers\SuperAdmin\DashboardController;
use App\Models\SalesExecutive;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/**
 * SUPER ADMIN — sensitive / catalog routes (super_admin only)
 */
Route::prefix('v1/super-admin')->middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
    Route::get('/admins', [AdminManagerController::class, 'index']);
    Route::post('/admins', [AdminManagerController::class, 'store']);
    Route::get('/admins/{id}', [AdminManagerController::class, 'show']);
    Route::put('/admins/{id}', [AdminManagerController::class, 'update']);
    Route::delete('/admins/{id}', [AdminManagerController::class, 'destroy']);

    Route::get('/roles', [AdminManagerController::class, 'getRoles']);
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    Route::post('/leave-requests/{leaveRequestId}/approve', [DashboardController::class, 'approveLeaveRequest'])->whereNumber('leaveRequestId');
    Route::post('/leave-requests/{leaveRequestId}/reject', [DashboardController::class, 'rejectLeaveRequest'])->whereNumber('leaveRequestId');

    Route::get('office-staff/{id}/attendance', [OfficeStaffController::class, 'attendance'])->whereNumber('id');
    Route::get('office-staff/{id}/payrolls', [OfficeStaffController::class, 'payrolls'])->whereNumber('id');
    Route::post('office-staff/{id}/payrolls', [OfficeStaffController::class, 'storePayroll'])->whereNumber('id');
    Route::patch('office-staff/{id}/payrolls/{payrollId}', [OfficeStaffController::class, 'updatePayroll'])->whereNumber(['id', 'payrollId']);
    Route::apiResource('office-staff', OfficeStaffController::class);

    Route::get('/sales-users', [ChatController::class, 'salesUsers']);
    Route::get('/chat/conversations', [ChatController::class, 'conversations']);
    Route::get('/chat/messages/{userId}', [ChatController::class, 'messages']);
    Route::post('/chat/send', [ChatController::class, 'send']);
    Route::patch('/chat/read', [ChatController::class, 'markAsRead']);
    Route::get('/chat/users', [ChatController::class, 'availableUsers']);
    Route::get('/media-files', [MediaUploadController::class, 'index']);

    Route::get('/ecom/brands', [BrandController::class, 'index']);
    Route::post('/ecom/brands', [BrandController::class, 'store']);
    Route::get('/ecom/categories/tree', [CategoryController::class, 'tree']);
    Route::get('/ecom/categories/options', [CategoryController::class, 'options']);
    Route::apiResource('ecom/categories', CategoryController::class)->except(['show']);
    Route::patch('/ecom/products/{product}/featured', [ProductController::class, 'toggleFeatured']);
    Route::apiResource('ecom/products', ProductController::class);
});

/**
 * SUPER ADMIN — operations data (super_admin + office_staff, same URLs under /v1/super-admin)
 */
Route::prefix('v1/super-admin')->middleware(['auth:sanctum', 'role:super_admin,office_staff'])->group(function () {
    Route::get('/units/options', [UnitController::class, 'options']);
    Route::get('/units/potential-heads', [UnitController::class, 'getPotentialUnitHeads']);
    Route::apiResource('units', UnitController::class);
    Route::apiResource('unit-heads', UnitHeadController::class);

    Route::apiResource('schools', SchoolController::class);

    Route::apiResource('coaches', CoachController::class);
    Route::get('/activities', [ActivityController::class, 'index']);
    Route::apiResource('activity-heads', ActivityHeadController::class);
    Route::apiResource('coordinators', CoordinatorController::class);
    Route::get('sales-executives/{id}/tracking', [SalesExecutiveTrackingController::class, 'summary'])->whereNumber('id');
    Route::post('sales-executives/{id}/tracking/ping', [SalesExecutiveTrackingController::class, 'storePing'])->whereNumber('id');
    Route::get('sales-executives/{id}/visits', [SalesExecutiveTrackingController::class, 'visits'])->whereNumber('id');
    Route::apiResource('sales-executives', SalesExecutiveController::class);

    Route::get('/attendance-directory', [AttendanceDirectoryController::class, 'index']);
    Route::get('/attendance-directory/{userId}/overview', [AttendanceDirectoryController::class, 'overview'])->whereNumber('userId');

    Route::prefix('locations')->group(function () {
        Route::get('/states', [LocationController::class, 'getStates']);
        Route::get('/cities/{stateId}', [LocationController::class, 'getCities']);
    });

    Route::post('/upload-media', [MediaUploadController::class, 'upload']);
});

/**
 * SALES EXECUTIVE ROUTES
 */
Route::prefix('v1/sales-executive')->middleware(['auth:sanctum', 'role:sales_executive'])->group(function () {
    Route::get('/profile', function (Request $request) {
        $user = $request->user()->load(['staffProfile', 'role']);
        $salesExec = SalesExecutive::where('user_id', $user->id)->first();

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => $user,
                'sales_executive' => $salesExec,
            ],
        ]);
    });

    Route::get('/chat/conversations', [ChatController::class, 'conversations']);
    Route::get('/chat/messages/{userId}', [ChatController::class, 'messages']);
    Route::post('/chat/send', [ChatController::class, 'send']);
    Route::patch('/chat/read', [ChatController::class, 'markAsRead']);
    Route::get('/chat/users', [ChatController::class, 'availableUsers']);
    Route::post('/upload-media', [MediaUploadController::class, 'upload']);

    Route::post('/tracking/ping', [SalesExecutiveTrackingController::class, 'storeMyPing']);
});

/**
 * OFFICE STAFF ROUTES (portal-only)
 */
Route::prefix('v1/office-staff')->middleware(['auth:sanctum', 'role:office_staff'])->group(function () {
    Route::get('/dashboard', [OfficeStaffDashboardController::class, 'dashboard']);
    Route::get('/payrolls', [OfficeStaffDashboardController::class, 'payrollHistory']);
    Route::post('/leave-requests', [OfficeStaffDashboardController::class, 'applyLeave']);
    Route::post('/attendance/check-in', [OfficeStaffDashboardController::class, 'checkIn']);
    Route::post('/attendance/check-out', [OfficeStaffDashboardController::class, 'checkOut']);
    Route::get('/attendance/overview', [OfficeStaffAttendanceController::class, 'overview']);
    Route::post('/attendance/approve-leave', [OfficeStaffAttendanceController::class, 'approveLeave']);
});

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Route::post('/login', [AuthController::class, 'login']);
