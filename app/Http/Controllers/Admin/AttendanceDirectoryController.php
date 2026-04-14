<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Payroll;
use App\Models\Role;
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

        $staffRoleIds = Role::query()
            ->whereNotIn('name', ['super_admin', 'admin'])
            ->pluck('id')
            ->all();

        $userQuery = User::query()
            ->with([
                'role',
                'staffProfile',
                'officeStaff.units',
                'unitHead.unit',
                'coordinator.unit',
                'coordinator.schools',
                'coach.school.unit',
                'coach.school.coordinator.user',
                'activityHead.activity',
                'salesExecutive',
            ])
            ->whereIn('role_id', $staffRoleIds)
            ->orderBy('name');

        if ($search !== '') {
            $term = '%'.$search.'%';
            $userQuery->where(function ($uq) use ($term) {
                $uq->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhereHas('officeStaff', function ($oq) use ($term) {
                        $oq->where('staff_id', 'like', $term);
                    });
            });
        }

        $tz = config('app.timezone');
        $staffUsers = $userQuery->get();
        $selectedDate = Carbon::parse($date)->startOfDay();
        $isFutureDate = $selectedDate->gt(now()->startOfDay());
        $attendanceRows = Attendance::query()
            ->whereDate('date', $date)
            ->whereIn('user_id', $staffUsers->pluck('id'))
            ->get()
            ->keyBy('user_id');

        $rows = $staffUsers->map(function (User $user) use ($attendanceRows, $tz, $date, $isFutureDate) {
            $a = $attendanceRows->get($user->id);
            $roleLabel = $user->role?->display_name ?? ($user->role?->name ?? '—');
            $dateStr = $a?->date?->format('Y-m-d') ?? $date;

            $inRaw = $a ? ($a->getRawOriginal('check_in') ?? $a->check_in) : null;
            $outRaw = $a ? ($a->getRawOriginal('check_out') ?? $a->check_out) : null;
            $in = $a ? $this->formatClock($dateStr, $inRaw, $tz) : null;
            $out = $a ? $this->formatClock($dateStr, $outRaw, $tz) : null;

            if ($in && $out) {
                $checkInDisplay = $in.' - '.$out;
            } elseif ($in) {
                $checkInDisplay = $in.' - --:--';
            } else {
                $checkInDisplay = '-- : --';
            }

            $status = $a
                ? ($isFutureDate
                    ? ($a->status === 'on_leave' ? 'ON_LEAVE' : 'NOT_MARKED')
                    : match ($a->status) {
                        'on_leave' => 'ON_LEAVE',
                        'absent' => 'ABSENT',
                        default => 'PRESENT',
                    })
                : ($isFutureDate ? 'NOT_MARKED' : 'ABSENT');

            return [
                'attendance_id' => $a?->id,
                'user_id' => $user->id,
                'id' => $this->resolveEmployeeId($user),
                'name' => $user->name,
                'role' => is_string($roleLabel) ? str_replace('_', ' ', $roleLabel) : $roleLabel,
                'phone' => $user->staffProfile?->phone ?? '—',
                'email' => $user->email,
                'checkIn' => $checkInDisplay,
                'status' => $status,
                'leave_reason' => $a?->status === 'on_leave' ? ($a->remarks ?: '—') : null,
                'avatar' => 'https://ui-avatars.com/api/?size=128&background=EBF4DF&color=5E821A&name='.rawurlencode($user->name),
            ];
        });

        return $this->successResponse($rows, 'Attendance directory.');
    }

    public function overview(Request $request, int $userId)
    {
        $user = User::query()
            ->withTrashed()
            ->with([
                'role',
                'staffProfile',
                'officeStaff.units',
                'unitHead.unit',
                'coordinator.unit',
                'coordinator.schools',
                'coach.school.unit',
                'coach.school.coordinator.user',
                'activityHead.activity',
                'salesExecutive',
            ])
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
        $today = now()->startOfDay();
        $endDate = $targetMonth->copy()->endOfMonth();
        $cursor = $targetMonth->copy()->startOfMonth();
        while ($cursor->lte($endDate)) {
            $dateStr = $cursor->format('Y-m-d');
            if (! isset($calendar[$dateStr]) && $cursor->lte($today)) {
                $calendar[$dateStr] = [
                    'status' => 'ABSENT',
                    'in' => null,
                    'out' => null,
                    'reason' => null,
                ];
            }
            $cursor->addDay();
        }
        ksort($calendar);

        $presentDays = $monthRows->whereIn('status', ['present', 'late'])->count();
        $leaveDays = $monthRows->where('status', 'on_leave')->count();
        $absentDays = collect($calendar)->where('status', 'ABSENT')->count();
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

        $assignment = $this->resolveAssignment($user);

        return $this->successResponse([
            'staff' => [
                'id' => $this->resolveEmployeeId($user),
                'user_id' => $user->id,
                'name' => $user->name,
                'role' => strtoupper((string) ($user->role?->name ?? 'staff')),
                'role_label' => $user->role?->display_name ?? str_replace('_', ' ', (string) ($user->role?->name ?? 'Staff')),
                'phone' => $user->staffProfile?->phone,
                'email' => $user->email,
                'unit' => $assignment['unit'],
                'coordinator' => $assignment['coordinator'],
                'school' => $assignment['school'],
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

    private function resolveEmployeeId(User $user): string
    {
        return (string) (
            $user->officeStaff?->staff_id
            ?? $user->unitHead?->staff_id
            ?? $user->coordinator?->staff_id
            ?? $user->coach?->staff_id
            ?? $user->activityHead?->staff_id
            ?? $user->salesExecutive?->staff_id
            ?? ('U-'.$user->id)
        );
    }

    private function resolveAssignment(User $user): array
    {
        $unit = '—';
        $coordinator = '—';
        $school = '—';
        $roleName = (string) ($user->role?->name ?? '');

        if ($roleName === 'office_staff') {
            $unitNames = $user->officeStaff?->units?->pluck('name')->filter()->unique()->values()->all() ?? [];
            $unit = count($unitNames) > 0 ? implode(', ', $unitNames) : '—';
        } elseif ($roleName === 'unit_head') {
            $unit = $user->unitHead?->unit?->name ?? '—';
        } elseif ($roleName === 'coordinator') {
            $unit = $user->coordinator?->unit?->name ?? '—';
            $schoolNames = $user->coordinator?->schools?->pluck('name')->filter()->unique()->values()->all() ?? [];
            $school = count($schoolNames) > 0 ? implode(', ', $schoolNames) : '—';
        } elseif ($roleName === 'coach') {
            $school = $user->coach?->school?->name ?? '—';
            $unit = $user->coach?->school?->unit?->name ?? '—';
            $coordinator = $user->coach?->school?->coordinator?->user?->name ?? '—';
        } elseif ($roleName === 'activity_head') {
            $unit = $user->activityHead?->activity?->name ?? '—';
        } elseif ($roleName === 'sales_executive') {
            $unit = 'Field';
        }

        return [
            'unit' => $unit,
            'coordinator' => $coordinator,
            'school' => $school,
        ];
    }
}
