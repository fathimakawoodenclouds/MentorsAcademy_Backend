<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalesExecutive;
use App\Models\User;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SalesExecutiveController extends Controller
{
    use ApiResponseTrait;

    /**
     * Display a listing of sales executives.
     */
    public function index(Request $request)
    {
        $query = SalesExecutive::with(['user.staffProfile']);

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->whereHas('user', function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            })->orWhere('staff_id', 'like', "%{$search}%");
        }

        if ($request->has('status') && $request->status !== 'All') {
            $status = $request->status;
            $query->whereHas('user.staffProfile', function($q) use ($status) {
                $q->where('status', strtolower($status));
            });
        }

        $executives = $query->latest()->paginate($request->input('per_page', 10));

        return response()->json($executives);
    }

    /**
     * Store a newly created sales executive.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            // User fields
            'fullName' => 'required|string|max:255',
            'username' => 'required|string|unique:users,email',
            'password' => 'required|string|min:6',

            // Staff Profile fields
            'phone' => 'nullable|string',
            'dob' => 'nullable|date',
            'gender' => 'nullable|in:male,female,other',
            'addressLine1' => 'nullable|string',
            'addressLine2' => 'nullable|string',
            'landmark' => 'nullable|string',
            'state' => 'nullable|string',
            'city' => 'nullable|string',
            'pincode' => 'nullable|string',
            'wage_type' => 'nullable|in:Monthly,Weekly,Daily',
            'wageAmount' => 'nullable|numeric',

            // Sales Executive fields
            'daAllowance' => 'nullable|numeric',
            'taAllowance' => 'nullable|numeric',
        ]);

        return DB::transaction(function() use ($validated) {
            // 1. Create User
            $user = User::create([
                'name' => $validated['fullName'],
                'email' => $validated['username'],
                'password' => Hash::make($validated['password']),
                'role_id' => 9, // sales_executive
            ]);

            // 2. Create Staff Profile
            $address = trim(implode(', ', array_filter([
                $validated['addressLine1'] ?? null,
                $validated['addressLine2'] ?? null,
                $validated['landmark'] ?? null,
            ])));

            $user->staffProfile()->create([
                'phone' => $validated['phone'] ?? null,
                'age' => $validated['age'] ?? null,
                'gender' => $validated['gender'] ?? null,
                'address' => $address ?: null,
                'state' => $validated['state'] ?? null,
                'city' => $validated['city'] ?? null,
                'pincode' => $validated['pincode'] ?? null,
                'wage_type' => $validated['wage_type'] ?? 'Monthly',
                'salary' => $validated['wageAmount'] ?? 0,
                'status' => 'active',
            ]);

            // 3. Create Sales Executive
            $exec = SalesExecutive::create([
                'user_id' => $user->id,
                'da_allowance' => $validated['daAllowance'] ?? 0,
                'ta_allowance' => $validated['taAllowance'] ?? 0,
            ]);

            return $this->successResponse(
                $exec->load(['user.staffProfile']),
                'Sales Executive created successfully!',
                201
            );
        });
    }

    /**
     * Display the specified sales executive.
     */
    public function show(string $id)
    {
        $exec = SalesExecutive::with(['user.staffProfile'])->find($id);

        if (!$exec) {
            return $this->errorResponse('Sales Executive not found', 404);
        }

        return $this->successResponse($exec, 'Sales Executive details retrieved.');
    }

    /**
     * Update the specified sales executive.
     */
    public function update(Request $request, string $id)
    {
        $exec = SalesExecutive::with('user.staffProfile')->find($id);

        if (!$exec) {
            return $this->errorResponse('Sales Executive not found', 404);
        }

        $validated = $request->validate([
            'fullName' => 'sometimes|required|string|max:255',
            'username' => 'sometimes|required|string|unique:users,email,' . $exec->user_id,
            'password' => 'nullable|string|min:6',

            'phone' => 'nullable|string',
            'dob' => 'nullable|date',
            'gender' => 'nullable|in:male,female,other',
            'addressLine1' => 'nullable|string',
            'addressLine2' => 'nullable|string',
            'landmark' => 'nullable|string',
            'state' => 'nullable|string',
            'city' => 'nullable|string',
            'pincode' => 'nullable|string',
            'wage_type' => 'nullable|in:Monthly,Weekly,Daily',
            'wageAmount' => 'nullable|numeric',

            'daAllowance' => 'nullable|numeric',
            'taAllowance' => 'nullable|numeric',
        ]);

        return DB::transaction(function() use ($validated, $exec) {
            // 1. Update User
            $userData = [];
            if (isset($validated['fullName'])) $userData['name'] = $validated['fullName'];
            if (isset($validated['username'])) $userData['email'] = $validated['username'];
            if (!empty($validated['password'])) $userData['password'] = Hash::make($validated['password']);

            if (!empty($userData)) {
                $exec->user->update($userData);
            }

            // 2. Update Staff Profile
            $profileData = [];
            if (isset($validated['phone'])) $profileData['phone'] = $validated['phone'];
            if (isset($validated['age'])) $profileData['age'] = $validated['age'];
            if (isset($validated['gender'])) $profileData['gender'] = $validated['gender'];
            if (isset($validated['state'])) $profileData['state'] = $validated['state'];
            if (isset($validated['city'])) $profileData['city'] = $validated['city'];
            if (isset($validated['pincode'])) $profileData['pincode'] = $validated['pincode'];
            if (isset($validated['wage_type'])) $profileData['wage_type'] = $validated['wage_type'];
            if (isset($validated['wageAmount'])) $profileData['salary'] = $validated['wageAmount'];

            if (isset($validated['addressLine1']) || isset($validated['addressLine2']) || isset($validated['landmark'])) {
                $address = trim(implode(', ', array_filter([
                    $validated['addressLine1'] ?? null,
                    $validated['addressLine2'] ?? null,
                    $validated['landmark'] ?? null,
                ])));
                $profileData['address'] = $address ?: $exec->user->staffProfile->address;
            }

            if (!empty($profileData) && $exec->user->staffProfile) {
                $exec->user->staffProfile->update($profileData);
            }

            // 3. Update Sales Executive
            $execData = [];
            if (isset($validated['daAllowance'])) $execData['da_allowance'] = $validated['daAllowance'];
            if (isset($validated['taAllowance'])) $execData['ta_allowance'] = $validated['taAllowance'];

            if (!empty($execData)) {
                $exec->update($execData);
            }

            return $this->successResponse(
                $exec->fresh()->load(['user.staffProfile']),
                'Sales Executive updated successfully!'
            );
        });
    }

    /**
     * Remove the specified sales executive.
     */
    public function destroy(string $id)
    {
        $exec = SalesExecutive::find($id);

        if (!$exec) {
            return $this->errorResponse('Sales Executive not found', 404);
        }

        $exec->delete();

        return $this->successResponse(null, 'Sales Executive deleted successfully!');
    }
}
