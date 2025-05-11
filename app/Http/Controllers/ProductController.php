<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Stock;



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
    {
        $warehouseId = $request->get('warehouse_id');

        $product = Product::findOrFail($id);

        $stock = Stock::where('product_id', $id)
            ->where('warehouse_id', $warehouseId)
            ->first();

        return response()->json([
            'id'    => $product->id,
            'code'  => $product->code,
            'unit'  => $product->unit, // مباشرة من خصائص المنتج
            'stock'  => optional($stock)->quantity ?? 0,
        ]);
    }



}
