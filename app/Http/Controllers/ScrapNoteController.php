<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ExitNote;
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
            'materials.*.quantity' => 'required|numeric|min:0.01',
            'materials.*.notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {

            $scrapNote = null;

            DB::transaction(function () use ($request, &$scrapNote) {
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
                    'approved_by' => null, // سيتم الموافقة لاحقاً
                    'serial_number' =>$this->generateSerialNumber(),
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

                    // خصم الكمية من المخزون (يمكن تعديله حسب نظام المخازن)
//                    DB::table('stocks')
//                        ->where('product_id', $material['product_id'])
//                        ->decrement('quantity', $material['quantity']);

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
                message: 'فشل في إنشاء مذكرة التلف: ' . $e->getMessage(),
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

                if ($scrapNote->status != ScrapNote::STATUS_PENDING) {
                    throw new \Exception('لا يمكن الموافقة على مذكرة غير معلقة');
                }

                // التحقق من توفر الكميات قبل التنقيص
                foreach ($scrapNote->materials as $material) {
                    $available = DB::table('stocks')
                        ->where('product_id', $material->product_id)
                        ->sum('quantity');

                    if ($available < $material->quantity) {
                        throw new \Exception("الكمية غير متوفرة للمنتج {$material->product_id}");
                    }
                }

                // تنقيص الكميات
                foreach ($scrapNote->materials as $material) {
                    DB::table('stocks')
                        ->where('product_id', $material->product_id)
                        ->decrement('quantity', $material->quantity);
                }

                $scrapNote->update([
                    'status' => ScrapNote::STATUS_APPROVED,
                    'approved_by' =>null /*auth()->id()*/,
                    'approved_at' => now(),
                ]);
            });

            return $this->successMessage(
               'تمت الموافقة على مذكرة التلف وتنقيص الكميات بنجاح'
            );

        } catch (\Exception $e) {

            return $this->errorResponse(
                message:  'فشل في الموافقة على المذكرة' . $e->getMessage(),
                code: 422,
                internalCode: 'SCRAP_NOTE_CREATION_FAILED'
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

}
