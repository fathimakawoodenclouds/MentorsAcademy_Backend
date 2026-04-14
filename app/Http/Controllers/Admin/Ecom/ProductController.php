<?php

namespace App\Http\Controllers\Admin\Ecom;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantValue;
use App\Support\UniqueSlug;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    use ApiResponseTrait;

    private const PRODUCT_RELATIONS = [
        'brand.logoMedia',
        'category',
        'thumbnail',
        'options.values',
        'variants.variantValues.optionValue',
        'variants.image',
        'images.media',
    ];

    public function index(Request $request)
    {
        $query = Product::query()
            ->with([
                'brand:id,name,uuid,logo_media_id',
                'brand.logoMedia:id,file_name,file_path',
                'category:id,name,parent_id,uuid,slug',
                'thumbnail:id,file_name,file_path',
                'variants',
            ])
            ->withCount('variants')
            ->orderByDesc('id');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', '%'.$s.'%')
                    ->orWhere('slug', 'like', '%'.$s.'%')
                    ->orWhere('description', 'like', '%'.$s.'%');
            });
        }

        if ($request->boolean('without_brand')) {
            $query->whereNull('brand_id');
        } elseif ($request->filled('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $paginator = $query->paginate($request->integer('per_page', 15));
        $items = collect($paginator->items())->map(fn (Product $p) => $this->serializeListRow($p))->values()->all();
        $paginator->setCollection(collect($items));

        return response()->json($paginator);
    }

    public function show(Product $product)
    {
        $product->load(self::PRODUCT_RELATIONS);

        return $this->successResponse(
            $this->formatProduct($product),
            'Product retrieved successfully'
        );
    }

    public function store(Request $request)
    {
        $validated = $this->validateStore($request);

        $product = DB::transaction(function () use ($validated) {
            $slug = UniqueSlug::for('products', $validated['name']);

            $hasVariants = (bool) ($validated['has_variants'] ?? false)
                || (isset($validated['options']) && count($validated['options']) > 0)
                || (isset($validated['variants']) && count($validated['variants']) > 1);

            $product = Product::create([
                'brand_id' => $validated['brand_id'] ?? null,
                'category_id' => $validated['category_id'] ?? null,
                'name' => $validated['name'],
                'slug' => $slug,
                'description' => $validated['description'] ?? null,
                'hsn_code' => $validated['hsn_code'] ?? null,
                'warranty' => $validated['warranty'] ?? null,
                'return_policy_days' => $validated['return_policy_days'] ?? null,
                'thumbnail_media_id' => $validated['thumbnail_media_id'] ?? null,
                'is_featured' => (bool) ($validated['is_featured'] ?? false),
                'has_variants' => $hasVariants,
                'tags' => $validated['tags'] ?? null,
                'attributes' => $validated['attributes'] ?? null,
                'status' => $validated['status'] ?? 'active',
            ]);

            $this->syncOptionsFromPayload($product, $validated['options'] ?? []);
            $this->syncVariantsFromPayload($product, $validated['variants'] ?? [], false);
            $this->syncImagesFromPayload($product, $validated['images'] ?? []);

            return $product->fresh(self::PRODUCT_RELATIONS);
        });

        return $this->successResponse(
            $this->formatProduct($product),
            'Product created successfully',
            201
        );
    }

    public function update(Request $request, Product $product)
    {
        $validated = $this->validateUpdate($request, $product);

        $product = DB::transaction(function () use ($product, $validated, $request) {
            if (isset($validated['name'])) {
                $product->slug = UniqueSlug::for('products', $validated['name'], $product->id);
            }

            $updates = [];
            foreach ([
                'brand_id', 'category_id', 'name', 'description', 'hsn_code', 'warranty',
                'return_policy_days', 'thumbnail_media_id', 'is_featured', 'tags', 'attributes', 'status',
            ] as $field) {
                if (array_key_exists($field, $validated)) {
                    $updates[$field] = $validated[$field];
                }
            }

            if ($request->has('has_variants')) {
                $updates['has_variants'] = (bool) $validated['has_variants'];
            } elseif ($request->has('options') || $request->has('variants')) {
                $hasVariants = (isset($validated['options']) && count($validated['options']) > 0)
                    || (isset($validated['variants']) && count($validated['variants']) > 1);
                $updates['has_variants'] = $hasVariants;
            }

            if ($updates !== []) {
                $product->fill($updates);
                $product->save();
            }

            if ($request->has('options')) {
                $this->syncOptionsUpdate($product, $validated['options'] ?? []);
            }

            if ($request->has('variants')) {
                $this->syncVariantsFromPayload($product, $validated['variants'] ?? [], true);
            }

            if ($request->has('images')) {
                $this->syncImagesFromPayload($product, $validated['images'] ?? []);
            }

            return $product->fresh(self::PRODUCT_RELATIONS);
        });

        return $this->successResponse(
            $this->formatProduct($product),
            'Product updated successfully'
        );
    }

    public function toggleFeatured(Request $request, Product $product)
    {
        $data = $request->validate([
            'is_featured' => 'required|boolean',
        ]);

        $product->is_featured = $data['is_featured'];
        $product->save();

        return $this->successResponse(
            ['is_featured' => $product->is_featured],
            'Featured updated.'
        );
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return $this->successResponse(null, 'Product deleted successfully');
    }

    private function validateStore(Request $request): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'brand_id' => 'nullable|exists:brands,id',
            'category_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
            'hsn_code' => 'nullable|string|max:255',
            'warranty' => 'nullable|string|max:255',
            'return_policy_days' => 'nullable|integer|min:0',
            'thumbnail_media_id' => 'nullable|uuid|exists:media_files,id',
            'status' => 'required|in:active,inactive,archived',
            'is_featured' => 'sometimes|boolean',
            'has_variants' => 'sometimes|boolean',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:255',
            'attributes' => 'nullable|array',
            'options' => 'nullable|array',
            'options.*.name' => 'required_with:options|string|max:255',
            'options.*.values' => 'required_with:options|array|min:1',
            'options.*.values.*' => 'nullable|string|max:255',
            'variants' => 'required|array|min:1',
            'variants.*.sku' => ['required', 'string', 'max:255', Rule::unique('product_variants', 'sku')],
            'variants.*.name' => 'nullable|string|max:255',
            'variants.*.barcode' => 'nullable|string|max:255',
            'variants.*.mrp' => 'required|numeric|min:0',
            'variants.*.selling_price' => 'required|numeric|min:0',
            'variants.*.cost_price' => 'nullable|numeric|min:0',
            'variants.*.stock_qty' => 'required|integer|min:0',
            'variants.*.min_order_qty' => 'nullable|integer|min:0',
            'variants.*.max_order_qty' => 'nullable|integer|min:0',
            'variants.*.weight' => 'nullable|numeric|min:0',
            'variants.*.length_cm' => 'nullable|numeric|min:0',
            'variants.*.width_cm' => 'nullable|numeric|min:0',
            'variants.*.height_cm' => 'nullable|numeric|min:0',
            'variants.*.tax_percentage' => 'nullable|numeric|min:0|max:100',
            'variants.*.tax_inclusive' => 'sometimes|boolean',
            'variants.*.image_media_id' => 'nullable|uuid|exists:media_files,id',
            'variants.*.is_default' => 'sometimes|boolean',
            'variants.*.status' => 'sometimes|in:active,inactive,archived',
            'variants.*.option_values' => 'nullable|array',
            'variants.*.option_values.*' => 'string|max:255',
            'images' => 'nullable|array',
            'images.*.media_id' => 'required|uuid|exists:media_files,id',
            'images.*.sort_order' => 'nullable|integer|min:0',
        ];

        $validated = $request->validate($rules);
        $this->assertCategoryBrandMatch($validated);

        return $validated;
    }

    private function validateUpdate(Request $request, Product $product): array
    {
        $rules = [
            'name' => 'sometimes|string|max:255',
            'brand_id' => 'nullable|exists:brands,id',
            'category_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
            'hsn_code' => 'nullable|string|max:255',
            'warranty' => 'nullable|string|max:255',
            'return_policy_days' => 'nullable|integer|min:0',
            'thumbnail_media_id' => 'nullable|uuid|exists:media_files,id',
            'status' => 'sometimes|in:active,inactive,archived',
            'is_featured' => 'sometimes|boolean',
            'has_variants' => 'sometimes|boolean',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:255',
            'attributes' => 'nullable|array',
            'options' => 'nullable|array',
            'options.*.uuid' => 'sometimes|uuid',
            'options.*.name' => 'required_with:options|string|max:255',
            'options.*.values' => 'required_with:options|array|min:1',
            'options.*.values.*' => 'nullable|string|max:255',
            'variants' => 'nullable|array|min:1',
            'images' => 'nullable|array',
            'images.*.media_id' => 'required|uuid|exists:media_files,id',
            'images.*.sort_order' => 'nullable|integer|min:0',
        ];

        foreach ($request->input('variants', []) as $i => $variant) {
            $rules["variants.$i.uuid"] = 'sometimes|nullable|uuid';
            $variantUuid = $variant['uuid'] ?? null;
            if ($variantUuid && Str::isUuid($variantUuid)) {
                $rules["variants.$i.sku"] = [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('product_variants', 'sku')->ignore($variantUuid, 'uuid'),
                ];
            } else {
                $rules["variants.$i.sku"] = [
                    'required',
                    'string',
                    'max:255',
                    function (string $attribute, mixed $value, \Closure $fail) use ($product) {
                        $exists = ProductVariant::withTrashed()
                            ->where('sku', $value)
                            ->where('product_id', '!=', $product->id)
                            ->exists();
                        if ($exists) {
                            $fail(__('The SKU is already used by another product.'));
                        }
                    },
                ];
            }
            $rules["variants.$i.name"] = 'nullable|string|max:255';
            $rules["variants.$i.barcode"] = 'nullable|string|max:255';
            $rules["variants.$i.mrp"] = 'sometimes|numeric|min:0';
            $rules["variants.$i.selling_price"] = 'sometimes|numeric|min:0';
            $rules["variants.$i.cost_price"] = 'nullable|numeric|min:0';
            $rules["variants.$i.stock_qty"] = 'sometimes|integer|min:0';
            $rules["variants.$i.min_order_qty"] = 'nullable|integer|min:0';
            $rules["variants.$i.max_order_qty"] = 'nullable|integer|min:0';
            $rules["variants.$i.weight"] = 'nullable|numeric|min:0';
            $rules["variants.$i.length_cm"] = 'nullable|numeric|min:0';
            $rules["variants.$i.width_cm"] = 'nullable|numeric|min:0';
            $rules["variants.$i.height_cm"] = 'nullable|numeric|min:0';
            $rules["variants.$i.tax_percentage"] = 'nullable|numeric|min:0|max:100';
            $rules["variants.$i.tax_inclusive"] = 'sometimes|boolean';
            $rules["variants.$i.image_media_id"] = 'nullable|uuid|exists:media_files,id';
            $rules["variants.$i.is_default"] = 'sometimes|boolean';
            $rules["variants.$i.status"] = 'sometimes|in:active,inactive,archived';
            $rules["variants.$i.option_values"] = 'nullable|array';
            $rules["variants.$i.option_values.*"] = 'string|max:255';
        }

        $validated = $request->validate($rules);
        $this->assertCategoryBrandMatch($validated, $product);

        return $validated;
    }

    private function assertCategoryBrandMatch(array $validated, ?Product $product = null): void
    {
        if (! array_key_exists('category_id', $validated)) {
            return;
        }

        $brandId = array_key_exists('brand_id', $validated)
            ? $validated['brand_id']
            : $product?->brand_id;

        if ($validated['category_id'] === null) {
            return;
        }

        $cat = Category::findOrFail($validated['category_id']);
        if (! $this->brandsMatch($cat->brand_id, $brandId)) {
            throw ValidationException::withMessages([
                'category_id' => ['Category must match product brand (or both unbranded).'],
            ]);
        }
    }

    private function syncOptionsFromPayload(Product $product, array $options): void
    {
        foreach ($options as $optData) {
            $option = ProductOption::create([
                'product_id' => $product->id,
                'name' => $optData['name'],
            ]);
            foreach ($optData['values'] as $val) {
                $valueStr = $this->normalizeOptionValueEntry($val);
                if ($valueStr === '') {
                    continue;
                }
                ProductOptionValue::create([
                    'product_option_id' => $option->id,
                    'value' => $valueStr,
                ]);
            }
        }
    }

    private function syncOptionsUpdate(Product $product, array $options): void
    {
        $incomingUuids = collect($options)
            ->pluck('uuid')
            ->filter(fn ($u) => is_string($u) && Str::isUuid($u))
            ->values()
            ->all();

        if ($incomingUuids !== []) {
            $product->options()->whereNotIn('uuid', $incomingUuids)->delete();
        }

        foreach ($options as $optData) {
            $uuid = isset($optData['uuid']) && Str::isUuid($optData['uuid']) ? $optData['uuid'] : null;
            if ($uuid) {
                $option = ProductOption::updateOrCreate(
                    ['uuid' => $uuid, 'product_id' => $product->id],
                    ['name' => $optData['name']]
                );
            } else {
                $option = ProductOption::create([
                    'product_id' => $product->id,
                    'name' => $optData['name'],
                ]);
            }

            $incomingValueUuids = collect($optData['values'])
                ->map(fn ($val) => is_array($val) && isset($val['uuid']) && Str::isUuid($val['uuid']) ? $val['uuid'] : null)
                ->filter()
                ->values()
                ->all();

            if ($incomingValueUuids === []) {
                $option->values()->delete();
            } else {
                $option->values()->whereNotIn('uuid', $incomingValueUuids)->delete();
            }

            foreach ($optData['values'] as $val) {
                $valueStr = $this->normalizeOptionValueEntry($val);
                if ($valueStr === '') {
                    continue;
                }
                $valUuid = is_array($val) && isset($val['uuid']) && Str::isUuid($val['uuid']) ? $val['uuid'] : null;
                if ($valUuid) {
                    ProductOptionValue::updateOrCreate(
                        ['uuid' => $valUuid, 'product_option_id' => $option->id],
                        ['value' => $valueStr]
                    );
                } else {
                    ProductOptionValue::create([
                        'product_option_id' => $option->id,
                        'value' => $valueStr,
                    ]);
                }
            }
        }
    }

    private function normalizeOptionValueEntry(mixed $val): string
    {
        if (is_array($val)) {
            return trim((string) ($val['value'] ?? ''));
        }

        return trim((string) $val);
    }

    private function syncVariantsFromPayload(Product $product, array $variants, bool $isUpdate): void
    {
        if ($variants === []) {
            return;
        }

        if ($isUpdate) {
            $incomingSkus = collect($variants)->pluck('sku')->filter()->values()->all();
            $incomingUuids = collect($variants)
                ->pluck('uuid')
                ->filter(fn ($u) => is_string($u) && Str::isUuid($u))
                ->values()
                ->all();

            $existingVariantsToKeep = $product->variants()
                ->where(function ($q) use ($incomingUuids, $incomingSkus) {
                    if ($incomingUuids !== [] && $incomingSkus !== []) {
                        $q->whereIn('uuid', $incomingUuids)->orWhereIn('sku', $incomingSkus);
                    } elseif ($incomingUuids !== []) {
                        $q->whereIn('uuid', $incomingUuids);
                    } elseif ($incomingSkus !== []) {
                        $q->whereIn('sku', $incomingSkus);
                    }
                })
                ->pluck('id')
                ->toArray();

            if ($existingVariantsToKeep !== []) {
                $product->variants()->whereNotIn('id', $existingVariantsToKeep)->delete();
            } else {
                $product->variants()->delete();
            }
        }

        foreach ($variants as $variantData) {
            $variant = $this->upsertOneVariant($product, $variantData, $isUpdate);
            $variant->variantValues()->delete();
            foreach ($variantData['option_values'] ?? [] as $val) {
                $optionValue = $this->findOptionValueForProduct($product, (string) $val);
                if ($optionValue) {
                    ProductVariantValue::create([
                        'variant_id' => $variant->id,
                        'option_value_id' => $optionValue->id,
                    ]);
                }
            }
        }

        $this->ensureSingleDefaultVariant($product);
    }

    private function upsertOneVariant(Product $product, array $variantData, bool $isUpdate): ProductVariant
    {
        $payload = [
            'sku' => $variantData['sku'],
            'name' => $variantData['name'] ?? null,
            'barcode' => $variantData['barcode'] ?? null,
            'mrp' => $variantData['mrp'] ?? 0,
            'selling_price' => $variantData['selling_price'] ?? 0,
            'cost_price' => $variantData['cost_price'] ?? null,
            'stock_qty' => $variantData['stock_qty'] ?? 0,
            'min_order_qty' => $variantData['min_order_qty'] ?? null,
            'max_order_qty' => $variantData['max_order_qty'] ?? null,
            'weight' => $variantData['weight'] ?? null,
            'length_cm' => $variantData['length_cm'] ?? null,
            'width_cm' => $variantData['width_cm'] ?? null,
            'height_cm' => $variantData['height_cm'] ?? null,
            'tax_percentage' => $variantData['tax_percentage'] ?? null,
            'tax_inclusive' => array_key_exists('tax_inclusive', $variantData) ? (bool) $variantData['tax_inclusive'] : true,
            'image_media_id' => $variantData['image_media_id'] ?? null,
            'is_default' => (bool) ($variantData['is_default'] ?? false),
            'status' => $variantData['status'] ?? 'active',
        ];

        if ($isUpdate) {
            $variantUuid = $variantData['uuid'] ?? null;
            $existing = null;
            if ($variantUuid && Str::isUuid($variantUuid)) {
                $existing = $product->variants()->where('uuid', $variantUuid)->first();
            }
            if (! $existing) {
                $existing = $product->variants()->where('sku', $variantData['sku'])->first();
            }
            if ($existing) {
                if (isset($variantData['sku']) && $variantData['sku'] !== $existing->sku) {
                    $taken = ProductVariant::withTrashed()
                        ->where('sku', $variantData['sku'])
                        ->where('id', '!=', $existing->id)
                        ->exists();
                    if ($taken) {
                        throw ValidationException::withMessages([
                            'variants' => ['The SKU has already been taken.'],
                        ]);
                    }
                }
                $existing->fill($payload);
                $existing->save();

                return $existing->fresh();
            }
        }

        return ProductVariant::create(array_merge($payload, [
            'product_id' => $product->id,
        ]));
    }

    private function findOptionValueForProduct(Product $product, string $val): ?ProductOptionValue
    {
        if (Str::isUuid($val)) {
            return ProductOptionValue::query()
                ->where('uuid', $val)
                ->whereHas('productOption', fn ($q) => $q->where('product_id', $product->id))
                ->first();
        }

        return ProductOptionValue::query()
            ->where('value', $val)
            ->whereHas('productOption', fn ($q) => $q->where('product_id', $product->id))
            ->first();
    }

    private function ensureSingleDefaultVariant(Product $product): void
    {
        $defaults = $product->variants()->where('is_default', true)->orderBy('id')->get();
        if ($defaults->count() > 1) {
            $keep = $defaults->first();
            $product->variants()->where('is_default', true)->where('id', '!=', $keep->id)->update(['is_default' => false]);
        }

        if ($product->variants()->where('is_default', true)->doesntExist() && $product->variants()->exists()) {
            $first = $product->variants()->orderBy('id')->first();
            $first?->update(['is_default' => true]);
        }
    }

    private function syncImagesFromPayload(Product $product, array $images): void
    {
        $product->images()->delete();
        foreach (array_values($images) as $index => $img) {
            ProductImage::create([
                'product_id' => $product->id,
                'media_id' => $img['media_id'],
                'sort_order' => $img['sort_order'] ?? $index,
            ]);
        }
    }

    private function formatProduct(Product $product): array
    {
        return [
            'id' => $product->id,
            'uuid' => $product->uuid,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'hsn_code' => $product->hsn_code,
            'warranty' => $product->warranty,
            'return_policy_days' => $product->return_policy_days,
            'is_featured' => $product->is_featured,
            'has_variants' => $product->has_variants,
            'tags' => $product->tags ?? [],
            'attributes' => (object) ($product->attributes ?? []),
            'status' => $product->status,
            'brand_id' => $product->brand_id,
            'brand' => $product->brand ? [
                'id' => $product->brand->id,
                'uuid' => $product->brand->uuid,
                'name' => $product->brand->name,
                'logo' => $product->brand->logoMedia?->file_url,
            ] : null,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'uuid' => $product->category->uuid,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ] : null,
            'thumbnail_media_id' => $product->thumbnail_media_id,
            'thumbnail' => $product->thumbnail?->file_url,
            'options' => $product->options->map(function (ProductOption $option) {
                return [
                    'id' => $option->id,
                    'uuid' => $option->uuid,
                    'name' => $option->name,
                    'values' => $option->values->map(fn (ProductOptionValue $value) => [
                        'id' => $value->id,
                        'uuid' => $value->uuid,
                        'value' => $value->value,
                    ]),
                ];
            }),
            'variants' => $product->variants->map(function (ProductVariant $variant) use ($product) {
                return [
                    'id' => $variant->id,
                    'uuid' => $variant->uuid,
                    'sku' => $variant->sku,
                    'name' => $variant->name,
                    'display_name' => $variant->getDisplayName($product),
                    'barcode' => $variant->barcode,
                    'mrp' => $variant->mrp,
                    'selling_price' => $variant->selling_price,
                    'cost_price' => $variant->cost_price,
                    'discount_percentage' => $variant->getDiscountPercentage(),
                    'discount_amount' => $variant->getDiscountAmount(),
                    'stock_qty' => $variant->stock_qty,
                    'min_order_qty' => $variant->min_order_qty,
                    'max_order_qty' => $variant->max_order_qty,
                    'weight' => $variant->weight,
                    'length_cm' => $variant->length_cm,
                    'width_cm' => $variant->width_cm,
                    'height_cm' => $variant->height_cm,
                    'tax_percentage' => $variant->tax_percentage,
                    'tax_inclusive' => $variant->tax_inclusive,
                    'is_default' => $variant->is_default,
                    'status' => $variant->status,
                    'image' => $variant->image ? [
                        'id' => $variant->image->id,
                        'file_name' => $variant->image->file_name,
                        'file_url' => $variant->image->file_url,
                    ] : null,
                    'option_values' => $variant->variantValues->map(function (ProductVariantValue $vv) {
                        return [
                            'id' => $vv->optionValue->id,
                            'uuid' => $vv->optionValue->uuid,
                            'value' => $vv->optionValue->value,
                        ];
                    }),
                ];
            }),
            'images' => $product->images->map(function (ProductImage $image) {
                return [
                    'id' => $image->id,
                    'uuid' => $image->uuid,
                    'sort_order' => $image->sort_order,
                    'media' => $image->media ? [
                        'id' => $image->media->id,
                        'file_name' => $image->media->file_name,
                        'file_url' => $image->media->file_url,
                    ] : null,
                ];
            }),
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }

    private function serializeListRow(Product $p): array
    {
        $v = $p->variants->firstWhere('is_default', true) ?? $p->variants->first();
        $thumb = $p->thumbnail?->file_url;

        return [
            'id' => $p->id,
            'uuid' => $p->uuid,
            'name' => $p->name,
            'slug' => $p->slug,
            'description' => $p->description,
            'status' => $p->status,
            'status_label' => ucfirst((string) $p->status),
            'is_featured' => $p->is_featured,
            'has_variants' => $p->has_variants,
            'brand' => $p->brand,
            'brand_id' => $p->brand_id,
            'category' => $p->category,
            'category_id' => $p->category_id,
            'thumbnail_url' => $thumb,
            'image_url' => $thumb,
            'created_at' => $p->created_at?->format('Y-m-d'),
            'updated_at' => $p->updated_at?->format('Y-m-d'),
            'sku' => $v?->sku,
            'stock_qty' => $v?->stock_qty ?? 0,
            'variants_count' => $p->variants_count ?? $p->variants->count(),
            'default_variant_id' => $v?->id,
        ];
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
}
