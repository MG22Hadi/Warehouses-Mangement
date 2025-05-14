<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EntryNote;
use App\Models\EntryNoteItem;
use App\Models\ExitNote;
use App\Models\ExitNoteItem;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ExitNoteController extends Controller
{
    use ApiResponse;

    public function index()
    {
        try {
            $notes = ExitNote::withCount('items') // هنا نستخدم withCount بدلاً of with
            ->with(['warehouse', 'user'])
                ->get();

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

                $exitNote = ExitNote::create([
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

                    // التحقق من وجود كمية كافية في المخزون
                    if ($stock->quantity < $item['quantity']) {
                        throw new \Exception("الكمية المطلوبة (".$item['quantity'].") غير متوفرة في المخزون (الكمية المتاحة: ".$stock->quantity.") للمنتج ID: ".$item['product_id']);
                    }

                    DB::table('stocks')
                        ->where('product_id', $item['product_id'])
                        ->where('warehouse_id', $item['warehouse_id'])
                        ->increment('quantity', $item['quantity']);

                    ExitNoteItem::create([
                        'entry_note_id' => $exitNote->id,
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $item['warehouse_id'],
                        'quantity' => $item['quantity'],
                        'notes' => $item['notes'] ?? null,
                        'created_by' => $request->user()->id
                    ]);
                }

                return [
                    'entry_note' => $exitNote,
                    'message' => 'تم إنشاء المذكرة بنجاح'
                ];
            });

            return $this->successResponse($result['entry_note'], $result['message'], 201);

        } catch (\Exception $e) {
            return $this->errorResponse(
                message: $e->getMessage(),
                code: 500,
                errors: ['trace' => $e->getTraceAsString()],
                internalCode: 'EXIT_NOTE_CREATION_FAILED'
            );
        }
    }


    // إظهار مذكرة محددة
    public function show($id)
    {
        try {
            $note = ExitNote::with(['items.product', 'warehouse', 'user'])->findOrFail($id);
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
