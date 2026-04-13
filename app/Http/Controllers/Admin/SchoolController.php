<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolActivity;
use App\Models\Unit;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class SchoolController extends Controller
{
    use ApiResponseTrait;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = School::with(['unit', 'coordinator.user']);

        if ($request->filled('unit_id')) {
            $query->where('unit_id', $request->unit_id);
        }

        if ($request->filled('coordinator_id')) {
            $query->where('coordinator_id', $request->coordinator_id);
        }

        if ($request->filled('location')) {
            $query->where('location', 'like', "%{$request->location}%");
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $schools = $query->latest()->paginate($request->input('per_page', 10));

        return response()->json($schools);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'unit_id' => 'required|exists:units,id',
            'coordinator_id' => 'required|exists:coordinators,id',
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'location' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'pincode' => 'nullable|string|max:10',
            'address' => 'nullable|string',
            'academic_year' => 'nullable|string|max:20',
            'status' => 'nullable|string|in:ACTIVE,INACTIVE',
        ]);

        $school = School::create($validated);

        // Save school activities if provided
        if ($request->has('activities')) {
            foreach ($request->activities as $activity) {
                $school->schoolActivities()->create([
                    'activity_id' => $activity['activity_id'],
                    'amount_type' => $activity['amount_type'] ?? 'per_head',
                    'amount_per_unit' => $activity['amount_per_unit'] ?? 0,
                    'quantity' => $activity['quantity'] ?? null,
                    'total_amount' => $activity['total_amount'] ?? 0,
                    'start_date' => $activity['start_date'] ?? null,
                    'end_date' => $activity['end_date'] ?? null,
                    'time_slots' => $activity['time_slots'] ?? null,
                    'payment_status' => 'pending',
                ]);
            }
        }

        return $this->successResponse(
            $school->load(['unit', 'coordinator.user', 'schoolActivities.activity']),
            'School successfully created!',
            201
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $school = School::with([
                'unit',
                'coordinator.user',
                'coaches.user',
                'schoolActivities.activity.activityHead.user'
            ])
            ->withCount('coaches')
            ->find($id);

        if (!$school) {
            return $this->errorResponse('School not found', 404);
        }

        return $this->successResponse($school, 'School details retrieved.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $school = School::find($id);

        if (!$school) {
            return $this->errorResponse('School not found', 404);
        }

        $validated = $request->validate([
            'unit_id' => 'sometimes|required|exists:units,id',
            'coordinator_id' => 'sometimes|required|exists:coordinators,id',
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'location' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'pincode' => 'nullable|string|max:10',
            'address' => 'nullable|string',
            'academic_year' => 'nullable|string|max:20',
            'status' => 'nullable|string|in:ACTIVE,INACTIVE',
        ]);

        $school->update($validated);

        // Sync school activities if provided
        if ($request->has('activities')) {
            $school->schoolActivities()->delete();
            foreach ($request->activities as $activity) {
                $school->schoolActivities()->create([
                    'activity_id' => $activity['activity_id'],
                    'amount_type' => $activity['amount_type'] ?? 'per_head',
                    'amount_per_unit' => $activity['amount_per_unit'] ?? 0,
                    'quantity' => $activity['quantity'] ?? null,
                    'total_amount' => $activity['total_amount'] ?? 0,
                    'start_date' => $activity['start_date'] ?? null,
                    'end_date' => $activity['end_date'] ?? null,
                    'time_slots' => $activity['time_slots'] ?? null,
                    'payment_status' => $activity['payment_status'] ?? 'pending',
                ]);
            }
        }

        return $this->successResponse(
            $school->load(['unit', 'coordinator.user', 'schoolActivities.activity']),
            'School successfully updated!'
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $school = School::find($id);

        if (!$school) {
            return $this->errorResponse('School not found', 404);
        }

        $school->delete();

        return $this->successResponse(null, 'School successfully deleted!');
    }
}
