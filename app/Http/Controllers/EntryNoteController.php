<?php

namespace App\Http\Controllers;

use App\Models\EntryNote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Validator;
use App\Models\Stock;

class EntryNoteController extends Controller
{
    use ApiResponse;

    // إظهار كل المذكرات
    public function index()
    {
        try {
            $notes = EntryNote::with(['items.product', 'warehouse', 'user'])->get();
            return $this->successResponse($notes, 'تم جلب المذكرات بنجاح');
        } catch (\Exception $e) {
            return $this->handleExceptionResponse($e);
        }
    }


    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'serial_number' => 'required|unique:entry_notes',
            'date' => 'required|date',
            'warehouse_id' => 'required|exists:warehouses,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            DB::transaction(function () use ($request) {
                $entryNote = EntryNote::create([
                    'serial_number' => $request->serial_number,
                    'date' => $request->date,
                    'warehouse_id' => $request->warehouse_id,
                    'created_by' => auth()->id(),
                ]);

                foreach ($request->items as $item) {
                    $stock = Stock::where('product_id', $item['product_id'])
                        ->where('warehouse_id', $request->warehouse_id)
                        ->first();

                    if (!$stock) {
                        throw new \Exception("لا يوجد مخزون لهذا المنتج في المستودع المختار.");
                    }

                    if ($item['quantity'] > $stock->quantity) {
                        throw new \Exception("الكمية المدخلة للمنتج تتجاوز الكمية المتوفرة في المستودع.");
                    }

                    $entryNote->items()->create([
                        'product_id' => $item['product_id'],
                        'quantity'   => $item['quantity'],
                        'notes'      => $item['notes'] ?? null,
                    ]);

                    // تحديث الكمية في المخزون بعد الإدخال
                    $stock->quantity -= $item['quantity'];
                    $stock->save();
                }
            });

            return $this->successMessage('تم إنشاء المذكرة بنجاح', 201);
        } catch (\Exception $e) {
            return $this->handleExceptionResponse($e, 'فشل في إنشاء المذكرة');
        }
    }


    // إظهار مذكرة محددة
    public function show($id)
    {
        try {
            $note = EntryNote::with(['items.product', 'warehouse', 'user'])->findOrFail($id);
            return $this->successResponse($note, 'تم جلب المذكرة بنجاح');
        } catch (\Exception $e) {
            return $this->handleExceptionResponse($e, 'المذكرة غير موجودة');
        }
    }
}
