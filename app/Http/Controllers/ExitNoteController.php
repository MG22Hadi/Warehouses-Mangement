<?php

namespace App\Http\Controllers;

use App\Models\Custody;
use App\Models\CustodyItem;
use App\Models\EntryNote;
use App\Models\ExitNote;
use App\Models\ExitNoteItem;
use App\Models\Location;
use App\Models\MaterialRequest;
use App\Models\Product;
use App\Models\ProductLocation;
use App\Models\ProductMovement;
use App\Models\Stock;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use mysql_xdevapi\Exception;

class ExitNoteController extends Controller
{
    use ApiResponse;

    //عرض كل المذكرات
    public function index()
    {
        try {
            // تحميل العلاقات المتداخلة:
            // - 'warehouse': المستودع الذي خرجت منه المذكرة (إذا كانت موجودة).
            // - 'user': المستخدم الذي أنشأ المذكرة (إذا كانت موجودة).
            // - 'items.product': تفاصيل المنتج لكل عنصر في المذكرة.
            // - 'items.location': تفاصيل الموقع الذي خرج منه المنتج لكل عنصر.
            $notes = ExitNote::withCount('items')
                ->with([
                    'warehouse',    //   علاقة المستودع
                    'createdBy',
                    'user',         //   علاقة المستخدم
                    'items.product',  //   تحميل تفاصيل المنتج لكل عنصر إخراج
                    'items.location'  //   تحميل تفاصيل الموقع لكل عنصر إخراج
                ])
                ->get();

            return $this->successResponse($notes, 'تم جلب المذكرات مع عدد الأصناف بنجاح');
        } catch (\Exception $e) {
            return $this->handleExceptionResponse($e);
        }
    }

    /**
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'material_request_id' => 'required|exists:material_requests,id',
            'date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.warehouse_id' => 'required|exists:warehouses,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $exitNote = null;

            DB::transaction(function () use ($request, &$exitNote) {
                // جلب طلب المواد مع العناصر والكميات المعتمدة
                $materialRequest = MaterialRequest::with(['items' => function($query) {
                    $query->where('quantity_approved', '>', 0);
                }])->findOrFail($request->material_request_id);

                if ($materialRequest->status != 'approved') {
                    throw new \Exception('لا يمكن إنشاء سند خروج لطلب مواد غير معتمد');
                }

                // التحقق من أن جميع العناصر المراد إخراجها موجودة في طلب المواد
                $requestItems = collect($request->items);
                $materialRequestItems = $materialRequest->items;

                foreach ($requestItems as $requestItem) {
                    $matchingItem = $materialRequestItems->firstWhere('product_id', $requestItem['product_id']);

                    if (!$matchingItem) {
                        throw new \Exception('المنتج المحدد غير موجود في طلب المواد المعتمد');
                    }

                    if ($requestItem['quantity'] > $matchingItem->quantity_approved) {
                        throw new \Exception("الكمية المطلوبة ({$requestItem['quantity']}) للمنتج ID {$requestItem['product_id']} أكبر من الكمية المعتمدة ({$matchingItem->quantity_approved})");
                    }

                    // التحقق من توفر الكمية في المستودع المحدد
                    $warehouseStock = DB::table('stocks')
                        ->where('warehouse_id', $requestItem['warehouse_id'])
                        ->where('product_id', $requestItem['product_id'])
                        ->first();

                    if (!$warehouseStock || $warehouseStock->quantity < $requestItem['quantity']) {
                        throw new \Exception("الكمية غير متوفرة في المستودع للمنتج ID {$requestItem['product_id']}");
                    }
                }

                // إنشاء سند الخروج
                $exitNote = ExitNote::create([
                    'material_request_id' => $request->material_request_id,
                    'created_by' => $request->user()->id,
                    'serial_number' => $this->generateSerialNumber(),
                    'date' => $request->date,
                ]);

                // إضافة عناصر سند الخروج مع تحديث المخزون
                foreach ($requestItems as $item) {
                    ExitNoteItem::create([
                        'exit_note_id' => $exitNote->id,
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $item['warehouse_id'],
                        'quantity' => $item['quantity'],
                        'notes' => $item['notes'] ?? null,
                    ]);

                    // تحديث كمية المخزون في المستودع
                    DB::table('stocks')
                        ->where('warehouse_id', $item['warehouse_id'])
                        ->where('product_id', $item['product_id'])
                        ->decrement('quantity', $item['quantity']);
                }

                $materialRequest->update(['status' => 'delivered']);
            });

            // إعادة تحميل النموذج مع العلاقات
            $exitNote = ExitNote::with([
                'items.product',
                'items.warehouse',
                'materialRequest'
            ])->find($exitNote->id);

            return $this->successResponse(
                $exitNote,
                'تم إنشاء سند الخروج بنجاح'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                message: 'فشل في إنشاء سند الخروج: ' . $e->getMessage(),
                code: 422,
                internalCode: 'EXIT_NOTE_CREATION_FAILED'
            );
        }
    }
     **/

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'material_request_id' => 'required|exists:material_requests,id',
            'date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.warehouse_id' => 'required|exists:warehouses,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.notes' => 'nullable|string|max:500',
            'items.*.location_id' => 'required|exists:locations,id', // <--- جديد: يجب تحديد الموقع
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $exitNote = null;
            $custody = null;
            $pendingCustodyItems = [];
            $requesterId = null;

            DB::transaction(function () use ($request, &$exitNote, &$custody, &$pendingCustodyItems, &$requesterId) {
                $serialNumber = $this->generateSerialNumber();
                // $pmSerialNumber -- تم نقلها داخل الحلقة

                $materialRequest = MaterialRequest::with([
                    'requestedBy',
                    'items' => function ($query) {
                        $query->where('quantity_approved', '>', 0);
                    }
                ])->findOrFail($request->material_request_id);

                if ($materialRequest->status != 'approved') {
                    throw new \Exception('لا يمكن إنشاء سند خروج لطلب مواد غير معتمد.');
                }

                if (!$materialRequest->requestedBy) {
                    throw new \Exception('المستخدم الذي طلب المواد غير موجود أو غير مرتبط بطلب المواد. يرجى التحقق من تكامل البيانات.');
                }

                $requesterId = $materialRequest->requestedBy->id;

                $requestItems = collect($request->items);
                $materialRequestItems = $materialRequest->items;

                // إنشاء سند الخروج أولاً
                $exitNote = ExitNote::create([
                    'material_request_id' => $request->material_request_id,
                    'created_by' => $request->user()->id,
                    'serial_number' => $serialNumber,
                    'date' => $request->date,
                ]);

                foreach ($requestItems as $item) {
                    $matchingItem = $materialRequestItems->firstWhere('product_id', $item['product_id']);

                    if (!$matchingItem) {
                        throw new \Exception('المنتج ID ' . $item['product_id'] . ' المحدد غير موجود في طلب المواد المعتمد.');
                    }

                    if ($item['quantity'] > $matchingItem->quantity_approved) {
                        throw new \Exception("الكمية المطلوبة ({$item['quantity']}) للمنتج ID {$item['product_id']} أكبر من الكمية المعتمدة ({$matchingItem->quantity_approved}).");
                    }

                    // 1. جلب المنتج والموقع
                    $product = Product::find($item['product_id']);
                    $location = Location::find($item['location_id']);
                    $warehouseId = $item['warehouse_id'];
                    $quantityToSubtract = $item['quantity'];

                    // التحقق من وجود المنتج والموقع
                    if (!$product) {
                        throw new \Exception("المنتج (ID: " . $item['product_id'] . ") غير موجود.");
                    }
                    if (!$location) {
                        throw new \Exception("الموقع (ID: " . $item['location_id'] . ") غير موجود.");
                    }

                    // 2. التحقق من مطابقة المستودع: تأكد أن الموقع ينتمي للمستودع المحدد في الـ item
                    if ($location->warehouse_id != $warehouseId) {
                        throw new \Exception("الموقع (ID: " . $item['location_id'] . ") لا ينتمي للمستودع المحدد (ID: " . $warehouseId . ").");
                    }

                    // 3. التحقق من مطابقة نوع الوحدة بين المنتج والموقع
                    if ($location->capacity_unit_type != $product->unit) {
                        throw new \Exception("لا يمكن سحب المنتج (وحدته: " . $product->unit . ") من الموقع (وحدته: " . $location->capacity_unit_type . "). يجب أن تتطابق الوحدات.");
                    }

                    // 4. التحقق من الكمية المتوفرة في ProductLocation
                    $productLocation = ProductLocation::where('product_id', $item['product_id'])
                        ->where('location_id', $item['location_id'])
                        ->first();

                    if (!$productLocation || $productLocation->quantity < $quantityToSubtract) {
                        throw new \Exception("الكمية المطلوبة ({$quantityToSubtract} {$product->unit}) للمنتج '{$product->name}' غير متوفرة في الموقع '{$location->name}'. الكمية المتاحة: " . ($productLocation ? $productLocation->quantity : 0) . " {$product->unit}.");
                    }

                    // 5. تحديث كمية المنتج في الموقع (ProductLocation)
                    $productLocation->decrement('quantity', $quantityToSubtract);

                    // 6. تحديث السعة المستخدمة للموقع (Location)
                    $location->decrement('used_capacity_units', $quantityToSubtract);

                    // 7. تحديث كمية المخزون الإجمالي (Stock)
                    $stock = Stock::where('warehouse_id', $warehouseId)
                        ->where('product_id', $item['product_id'])
                        ->first();

                    if (!$stock || $stock->quantity < $quantityToSubtract) {
                        // هذا الشرط يجب أن يكون قد تم التقاطه بواسطة التحقق من productLocation
                        // ولكن للموثوقية يمكن الاحتفاظ به أو إزالته إذا كنا نثق بـ productLocation تمامًا
                        throw new \Exception("الكمية غير متوفرة في المخزون العام للمستودع للمنتج ID {$item['product_id']}.");
                    }

                    $prvStockQuantity = $stock->quantity; // الكمية قبل الخصم من المخزون العام
                    $stock->decrement('quantity', $quantityToSubtract);
                    $afterStockQuantity = $stock->quantity; // الكمية بعد الخصم من المخزون العام

                    // 8. إنشاء عنصر سند الخروج
                    ExitNoteItem::create([
                        'exit_note_id' => $exitNote->id,
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $warehouseId,
                        'quantity' => $item['quantity'],
                        'notes' => $item['notes'] ?? null,
                        // لا يوجد location_id هنا، لأنها تسجل عملية الخروج ككل
                    ]);

                    // 9. إنشاء عهدة للمواد غير المستهلكة (كما في الكود الأصلي)
                    if (!$product->consumable) {
                        if (!$custody) {
                            $custody = Custody::create([
                                'user_id' => $requesterId,
                                'date' => $exitNote->date,
                                'notes' => 'عهدة تلقائية للمواد غير المستهلكة من سند الإخراج رقم: ' . $exitNote->serial_number,
                            ]);
                        }

                        $custodyItem = CustodyItem::create([
                            'custody_id' => $custody->id,
                            'product_id' => $item['product_id'],
                            'exit_note_id' => $exitNote->id,
                            'quantity' => $item['quantity'],
                            'notes' => $item['notes'] ?? null,
                            'room_id' => null, // يمكن تحديثها لاحقاً بواسطة route assignRoomsToCustodyItems
                        ]);
                        $pendingCustodyItems[] = $custodyItem->load('product');
                    }

                    // 10. إنشاء حركة المنتج
                    $pmSerialNumber = $this->generateSerialNumberPM(); // <--- توليد رقم تسلسلي فريد هنا لكل حركة

                    ProductMovement::create([
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $warehouseId,
                        'type' => 'exit',
                        'reference_serial' => $pmSerialNumber, // فريد لكل حركة
                        'prv_quantity' => $prvStockQuantity, // الكمية قبل الخصم من المخزون العام
                        'note_quantity' => $item['quantity'],
                        'after_quantity' => $afterStockQuantity, // الكمية بعد الخصم من المخزون العام
                        'date' => $request->date,
                        'reference_type' => 'ExitNote',
                        'reference_id' => $exitNote->id,
                        'user_id' => $request->user()->id,
                        'notes' => $item['notes'] ?? 'إخراج من سند رقم: ' . $serialNumber,
                    ]);
                }

                $materialRequest->update(['status' => 'delivered']);
            });

            // إعادة تحميل سند الإخراج بعد انتهاء الـ transaction
            $exitNote = ExitNote::with([
                'items.product',
                'items.warehouse',
                'materialRequest'
            ])->find($exitNote->id);

            // الاستجابة النهائية
            return $this->successResponse(
                [
                    'exit_note' => $exitNote,
                    'pending_custody_items' => $pendingCustodyItems,
                    'requester_id' => $requesterId
                ],
                'تم إنشاء سند الخروج بنجاح. يرجى تحديد الغرف للمواد غير المستهلكة.'
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->errorResponse(
                message: 'فشل في إنشاء سند الخروج: ' . $e->getMessage(),
                code: 422,
                internalCode: 'EXIT_NOTE_CREATION_FAILED'
            );
        }
    }

    // إظهار مذكرة محددة
    public function show($id)
    {
        try {
            // تحميل العلاقات المتداخلة:
            // - 'warehouse': المستودع الذي خرجت منه المذكرة.
            // - 'user': المستخدم الذي أنشأ المذكرة.
            // - 'items.product': تفاصيل المنتج لكل عنصر في المذكرة.
            // - 'items.location': تفاصيل الموقع الذي خرج منه المنتج لكل عنصر.
            $note = ExitNote::with([
                'warehouse',    // <--- إضافة: تحميل المستودع
                'createdBy',
                'user',         // <--- إضافة: تحميل المستخدم
                'items.product',    // <--- جديد: تحميل تفاصيل المنتج لكل عنصر إخراج
                'items.location'    // <--- جديد: تحميل تفاصيل الموقع لكل عنصر إخراج
            ])
                ->findOrFail($id);
            return $this->successResponse($note, 'تم جلب المذكرة بنجاح');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // استخدام notFoundResponse لرسائل 404
            return $this->notFoundResponse('سند الإخراج غير موجود.');
        } catch (\Exception $e) {
            return $this->handleExceptionResponse($e);
        }
    }

    //لتوليد السيريال نمبر
    private function generateSerialNumber(): string
    {
        $currentYear = date('Y');

        // الحصول على آخر مذكرة لهذه السنة
        $lastEntry = ExitNote::whereYear('created_at', $currentYear)
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
