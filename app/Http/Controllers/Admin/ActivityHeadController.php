<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\ActivityHead;
use App\Models\User;
use App\Models\StaffProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ActivityHeadController extends Controller
{
    public function index()
    {
        $heads = ActivityHead::with(['user.staffProfile', 'activity'])->latest()->get();
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
            'activity_id' => 'required|exists:activities,id',
            'department' => 'nullable|string',
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
                    'role_id' => 7, // activity_head
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

                // 3. Create Activity Head
                $activityHead = ActivityHead::create([
                    'user_id' => $user->id,
                    'activity_id' => $validated['activity_id'],
                    'department' => $validated['department'] ?? 'Athletics',
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Activity Head created successfully',
                    'data' => $activityHead->load('user.staffProfile')
                ], 201);
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create Activity Head: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        $head = ActivityHead::with(['user.staffProfile', 'activity'])->findOrFail($id);
        return response()->json([
            'status' => 'success',
            'data' => $head
        ]);
    }

    public function update(Request $request, string $id)
    {
        $head = ActivityHead::findOrFail($id);
        
        $validated = $request->validate([
            'fullName' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $head->user_id,
            'password' => 'nullable|string|min:6',
            'activity_id' => 'required|exists:activities,id',
            'department' => 'nullable|string',
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

                // Update Activity Head
                $head->update([
                    'activity_id' => $validated['activity_id'],
                    'department' => $validated['department'] ?? $head->department,
                ]);
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Activity Head updated successfully'
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
        $head = ActivityHead::findOrFail($id);
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
                'message' => 'Activity Head deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete: ' . $e->getMessage()
            ], 500);
        }
    }
}
