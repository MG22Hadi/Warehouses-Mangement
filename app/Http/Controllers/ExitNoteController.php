<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EntryNote;
use App\Models\EntryNoteItem;
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
            $notes = EntryNote::withCount('items') // هنا نستخدم withCount بدلاً of with
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

                    DB::table('stocks')
                        ->where('product_id', $item['product_id'])
                        ->where('warehouse_id', $item['warehouse_id'])
                        ->increment('quantity', $item['quantity']);

                    EntryNoteItem::create([
                        'entry_note_id' => $entryNote->id,
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $item['warehouse_id'],
                        'quantity' => $item['quantity'],
                        'notes' => $item['notes'] ?? null,
                        'created_by' => $request->user()->id
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
                message: $e->getMessage(),
                code: 500,
                errors: ['trace' => $e->getTraceAsString()],
                internalCode: 'ENTRY_NOTE_CREATION_FAILED'
            );
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

    //لتوليد السيريال نمبر
    private function generateSerialNumber()
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
