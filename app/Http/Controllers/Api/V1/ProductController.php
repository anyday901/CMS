<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Products through the REST API, in the store's order, hidden ones included. */
class ProductController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'product_group_id' => ['nullable', 'integer'],
            'active' => ['nullable', 'boolean'],
        ]);

        $products = Product::with('group')
            ->when($filters['product_group_id'] ?? null, fn ($query, $id) => $query->where('product_group_id', $id))
            ->when(isset($filters['active']), fn ($query) => $query->where('active', $request->boolean('active')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ProductResource::collection($products);
    }

    public function show(Product $product): ProductResource
    {
        return ProductResource::make($product->load(['group', 'prices']));
    }
}
