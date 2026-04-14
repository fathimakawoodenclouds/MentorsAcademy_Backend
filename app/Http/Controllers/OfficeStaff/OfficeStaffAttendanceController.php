<?php

namespace App\Http\Controllers\OfficeStaff;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Coach;
use App\Models\Coordinator;
use App\Models\OfficeStaff;
use App\Models\School;
use App\Models\Unit;
use App\Models\UnitHead;
use App\Models\User;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OfficeStaffAttendanceController extends Controller
{
    use ApiResponseTrait;

    public function overview(Request $request)
    {
        $user = $request->user();
        $officeStaff = OfficeStaff::with('units')->where('user_id', $user->id)->first();
        if (! $officeStaff) {
            return $this->errorResponse('Office staff profile not found.', 404);
        }

        $unitIds = $officeStaff->units->pluck('id')->values()->all();
        if (count($unitIds) === 0) {
            return $this->successResponse([
                'summary' => [
                    'present' => 0,
                    'on_leave' => 0,
                    'absent' => 0,
                    'total' => 0,
                ],
                'rows' => [],
            ], 'No units assigned.');
        }

        $date = $request->input('date', now()->toDateString());
        $search = trim((string) $request->input('search', ''));

        $userUnitMap = $this->resolveUserUnitMap($unitIds);
        $allowedUserIds = array_keys($userUnitMap);

        if ($search !== '') {
            $matchingIds = User::query()
                ->whereIn('id', $allowedUserIds)
                ->where(function ($q) use ($search) {
                    $q->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                })
                ->pluck('id')
                ->all();
            $allowedUserIds = array_values(array_map('intval', $matchingIds));
        }

        if (count($allowedUserIds) === 0) {
            return $this->successResponse([
                'summary' => [
                    'present' => 0,
                    'on_leave' => 0,
                    'absent' => 0,
                    'total' => 0,
                ],
                'rows' => [],
            ], 'Attendance overview loaded.');
        }

        $users = User::query()
            ->with(['role', 'staffProfile', 'officeStaff'])
            ->whereIn('id', $allowedUserIds)
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        $attendanceByUser = Attendance::query()
            ->whereIn('user_id', $allowedUserIds)
            ->whereDate('date', $date)
            ->get()
            ->keyBy('user_id');

        $rows = collect($allowedUserIds)
            ->map(function (int $uid) use ($users, $attendanceByUser, $userUnitMap, $date) {
                $u = $users->get($uid);
                if (! $u) {
                    return null;
                }

                $a = $attendanceByUser->get($uid);
                $status = match ($a?->status) {
                    'on_leave' => 'ON_LEAVE',
                    'present', 'late' => 'PRESENT',
                    default => 'ABSENT',
                };
                $unitMeta = $userUnitMap[$uid] ?? null;

                return [
                    'attendance_id' => $a?->id,
                    'user_id' => $uid,
                    'id' => (string) ($u->officeStaff?->staff_id ?? 'U-'.$u->id),
                    'name' => $u->name,
                    'role' => $u->role?->display_name ?? str_replace('_', ' ', (string) ($u->role?->name ?? 'Staff')),
                    'unit_id' => $unitMeta['unit_id'] ?? null,
                    'unit' => $unitMeta['unit_name'] ?? '—',
                    'phone' => $u->staffProfile?->phone ?? '—',
                    'email' => $u->email,
                    'checkIn' => $this->formatCheckWindow($a, $date),
                    'status' => $status,
                    'remarks' => $a?->remarks,
                    'avatar' => 'https://ui-avatars.com/api/?size=128&background=EBF4DF&color=5E821A&name='.rawurlencode((string) $u->name),
                ];
            })
            ->filter()
            ->values();

        return $this->successResponse([
            'summary' => [
                'present' => $rows->where('status', 'PRESENT')->count(),
                'on_leave' => $rows->where('status', 'ON_LEAVE')->count(),
                'absent' => $rows->where('status', 'ABSENT')->count(),
                'total' => $rows->count(),
            ],
            'rows' => $rows,
        ], 'Attendance overview loaded.');
    }

    public function approveLeave(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'date' => 'required|date',
            'reason' => 'nullable|string|max:500',
        ]);

        $authUser = $request->user();
        $officeStaff = OfficeStaff::with('units')->where('user_id', $authUser->id)->first();
        if (! $officeStaff) {
            return $this->errorResponse('Office staff profile not found.', 404);
        }

        $unitIds = $officeStaff->units->pluck('id')->values()->all();
        if (count($unitIds) === 0) {
            return $this->errorResponse('No units assigned to this office staff.', 422);
        }

        $targetUnitMap = $this->resolveUserUnitMap($unitIds);
        if (! array_key_exists((int) $validated['user_id'], $targetUnitMap)) {
            return $this->errorResponse('You can approve leave only for staff in your assigned units.', 403);
        }

        return DB::transaction(function () use ($validated, $authUser) {
            $row = Attendance::query()
                ->where('user_id', $validated['user_id'])
                ->whereDate('date', $validated['date'])
                ->lockForUpdate()
                ->first();

            if ($row && ($row->check_in || $row->check_out)) {
                return $this->errorResponse('Cannot approve leave after check-in/out is already recorded.', 422);
            }

            if (! $row) {
                $row = Attendance::create([
                    'user_id' => $validated['user_id'],
                    'date' => $validated['date'],
                    'status' => 'on_leave',
                    'remarks' => $validated['reason'] ?: 'Approved by office staff',
                ]);
            } else {
                $row->update([
                    'status' => 'on_leave',
                    'check_in' => null,
                    'check_out' => null,
                    'remarks' => $validated['reason'] ?: 'Approved by office staff',
                ]);
            }

            return $this->successResponse([
                'attendance_id' => $row->id,
                'status' => 'ON_LEAVE',
                'approved_by_user_id' => $authUser->id,
            ], 'Leave approved successfully.');
        });
    }

    private function resolveUserUnitMap(array $unitIds): array
    {
        $unitNames = Unit::query()
            ->whereIn('id', $unitIds)
            ->pluck('name', 'id')
            ->all();

        $map = [];

        UnitHead::query()
            ->whereIn('unit_id', $unitIds)
            ->get(['user_id', 'unit_id'])
            ->each(function (UnitHead $r) use (&$map, $unitNames) {
                $map[(int) $r->user_id] = [
                    'unit_id' => (int) $r->unit_id,
                    'unit_name' => $unitNames[$r->unit_id] ?? '—',
                ];
            });

        Coordinator::query()
            ->whereIn('unit_id', $unitIds)
            ->get(['user_id', 'unit_id'])
            ->each(function (Coordinator $r) use (&$map, $unitNames) {
                $map[(int) $r->user_id] = [
                    'unit_id' => (int) $r->unit_id,
                    'unit_name' => $unitNames[$r->unit_id] ?? '—',
                ];
            });

        $schoolsById = School::query()
            ->whereIn('unit_id', $unitIds)
            ->get(['id', 'unit_id'])
            ->keyBy('id');

        Coach::query()
            ->whereIn('school_id', $schoolsById->keys()->all())
            ->get(['user_id', 'school_id'])
            ->each(function (Coach $r) use (&$map, $schoolsById, $unitNames) {
                $school = $schoolsById->get($r->school_id);
                if (! $school) {
                    return;
                }
                $map[(int) $r->user_id] = [
                    'unit_id' => (int) $school->unit_id,
                    'unit_name' => $unitNames[$school->unit_id] ?? '—',
                ];
            });

        OfficeStaff::query()
            ->with('units:id,name')
            ->whereHas('units', fn ($q) => $q->whereIn('units.id', $unitIds))
            ->get(['id', 'user_id'])
            ->each(function (OfficeStaff $r) use (&$map) {
                $firstUnit = $r->units->first();
                if (! $firstUnit) {
                    return;
                }
                $map[(int) $r->user_id] = [
                    'unit_id' => (int) $firstUnit->id,
                    'unit_name' => $firstUnit->name,
                ];
            });

        return $map;
    }

    private function formatCheckWindow(?Attendance $a, string $date): string
    {
        if (! $a) {
            return '-- : --';
        }

        $tz = config('app.timezone');
        $inRaw = $a->getRawOriginal('check_in') ?? $a->check_in;
        $outRaw = $a->getRawOriginal('check_out') ?? $a->check_out;
        $in = $this->formatClock($date, $inRaw, $tz);
        $out = $this->formatClock($date, $outRaw, $tz);

        if ($in && $out) {
            return $in.' - '.$out;
        }
        if ($in) {
            return $in.' - --:--';
        }
        return '-- : --';
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
