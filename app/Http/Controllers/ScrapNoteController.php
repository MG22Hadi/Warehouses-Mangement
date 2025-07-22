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
            'materials.*.location_id' => 'required|exists:locations,id', // <--- جديد: أمين المستودع يحدد الموقع هنا
            'materials.*.notes' => 'nullable|string|max:500',
        ], [
            'materials.*.location_id.required' => 'الموقع مطلوب لكل مادة تلف.',
            'materials.*.location_id.exists' => 'الموقع المحدد غير موجود.',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $scrapNote = null;

            DB::transaction(function () use ($request, &$scrapNote) {

                // التحقق من توفر الكميات في المواقع المحددة قبل إنشاء أي شيء
                foreach ($request->materials as $material) {
                    $product = Product::find($material['product_id']);
                    $location = Location::find($material['location_id']);

                    if (!$product) {
                        throw new \Exception("المنتج ID {$material['product_id']} غير موجود.");
                    }
                    if (!$location) {
                        throw new \Exception("الموقع ID {$material['location_id']} غير موجود.");
                    }

                    // التحقق من مطابقة نوع الوحدة بين المنتج والموقع (ضروري)
                    if ($product->unit !== $location->capacity_unit_type) {
                        throw new \Exception("لا يمكن إتلاف المنتج (وحدته: " . $product->unit . ") من الموقع (وحدته: " . $location->capacity_unit_type . "). يجب أن تتطابق الوحدات.");
                    }

                    // التحقق من الكمية المتوفرة في ProductLocation للموقع المحدد
                    $productLocation = ProductLocation::where('product_id', $material['product_id'])
                        ->where('location_id', $material['location_id'])
                        ->first();

                    if (!$productLocation || $productLocation->quantity < $material['quantity']) {
                        $availableInLocation = $productLocation ? $productLocation->quantity : 0;
                        throw new \Exception("الكمية المطلوبة للإتلاف ({$material['quantity']}) للمنتج '{$product->name}' غير متوفرة في الموقع '{$location->name}' (المتاح: {$availableInLocation}).");
                    }
                }

                // إنشاء مذكرة التلف بعد اجتياز جميع التحققات
                $scrapNote = ScrapNote::create([
                    'created_by' => $request->user()->id,
                    'approved_by' => null,
                    'serial_number' => $this->generateSerialNumber(),
                    'reason' => $request->reason,
                    'date' => $request->date,
                    'notes' => $request->notes,
                ]);

                // إضافة المواد التالفة وتخزين location_id
                foreach ($request->materials as $material) {
                    ScrappedMaterial::create([
                        'scrap_note_id' => $scrapNote->id,
                        'product_id' => $material['product_id'],
                        'quantity' => $material['quantity'],
                        'location_id' => $material['location_id'], // <--- تخزين الـ location_id هنا
                        'notes' => $material['notes'] ?? null,
                    ]);
                }
            });

            $scrapNote = ScrapNote::find($scrapNote->id);

            return $this->successResponse(
                $scrapNote,
                'تم إنشاء مذكرة التلف بنجاح وسوف يتم مراجعتها للموافقة'
            );

        } catch (\Throwable $e) { // استخدام Throwable لأخطاء أوسع
            DB::rollBack();
            return $this->errorResponse(
                message: 'فشل في إنشاء مذكرة التلف: ' . $e->getMessage(),
                code: 422,
                internalCode: 'SCRAP_NOTE_CREATION_FAILED'
            );
        }
    }
/**  ابروف قبل اللوكيشن
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
                    'approved_by' =>null /*auth()->id(),
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
    }**/

    public function approve(Request $request, $id)
    {
        $user = Auth::user();

        try {
            DB::transaction(function () use ($id, $request, $user) {
                $scrapNote = ScrapNote::with('materials.product')->findOrFail($id);

                if ($scrapNote->status != ScrapNote::STATUS_PENDING) {
                    throw new \Exception('لا يمكن الموافقة على مذكرة تلف غير معلقة.');
                }

                // ** لا يوجد validator هنا للكميات المعتمدة **
                // المدير يوافق على المذكرة ككل، والكميات المعتمدة هي الكميات التي حددها أمين المستودع أصلاً.

                // الحلقة: تنفيذ الخصومات والتحديثات الفعلية
                foreach ($scrapNote->materials as $material) {
                    $productId = $material->product_id;
                    $quantityToScrap = $material->quantity; // <--- استخدام الكمية الأصلية التي طلبها أمين المستودع
                    $locationId = $material->location_id;   // <--- استخدام الـ location_id المخزن أصلاً

                    // إذا كانت الكمية الأصلية صفر أو أقل، لا نقوم بأي خصم أو حركة لهذه المادة
                    if ($quantityToScrap <= 0) {
                        $material->update(['quantity_approved' => 0]);
                        continue;
                    }

                    // **تحققات إضافية (مهمة جداً):**
                    // إعادة التحقق من توفر الكمية في الموقع **قبل** الخصم الفعلي.
                    // هذا يمنع المشاكل إذا تم سحب المنتج من الموقع بين وقت إنشاء المذكرة والموافقة عليها.
                    $location = Location::find($locationId);
                    if (!$location) {
                        throw new \Exception("الموقع (ID: {$locationId}) غير موجود للمادة التالفة (ID: {$material->id}).");
                    }
                    if ($material->product->unit !== $location->capacity_unit_type) {
                        throw new \Exception("لا يمكن إتلاف المنتج (وحدته: {$material->product->unit}) من الموقع (وحدته: {$location->capacity_unit_type}). يجب أن تتطابق الوحدات.");
                    }
                    $productLocation = ProductLocation::where('product_id', $productId)
                        ->where('location_id', $locationId)
                        ->first();
                    if (!$productLocation || $productLocation->quantity < $quantityToScrap) {
                        $availableInLocation = $productLocation ? $productLocation->quantity : 0;
                        throw new \Exception("الكمية المطلوبة للإتلاف ({$quantityToScrap}) للمنتج '{$material->product->name}' غير متوفرة حالياً في الموقع '{$location->name}' (المتاح: {$availableInLocation}). **يرجى رفض المذكرة وإعادة إنشائها بكميات صحيحة.**");
                    }

                    // تحديث عنصر ScrappedMaterial بالكمية الأصلية كموافقة عليها
                    $material->update([
                        'quantity_approved' => $quantityToScrap, // الكمية المعتمدة هي نفسها الكمية المطلوبة
                    ]);

                    // الخصم من product_locations
                    $productLocation->decrement('quantity', $quantityToScrap);
                    if ($productLocation->quantity <= 0) {
                        $productLocation->delete(); // اختيارياً: حذف السجل إذا أصبحت الكمية صفراً أو أقل
                    }

                    // الخصم من locations.used_capacity_units
                    $location->decrement('used_capacity_units', $quantityToScrap);

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
                        'reference_serial' => $this->generateSerialNumberPM(),
                        'prv_quantity' => $prvQuantity,
                        'note_quantity' => $quantityToScrap,
                        'after_quantity' => $stock->quantity,
                        'date' => now(),
                        'reference_type' => 'ScrappedMaterial',
                        'reference_id' => $material->id,
                        'user_id' => $user->id,
                        'notes' => 'إتلاف المنتج ' . $material->product->name . ' من الموقع ' . $location->name . ' بناءً على موافقة مذكرة التلف ' . $scrapNote->serial_number,
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
                null,'تمت الموافقة على مذكرة التلف وتنقيص الكميات بنجاح.'
            );
        } catch (\Throwable $e) { // استخدام Throwable لأخطاء أوسع
            DB::rollBack();
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
