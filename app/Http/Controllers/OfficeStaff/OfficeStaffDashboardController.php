<?php

namespace App\Http\Controllers\OfficeStaff;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Coach;
use App\Models\Coordinator;
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
        $user = $request->user()->load(['staffProfile', 'officeStaff']);

        $counts = [
            'units' => Unit::count(),
            'unit_heads' => UnitHead::count(),
            'coordinators' => Coordinator::count(),
            'schools' => School::count(),
            'coaches' => Coach::count(),
        ];

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
            ->limit(10)
            ->get()
            ->map(fn (Payroll $p) => $this->mapPayrollRow($p));

        $profile = $user->staffProfile;

        return $this->successResponse([
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'staff_id' => $user->officeStaff?->staff_id,
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
            'payroll_rows' => $payrolls,
        ], 'Dashboard loaded.');
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
        $displayDate = $p->paid_at
            ? Carbon::parse($p->paid_at)->format('M j, Y')
            : Carbon::createFromFormat('Y-m', $p->month)->endOfMonth()->format('M j, Y');

        $status = match ($p->status) {
            'paid' => 'PROCESSED',
            'pending' => 'PENDING',
            default => 'FAILED',
        };

        return [
            'id' => $p->id,
            'date' => $displayDate,
            'reference' => 'PAY-'.str_pad((string) $p->id, 6, '0', STR_PAD_LEFT),
            'amount' => '$'.number_format((float) $p->amount, 2),
            'status' => $status,
        ];
    }
}
