<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdminRequest;
use App\Models\User;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Traits\ApiResponseTrait;
use Illuminate\Support\Facades\Hash;

class AdminManagerController extends Controller
{
    use ApiResponseTrait;

    /**
     * Get list of all Admins with pagination and search
     */
    public function index(\Illuminate\Http\Request $request)
    {
        // Exclude the Super Admin
        $query = User::whereHas('role', function($q) {
            $q->where('name', '!=', 'super_admin');
        })->with(['role', 'staffProfile']);

        // Filter by text search
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }
        
        // Filter by Role Name (Dropdown UI)
        if ($request->has('role') && $request->role !== 'All Roles') {
            $roleSlug = $request->role; // Now driven directly from DB roles
            $query->whereHas('role', function($q) use ($roleSlug) {
                $q->where('name', $roleSlug);
            });
        }
        
        // Filter by Status (Dropdown UI)
        if ($request->has('status') && $request->status !== 'All') {
            $statusInput = strtolower($request->status);
            $query->whereHas('staffProfile', function($q) use ($statusInput) {
                $q->where('status', $statusInput);
            });
        }

        // Return standard Laravel pagination format natively
        $admins = $query->latest()->paginate($request->input('per_page', 10));
        
        return response()->json($admins); // Return raw paginator object
    }

    /**
     * Create a new Admin with empty staff profile
     */
    public function store(StoreAdminRequest $request)
    {
        $adminRole = Role::where('name', 'admin')->first();
        
        $admin = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role_id' => $adminRole->id,
        ]);

        // Auto-create an empty structured staff profile for the new Admin
        StaffProfile::create([
            'user_id' => $admin->id,
            'status' => 'active',
        ]);

        return $this->successResponse($admin->load('staffProfile'), 'Admin successfully created!', 201);
    }

    /**
     * Get details of a specific Admin
     */
    public function show($id)
    {
        $adminRole = Role::where('name', 'admin')->first();
        $admin = User::where('id', $id)->where('role_id', $adminRole->id)->with('staffProfile')->first();
        
        if (!$admin) return $this->errorResponse('Admin not found', 404);
        
        return $this->successResponse($admin, 'Admin details retrieved.');
    }

    /**
     * Update an Admin's details
     */
    public function update(\Illuminate\Http\Request $request, $id)
    {
        $adminRole = Role::where('name', 'admin')->first();
        $admin = User::where('id', $id)->where('role_id', $adminRole->id)->first();
        
        if (!$admin) return $this->errorResponse('Admin not found', 404);

        $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', \Illuminate\Validation\Rule::unique('users')->whereNull('deleted_at')->ignore($id)],
            'password' => ['nullable', 'string', 'min:8']
        ]);

        $admin->name = $request->name ?? $admin->name;
        $admin->email = $request->email ?? $admin->email;
        if ($request->password) {
            $admin->password = Hash::make($request->password);
        }
        $admin->save();

        return $this->successResponse($admin->load('staffProfile'), 'Admin successfully updated!');
    }

    /**
     * Soft Delete an Admin
     */
    public function destroy($id)
    {
        $adminRole = Role::where('name', 'admin')->first();
        $admin = User::where('id', $id)->where('role_id', $adminRole->id)->first();
        
        if (!$admin) return $this->errorResponse('Admin not found', 404);

        $admin->delete(); // Triggers soft delete

        return $this->successResponse(null, 'Admin successfully deleted!');
    }

    /**
     * Get all attachable roles for dynamic UI dropdowns
     */
    public function getRoles()
    {
        // Return roles excluding super_admin formatted for UI if needed
        $roles = Role::where('name', '!=', 'super_admin')->get(['id', 'name']);
        return response()->json($roles);
    }
}
