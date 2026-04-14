<?php

namespace App\Http\Controllers\Admin\Ecom;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request)
    {
        $query = Brand::query()->orderBy('name');

        if ($request->boolean('active_only')) {
            $query->active();
        }

        $brands = $query->get(['id', 'uuid', 'name', 'is_active']);

        return $this->successResponse($brands);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);

        $brand = Brand::create([
            'name' => $validated['name'],
            'is_active' => array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : true,
        ]);

        return $this->successResponse($brand, 'Brand created.', 201);
    }
}
