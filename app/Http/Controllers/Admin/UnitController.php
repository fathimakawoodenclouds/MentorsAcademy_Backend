<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\User;
use App\Models\Role;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    use ApiResponseTrait;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Unit::with(['unitHead.user']);

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->has('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }

        $units = $query->withCount('schools')->latest()->paginate($request->get('per_page', 10));
        
        return response()->json($units);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'pin_code' => 'nullable|string|max:20',
            'status' => 'nullable|string|in:ACTIVE,INACTIVE,PENDING',
            'unit_head_id' => 'nullable|exists:users,id',
        ]);

        $unitHeadUserId = $validated['unit_head_id'] ?? null;
        unset($validated['unit_head_id']);

        $unit = Unit::create($validated);

        if ($unitHeadUserId) {
            $unit->unitHead()->create([
                'user_id' => $unitHeadUserId,
            ]);
        }

        return $this->successResponse($unit->load('unitHead.user'), 'Unit successfully created!', 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $unit = Unit::with(['unitHead.user', 'schools'])
                    ->withCount(['schools', 'coaches', 'coordinators'])
                    ->find($id);
        
        if (!$unit) {
            return $this->errorResponse('Unit not found', 404);
        }
        
        return $this->successResponse($unit, 'Unit details retrieved.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $unit = Unit::find($id);
        
        if (!$unit) {
            return $this->errorResponse('Unit not found', 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'location' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'pin_code' => 'nullable|string|max:20',
            'status' => 'nullable|string|in:ACTIVE,INACTIVE,PENDING',
            'unit_head_id' => 'nullable|exists:users,id',
        ]);

        // Separate unit_head_id from unit fields
        $unitHeadUserId = $validated['unit_head_id'] ?? null;
        unset($validated['unit_head_id']);

        $unit->update($validated);

        // Handle Unit Head assignment
        if ($unitHeadUserId) {
            $unit->unitHead()->updateOrCreate(
                ['unit_id' => $unit->id],
                ['user_id' => $unitHeadUserId]
            );
        }

        return $this->successResponse($unit->load('unitHead.user'), 'Unit successfully updated!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $unit = Unit::find($id);
        
        if (!$unit) {
            return $this->errorResponse('Unit not found', 404);
        }

        $unit->delete();

        return $this->successResponse(null, 'Unit successfully deleted!');
    }

    /**
     * Get all users who can be assigned as Unit Heads.
     */
    public function getPotentialUnitHeads()
    {
        $role = Role::where('name', 'unit_head')->first();
        
        if (!$role) {
            return response()->json([]);
        }

        $users = User::where('role_id', $role->id)->get(['id', 'name']);
        
        return response()->json($users);
    }

    /**
     * Lightweight unit options list (for selectors).
     */
    public function options()
    {
        $units = Unit::query()
            ->orderBy('name')
            ->get(['id', 'unit_id', 'name']);

        return $this->successResponse($units, 'Unit options loaded.');
    }
}
