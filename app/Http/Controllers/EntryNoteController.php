<?php

namespace App\Http\Controllers;

use App\Models\EntryNote;
use App\Models\EntryNoteItem;
use App\Models\ExitNote;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductLocation;
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

                ->with([
                    'warehouse',
                    'createdBy',
                    'user',
                    'items.product',  //  تحميل تفاصيل المنتج لكل عنصر إدخال
                    'items.location'  //  تحميل تفاصيل الموقع لكل عنصر إدخال
                ])
                ->get();

            return $this->successResponse($notes, 'تم جلب المذكرات مع تفاصيل الأصناف والمواقع بنجاح');
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
            'items.*.location_id' => 'required|exists:locations,id', //  معرف الموقع
            'items.*.internal_shelf_number' => 'nullable|string|max:255', // الوصف الداخلي للرف
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

                    // 1. جلب المنتج والموقع للتحقق من السعة والوحدة
                    $product = Product::find($item['product_id']);
                    $location = Location::find($item['location_id']);


                    // تحقق من وجود المنتج والموقع
                    if (!$product) {
                        throw new \Exception("المنتج (ID: " . $item['product_id'] . ") غير موجود.");
                    }
                    if (!$location) {
                        throw new \Exception("الموقع (ID: " . $item['location_id'] . ") غير موجود.");
                    }

                    // 2. التحقق من مطابقة المستودع: تأكد أن الموقع ينتمي للمستودع المحدد في الـ item
                    if ($location->warehouse_id != $item['warehouse_id']) {
                        throw new \Exception("الموقع (ID: " . $item['location_id'] . ") لا ينتمي للمستودع المحدد (ID: " . $item['warehouse_id'] . ").");
                    }

                    // 3. التحقق من مطابقة نوع الوحدة بين المنتج والموقع
                    if ($location->capacity_unit_type != $product->unit) {
                        throw new \Exception("لا يمكن تخزين المنتج (وحدته: " . $product->unit . ") في الموقع (وحدته: " . $location->capacity_unit_type . "). يجب أن تتطابق الوحدات.");
                    }

                    // 4. التحقق من السعة المتاحة
                    $currentUsedCapacity = $location->used_capacity_units;
                    $locationCapacity = $location->capacity_units;
                    $quantityToAdd = $item['quantity'];

                    if (($currentUsedCapacity + $quantityToAdd) > $locationCapacity) {
                        $availableCapacity = $locationCapacity - $currentUsedCapacity;
                        throw new \Exception("الموقع '" . $location->name . "' لا يملك سعة كافية. السعة المتاحة هي: " . $availableCapacity . " " . $location->capacity_unit_type . ". الكمية المطلوبة: " . $quantityToAdd . " " . $product->unit . ".");
                    }

                    // 5. تحديث أو إنشاء سجل product_locations
                    $productLocation = ProductLocation::firstOrNew([
                        'product_id' => $item['product_id'],
                        'location_id' => $item['location_id'],
                    ]);

                    $productLocation->quantity += $item['quantity'];
                    $productLocation->internal_shelf_number = $item['internal_shelf_number'] ?? null; // حفظ الوصف الداخلي
                    $productLocation->save();

                    // 6. تحديث السعة المستخدمة للموقع
                    $location->increment('used_capacity_units', $item['quantity']);

                    // 7 تحديث المخزون الإجمالي في المستودع
                    $stock = DB::table('stocks')
                        ->where('product_id', $item['product_id'])
                        ->where('warehouse_id', $item['warehouse_id'])
                        ->first();

                    if (!$stock) {
                        //throw new \Exception("لا يوجد مخزون لهذا المنتج في المستودع المختار.");

                        // إذا لم يكن هناك سجل مخزون للمنتج في المستودع (وهذا لا ينبغي أن يحدث بعد الآن بفضل تابع إنشاء المنتج)
                        // يمكنك إنشاءه هنا بكمية 0 ثم Increment
                        DB::table('stocks')->insert([
                            'product_id' => $item['product_id'],
                            'warehouse_id' => $item['warehouse_id'],
                            'quantity' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    // تحديث المخزون
                    DB::table('stocks')
                        ->where('product_id', $item['product_id'])
                        ->where('warehouse_id', $item['warehouse_id'])
                        ->increment('quantity', $item['quantity']);

                    // 8  إنشاء عنصر مذكرة الدخول
                    EntryNoteItem::create([
                        'entry_note_id' => $entryNote->id,
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $item['warehouse_id'],
                        'location_id' => $item['location_id'],
                        'quantity' => $item['quantity'],
                        'notes' => $item['notes'] ?? null,
                        'created_by' => $request->user()->id
                    ]);

                    // احصل على كمية المخزون بعد التحديث
                    $afterQuantity = DB::table('stocks')
                        ->where('product_id', $item['product_id'])
                        ->where('warehouse_id', $item['warehouse_id'])
                        ->value('quantity');

                    // إنشاء حركة المنتج
                    ProductMovement::create([
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $item['warehouse_id'],
                        'type' => 'entry',
                        'reference_serial' => $entryNote->serial_number,
                        'prv_quantity' => $afterQuantity - $item['quantity'], // قبل التحديث
                        'note_quantity' => $item['quantity'],
                        'after_quantity' => $afterQuantity, // بعد التحديث
                        'date' => $request->date,
                        'reference_type' => 'EntryNote',
                        'reference_id' => $entryNote->id,
                        'user_id' => $request->user()->id,
                        'notes' => $item['notes'] ?? 'إدخال من سند رقم: ' . $serialNumber,
                    ]);

                    //////////////////////

                    // 9. إنشاء حركة المنتج (يبقى كما هو، مع تحديث prv_quantity و after_quantity)
                    // يجب جلب قيمة المخزون الحالية قبل Increment لتكون prv_quantity صحيحة
                    //                    $prevStockQuantity = DB::table('stocks')
                    //                        ->where('product_id', $item['product_id'])
                    //                        ->where('warehouse_id', $item['warehouse_id'])
                    //                        ->value('quantity'); // جلب الكمية بعد التحديث


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
            // تحميل العلاقات المتداخلة:
            // - 'warehouse': المستودع الذي دخلت إليه المذكرة.
            // - 'user': المستخدم الذي أنشأ المذكرة.
            // - 'items.product': تفاصيل المنتج لكل عنصر في المذكرة.
            // - 'items.location': تفاصيل الموقع الذي دخل إليه المنتج لكل عنصر.
            $note = EntryNote::with([
                'warehouse',
                'createdBy',
                'user',
                'items.product',    // <--- جديد: تحميل تفاصيل المنتج لكل عنصر إدخال
                'items.location'    // <--- جديد: تحميل تفاصيل الموقع لكل عنصر إدخال
            ])
                ->findOrFail($id);
            return $this->successResponse($note, 'تم جلب المذكرة بنجاح');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // استخدام notFoundResponse لرسائل 404
            return $this->notFoundResponse('المذكرة غير موجودة.');
        } catch (\Exception $e) {
            return $this->handleExceptionResponse($e);
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
                $folderNumber = floor($noteNumber / 50) + 1;
            }
        }

        return "($folderNumber/$noteNumber)";
    }
}
