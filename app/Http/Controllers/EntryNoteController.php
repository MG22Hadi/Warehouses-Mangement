<?php

namespace App\Http\Controllers;

use App\Models\EntryNote;
use App\Models\EntryNoteItem;
use App\Models\ExitNote;
use App\Models\Product;
use App\Models\ProductMovement;
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
            $notes = EntryNote::withCount('items') // هنا نستخدم withCount بدلاً of with

            ->with(['warehouse', 'user'])->get();

            return $this->successResponse($notes, 'تم جلب المذكرات مع عدد الأصناف بنجاح');
        } catch (\Exception $e) {
            return $this->handleExceptionResponse($e);
        }
    }


    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.warehouse_id' => 'required|exists:warehouses,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $result = DB::transaction(function () use ($request) {
                $serialNumber = $this->generateSerialNumber();


                $entryNote = EntryNote::create([
                    'serial_number' => $serialNumber,
                    'date' => $request->date,
                    'created_by' => $request->user()->id,
                ]);

                foreach ($request->items as $item) {
                    $stock = DB::table('stocks')
                        ->where('product_id', $item['product_id'])
                        ->where('warehouse_id', $item['warehouse_id'])
                        ->first();

                    if (!$stock) {
                        throw new \Exception("لا يوجد مخزون لهذا المنتج في المستودع المختار.");
                    }

                    // تحديث المخزون أولاً
                    DB::table('stocks')
                        ->where('product_id', $item['product_id'])
                        ->where('warehouse_id', $item['warehouse_id'])
                        ->increment('quantity', $item['quantity']);

                    // إنشاء عنصر مذكرة الدخول
                    EntryNoteItem::create([
                        'entry_note_id' => $entryNote->id,
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $item['warehouse_id'],
                        'quantity' => $item['quantity'],
                        'notes' => $item['notes'] ?? null,
                        'created_by' => $request->user()->id
                    ]);

                    // إنشاء حركة المنتج
                    ProductMovement::create([
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $item['warehouse_id'],
                        'type' => 'entry',
                        'reference_serial' => $entryNote->serial_number,
                        'prv_quantity' => $stock->quantity,
                        'note_quantity' => $item['quantity'],
                        'after_quantity' => $stock->quantity + $item['quantity'],
                        'date' => $request->date,
                        'reference_type' => 'EntryNote',
                        'reference_id' => $entryNote->id,
                        'user_id' => $request->user()->id,
                        'notes' => $item['notes'] ?? 'إدخال من سند رقم: ' . $serialNumber,
                    ]);
                }

                return [
                    'entry_note' => $entryNote,
                    'message' => 'تم إنشاء المذكرة بنجاح'
                ];
            });

            return $this->successResponse($result['entry_note'], $result['message'], 201);

        } catch (\Exception $e) {
            return $this->errorResponse(
                message: 'فشل في إنشاء مذكرة الدخول: ' . $e->getMessage(),
                code: 500,
                internalCode: 'ENTRY_NOTE_CREATION_FAILED'
            );
        }
    }


    // إظهار مذكرة محددة
    public function show($id)
    {
        try {
            $note = EntryNote::findOrFail($id);
            return $this->successResponse($note, 'تم جلب المذكرة بنجاح');
        } catch (\Exception $e) {
            return $this->handleExceptionResponse($e, 'المذكرة غير موجودة');
        }
    }

    //لتوليد السيريال نمبر
    private function generateSerialNumber(): string
    {
        $currentYear = date('Y');

        // الحصول على آخر مذكرة لهذه السنة
        $lastEntry = EntryNote::whereYear('created_at', $currentYear)
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
