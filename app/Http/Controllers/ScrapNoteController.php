<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ExitNote;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductLocation;
use App\Models\ProductMovement;
use App\Models\ScrapNote;
use App\Models\ScrappedMaterial;
use App\Models\Stock;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Services\InventoryService;

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
            $locationMessages = [];

            DB::transaction(function () use ($request, &$scrapNote, &$locationMessages) {
                // إنشاء مذكرة التلف
                $scrapNote = ScrapNote::create([
                    'created_by' => $request->user()->id,
                    'approved_by' => null,
                    'serial_number' => $this->generateSerialNumber(),
                    'reason' => $request->reason,
                    'date' => $request->date,
                    'notes' => $request->notes,
                ]);

                // إضافة المواد التالفة فقط
                foreach ($request->materials as $material) {
                    $product = Product::findOrFail($material['product_id']);
                    $quantity = $material['quantity'];

                    ScrappedMaterial::create([
                        'scrap_note_id' => $scrapNote->id,
                        'product_id' => $product->id,
                        'quantity' => $quantity,
                        'notes' => $material['notes'] ?? null,
                    ]);

                    // فقط رسالة توضيحية (بدون تخزين location_id)
                    $locationMessages[] = "تم إتلاف {$quantity} {$product->unit} من المنتج '{$product->name}'.";
                }
            });

            return $this->successResponse(
                [
                    'scrap_note' => $scrapNote->load('materials'),
                    'location_messages' => $locationMessages,
                ],
                'تم إنشاء مذكرة التلف بنجاح وسوف يتم مراجعتها للموافقة'
            );

        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->errorResponse(
                message: 'فشل في إنشاء مذكرة التلف: ' . $e->getMessage(),
                code: 422,
                internalCode: 'SCRAP_NOTE_CREATION_FAILED'
            );
        }
    }

    public function approve(Request $request, $id)
    {
        $user = $request->user();
        $locationMessages = [];
        try {
            DB::transaction(function () use ($id, $request, $user,  &$locationMessages) {
                $scrapNote = ScrapNote::with('materials.product')->findOrFail($id);

                if ($scrapNote->status != ScrapNote::STATUS_PENDING) {
                    throw new \Exception('لا يمكن الموافقة على مذكرة تلف غير معلقة.');
                }

                // الحلقة: تنفيذ الخصومات والتحديثات الفعلية
                foreach ($scrapNote->materials as $material) {
                    $productId = $material->product_id;

                    // ابحث عن موقع فيه الكمية المطلوبة
                    $productLocation = ProductLocation::where('product_id', $material->product_id)
                        ->where('quantity', '>=', $material->quantity)
                        ->first();

                    if (!$productLocation) {
                        throw new \Exception("الكمية المطلوبة ({$material->quantity}) من المنتج '{$material->name}' غير متوفرة في أي موقع.");
                    }

                    $location = $productLocation->location;

                    // خَصْم الكَمّيَّة
                    $productLocation->decrement('quantity', $material->quantity);
                    $location->decrement('used_capacity_units', $material->quantity);

                    // رسالة للمستخدم
                    $locationMessages[] = "تم خصم {$material->quantity} {$material->unit} من المنتج '{$material->name}' من الموقع '{$location->name}'.";


                    $quantityToScrap = $material->quantity; // <--- استخدام الكمية الأصلية التي طلبها أمين المستودع

                    // إذا كانت الكمية الأصلية صفر أو أقل، لا نقوم بأي خصم أو حركة لهذه المادة
                    if ($quantityToScrap <= 0) {
                        $material->update(['quantity_approved' => 0]);
                        continue;
                    }


                    // تحديث عنصر ScrappedMaterial بالكمية الأصلية كموافقة عليها
                    $material->update([
                        'quantity_approved' => $quantityToScrap, // الكمية المعتمدة هي نفسها الكمية المطلوبة
                    ]);



                    // تحديث المخزون الإجمالي (Stocks)
                    $stock = Stock::firstOrCreate(
                        ['product_id' => $productId, 'warehouse_id' => $location->warehouse_id],
                        ['quantity' => 0]
                    );

                    $prvQuantity = $stock->quantity;
                    $stock->decrement('quantity', $quantityToScrap);

                    // تسجيل حركة المنتج (ProductMovement)
                    ProductMovement::create([
                        'product_id' => $productId,
                        'warehouse_id' => $location->warehouse_id,
                        'type' => 'scrap',
                        'reference_serial' => $scrapNote->serial_number,
                        'prv_quantity' => $prvQuantity,
                        'note_quantity' => $quantityToScrap,
                        'after_quantity' => $stock->quantity,
                        'date' => now(),
                        'reference_type' => 'ScrappedMaterial',
                        'reference_id' => $material->id,
                        'user_id' => $user->id,
                        'notes' => 'إتلاف المنتج ' . $material->product->name . ' بناءً على موافقة مذكرة التلف ' . $scrapNote->serial_number,
                    ]);
                }

                // تحديث حالة مذكرة التلف
                $scrapNote->update([
                    'status' => ScrapNote::STATUS_APPROVED,
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                ]);
            });

            return $this->successResponse(
               $locationMessages,'تمت الموافقة على مذكرة التلف وتنقيص الكميات بنجاح.'
            );
        } catch (\Throwable $e) { // استخدام Throwable لأخطاء أوسع
            DB::rollBack();

            // تحقق إذا كان التطبيق في وضع التصحيح (Debug Mode)
            if (config('app.debug')) {
                //  في بيئة التطوير: أرجع الخطأ بالتفصيل الكامل
                return response()->json([
                    'success' => false,
                    'message' => 'حدث خطأ: ' . $e->getMessage(),
                    'file' => $e->getFile(), // <-- ملف الخطأ
                    'line' => $e->getLine(), // <-- سطر الخطأ
                    'trace' => $e->getTraceAsString() // <-- تتبع مسار الخطأ (اختياري لكن مفيد جداً)
                ], 500); // 500 هو رمز الخطأ الأنسب للخوادم
            }

            // في بيئة الإنتاج: أرجع رسالة عامة وآمنة
            return $this->errorResponse(
                message: 'فشل في الموافقة على المذكرة، حدث خطأ غير متوقع.',
                code: 500, // استخدم 500 Internal Server Error
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

    protected function generateSerialNumberPM()
    {
        $currentYear = date('Y');

        // الحصول على آخر مذكرة لهذه السنة
        $lastEntry = ProductMovement::whereYear('created_at', $currentYear)
            ->orderBy('id', 'desc')
            ->first();

        // تحديد الأرقام الجديدة
        if (!$lastEntry) {
            // أول مذكرة في السنة
            $folderNumber = 1;
            $noteNumber = 1;
        } else {
            // فك الترميز من السيريال السابق
            $serial = trim($lastEntry->reference_serial, '()');
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
