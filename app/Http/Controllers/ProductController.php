<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductMovement;
use App\Models\Stock;
use App\Traits\ApiResponse;



use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use mysql_xdevapi\Exception;

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
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:products,code',
            'unit' => 'required|string|max:50',
            'consumable' => 'required|boolean',
            'notes' => 'nullable|string',
            'warehouse_id' => 'required|exists:warehouses,id'
        ]);

        DB::beginTransaction();

        try {
            $product = Product::create([
                'name' => $validated['name'],
                'code' => $validated['code'],
                'unit' => $validated['unit'],
                'consumable' => $validated['consumable'],
                'notes' => $validated['notes'] ?? null,
            ]);

            // إضافة لسطر المخزون بكمية صفر
            $stock = Stock::create([
                'warehouse_id' => $validated['warehouse_id'],
                'product_id' => $product->id,
                'quantity' => 0,
            ]);

            DB::commit();

            return $this->successResponse([
                'product' => $product,
                'stock' => $stock,
            ], 'تم إنشاء المنتج وربطه بالمستودع بنجاح', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('فشل في إنشاء المنتج: ' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'code' => 'required|string|max:255|unique:products,code,' . $id,
                'unit' => 'required|string|max:255',
                'consumable' => 'boolean',
                'notes' => 'nullable|string',
                'warehouse_id' => 'required|exists:warehouses,id',
            ]);

            $product = Product::find($id);

            if (!$product) {
                return $this->notFoundResponse('المادة غير موجودة');
            }

            $product->update($validated);

            DB::commit();
            return $this->successResponse($product, 'تم تعديل المادة بنجاح',201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse($e->validator);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->handleExceptionResponse($e);
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            // التأكد من وجود المنتج
            $product = Product::findOrFail($id);

            // حذف السطور المرتبطة بالمخزون
            //Stock::where('product_id', $product->id)->delete();

            // حذف المنتج
            $product->delete();

            DB::commit();

            return $this->successResponse(null, 'تم حذف المنتج والمخزون المرتبط به بنجاح');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return $this->errorResponse('المنتج غير موجود', 404);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('فشل في حذف المنتج: ' . $e->getMessage(), 500);
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
           $count= count($products);

           return $this->successResponse(
               ['products'=>$products
                   ,'count'=>$count],
               'قائمة المواد المسترجعة بنجاح حسب الفلتر المختار ',
               201);
       } catch (\Throwable $e){
           return $this->handleExceptionResponse($e);
       }
    }

    public function show($id)
    {
        try {
            $product = Product::with(['stocks' => function($query) {
                $query->select('id', 'product_id', 'warehouse_id', 'quantity');
            }])->find($id);

            if (!$product) {
                return $this->notFoundResponse('المنتج غير موجود');
            }

            return $this->successResponse(
                ['product' => $product],
                'تم جلب بيانات المنتج بنجاح',
                200
            );
        } catch (\Exception $e) {
            return $this->errorResponse('فشل في جلب بيانات المنتج: ' . $e->getMessage(), 500);
        }
    }
}
