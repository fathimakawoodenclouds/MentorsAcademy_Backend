<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\Role;
use App\Models\School;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\LeaveReviewNotification;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use ApiResponseTrait;

    public function summary()
    {
        $today = now()->toDateString();
        $monthKey = now()->format('Y-m');

        $staffRoleIds = Role::query()
            ->whereIn('name', ['office_staff', 'unit_head', 'coordinator', 'coach', 'activity_head', 'sales_executive'])
            ->pluck('id')
            ->all();

        $staffUsers = User::query()
            ->with(['role', 'staffProfile'])
            ->whereIn('role_id', $staffRoleIds)
            ->get();
        $staffUserIds = $staffUsers->pluck('id')->all();

        $totalUnits = Unit::count();
        $totalSchools = School::count();
        $totalStaff = $staffUsers->count();
        $totalSales = Payroll::query()
            ->whereIn('user_id', $staffUserIds)
            ->where('status', 'paid')
            ->sum('amount_paid');

        $monthRows = Payroll::query()
            ->whereIn('user_id', $staffUserIds)
            ->where('month', $monthKey)
            ->get()
            ->groupBy('user_id');
        $totalDisbursed = (float) $monthRows->flatten()->sum('amount_paid');
        $staffWithSalary = $staffUsers->filter(fn (User $u) => (float) ($u->staffProfile?->salary ?? 0) > 0)->values();

        $pendingStaff = $staffWithSalary->map(function (User $user) use ($monthRows) {
            $salary = (float) ($user->staffProfile?->salary ?? 0);
            $paid = (float) ($monthRows->get($user->id)?->sum('amount_paid') ?? 0);
            $pending = max(0, $salary - $paid);

            return [
                'user_id' => $user->id,
                'name' => $user->name,
                'role' => $user->role?->display_name ?? str_replace('_', ' ', (string) ($user->role?->name ?? 'staff')),
                'salary' => round($salary, 2),
                'paid' => round($paid, 2),
                'pending' => round($pending, 2),
            ];
        })->values();

        $staffPaidCount = $pendingStaff->where('pending', '<=', 0)->count();
        $processingFees = round($totalDisbursed * 0.005, 2);

        $pendingAmount = (float) $pendingStaff->sum('pending');
        $waitingApproval = $pendingStaff->where('pending', '>', 0)->count();
        $completedCount = max($staffWithSalary->count(), 1);
        $completionPct = min(100, (int) round(($staffPaidCount / $completedCount) * 100));

        $todayRows = Attendance::query()
            ->whereDate('date', $today)
            ->whereIn('user_id', $staffUserIds)
            ->get()
            ->keyBy('user_id');
        $present = 0;
        $absent = 0;
        $onLeave = 0;
        foreach ($staffUserIds as $uid) {
            $status = $todayRows->get($uid)?->status;
            if (in_array($status, ['present', 'late'], true)) {
                $present++;
            } elseif ($status === 'on_leave') {
                $onLeave++;
            } else {
                $absent++;
            }
        }
        $totalToday = max($present + $absent + $onLeave, 1);

        $pendingLeave = LeaveRequest::query()
            ->with('user.role')
            ->whereHas('user', function ($q) use ($staffRoleIds) {
                $q->whereIn('role_id', $staffRoleIds);
            })
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (LeaveRequest $r) => [
                'id' => $r->id,
                'name' => $r->user?->name ?? '—',
                'role' => $r->user?->role?->display_name ?? str_replace('_', ' ', (string) ($r->user?->role?->name ?? 'staff')),
                'type' => $r->reason,
                'avatar' => strtoupper(substr($r->user?->name ?? 'U', 0, 2)),
                'leave_date' => $r->leave_date?->format('Y-m-d'),
            ]);

        return $this->successResponse([
            'stats' => [
                'total_units' => $totalUnits,
                'total_schools' => $totalSchools,
                'total_staff' => $totalStaff,
                'total_sales' => round($totalSales, 2),
            ],
            'payroll' => [
                'total_disbursed_salary' => round($totalDisbursed, 2),
                'total_staff_paid' => $staffPaidCount,
                'processing_fees' => $processingFees,
                'outstanding_amount' => round($pendingAmount, 2),
                'payroll_completion_percent' => $completionPct,
                'waiting_for_approval' => $waitingApproval,
                'pending_staff_details' => $pendingStaff
                    ->where('pending', '>', 0)
                    ->sortByDesc('pending')
                    ->values(),
            ],
            'attendance' => [
                'present_percent' => (int) round(($present / $totalToday) * 100),
                'absent_percent' => (int) round(($absent / $totalToday) * 100),
                'on_leave_percent' => (int) round(($onLeave / $totalToday) * 100),
            ],
            'leave_requests' => $pendingLeave,
        ], 'Dashboard summary loaded.');
    }

    public function approveLeaveRequest(Request $request, int $leaveRequestId)
    {
        $row = LeaveRequest::query()->where('id', $leaveRequestId)->where('status', 'pending')->first();
        if (! $row) {
            return $this->errorResponse('Leave request not found.', 404);
        }

        DB::transaction(function () use ($row, $request) {
            $row->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);

            Attendance::updateOrCreate(
                ['user_id' => $row->user_id, 'date' => $row->leave_date],
                ['status' => 'on_leave', 'check_in' => null, 'check_out' => null, 'remarks' => $row->reason]
            );

            if ($row->user) {
                $row->user->notify(new LeaveReviewNotification($row));
            }
        });

        return $this->successResponse(null, 'Leave request approved.');
    }

    public function rejectLeaveRequest(Request $request, int $leaveRequestId)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $row = LeaveRequest::query()->where('id', $leaveRequestId)->where('status', 'pending')->first();
        if (! $row) {
            return $this->errorResponse('Leave request not found.', 404);
        }

        $row->update([
            'status' => 'rejected',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'rejection_reason' => $validated['reason'],
        ]);

        if ($row->user) {
            $row->user->notify(new LeaveReviewNotification($row));
        }

        return $this->successResponse(null, 'Leave request rejected.');
    }
}
