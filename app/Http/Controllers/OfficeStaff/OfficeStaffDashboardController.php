<?php

namespace App\Http\Controllers\OfficeStaff;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Coach;
use App\Models\Coordinator;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\School;
use App\Models\Unit;
use App\Models\UnitHead;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OfficeStaffDashboardController extends Controller
{
    use ApiResponseTrait;

    public function dashboard(Request $request)
    {
        $user = $request->user()->load(['staffProfile', 'officeStaff.units']);
        $assignedUnitIds = $user->officeStaff?->units?->pluck('id')->values()->all() ?? [];

        if (count($assignedUnitIds) > 0) {
            $counts = [
                'units' => count($assignedUnitIds),
                'unit_heads' => UnitHead::query()->whereIn('unit_id', $assignedUnitIds)->count(),
                'coordinators' => Coordinator::query()->whereIn('unit_id', $assignedUnitIds)->count(),
                'schools' => School::query()->whereIn('unit_id', $assignedUnitIds)->count(),
                'coaches' => Coach::query()
                    ->whereHas('school', fn ($q) => $q->whereIn('unit_id', $assignedUnitIds))
                    ->count(),
            ];
        } else {
            $counts = [
                'units' => 0,
                'unit_heads' => 0,
                'coordinators' => 0,
                'schools' => 0,
                'coaches' => 0,
            ];
        }

        $year = (int) now()->year;
        $month = (int) now()->month;

        $monthRows = Attendance::query()
            ->where('user_id', $user->id)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->get();

        $workingDays = $monthRows->whereIn('status', ['present', 'late'])->count();
        $totalLeaves = $monthRows->where('status', 'on_leave')->count();

        $todayRow = Attendance::query()
            ->where('user_id', $user->id)
            ->whereDate('date', now()->toDateString())
            ->first();

        $shift = $this->formatShiftState($todayRow);

        $payrolls = Payroll::query()
            ->where('user_id', $user->id)
            ->orderByDesc('month')
            ->limit(5)
            ->get()
            ->map(fn (Payroll $p) => $this->mapPayrollRow($p));

        $profile = $user->staffProfile;

        return $this->successResponse([
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'staff_id' => $user->officeStaff?->staff_id,
                'assigned_units' => $user->officeStaff?->units?->map(fn (Unit $unit) => [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'unit_id' => $unit->unit_id,
                ])->values() ?? [],
            ],
            'staff_profile' => $profile ? [
                'phone' => $profile->phone,
                'wage_type' => $profile->wage_type,
                'salary' => $profile->salary !== null ? (float) $profile->salary : null,
                'status' => $profile->status,
                'city' => $profile->city,
                'state' => $profile->state,
            ] : null,
            'counts' => $counts,
            'attendance' => [
                'month_working_days' => $workingDays,
                'month_leaves' => $totalLeaves,
                'shift' => $shift,
            ],
            'leave_requests' => LeaveRequest::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->limit(5)
                ->get()
                ->map(fn (LeaveRequest $r) => [
                    'id' => $r->id,
                    'leave_date' => $r->leave_date?->format('Y-m-d'),
                    'reason' => $r->reason,
                    'status' => strtoupper($r->status),
                ]),
            'leave_notifications' => $user->notifications()
                ->where('type', \App\Notifications\LeaveReviewNotification::class)
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn ($n) => [
                    'id' => $n->id,
                    'message' => $n->data['message'] ?? 'Leave request updated.',
                    'status' => $n->data['status'] ?? null,
                    'leave_date' => $n->data['leave_date'] ?? null,
                    'created_at' => optional($n->created_at)->toDateTimeString(),
                    'read_at' => optional($n->read_at)->toDateTimeString(),
                ]),
            'payroll_rows' => $payrolls,
        ], 'Dashboard loaded.');
    }

    public function payrollHistory(Request $request)
    {
        $user = $request->user();
        $perPage = (int) $request->input('per_page', 5);
        $rows = Payroll::query()
            ->where('user_id', $user->id)
            ->orderByDesc('month')
            ->paginate($perPage);

        $mapped = collect($rows->items())->map(fn (Payroll $p) => $this->mapPayrollRow($p))->values();

        return $this->successResponse([
            'rows' => $mapped,
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ], 'Payroll history loaded.');
    }

    public function applyLeave(Request $request)
    {
        $validated = $request->validate([
            'leave_date' => 'required|date',
            'reason' => 'required|string|max:500',
        ]);

        $user = $request->user();

        $existing = LeaveRequest::query()
            ->where('user_id', $user->id)
            ->whereDate('leave_date', $validated['leave_date'])
            ->where('status', 'pending')
            ->first();
        if ($existing) {
            return $this->errorResponse('A pending leave request already exists for this date.', 422);
        }

        $row = LeaveRequest::create([
            'user_id' => $user->id,
            'leave_date' => $validated['leave_date'],
            'reason' => $validated['reason'],
            'status' => 'pending',
        ]);

        return $this->successResponse($row, 'Leave request submitted.', 201);
    }

    public function checkIn(Request $request)
    {
        $user = $request->user();
        $date = now()->toDateString();

        return DB::transaction(function () use ($user, $date) {
            $row = Attendance::query()
                ->where('user_id', $user->id)
                ->whereDate('date', $date)
                ->lockForUpdate()
                ->first();

            if ($row && $row->status === 'on_leave') {
                return $this->errorResponse('You are marked on leave for today.', 422);
            }

            if ($row && $row->check_in && ! $row->check_out) {
                return $this->errorResponse('You are already checked in.', 422);
            }

            if ($row && $row->check_in && $row->check_out) {
                return $this->errorResponse('Today’s attendance is already complete.', 422);
            }

            $now = now()->format('H:i:s');

            if (! $row) {
                $row = Attendance::create([
                    'user_id' => $user->id,
                    'date' => $date,
                    'status' => 'present',
                    'check_in' => $now,
                    'remarks' => null,
                ]);
            } else {
                $row->update([
                    'status' => 'present',
                    'check_in' => $now,
                    'check_out' => null,
                ]);
            }

            $row->refresh();

            return $this->successResponse(
                ['shift' => $this->formatShiftState($row)],
                'Checked in.'
            );
        });
    }

    public function checkOut(Request $request)
    {
        $user = $request->user();
        $date = now()->toDateString();

        return DB::transaction(function () use ($user, $date) {
            $row = Attendance::query()
                ->where('user_id', $user->id)
                ->whereDate('date', $date)
                ->lockForUpdate()
                ->first();

            if (! $row || ! $row->check_in) {
                return $this->errorResponse('Check in first.', 422);
            }

            if ($row->check_out) {
                return $this->errorResponse('Already checked out.', 422);
            }

            $row->forceFill([
                'check_out' => now()->format('H:i:s'),
            ])->save();
            $row->refresh();

            return $this->successResponse(
                ['shift' => $this->formatShiftState($row)],
                'Checked out.'
            );
        });
    }

    private function formatShiftState(?Attendance $todayRow): array
    {
        if (! $todayRow) {
            return [
                'checked_in' => false,
                'checked_out' => false,
                'check_in_time' => null,
                'check_out_time' => null,
            ];
        }

        $tz = config('app.timezone');
        $dateStr = $todayRow->date->format('Y-m-d');
        $inRaw = $todayRow->getRawOriginal('check_in') ?? $todayRow->check_in;
        $outRaw = $todayRow->getRawOriginal('check_out') ?? $todayRow->check_out;

        return [
            'checked_in' => (bool) $inRaw && ! $outRaw,
            'checked_out' => (bool) $outRaw,
            'check_in_time' => $this->formatClock($dateStr, $inRaw, $tz),
            'check_out_time' => $this->formatClock($dateStr, $outRaw, $tz),
        ];
    }

    private function formatClock(string $dateStr, mixed $time, string $tz): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        if ($time instanceof \Carbon\CarbonInterface) {
            return $time->copy()->timezone($tz)->format('h:i A');
        }

        $t = trim((string) $time);
        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $t)) {
            return Carbon::parse($dateStr.' '.$t, $tz)->timezone($tz)->format('h:i A');
        }

        return Carbon::parse($t)->timezone($tz)->format('h:i A');
    }

    private function mapPayrollRow(Payroll $p): array
    {
        $total = (float) $p->amount;
        $paid = (float) ($p->amount_paid ?? 0);
        $pending = max(0, $total - $paid);
        $displayDate = $p->paid_at
            ? Carbon::parse($p->paid_at)->format('M j, Y')
            : Carbon::createFromFormat('Y-m', $p->month)->endOfMonth()->format('M j, Y');

        $status = match ($p->status) {
            'paid' => 'PAID',
            'pending' => 'PENDING',
            default => 'FAILED',
        };

        return [
            'id' => $p->id,
            'date' => $displayDate,
            'reference' => 'PAY-'.str_pad((string) $p->id, 6, '0', STR_PAD_LEFT),
            'amount' => '$'.number_format($paid, 2),
            'pending_amount' => '$'.number_format($pending, 2),
            'status' => $status,
        ];
    }
}
