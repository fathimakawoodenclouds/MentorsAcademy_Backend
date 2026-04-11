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
        $query = Unit::with(['unitHead:id,name']);

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
            'unit_head_id' => 'nullable|exists:users,id',
            'location' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'pin_code' => 'nullable|string|max:20',
            'status' => 'nullable|string|in:ACTIVE,INACTIVE,PENDING',
        ]);

        $unit = Unit::create($validated);

        return $this->successResponse($unit->load('unitHead'), 'Unit successfully created!', 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $unit = Unit::with(['unitHead', 'schools'])
                    ->withCount(['schools', 'coaches'])
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
            'unit_head_id' => 'nullable|exists:users,id',
            'location' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'pin_code' => 'nullable|string|max:20',
            'status' => 'nullable|string|in:ACTIVE,INACTIVE,PENDING',
        ]);

        $unit->update($validated);

        return $this->successResponse($unit->load('unitHead'), 'Unit successfully updated!');
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
}
