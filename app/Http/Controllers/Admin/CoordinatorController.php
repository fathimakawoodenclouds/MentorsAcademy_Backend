<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coordinator;
use App\Models\OfficeStaff;
use App\Models\User;
use App\Models\StaffProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CoordinatorController extends Controller
{
    public function index(Request $request)
    {
        $query = Coordinator::with(['user.staffProfile', 'unit', 'schools']);
        $allowedUnitIds = $this->resolveAllowedUnitIds($request);
        if ($allowedUnitIds !== null) {
            $query->whereIn('unit_id', $allowedUnitIds);
        }
        
        if ($request->has('unit_id')) {
            $query->where('unit_id', $request->unit_id);
        }

        $coordinators = $query->latest()->get();
        
        return response()->json([
            'status' => 'success',
            'data' => $coordinators
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
                    'role_id' => 5, // coordinator
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

                // 3. Create Coordinator
                $coordinator = Coordinator::create([
                    'user_id' => $user->id,
                    'unit_id' => $validated['unit_id'],
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Coordinator created successfully',
                    'data' => $coordinator->load(['user.staffProfile', 'unit'])
                ], 201);
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create Coordinator: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        $coordinator = Coordinator::with(['user.staffProfile', 'unit', 'schools.coaches.user.staffProfile'])->findOrFail($id);
        $allowedUnitIds = $this->resolveAllowedUnitIds(request());
        if ($allowedUnitIds !== null && ! in_array((int) $coordinator->unit_id, $allowedUnitIds, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized to access this coordinator.'
            ], 403);
        }
        return response()->json([
            'status' => 'success',
            'data' => $coordinator
        ]);
    }

    public function update(Request $request, string $id)
    {
        $coordinator = Coordinator::findOrFail($id);
        
        $validated = $request->validate([
            'fullName' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $coordinator->user_id,
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
            DB::transaction(function () use ($coordinator, $validated) {
                // Update User
                $userData = [
                    'name' => $validated['fullName'],
                    'email' => $validated['email'],
                ];
                if (!empty($validated['password'])) {
                    $userData['password'] = Hash::make($validated['password']);
                }
                $coordinator->user->update($userData);

                // Update Staff Profile
                $coordinator->user->staffProfile()->update([
                    'phone' => $validated['phone'] ?? $coordinator->user->staffProfile->phone,
                    'address' => $validated['address'] ?? $coordinator->user->staffProfile->address,
                    'age' => $validated['age'] ?? $coordinator->user->staffProfile->age,
                    'gender' => $validated['gender'] ?? $coordinator->user->staffProfile->gender,
                    'city' => $validated['city'] ?? $coordinator->user->staffProfile->city,
                    'state' => $validated['state'] ?? $coordinator->user->staffProfile->state,
                    'pincode' => $validated['pincode'] ?? $coordinator->user->staffProfile->pincode,
                    'wage_type' => $validated['wage_type'] ?? $coordinator->user->staffProfile->wage_type,
                    'salary' => $validated['salary'] ?? $coordinator->user->staffProfile->salary,
                ]);

                // Update Coordinator
                $coordinator->update([
                    'unit_id' => $validated['unit_id'],
                ]);
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Coordinator updated successfully'
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
        $coordinator = Coordinator::findOrFail($id);
        try {
            DB::transaction(function () use ($coordinator) {
                $user = $coordinator->user;
                $coordinator->delete();
                if ($user) {
                    $user->staffProfile()->delete();
                    $user->delete();
                }
            });
            return response()->json([
                'status' => 'success',
                'message' => 'Coordinator deleted successfully'
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
