<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\UnitHead;
use App\Models\OfficeStaff;
use App\Models\User;
use App\Models\StaffProfile;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UnitHeadController extends Controller
{
    public function index(Request $request)
    {
        $query = UnitHead::with(['user.staffProfile', 'unit']);
        $allowedUnitIds = $this->resolveAllowedUnitIds($request);
        if ($allowedUnitIds !== null) {
            $query->whereIn('unit_id', $allowedUnitIds);
        }

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->whereHas('user', function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('unit') && $request->unit !== 'All Units') {
            $unitName = $request->unit;
            $query->whereHas('unit', function($q) use ($unitName) {
                $q->where('name', $unitName);
            });
        }

        $heads = $query->latest()->paginate($request->input('per_page', 8));
        
        return response()->json([
            'status' => 'success',
            'data' => $heads
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'fullName' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'unit_id' => 'required|exists:units,id',
            'phone' => 'nullable|string',
            'age' => 'nullable|integer',
            'gender' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'state' => 'nullable|string',
            'pincode' => 'nullable|string',
            'wage_type' => 'nullable|string',
            'salary' => 'nullable|numeric',
        ]);

        try {
            return DB::transaction(function () use ($validated) {
                // 1. Create User
                $user = User::create([
                    'name' => $validated['fullName'],
                    'email' => $validated['email'],
                    'password' => Hash::make($validated['password']),
                    'role_id' => 9, // Assuming unit_head role is 9. Adjust if needed based on roles table. 
                    // Wait, let's look up role by name to be safe if possible, or just hardcode if it works.
                ]);

                // 2. Create Staff Profile
                StaffProfile::create([
                    'user_id' => $user->id,
                    'phone' => $validated['phone'] ?? null,
                    'address' => $validated['address'] ?? null,
                    'age' => $validated['age'] ?? null,
                    'gender' => $validated['gender'] ?? null,
                    'city' => $validated['city'] ?? null,
                    'state' => $validated['state'] ?? null,
                    'pincode' => $validated['pincode'] ?? null,
                    'wage_type' => $validated['wage_type'] ?? 'Monthly',
                    'salary' => $validated['salary'] ?? 0,
                    'status' => 'active',
                    'joining_date' => now(),
                ]);

                // 3. Create Unit Head
                $unitHead = UnitHead::create([
                    'user_id' => $user->id,
                    'unit_id' => $validated['unit_id'],
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Unit Head created successfully',
                    'data' => $unitHead->load(['user.staffProfile', 'unit'])
                ], 201);
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create Unit Head: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        $head = UnitHead::with(['user.staffProfile', 'unit.schools'])->findOrFail($id);
        $allowedUnitIds = $this->resolveAllowedUnitIds(request());
        if ($allowedUnitIds !== null && ! in_array((int) $head->unit_id, $allowedUnitIds, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized to access this unit head.'
            ], 403);
        }
        return response()->json([
            'status' => 'success',
            'data' => $head
        ]);
    }

    public function update(Request $request, string $id)
    {
        $head = UnitHead::findOrFail($id);
        
        $validated = $request->validate([
            'fullName' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $head->user_id,
            'password' => 'nullable|string|min:6',
            'unit_id' => 'required|exists:units,id',
            'phone' => 'nullable|string',
            'age' => 'nullable|integer',
            'gender' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'state' => 'nullable|string',
            'pincode' => 'nullable|string',
            'wage_type' => 'nullable|string',
            'salary' => 'nullable|numeric',
        ]);

        try {
            DB::transaction(function () use ($head, $validated) {
                // Update User
                $userData = [
                    'name' => $validated['fullName'],
                    'email' => $validated['email'],
                ];
                if (!empty($validated['password'])) {
                    $userData['password'] = Hash::make($validated['password']);
                }
                $head->user->update($userData);

                // Update Staff Profile
                $head->user->staffProfile()->update([
                    'phone' => $validated['phone'] ?? $head->user->staffProfile->phone,
                    'address' => $validated['address'] ?? $head->user->staffProfile->address,
                    'age' => $validated['age'] ?? $head->user->staffProfile->age,
                    'gender' => $validated['gender'] ?? $head->user->staffProfile->gender,
                    'city' => $validated['city'] ?? $head->user->staffProfile->city,
                    'state' => $validated['state'] ?? $head->user->staffProfile->state,
                    'pincode' => $validated['pincode'] ?? $head->user->staffProfile->pincode,
                    'wage_type' => $validated['wage_type'] ?? $head->user->staffProfile->wage_type,
                    'salary' => $validated['salary'] ?? $head->user->staffProfile->salary,
                ]);

                // Update Unit Head
                $head->update([
                    'unit_id' => $validated['unit_id'],
                ]);
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Unit Head updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        $head = UnitHead::findOrFail($id);
        try {
            DB::transaction(function () use ($head) {
                $user = $head->user;
                $head->delete();
                if ($user) {
                    $user->staffProfile()->delete();
                    $user->delete();
                }
            });
            return response()->json([
                'status' => 'success',
                'message' => 'Unit Head deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete: ' . $e->getMessage()
            ], 500);
        }
    }

    private function resolveAllowedUnitIds(Request $request): ?array
    {
        $authUser = $request->user();
        if (! $authUser || ! $authUser->hasRole('office_staff')) {
            return null;
        }

        return OfficeStaff::query()
            ->where('user_id', $authUser->id)
            ->first()
            ?->units()
            ->pluck('units.id')
            ->map(fn ($unitId) => (int) $unitId)
            ->values()
            ->all() ?? [];
    }
}
