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
            'materials.*.notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {

            $scrapNote = null;

            DB::transaction(function () use ($request, &$scrapNote) {
                /**
                // التحقق من توفر الكميات في المخزون قبل إنشاء المذكرة
                foreach ($request->materials as $material) {
                    $availableQuantity = DB::table('stocks')
                        ->where('product_id', $material['product_id'])
                        ->sum('quantity');

                    if ($availableQuantity < $material['quantity']) {
                        throw new \Exception("الكمية المطلوبة ({$material['quantity']}) للمنتج ID {$material['product_id']} غير متوفرة في المخزون (المتاح: {$availableQuantity})");
                    }
                }*/

                // التحقق من توفر الكميات في المخزون بناءً على ProductLocation
                foreach ($request->materials as $material) {
                    $product = Product::find($material['product_id']); // جلب المنتج للحصول على اسمه
                    if (!$product) {
                        throw new \Exception("المنتج ID {$material['product_id']} غير موجود.");
                    }

                    // جمع الكميات من كل المواقع التي تحتوي على هذا المنتج
                    $availableQuantity = ProductLocation::where('product_id', $material['product_id'])
                        ->sum('quantity');

                    if ($availableQuantity < $material['quantity']) {
                        throw new \Exception("الكمية المطلوبة ({$material['quantity']}) للمنتج '{$product->name}' غير متوفرة في المخزون (المتاح: {$availableQuantity}).");
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

                // التحقق من صحة location_id والكمية المقبولة لكل عنصر
                $validator = Validator::make($request->all(), [
                    'materials' => 'required|array|min:1', // التأكد من وجود مصفوفة المواد
                    'materials.*.id' => 'required|exists:scrapped_materials,id', // معرف عنصر ScrappedMaterial المحدد
                    'materials.*.location_id' => [ // الموقع الذي سيتم إتلاف العنصر منه
                        'required',
                        'exists:locations,id',
                    ],
                    'materials.*.scrapped_quantity_approved' => [ // الكمية الفعلية المعتمدة للإتلاف من هذا الموقع
                        'required',
                        'numeric',
                        'min:0.01',
                    ],
                ]);

                if ($validator->fails()) {
                    throw new \Illuminate\Validation\ValidationException($validator); // إطلاق استثناء التحقق ليتم التقاطه بواسطة الـ catch block
                }

                $requestMaterials = collect($request->materials); // تحويل إلى مجموعة لتسهيل البحث

                // الحلقة الأولى: تنفيذ جميع التحققات من الكمية والسعة قبل أي خصومات
                foreach ($scrapNote->materials as $material) {
                    $requestMaterial = $requestMaterials->firstWhere('id', $material->id);

                    if (!$requestMaterial) {
                        throw new \Exception("تفاصيل الكمية والموقع للمادة التالفة (ID: {$material->id}) مفقودة من الطلب.");
                    }

                    $productId = $material->product_id;
                    $scrappedQuantityApproved = $requestMaterial['scrapped_quantity_approved'];
                    $locationId = $requestMaterial['location_id'];

                    $location = Location::find($locationId);
                    if (!$location) {
                        throw new \Exception("الموقع (ID: {$locationId}) غير موجود.");
                    }

                    // التحقق مما إذا كانت وحدة المنتج تتطابق مع نوع وحدة سعة الموقع
                    if ($material->product->unit !== $location->capacity_unit_type) {
                        throw new \Exception("لا يمكن إتلاف المنتج (وحدته: {$material->product->unit}) من الموقع (وحدته: {$location->capacity_unit_type}). يجب أن تتطابق الوحدات.");
                    }

                    // التحقق مما إذا كانت الكمية المطلوبة للإتلاف من هذا الموقع متاحة
                    $productLocation = ProductLocation::where('product_id', $productId)
                        ->where('location_id', $locationId)
                        ->first();

                    if (!$productLocation || $productLocation->quantity < $scrappedQuantityApproved) {
                        $availableInLocation = $productLocation ? $productLocation->quantity : 0;
                        throw new \Exception("الكمية المطلوبة للإتلاف ({$scrappedQuantityApproved}) للمنتج '{$material->product->name}' غير متوفرة في الموقع '{$location->name}' (المتاح: {$availableInLocation}).");
                    }

                    // التأكد أيضاً من أن الكمية المقبولة لا تتجاوز الكمية الأصلية المطلوبة في المذكرة
                    if ($scrappedQuantityApproved > $material->quantity) {
                        throw new \Exception("الكمية المقبولة للإتلاف ({$scrappedQuantityApproved}) للمنتج '{$material->product->name}' لا يمكن أن تتجاوز الكمية المطلوبة في المذكرة ({$material->quantity}).");
                    }
                }

                // الحلقة الثانية: تنفيذ الخصومات والتحديثات الفعلية إذا مرت جميع التحققات
                foreach ($scrapNote->materials as $material) {
                    $requestMaterial = $requestMaterials->firstWhere('id', $material->id);

                    $productId = $material->product_id;
                    $scrappedQuantityApproved = $requestMaterial['scrapped_quantity_approved'];
                    $locationId = $requestMaterial['location_id'];

                    $location = Location::find($locationId); // إعادة جلب الموقع للسلامة
                    $productLocation = ProductLocation::where('product_id', $productId)
                        ->where('location_id', $locationId)
                        ->first(); // إعادة جلب ProductLocation للسلامة

                    // تحديث عنصر ScrappedMaterial بالكمية المقبولة والموقع
                    $material->update([
                        'quantity_approved' => $scrappedQuantityApproved,
                        'location_id' => $locationId, // تخزين الموقع الذي تم الإتلاف منه
                    ]);

                    // الخصم من product_locations
                    $productLocation->decrement('quantity', $scrappedQuantityApproved);
                    if ($productLocation->quantity <= 0) {
                        $productLocation->delete(); // اختيارياً: حذف السجل إذا أصبحت الكمية صفراً أو أقل
                    }

                    // الخصم من locations.used_capacity_units
                    $location->decrement('used_capacity_units', $scrappedQuantityApproved);

                    // تحديث المخزون الإجمالي (Stocks)
                    $stock = Stock::firstOrCreate(
                        ['product_id' => $productId, 'warehouse_id' => $location->warehouse_id], // المخزون مرتبط بالمنتج والمستودع
                        ['quantity' => 0]
                    );

                    $prvQuantity = $stock->quantity;
                    $stock->decrement('quantity', $scrappedQuantityApproved);

                    // تسجيل حركة المنتج (ProductMovement)
                    ProductMovement::create([
                        'product_id' => $productId,
                        'warehouse_id' => $location->warehouse_id,
                        'type' => 'scrap', // نوع الحركة
                        'reference_serial' => $this->generateSerialNumberPM(),
                        'prv_quantity' => $prvQuantity,
                        'note_quantity' => $scrappedQuantityApproved,
                        'after_quantity' => $stock->quantity,
                        'date' => now(),
                        'reference_type' => 'ScrappedMaterial', // الربط بعنصر ScrappedMaterial المحدد
                        'reference_id' => $material->id,
                        'user_id' => $user->id,
                        'notes' => 'إتلاف المنتج ' . $material->product->name . ' من الموقع ' . $location->name,
                    ]);
                }

                // تحديث حالة مذكرة التلف
                $scrapNote->update([
                    'status' => ScrapNote::STATUS_APPROVED,
                    'approved_by' => $user->id, // استخدام معرف المستخدم المصادق عليه
                    'approved_at' => now(),
                ]);
            });

            return $this->successResponse(
                'تمت الموافقة على مذكرة التلف وتنقيص الكميات بنجاح.',
                null // لا توجد بيانات محددة مطلوبة لهذه الرسالة الناجحة
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            // إعادة إطلاق استثناءات التحقق ليتم التعامل معها بواسطة validationErrorResponse
            return $this->validationErrorResponse($e->validator);
        } catch (\Exception $e) {
            return $this->errorResponse(
                message: 'فشل في الموافقة على المذكرة: ' . $e->getMessage(),
                code: 422, // 422 لأخطاء التحقق الخاصة بالتطبيق/المنطق مثل الكمية غير الكافية
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
