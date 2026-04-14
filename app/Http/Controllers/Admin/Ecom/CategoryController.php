<?php

namespace App\Http\Controllers\Admin\Ecom;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\UniqueSlug;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CategoryController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request)
    {
        $query = Category::query()
            ->with(['brand:id,name', 'image:id,file_path'])
            ->orderBy('name');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', '%'.$s.'%')
                    ->orWhere('slug', 'like', '%'.$s.'%');
            });
        }

        if ($request->boolean('without_brand')) {
            $query->whereNull('brand_id');
        } elseif ($request->filled('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }

        if ($request->filled('parent_id')) {
            $query->where('parent_id', $request->parent_id);
        } elseif ($request->boolean('roots_only')) {
            $query->whereNull('parent_id');
        }

        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $paginator = $query->paginate($request->integer('per_page', 15));

        $parentIdsWithChildren = Category::query()
            ->whereNotNull('parent_id')
            ->distinct()
            ->pluck('parent_id')
            ->flip()
            ->all();

        $maps = $this->breadcrumbMapsForCategories(collect($paginator->items()));

        $items = collect($paginator->items())->map(function (Category $c) use ($parentIdsWithChildren, $maps) {
            $key = $c->brand_id === null ? '_null_' : $c->brand_id;
            $byId = $maps[$key] ?? collect();

            return $this->serializeCategoryRow($c, $parentIdsWithChildren, $byId);
        })->values()->all();

        $paginator->setCollection(collect($items));

        return response()->json($paginator);
    }

    public function tree(Request $request)
    {
        $query = Category::query()
            ->with(['childrenRecursive', 'brand:id,name', 'image:id,file_path'])
            ->whereNull('parent_id')
            ->orderBy('name');

        if ($request->boolean('without_brand')) {
            $query->whereNull('brand_id');
        } elseif ($request->filled('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }

        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', '%'.$s.'%')
                    ->orWhere('slug', 'like', '%'.$s.'%');
            });
        }

        $roots = $query->get();

        return $this->successResponse($roots->map(fn (Category $c) => $this->serializeTreeNode($c))->values()->all());
    }

    public function options(Request $request)
    {
        $request->validate([
            'search' => 'nullable|string|max:255',
            'exclude_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
        ]);

        $brandFilter = $request->filled('brand_id') ? (int) $request->brand_id : null;

        $q = Category::query()
            ->when(
                $brandFilter === null,
                fn ($qq) => $qq->whereNull('brand_id'),
                fn ($qq) => $qq->where('brand_id', $brandFilter)
            )
            ->orderBy('name');

        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(function ($qq) use ($s) {
                $qq->where('name', 'like', '%'.$s.'%')
                    ->orWhere('slug', 'like', '%'.$s.'%');
            });
        }

        $exclude = [];
        if ($request->filled('exclude_id')) {
            $exclude = $this->descendantCategoryIds((int) $request->exclude_id);
            $exclude[] = (int) $request->exclude_id;
        }

        if ($exclude !== []) {
            $q->whereNotIn('id', $exclude);
        }

        $rows = $q->get(['id', 'name', 'parent_id', 'slug']);

        $parentIdsWithChildren = Category::query()
            ->whereNotNull('parent_id')
            ->distinct()
            ->pluck('parent_id')
            ->flip()
            ->all();

        $fullById = Category::query()
            ->when(
                $brandFilter === null,
                fn ($qq) => $qq->whereNull('brand_id'),
                fn ($qq) => $qq->where('brand_id', $brandFilter)
            )
            ->get(['id', 'name', 'parent_id'])
            ->keyBy('id');

        $data = $rows->map(function (Category $c) use ($fullById, $parentIdsWithChildren) {
            $path = $this->breadcrumbForCategory($c, $fullById);

            return [
                'id' => $c->id,
                'name' => $c->name,
                'parent_id' => $c->parent_id,
                'slug' => $c->slug,
                'is_parent' => isset($parentIdsWithChildren[$c->id]),
                'breadcrumb' => $path,
            ];
        })->values();

        return $this->successResponse($data);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'brand_id' => 'nullable|exists:brands,id',
            'parent_id' => 'nullable|exists:categories,id',
            'is_active' => 'sometimes|boolean',
            'image_media_id' => 'nullable|uuid|exists:media_files,id',
        ]);

        $brandId = array_key_exists('brand_id', $validated) ? $validated['brand_id'] : null;

        if (! empty($validated['parent_id'])) {
            $parent = Category::findOrFail($validated['parent_id']);
            if (! $this->brandsMatch($parent->brand_id, $brandId)) {
                return $this->errorResponse('Parent category must belong to the same brand (or both unbranded).', 422);
            }
        }

        $slug = UniqueSlug::for('categories', $validated['name']);

        $category = Category::create([
            'brand_id' => $brandId,
            'name' => $validated['name'],
            'slug' => $slug,
            'parent_id' => $validated['parent_id'] ?? null,
            'is_active' => array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : true,
            'image_media_id' => $validated['image_media_id'] ?? null,
        ]);

        $category->load(['brand:id,name', 'image:id,file_path']);
        $byId = $this->categoryBreadcrumbMapForBrand($category->brand_id);

        return $this->successResponse(
            $this->serializeCategoryRow($category, $this->parentIdLookup(), $byId),
            'Category created.',
            201
        );
    }

    public function update(Request $request, Category $category)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'brand_id' => 'sometimes|nullable|exists:brands,id',
            'parent_id' => 'nullable|exists:categories,id',
            'is_active' => 'sometimes|boolean',
            'image_media_id' => 'nullable|uuid|exists:media_files,id',
        ]);

        $brandId = array_key_exists('brand_id', $validated)
            ? $validated['brand_id']
            : $category->brand_id;

        if (array_key_exists('parent_id', $validated)) {
            $pid = $validated['parent_id'];
            if ($pid !== null) {
                if ((int) $pid === (int) $category->id) {
                    return $this->errorResponse('A category cannot be its own parent.', 422);
                }
                if (in_array((int) $pid, $this->descendantCategoryIds($category->id), true)) {
                    return $this->errorResponse('Invalid parent: cannot nest under a descendant.', 422);
                }
                $parent = Category::findOrFail($pid);
                if (! $this->brandsMatch($parent->brand_id, $brandId)) {
                    return $this->errorResponse('Parent category must belong to the same brand (or both unbranded).', 422);
                }
            }
        }

        if (isset($validated['name'])) {
            $category->slug = UniqueSlug::for('categories', $validated['name'], $category->id);
        }

        $category->fill([
            'name' => $validated['name'] ?? $category->name,
            'brand_id' => $brandId,
            'parent_id' => array_key_exists('parent_id', $validated)
                ? $validated['parent_id']
                : $category->parent_id,
            'is_active' => array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : $category->is_active,
            'image_media_id' => array_key_exists('image_media_id', $validated)
                ? $validated['image_media_id']
                : $category->image_media_id,
        ]);

        $category->save();
        $category->load(['brand:id,name', 'image:id,file_path']);
        $byId = $this->categoryBreadcrumbMapForBrand($category->brand_id);

        return $this->successResponse(
            $this->serializeCategoryRow($category, $this->parentIdLookup(), $byId),
            'Category updated.'
        );
    }

    public function destroy(Category $category)
    {
        $category->delete();

        return $this->successResponse(null, 'Category deleted.');
    }

    private function parentIdLookup(): array
    {
        return Category::query()
            ->whereNotNull('parent_id')
            ->distinct()
            ->pluck('parent_id')
            ->flip()
            ->all();
    }

    /**
     * @param  Collection<int, Category>  $items
     * @return array<string, Collection<int, Category>>
     */
    private function breadcrumbMapsForCategories(Collection $items): array
    {
        $maps = [];
        foreach ($items->pluck('brand_id')->unique() as $bid) {
            $key = $bid === null ? '_null_' : $bid;
            $maps[$key] = $this->categoryBreadcrumbMapForBrand($bid);
        }

        return $maps;
    }

    private function categoryBreadcrumbMapForBrand(mixed $brandId)
    {
        return Category::query()
            ->when(
                $brandId === null || $brandId === '',
                fn ($q) => $q->whereNull('brand_id'),
                fn ($q) => $q->where('brand_id', (int) $brandId)
            )
            ->get(['id', 'name', 'parent_id'])
            ->keyBy('id');
    }

    private function brandsMatch(mixed $a, mixed $b): bool
    {
        $ai = $a === null ? null : (int) $a;
        $bi = $b === null ? null : (int) $b;

        if ($ai === null && $bi === null) {
            return true;
        }

        if ($ai === null || $bi === null) {
            return false;
        }

        return $ai === $bi;
    }

    /**
     * @param  array<int, mixed>  $parentIdsWithChildren
     * @param  Collection<int, Category>  $byId
     */
    private function serializeCategoryRow(Category $c, array $parentIdsWithChildren, $byId): array
    {
        return [
            'id' => $c->id,
            'uuid' => $c->uuid,
            'name' => $c->name,
            'slug' => $c->slug,
            'brand' => $c->brand,
            'brand_id' => $c->brand_id,
            'is_active' => $c->is_active,
            'status_label' => $c->is_active ? 'Active' : 'Inactive',
            'hierarchy' => $this->breadcrumbForCategory($c, $byId),
            'image_url' => $c->image?->file_url,
            'image_media_id' => $c->image_media_id,
            'is_parent' => isset($parentIdsWithChildren[$c->id]),
            'parent_id' => $c->parent_id,
        ];
    }

    private function serializeTreeNode(Category $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'slug' => $c->slug,
            'is_active' => $c->is_active,
            'status_label' => $c->is_active ? 'Active' : 'Inactive',
            'brand' => $c->brand,
            'image_url' => $c->image?->file_url,
            'children' => $c->childrenRecursive
                ->map(fn (Category $ch) => $this->serializeTreeNode($ch))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, Category>  $byId
     */
    private function breadcrumbForCategory(Category $c, $byId): string
    {
        $parts = [];
        $current = $c;
        $guard = 0;

        while ($current && $guard++ < 64) {
            array_unshift($parts, $current->name);
            if (! $current->parent_id) {
                break;
            }
            $current = $byId->get($current->parent_id);
        }

        return implode(' > ', $parts);
    }

    /**
     * @return array<int, int>
     */
    private function descendantCategoryIds(int $id): array
    {
        $ids = [];
        $children = Category::query()->where('parent_id', $id)->pluck('id');
        foreach ($children as $cid) {
            $ids[] = (int) $cid;
            $ids = array_merge($ids, $this->descendantCategoryIds((int) $cid));
        }

        return $ids;
    }
}
