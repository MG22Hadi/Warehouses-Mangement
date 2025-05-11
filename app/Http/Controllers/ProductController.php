<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Stock;


use Illuminate\Http\Request;

class ProductController extends Controller
{

    // البحث بالاسم مع أوتوكومبليت
    public function search(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = $request->get('query');

        $products = Product::where('name', 'like', "%$query%")
            ->limit(10)
            ->get(['id', 'name']);

        return response()->json($products);
    }

    // جلب تفاصيل المنتج (كود، وحدة، كمية)
    public function details(Request $request, $id)
    // تابع إضافة مادة يلي هيي الترويسة 😉
    public function store(Request $request)
    {
        $warehouseId = $request->get('warehouse_id');
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:products,code',
            'unit' => 'required|string|max:255',
            'consumable' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $product = Product::findOrFail($id);

        $stock = Stock::where('product_id', $id)
            ->where('warehouse_id', $warehouseId)
            ->first();
        $product = Product::create($validated);

        return response()->json([
            'id'    => $product->id,
            'code'  => $product->code,
            'unit'  => $product->unit, // مباشرة من خصائص المنتج
            'stock'  => optional($stock)->quantity ?? 0,
        ]);
            'message' => 'Product created successfully',
            'data' => $product
        ], 201);
    }



}
