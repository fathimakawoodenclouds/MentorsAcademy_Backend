<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Payroll;
use App\Models\User;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AttendanceDirectoryController extends Controller
{
    use ApiResponseTrait;

    /**
     * List attendance rows for a calendar day (check-in / check-out from attendances table).
     */
    public function index(Request $request)
    {
        $date = $request->input('date', now()->toDateString());
        $search = (string) $request->input('search', '');

        $query = Attendance::query()
            ->with(['user.role', 'user.staffProfile', 'user.officeStaff'])
            ->whereDate('date', $date)
            ->orderByDesc('updated_at');

        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->whereHas('user', function ($uq) use ($term) {
                $uq->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        $tz = config('app.timezone');

        $rows = $query->get()->map(function (Attendance $a) use ($tz) {
            $user = $a->user;
            $roleLabel = $user->role?->display_name ?? ($user->role?->name ?? '—');
            $dateStr = $a->date->format('Y-m-d');

            $inRaw = $a->getRawOriginal('check_in') ?? $a->check_in;
            $outRaw = $a->getRawOriginal('check_out') ?? $a->check_out;

            $in = $this->formatClock($dateStr, $inRaw, $tz);
            $out = $this->formatClock($dateStr, $outRaw, $tz);

            if ($in && $out) {
                $checkInDisplay = $in.' - '.$out;
            } elseif ($in) {
                $checkInDisplay = $in.' - --:--';
            } else {
                $checkInDisplay = '-- : --';
            }

            $status = match ($a->status) {
                'on_leave' => 'ON_LEAVE',
                'absent' => 'ABSENT',
                default => 'PRESENT',
            };

            return [
                'attendance_id' => $a->id,
                'user_id' => $user->id,
                'id' => (string) ($user->officeStaff?->staff_id ?? 'U-'.$user->id),
                'name' => $user->name,
                'role' => is_string($roleLabel) ? str_replace('_', ' ', $roleLabel) : $roleLabel,
                'phone' => $user->staffProfile?->phone ?? '—',
                'email' => $user->email,
                'checkIn' => $checkInDisplay,
                'status' => $status,
                'avatar' => 'https://ui-avatars.com/api/?size=128&background=EBF4DF&color=5E821A&name='.rawurlencode($user->name),
            ];
        });

        return $this->successResponse($rows, 'Attendance directory.');
    }

    public function overview(Request $request, int $userId)
    {
        $user = User::query()
            ->withTrashed()
            ->with(['role', 'staffProfile', 'officeStaff'])
            ->find($userId);

        if (! $user) {
            return $this->errorResponse('Staff not found.', 404);
        }

        $monthDate = $request->input('month');
        $targetMonth = $monthDate
            ? Carbon::parse($monthDate)->startOfMonth()
            : now()->startOfMonth();

        $year = (int) $targetMonth->year;
        $month = (int) $targetMonth->month;

        $monthRows = Attendance::query()
            ->where('user_id', $user->id)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->orderBy('date')
            ->get();

        $tz = config('app.timezone');
        $calendar = $monthRows->mapWithKeys(function (Attendance $a) use ($tz) {
            $dateStr = $a->date->format('Y-m-d');
            $inRaw = $a->getRawOriginal('check_in') ?? $a->check_in;
            $outRaw = $a->getRawOriginal('check_out') ?? $a->check_out;

            return [$dateStr => [
                'status' => match ($a->status) {
                    'on_leave' => 'ON_LEAVE',
                    'absent' => 'ABSENT',
                    default => 'PRESENT',
                },
                'in' => $this->formatClock($dateStr, $inRaw, $tz),
                'out' => $this->formatClock($dateStr, $outRaw, $tz),
                'reason' => $a->remarks,
            ]];
        })->all();

        $presentDays = $monthRows->whereIn('status', ['present', 'late'])->count();
        $leaveDays = $monthRows->where('status', 'on_leave')->count();
        $absentDays = $monthRows->where('status', 'absent')->count();
        $trackedDays = max($presentDays + $leaveDays + $absentDays, 1);
        $attendanceRate = round(($presentDays / $trackedDays) * 100).'%';

        $todayStatus = optional($monthRows->firstWhere('date', now()->toDateString()))->status;
        $statusLabel = match ($todayStatus) {
            'on_leave' => 'ON_LEAVE',
            'absent' => 'ABSENT',
            default => 'PRESENT',
        };

        $credits = Payroll::query()
            ->where('user_id', $user->id)
            ->orderByDesc('month')
            ->limit(5)
            ->get()
            ->map(function (Payroll $p) {
                $displayDate = $p->paid_at
                    ? Carbon::parse($p->paid_at)->format('M j, Y')
                    : Carbon::createFromFormat('Y-m', $p->month)->endOfMonth()->format('M j, Y');

                return [
                    'id' => $p->id,
                    'month' => Carbon::createFromFormat('Y-m', $p->month)->format('F Y'),
                    'date' => $displayDate,
                    'amount' => '$'.number_format((float) $p->amount, 2),
                    'status' => strtoupper($p->status === 'paid' ? 'credited' : $p->status),
                ];
            })
            ->values();

        return $this->successResponse([
            'staff' => [
                'id' => (string) ($user->officeStaff?->staff_id ?? 'U-'.$user->id),
                'user_id' => $user->id,
                'name' => $user->name,
                'role' => strtoupper((string) ($user->role?->name ?? 'staff')),
                'role_label' => $user->role?->display_name ?? str_replace('_', ' ', (string) ($user->role?->name ?? 'Staff')),
                'phone' => $user->staffProfile?->phone,
                'email' => $user->email,
                'unit' => '—',
                'coordinator' => '—',
                'school' => '—',
                'avatar' => 'https://ui-avatars.com/api/?size=160&background=EBF4DF&color=5E821A&name='.rawurlencode($user->name),
                'status' => $statusLabel,
                'salary' => [
                    'present_days' => $presentDays,
                    'leave_days' => $leaveDays,
                    'absent_days' => $absentDays,
                    'attendance_rate' => $attendanceRate,
                    'monthly_salary' => $user->staffProfile?->salary !== null
                        ? '$'.number_format((float) $user->staffProfile->salary, 2)
                        : '—',
                ],
            ],
            'calendar' => [
                'month' => $targetMonth->format('Y-m'),
                'days' => $calendar,
            ],
            'credits' => $credits,
        ], 'Attendance overview loaded.');
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
}
