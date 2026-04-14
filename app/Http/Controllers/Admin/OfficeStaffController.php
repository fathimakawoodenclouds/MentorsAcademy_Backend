<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\OfficeStaff;
use App\Models\Payroll;
use App\Models\Role;
use App\Models\User;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class OfficeStaffController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request)
    {
        $query = OfficeStaff::with(['user.staffProfile', 'units']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('staff_id', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('pay_type') && $request->pay_type !== 'All') {
            $payType = $request->pay_type;
            $query->whereHas('user.staffProfile', function ($q) use ($payType) {
                $q->where('wage_type', $payType);
            });
        }

        $perPage = (int) $request->input('per_page', 4);
        $staff = $query->latest('id')->paginate($perPage);

        return response()->json($staff);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'fullName' => 'required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'email' => 'required|email|max:255|unique:users,email',
            'username' => ['required', 'string', 'max:255', Rule::in([$request->input('email')])],
            'password' => 'required|string|min:6',
            'age' => 'required|integer|min:16|max:100',
            'addressLine1' => 'required|string|max:500',
            'addressLine2' => 'nullable|string|max:500',
            'landmark' => 'nullable|string|max:255',
            'state' => 'required|string|max:100',
            'city' => 'required|string|max:100',
            'pincode' => 'required|string|max:10',
            'payType' => 'required|in:Monthly,Daily',
            'amount' => 'required|numeric|min:0',
            'unit_ids' => 'required|array|min:1|max:2',
            'unit_ids.*' => 'required|integer|distinct|exists:units,id',
        ]);

        $roleId = Role::where('name', 'office_staff')->value('id');
        if (! $roleId) {
            return $this->errorResponse('Office staff role is not configured. Run RoleSeeder.', 500);
        }

        return DB::transaction(function () use ($validated, $roleId) {
            $user = User::create([
                'name' => $validated['fullName'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role_id' => $roleId,
            ]);

            $address = trim(implode(', ', array_filter([
                $validated['addressLine1'] ?? null,
                $validated['addressLine2'] ?? null,
                $validated['landmark'] ?? null,
            ])));

            $user->staffProfile()->create([
                'phone' => $validated['phone'] ?? null,
                'age' => $validated['age'],
                'address' => $address ?: null,
                'state' => $validated['state'],
                'city' => $validated['city'],
                'pincode' => $validated['pincode'],
                'wage_type' => $validated['payType'],
                'salary' => $validated['amount'],
                'status' => 'active',
            ]);

            $record = OfficeStaff::create([
                'user_id' => $user->id,
            ]);

            $record->units()->sync($validated['unit_ids']);

            return $this->successResponse(
                $record->load(['user.staffProfile', 'units']),
                'Office staff created successfully.',
                201
            );
        });
    }

    public function show(string $id)
    {
        $record = OfficeStaff::with(['user.staffProfile', 'units'])->find($id);
        if (! $record) {
            return $this->errorResponse('Office staff not found', 404);
        }

        $userId = $record->user_id;
        $preview = Payroll::where('user_id', $userId)
            ->orderByDesc('month')
            ->limit(3)
            ->get();

        $salaryHistoryPreview = $preview->map(function (Payroll $p) {
            $total = (float) $p->amount;
            $paid = (float) ($p->amount_paid ?? 0);
            $pending = max(0, $total - $paid);
            return [
                'id' => $p->id,
                'date' => $p->paid_at
                    ? Carbon::parse($p->paid_at)->format('M j, Y')
                    : Carbon::createFromFormat('Y-m', $p->month)->endOfMonth()->format('M j, Y'),
                'method' => 'Bank Transfer',
                'amount' => $this->formatMoney($total),
                'amount_paid' => $this->formatMoney($paid),
                'pending_amount' => $this->formatMoney($pending),
                'status' => strtoupper($p->status === 'paid' ? 'PAID' : ($p->status === 'pending' ? 'PENDING' : 'FAILED')),
                'raw_month' => $p->month,
                'raw_amount' => $total,
                'raw_amount_paid' => $paid,
                'raw_status' => $p->status,
                'paid_at_iso' => $p->paid_at ? Carbon::parse($p->paid_at)->format('Y-m-d') : null,
            ];
        });

        return $this->successResponse([
            'office_staff' => $record,
            'salary_history_preview' => $salaryHistoryPreview,
        ], 'Office staff retrieved.');
    }

    public function update(Request $request, string $id)
    {
        $record = OfficeStaff::with('user.staffProfile')->find($id);
        if (! $record) {
            return $this->errorResponse('Office staff not found', 404);
        }

        $validated = $request->validate([
            'fullName' => 'sometimes|required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($record->user_id)],
            'password' => 'nullable|string|min:6',
            'age' => 'sometimes|required|integer|min:16|max:100',
            'addressLine1' => 'sometimes|required|string|max:500',
            'addressLine2' => 'nullable|string|max:500',
            'landmark' => 'nullable|string|max:255',
            'state' => 'sometimes|required|string|max:100',
            'city' => 'sometimes|required|string|max:100',
            'pincode' => 'sometimes|required|string|max:10',
            'payType' => 'sometimes|required|in:Monthly,Daily',
            'amount' => 'sometimes|required|numeric|min:0',
            'unit_ids' => 'sometimes|required|array|min:1|max:2',
            'unit_ids.*' => 'required|integer|distinct|exists:units,id',
        ]);

        return DB::transaction(function () use ($validated, $record) {
            $userData = [];
            if (isset($validated['fullName'])) {
                $userData['name'] = $validated['fullName'];
            }
            if (isset($validated['email'])) {
                $userData['email'] = $validated['email'];
            }
            if (! empty($validated['password'])) {
                $userData['password'] = Hash::make($validated['password']);
            }
            if (! empty($userData)) {
                $record->user->update($userData);
            }

            $profileData = [];
            foreach (['phone', 'age', 'state', 'city', 'pincode'] as $f) {
                if (array_key_exists($f, $validated)) {
                    $profileData[$f] = $validated[$f];
                }
            }
            if (isset($validated['payType'])) {
                $profileData['wage_type'] = $validated['payType'];
            }
            if (isset($validated['amount'])) {
                $profileData['salary'] = $validated['amount'];
            }
            if (isset($validated['addressLine1']) || isset($validated['addressLine2']) || isset($validated['landmark'])) {
                $address = trim(implode(', ', array_filter([
                    $validated['addressLine1'] ?? null,
                    $validated['addressLine2'] ?? null,
                    $validated['landmark'] ?? null,
                ])));
                if ($address !== '') {
                    $profileData['address'] = $address;
                }
            }

            if (! empty($profileData) && $record->user->staffProfile) {
                $record->user->staffProfile->update($profileData);
            }

            if (array_key_exists('unit_ids', $validated)) {
                $record->units()->sync($validated['unit_ids']);
            }

            return $this->successResponse(
                $record->fresh()->load(['user.staffProfile', 'units']),
                'Office staff updated successfully.'
            );
        });
    }

    public function destroy(string $id)
    {
        $record = OfficeStaff::find($id);
        if (! $record) {
            return $this->errorResponse('Office staff not found', 404);
        }

        $record->delete();

        return $this->successResponse(null, 'Office staff deleted successfully.');
    }

    public function attendance(Request $request, string $id)
    {
        $record = OfficeStaff::with('user')->find($id);
        if (! $record) {
            return $this->errorResponse('Office staff not found', 404);
        }

        $year = (int) $request->input('year', now()->year);
        $month = (int) $request->input('month', now()->month);

        $rows = Attendance::query()
            ->where('user_id', $record->user_id)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->orderBy('date')
            ->get();

        $days = [];
        $present = 0;
        $absent = 0;
        $leaves = 0;
        $hoursSum = 0.0;
        $hoursCount = 0;

        foreach ($rows as $row) {
            $key = $row->date->format('Y-m-d');
            if ($row->status === 'present') {
                $present++;
                $in = $row->check_in ? Carbon::parse($row->check_in)->format('h:i A') : null;
                $out = $row->check_out ? Carbon::parse($row->check_out)->format('h:i A') : null;
                if ($row->check_in && $row->check_out) {
                    $ci = Carbon::parse($row->date->format('Y-m-d').' '.$row->check_in);
                    $co = Carbon::parse($row->date->format('Y-m-d').' '.$row->check_out);
                    if ($co->lt($ci)) {
                        $co->addDay();
                    }
                    $hoursSum += $ci->diffInMinutes($co) / 60;
                    $hoursCount++;
                }
                $days[$key] = [
                    'status' => 'present',
                    'in' => $in ?? '—',
                    'out' => $out ?? '—',
                ];
            } elseif ($row->status === 'on_leave') {
                $leaves++;
                $days[$key] = [
                    'status' => 'on-leave',
                    'reason' => $row->remarks ?: 'Approved leave',
                ];
            } elseif ($row->status === 'absent') {
                $absent++;
                $days[$key] = [
                    'status' => 'absent',
                    'reason' => $row->remarks ?: 'Unexcused',
                ];
            } elseif ($row->status === 'late') {
                $present++;
                $days[$key] = [
                    'status' => 'present',
                    'in' => $row->check_in ? Carbon::parse($row->check_in)->format('h:i A') : '—',
                    'out' => $row->check_out ? Carbon::parse($row->check_out)->format('h:i A') : '—',
                ];
            } else {
                $days[$key] = [
                    'status' => 'absent',
                    'reason' => $row->remarks ?: 'Recorded',
                ];
                $absent++;
            }
        }

        $avgHours = $hoursCount > 0 ? round($hoursSum / $hoursCount, 1) : 0;

        return $this->successResponse([
            'staff_name' => $record->user->name,
            'staff_id' => $record->staff_id,
            'days' => $days,
            'summary' => [
                'total_present' => $present,
                'total_absent' => $absent,
                'total_leaves' => $leaves,
                'average_hours' => $avgHours,
            ],
        ], 'Attendance retrieved.');
    }

    public function payrolls(Request $request, string $id)
    {
        $record = OfficeStaff::with(['user.staffProfile', 'user'])->find($id);
        if (! $record) {
            return $this->errorResponse('Office staff not found', 404);
        }

        $year = (int) $request->input('year', now()->year);

        $rows = Payroll::query()
            ->where('user_id', $record->user_id)
            ->where('month', 'like', $year.'-%')
            ->orderByDesc('month')
            ->get();

        $wageType = $record->user->staffProfile->wage_type ?? 'Monthly';

        $ledger = $rows->map(function (Payroll $p) use ($wageType) {
            $monthLabel = Carbon::createFromFormat('Y-m', $p->month)->format('F Y');
            $base = (float) $p->amount;
            $paid = (float) ($p->amount_paid ?? 0);
            $pending = max(0, $base - $paid);

            return [
                'id' => $p->id,
                'month' => $monthLabel,
                'cycle' => $wageType === 'Daily' ? 'Daily' : 'Monthly',
                'base' => $this->formatMoney($base),
                'deductions' => $this->formatMoney($pending),
                'bonus' => $this->formatMoney(0),
                'total' => $this->formatMoney($paid),
                'pending_amount' => $this->formatMoney($pending),
                'status' => $p->status === 'paid' ? 'PAID' : ($p->status === 'pending' ? 'PENDING' : 'FAILED'),
                'date' => $p->paid_at ? Carbon::parse($p->paid_at)->format('M j, Y') : '—',
                'raw_month' => $p->month,
                'raw_amount' => $base,
                'raw_amount_paid' => $paid,
                'raw_status' => $p->status,
                'paid_at_iso' => $p->paid_at ? Carbon::parse($p->paid_at)->format('Y-m-d') : null,
            ];
        });

        $paid = $rows->where('status', 'paid');
        $annualTotal = $rows->sum('amount_paid');
        $nextPending = $rows->firstWhere('status', 'pending');
        $nextPayout = $nextPending
            ? Carbon::createFromFormat('Y-m', $nextPending->month)->endOfMonth()->format('M j, Y')
            : '—';

        return $this->successResponse([
            'staff_name' => $record->user->name,
            'ledger' => $ledger,
            'summary' => [
                'annual_total' => $this->formatMoney((float) $annualTotal),
                'bonuses_earned' => $this->formatMoney(0),
                'next_payout' => $nextPayout,
            ],
            'meta' => [
                'total_records' => $ledger->count(),
                'year' => $year,
            ],
        ], 'Payrolls retrieved.');
    }

    public function storePayroll(Request $request, string $id)
    {
        $record = OfficeStaff::with('user.staffProfile')->find($id);
        if (! $record) {
            return $this->errorResponse('Office staff not found', 404);
        }

        $validated = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'amount' => 'required|numeric|min:0',
            'amount_paid_now' => 'required|numeric|min:0',
            'status' => 'required|in:pending,paid,failed',
            'paid_at' => 'nullable|date',
        ]);

        $total = (float) $validated['amount'];
        $paidNow = min($total, max(0, (float) $validated['amount_paid_now']));
        $pending = max(0, $total - $paidNow);
        $status = $pending <= 0.0001 ? 'paid' : ($validated['status'] === 'failed' ? 'failed' : 'pending');

        $payroll = Payroll::create([
            'user_id' => $record->user_id,
            'month' => $validated['month'],
            'amount' => $total,
            'amount_paid' => $paidNow,
            'status' => $status,
            'paid_at' => $validated['paid_at'] ?? ($status === 'paid' ? now() : null),
        ]);

        return $this->successResponse($payroll, 'Payroll entry added.', 201);
    }

    public function updatePayroll(Request $request, string $id, string $payrollId)
    {
        $record = OfficeStaff::find($id);
        if (! $record) {
            return $this->errorResponse('Office staff not found', 404);
        }

        $payroll = Payroll::query()
            ->where('id', $payrollId)
            ->where('user_id', $record->user_id)
            ->first();
        if (! $payroll) {
            return $this->errorResponse('Payroll entry not found', 404);
        }

        $validated = $request->validate([
            'month' => ['sometimes', 'required', 'regex:/^\d{4}-\d{2}$/'],
            'amount' => 'sometimes|required|numeric|min:0',
            'amount_paid_now' => 'sometimes|required|numeric|min:0',
            'status' => 'sometimes|required|in:pending,paid,failed,completed',
            'paid_at' => 'nullable|date',
        ]);

        if (isset($validated['status']) && $validated['status'] === 'completed') {
            $validated['status'] = 'paid';
        }

        if (isset($validated['status']) && $validated['status'] === 'paid' && ! array_key_exists('paid_at', $validated)) {
            $validated['paid_at'] = now();
        }

        if (isset($validated['status']) && $validated['status'] !== 'paid' && array_key_exists('paid_at', $validated) && ! $validated['paid_at']) {
            $validated['paid_at'] = null;
        }

        $total = (float) ($validated['amount'] ?? $payroll->amount);
        $paidNow = (float) ($validated['amount_paid_now'] ?? $payroll->amount_paid);
        $paidNow = min($total, max(0, $paidNow));
        $pending = max(0, $total - $paidNow);

        $status = $validated['status'] ?? $payroll->status;
        if ($status === 'completed') {
            $status = 'paid';
        }
        if ($pending <= 0.0001) {
            $status = 'paid';
        } elseif ($status === 'paid') {
            $status = 'pending';
        }

        $payroll->update([
            'month' => $validated['month'] ?? $payroll->month,
            'amount' => $total,
            'amount_paid' => $paidNow,
            'status' => $status,
            'paid_at' => ($status === 'paid')
                ? ($validated['paid_at'] ?? $payroll->paid_at ?? now())
                : null,
        ]);

        return $this->successResponse($payroll->fresh(), 'Payroll entry updated.');
    }

    private function formatMoney(float $amount): string
    {
        return '$'.number_format($amount, 2);
    }
}
