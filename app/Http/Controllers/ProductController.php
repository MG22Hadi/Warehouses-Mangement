<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Stock;
use App\Traits\ApiResponse;



use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse;

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


    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'code' => 'required|string|max:255|unique:products,code',
                'unit' => 'required|string|max:255',
                'consumable' => 'boolean',
                'notes' => 'nullable|string',
            ]);

            $product = Product::create($validated);

            return $this->successResponse($product, 'تمت إضافة المادة بنجاح', 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse($e->validator);
        } catch (\Throwable $e) {
            return $this->handleExceptionResponse($e);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'code' => 'required|string|max:255|unique:products,code,' . $id,
                'unit' => 'required|string|max:255',
                'consumable' => 'boolean',
                'notes' => 'nullable|string',
            ]);

            $product = Product::find($id);

            if (!$product) {
                return $this->notFoundResponse('المادة غير موجودة');
            }

            $product->update($validated);

            return $this->successResponse($product, 'تم تعديل المادة بنجاح');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse($e->validator);
        } catch (\Throwable $e) {
            return $this->handleExceptionResponse($e);
        }
    }

    public function destroy($id)
    {
        try {
            $product = Product::find($id);

            if (!$product) {
                return $this->notFoundResponse('المادة غير موجودة');
            }

            $product->delete();

            return $this->successMessage('تم حذف المادة بنجاح');
        } catch (\Throwable $e) {
            return $this->handleExceptionResponse($e);
        }
    }

    public function index(Request $request)
    {
       try{
           $query= Product::query();

           // مصفوفة فلاتر
           $filters = ['name','code','unit','consumable','from_to'];
           $appliedFilters=[];

           if($request->has('name')){
               $query->where('name','like','%'.$request->name.'%');
               $appliedFilters=['name'];
           } elseif ($request->has('code')){
               $query->where('code','like','%'.$request->code .'%');
               $appliedFilters=['code'];
           } elseif ($request->has('unit')) {
               $query->where('unit', 'like', '%' . $request->unit . '%');
               $appliedFilters[] = 'unit';
           } elseif ($request->has('consumable')) {
               $query->where('consumable', $request->consumable);
               $appliedFilters[] = 'consumable';
           } elseif ($request->has('from')&& $request->has('to')){
               $query->whereBetween('created_at',[$request->from,$request->to]);
               $appliedFilters=['from_to'];
           }

           if(count($appliedFilters)>1){
               return $this->errorResponse(400,'يرجى استخدام فلتر واحد فقط في كل طلب');
           }

           $products=$query->orderBy('created_at','desc')->get();

           return $this->successResponse($products,'قائمة المواد المسترجعة بنجاح حسب الفلتر المختار ');
       } catch (\Throwable $e){
           return $this->handleExceptionResponse($e);
       }
    }
}
