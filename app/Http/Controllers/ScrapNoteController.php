<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ExitNote;
use App\Models\ProductMovement;
use App\Models\ScrapNote;
use App\Models\ScrappedMaterial;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ScrapNoteController extends Controller
{
    //
    use ApiResponse;
    // إظهار كل المذكرات
    public function index()
    {
        try {
            $notes = ScrapNote::with(['materials.product', 'createdBy', 'approvedBy'])
                ->withCount('materials as materials_count')
                ->orderBy('created_at', 'desc')
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
            'reason' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:1000',
            'materials' => 'required|array|min:1',
            'materials.*.product_id' => 'required|exists:products,id',
            'materials.*.warehouse_id' => 'required|exists:warehouses,id',
            'materials.*.quantity' => 'required|numeric|min:0.01',
            'materials.*.notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $scrapNote = null;

            DB::transaction(function () use ($request, &$scrapNote) {
                $serialNumber = $this->generateSerialNumber();

                // التحقق من توفر الكميات في المخزون قبل إنشاء المذكرة
                foreach ($request->materials as $material) {
                    $availableQuantity = DB::table('stocks')
                        ->where('product_id', $material['product_id'])
                        ->sum('quantity');

                    if ($availableQuantity < $material['quantity']) {
                        throw new \Exception("الكمية المطلوبة ({$material['quantity']}) للمنتج ID {$material['product_id']} غير متوفرة في المخزون (المتاح: {$availableQuantity})");
                    }
                }

                // إنشاء مذكرة التلف
                $scrapNote = ScrapNote::create([
                    'created_by' => $request->user()->id,
                    'approved_by' => null,
                    'serial_number' => $serialNumber,
                    'reason' => $request->reason,
                    'date' => $request->date,
                    'notes' => $request->notes,
                ]);

                // إضافة المواد التالفة
                foreach ($request->materials as $material) {
                    ScrappedMaterial::create([
                        'scrap_note_id' => $scrapNote->id,
                        'product_id' => $material['product_id'],
                        'quantity' => $material['quantity'],
                        'notes' => $material['notes'] ?? null,
                    ]);
                }
            });

            // إعادة تحميل النموذج مع العلاقات
            $scrapNote = ScrapNote::find($scrapNote->id);

            return $this->successResponse(
                $scrapNote,
                'تم إنشاء مذكرة التلف بنجاح وسوف يتم مراجعتها للموافقة'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                message: 'فشل في إنشاء مذكرة التلف : ' . $e->getMessage(),
                code: 422,
                internalCode: 'SCRAP_NOTE_CREATION_FAILED'
            );
        }
    }

    public function approve($id)
    {
        try {
            DB::transaction(function () use ($id) {
                $scrapNote = ScrapNote::with('materials')->findOrFail($id);
                $pmSerialNumber = $this->generateSerialNumberPM();

                if ($scrapNote->status != ScrapNote::STATUS_PENDING) {
                    throw new \Exception('لا يمكن الموافقة على مذكرة غير معلقة');
                }

                // التحقق من توفر الكميات قبل التنقيص
                foreach ($scrapNote->materials as $material) {
                    $stock = DB::table('stocks')
                        ->where('product_id', $material->product_id)
                        ->where('warehouse_id', $material->warehouse_id)
                        ->first();

                    if (!$stock || $stock->quantity < $material->quantity) {
                        throw new \Exception("الكمية غير متوفرة للمنتج {$material->product_id} في المستودع المحدد");
                    }
                }

                // تنقيص الكميات وتسجيل حركة المنتج
                foreach ($scrapNote->materials as $material) {
                    $stock = DB::table('stocks')
                        ->where('product_id', $material->product_id)
                        ->where('warehouse_id', $material->warehouse_id)
                        ->first();

                    DB::table('stocks')
                        ->where('product_id', $material->product_id)
                        ->where('warehouse_id', $material->warehouse_id)
                        ->decrement('quantity', $material->quantity);

                    // إنشاء حركة المنتج (تم نقلها من تابع store)
                    ProductMovement::create([
                        'product_id' => $material->product_id,
                        'warehouse_id' => $material->warehouse_id,
                        'type' => 'scrap',
                        'reference_serial' => $scrapNote->serial_number,
                        'prv_quantity' => $stock->quantity,
                        'note_quantity' => $material->quantity,
                        'after_quantity' => $stock->quantity - $material->quantity,
                        'date' => $scrapNote->date,
                        'reference_type' => 'ScrapNote',
                        'reference_id' => $scrapNote->id,
                        'user_id' =>$scrapNote->created_by,
                        'notes' => $material->notes ?? 'إدخال من سند رقم: ' . $scrapNote->serial_number,
                    ]);
                }

                $scrapNote->update([
                    'status' => ScrapNote::STATUS_APPROVED,
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                ]);
            });

            return $this->successMessage(
                'تمت الموافقة على مذكرة التلف وتنقيص الكميات بنجاح'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                message: 'فشل في الموافقة على المذكرة: ' . $e->getMessage(),
                code: 422,
                internalCode: 'SCRAP_NOTE_APPROVAL_FAILED'
            );
        }
    }

    public function reject(Request $request, $id)
    {
        $request->validate([
            'rejection_reason' => 'required|string'
        ]);

        try {
            $scrapNote = ScrapNote::findOrFail($id);

            if ($scrapNote->status != ScrapNote::STATUS_PENDING) {
                throw new \Exception("لا يمكن رفض طلب غير معلق ");
            }

            $scrapNote->update([
                'status' => ScrapNote::STATUS_REJECTED,
                'rejection_reason' => $request->rejection_reason,
                'approved_by' =>null /*auth()->id()*/,
                'approved_at' => now(),
            ]);

            return $this->successMessage( 'تم رفض مذكرة التلف بنجاح');

        } catch (\Exception $e) {
            return $this->errorResponse(
                message: 'فشل في رفض المذكرة' . $e->getMessage(),
                code: 422,
                internalCode: 'SCRAP_NOTE_CREATION_FAILED'
            );
        }
    }

    //إظهار مذكرة محددة
    public function show($id)
    {
        try {
            $note = ScrapNote::with([
                'materials.product', // تحميل المواد مع معلومات المنتج
                'createdBy:id,name', // معلومات منشئ المذكرة
                'approvedBy:id,name' // معلومات الموافق (إذا موجود)
            ])->findOrFail($id);
            return $this->successResponse($note, 'تم جلب المذكرة بنجاح');
        } catch (\Exception $e) {
            return $this->handleExceptionResponse($e, 'المذكرة غير موجودة');
        }
    }


    private function generateSerialNumber(): string
    {
        $currentYear = date('Y');

        // الحصول على آخر مذكرة لهذه السنة
        $lastEntry = ScrapNote::whereYear('created_at', $currentYear)
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

    private function generateSerialNumberPM(): string
    {
        $currentYear = date('Y');

        // الحصول على آخر مذكرة لهذه السنة
        $last = ProductMovement::whereYear('created_at', $currentYear)
            ->orderBy('id', 'desc')
            ->first();

        // تحديد الأرقام الجديدة
        if (!$last) {
            // أول مذكرة في السنة
            $folderNumber = 1;
            $noteNumber = 1;
        } else {
            // فك الترميز من السيريال السابق
            $serial = trim($last->reference_serial, '()');
            list($lastFolderNumber, $lastNoteNumber) = explode('/', $serial);

            $lastFolderNumber = (int)$lastFolderNumber;
            $lastNoteNumber = (int)$lastNoteNumber;

            // حساب الأرقام الجديدة
            $noteNumber = $lastNoteNumber + 1;
            $folderNumber = $lastFolderNumber;

            if ($noteNumber % 50 == 1 && $noteNumber > 50) {
                $folderNumber = floor($noteNumber / 50) + 1 ;
            }
        }

        return "($folderNumber/$noteNumber)";
    }

}
