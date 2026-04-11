<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coach;
use App\Models\User;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class CoachController extends Controller
{
    use ApiResponseTrait;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Coach::with(['user', 'school:id,name', 'activityHead.user', 'user.staffProfile', 'activity']);

        if ($request->has('school_id')) {
            $query->where('school_id', $request->school_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('user', function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $coaches = $query->latest()->paginate($request->get('per_page', 10));
        
        return response()->json($coaches);
    }

    /**
     * Store a newly created resource in storage.
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
            'age' => 'nullable|integer',
            'gender' => 'nullable|in:male,female,other',
            'address' => 'nullable|string',
            'state' => 'nullable|string',
            'city' => 'nullable|string',
            'pincode' => 'nullable|string',
            'wage_type' => 'nullable|in:Monthly,Weekly,Daily',
            'wage_amount' => 'nullable|numeric',
            
            // Coach fields
            'activity_head_id' => 'required|exists:activity_heads,id',
            'school_id' => 'required|exists:schools,id',
            'specialization' => 'nullable|string',
            'activity_id' => 'required|exists:activities,id',
        ]);

        return \DB::transaction(function() use ($validated) {
            // 1. Create User
            $user = \App\Models\User::create([
                'name' => $validated['fullName'],
                'email' => $validated['username'],
                'password' => \Hash::make($validated['password']),
                'role_id' => 8, // Coach
            ]);

            // 2. Create Staff Profile
            $user->staffProfile()->create([
                'phone' => $validated['phone'],
                'age' => $validated['age'],
                'gender' => $validated['gender'],
                'address' => $validated['address'],
                'state' => $validated['state'],
                'city' => $validated['city'],
                'pincode' => $validated['pincode'],
                'wage_type' => $validated['wage_type'],
                'salary' => $validated['wage_amount'],
                'status' => 'active',
            ]);

            // 3. Create Coach
            $coach = Coach::create([
                'user_id' => $user->id,
                'activity_head_id' => $validated['activity_head_id'],
                'school_id' => $validated['school_id'],
                'activity_id' => $validated['activity_id'],
                'specialization' => $validated['specialization'],
            ]);

            return $this->successResponse($coach->load(['user', 'school', 'activityHead', 'user.staffProfile', 'activity']), 'Coach successfully created!', 201);
        });
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $coach = Coach::with(['user.staffProfile', 'school', 'activityHead.user', 'activity'])->find($id);
        
        if (!$coach) {
            return $this->errorResponse('Coach not found', 404);
        }
        
        return $this->successResponse($coach, 'Coach details retrieved.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $coach = Coach::with('user.staffProfile')->find($id);
        
        if (!$coach) {
            return $this->errorResponse('Coach not found', 404);
        }

        $validated = $request->validate([
            'fullName' => 'sometimes|required|string|max:255',
            'username' => 'sometimes|required|string|unique:users,email,' . $coach->user_id,
            'password' => 'nullable|string|min:6',
            
            'phone' => 'nullable|string',
            'age' => 'nullable|integer',
            'gender' => 'nullable|in:male,female,other',
            'address' => 'nullable|string',
            'state' => 'nullable|string',
            'city' => 'nullable|string',
            'pincode' => 'nullable|string',
            'wage_type' => 'nullable|in:Monthly,Weekly,Daily',
            'wage_amount' => 'nullable|numeric',
            
            'activity_head_id' => 'sometimes|required|exists:activity_heads,id',
            'school_id' => 'sometimes|required|exists:schools,id',
            'specialization' => 'nullable|string',
            'activity_id' => 'sometimes|required|exists:activities,id',
        ]);

        return \DB::transaction(function() use ($validated, $coach) {
            // 1. Update User
            $userData = [];
            if (isset($validated['fullName'])) $userData['name'] = $validated['fullName'];
            if (isset($validated['username'])) $userData['email'] = $validated['username'];
            if (!empty($validated['password'])) $userData['password'] = \Hash::make($validated['password']);
            
            if (!empty($userData)) {
                $coach->user->update($userData);
            }

            // 2. Update Staff Profile
            $profileData = [
                'phone' => $validated['phone'] ?? $coach->user->staffProfile->phone,
                'age' => $validated['age'] ?? $coach->user->staffProfile->age,
                'gender' => $validated['gender'] ?? $coach->user->staffProfile->gender,
                'address' => $validated['address'] ?? $coach->user->staffProfile->address,
                'state' => $validated['state'] ?? $coach->user->staffProfile->state,
                'city' => $validated['city'] ?? $coach->user->staffProfile->city,
                'pincode' => $validated['pincode'] ?? $coach->user->staffProfile->pincode,
                'wage_type' => $validated['wage_type'] ?? $coach->user->staffProfile->wage_type,
                'salary' => $validated['wage_amount'] ?? $coach->user->staffProfile->salary,
            ];
            $coach->user->staffProfile->update(array_filter($profileData, fn($v) => !is_null($v)));

            // 3. Update Coach
            $coachData = [
                'activity_head_id' => $validated['activity_head_id'] ?? $coach->activity_head_id,
                'school_id' => $validated['school_id'] ?? $coach->school_id,
                'activity_id' => $validated['activity_id'] ?? $coach->activity_id,
                'specialization' => $validated['specialization'] ?? $coach->specialization,
            ];
            $coach->update(array_filter($coachData, fn($v) => !is_null($v)));

            return $this->successResponse($coach->load(['user', 'school', 'activityHead', 'user.staffProfile', 'activity']), 'Coach successfully updated!');
        });
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $coach = Coach::find($id);
        
        if (!$coach) {
            return $this->errorResponse('Coach not found', 404);
        }

        $coach->delete();

        return $this->successResponse(null, 'Coach successfully deleted!');
    }
}
