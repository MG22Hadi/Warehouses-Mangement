<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EntryNote;
use App\Models\EntryNoteItem;
use App\Models\ProductMovement;
use App\Models\ReceivingNote;
use App\Models\ReceivingNoteItem;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ReceivingNoteController extends Controller
{
    //
    use ApiResponse;
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'supplier_id' => 'required|exists:suppliers,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.warehouse_id' => 'required|exists:warehouses,id',
            'items.*.unit_price' => 'required|numeric|min:0.01',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $result = DB::transaction(function () use ($request) {
                $serialNumber = $this->generateSerialNumber();

                // إنشاء مذكرة الاستلام
                $receivingNote = ReceivingNote::create([
                    'serial_number' => $serialNumber,
                    'date' => $request->date,
                    'supplier_id' => $request->supplier_id,
                    'created_by' => $request->user()->id,
                ]);

                foreach ($request->items as $item) {
                    $totalPrice = $item['unit_price'] * $item['quantity'];

                    // البحث عن المخزون الحالي
                    $stock = DB::table('stocks')
                        ->where('product_id', $item['product_id'])
                        ->where('warehouse_id',$item['warehouse_id'] )
                        ->first();


                    // تحديث  سجل المخزون

                    DB::table('stocks')
                        ->where('product_id', $item['product_id'])
                        ->where('warehouse_id',$item['warehouse_id'])
                        ->increment('quantity', $item['quantity']);

                    // إنشاء عنصر مذكرة الاستلام

                    ReceivingNoteItem::create([
                        'receiving_note_id' => $receivingNote->id,
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $item['warehouse_id'],
                        'unit_price' => $item['unit_price'],
                        'quantity' => $item['quantity'],
                        'total_price' => $totalPrice,
                        'notes' => $item['notes'] ?? null,
                    ]);

                    // تسجيل حركة المنتج
                    ProductMovement::create([
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $item['warehouse_id'],
                        'type' => 'receive',
                        'reference_serial' => $receivingNote->serial_number,
                        'prv_quantity' => $stock->quantity,
                        'note_quantity' => $item['quantity'],
                        'after_quantity' => $stock->quantity + $item['quantity'],
                        'date' => $request->date,
                        'reference_type' => 'ReceivingNote',
                        'reference_id' => $receivingNote->id,
                        'user_id' => $request->user()->id,
                        'notes' => $item['notes'] ?? 'استلام من مورد عبر سند رقم: ' . $serialNumber,
                    ]);
                }

                return [
                    'receiving_note' => $receivingNote->load('items'),
                    'message' => 'تم إنشاء مذكرة الاستلام بنجاح'
                ];
            });

            return $this->successResponse($result['receiving_note'], $result['message'], 201);

        } catch (\Exception $e) {
            return $this->errorResponse(
                message: 'فشل في إنشاء مذكرة الاستلام: ' . $e->getMessage(),
                code: 500,
                internalCode: 'RECEIVING_NOTE_CREATION_FAILED'
            );
        }
    }


    //لتوليد السيريال نمبر
    private function generateSerialNumber(): string
    {
        $currentYear = date('Y');

        // الحصول على آخر مذكرة لهذه السنة
        $lastEntry = ReceivingNote::whereYear('created_at', $currentYear)
            ->orderBy('id', 'desc')
            ->first();

        // تحديد الأرقام الجديدة
        if (!$lastEntry) {
            // أول مذكرة في السنة
            $folderNumber = 1;
            $noteNumber = 1;
        } else {
            // فك الترميز من السيريال السابق
            $serial = trim($lastEntry->serial_number, '()');
            list($lastFolderNumber, $lastNoteNumber) = explode('/', $serial);

            $lastFolderNumber = (int)$lastFolderNumber;
            $lastNoteNumber = (int)$lastNoteNumber;

            // حساب الأرقام الجديدة
            $noteNumber = $lastNoteNumber + 1;
            $folderNumber = $lastFolderNumber;

            if ($noteNumber % 50 == 1 && $noteNumber > 50) {
                $folderNumber = floor($noteNumber / 50) + 1;
            }
        }

        return "($folderNumber/$noteNumber)";
    }


}
