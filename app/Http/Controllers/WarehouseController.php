<?php

namespace App\Http\Controllers;

use App\Models\Stock;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;


class WarehouseController extends Controller
{
    use ApiResponse;

    public function store(Request $request)
    {
        DB::beginTransaction();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'type' => 'nullable|string',
        ]);


        try {
            $warehouse = Warehouse::create([
                'name' => $validated['name'],
                'location' => $validated['location'],
            ]);

            DB::commit();

            return $this->successResponse(
                ['warehouse' => $warehouse],
                'تم إنشاء المستودع بنجاح',
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->errorResponse('فشل في إنشاء المستودع : ' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'name' => 'string|max:255',
                'location' => 'string|max:255',
            ]);

            $warehouse = Warehouse::find($id);

            if (!$warehouse) {
                return $this->notFoundResponse('المستودع غير موجودة');
            }

            $warehouse->update($validated);

            DB::commit();
            return $this->successResponse($warehouse, 'تم تعديل المستودع بنجاح',201);
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
            $warehouse = Warehouse::findOrFail($id);

            //Stock::where('warehouse_id', $warehouse->id)->delete();

            $warehouse->delete();

            DB::commit();

            return $this->successResponse(null, 'تم حذف المستودع والمخزون المرتبط به بنجاح');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return $this->errorResponse('المستودع غير موجود', 404);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('فشل في حذف المستودع: ' . $e->getMessage(), 500);
        }
    }

    public function index()
    {
        $warehouses=Warehouse::all();
        return $this->successResponse($warehouses,'هذه هي كل المستودعات يا عمي ',201);
    }

    public function show($id)
    {
        try {
            $warehouse = Warehouse::find($id);

            if (!$warehouse) {
                return $this->notFoundResponse('المستودع غير موجود');
            }

            return $this->successResponse(
                ['warehouse' => $warehouse],
                'تم جلب بيانات المستودع بنجاح',
                200
            );
        } catch (\Exception $e) {
            return $this->errorResponse('فشل في جلب بيانات المستودع: ' . $e->getMessage(), 500);
        }
    }
}
