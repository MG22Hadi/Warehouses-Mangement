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
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $exitNote = null;
            $custody = null;
            $pendingCustodyItems = [];
            $requesterId = null;
            $locationMessages = [];

            DB::transaction(function () use ($request, &$exitNote, &$custody, &$pendingCustodyItems, &$requesterId, &$locationMessages) {
                $serialNumber = $this->generateSerialNumber();

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
                    throw new \Exception('المستخدم الذي طلب المواد غير موجود أو غير مرتبط بطلب المواد.');
                }

                $requesterId = $materialRequest->requestedBy->id;
                $requestItems = collect($request->items);
                $materialRequestItems = $materialRequest->items;

                // إنشاء سند الخروج
                $exitNote = ExitNote::create([
                    'material_request_id' => $request->material_request_id,
                    'created_by' => $request->user()->id,
                    'serial_number' => $serialNumber,
                    'date' => $request->date,
                ]);

                foreach ($requestItems as $item) {
                    $matchingItem = $materialRequestItems->firstWhere('product_id', $item['product_id']);
                    if (!$matchingItem) {
                        throw new \Exception('المنتج ID ' . $item['product_id'] . ' غير موجود في طلب المواد.');
                    }
                    if ($item['quantity'] > $matchingItem->quantity_approved) {
                        throw new \Exception("الكمية المطلوبة أكبر من الكمية المعتمدة للمنتج ID {$item['product_id']}.");
                    }

                    $product = Product::find($item['product_id']);
                    $warehouseId = $item['warehouse_id'];
                    $quantityToSubtract = $item['quantity'];

                    if (!$product) {
                        throw new \Exception("المنتج ID {$item['product_id']} غير موجود.");
                    }

                    // المخزون العام
                    $stock = Stock::where('warehouse_id', $warehouseId)
                        ->where('product_id', $item['product_id'])
                        ->first();

                    if (!$stock || $stock->quantity < $quantityToSubtract) {
                        throw new \Exception("الكمية المطلوبة غير متوفرة في المخزون العام للمنتج {$product->name}.");
                    }

                    $prvStockQuantity = $stock->quantity;

                    // 🔥 توزيع الكمية على المواقع
                    $productLocations = ProductLocation::where('product_id', $item['product_id'])
                        ->whereHas('location', fn($q) => $q->where('warehouse_id', $warehouseId))
                        ->orderBy('quantity', 'desc')
                        ->get();

                    if ($productLocations->sum('quantity') < $quantityToSubtract) {
                        throw new \Exception("لا توجد كمية كافية من المنتج {$product->name} في المواقع.");
                    }

                    foreach ($productLocations as $pl) {
                        if ($quantityToSubtract <= 0) break;

                        $deduct = min($pl->quantity, $quantityToSubtract);

                        // خصم من ProductLocation
                        $pl->decrement('quantity', $deduct);

                        // خصم من السعة المستخدمة للموقع
                        $pl->location->decrement('used_capacity_units', $deduct);

                        // خصم من المخزون العام
                        $stock->decrement('quantity', $deduct);

                        // رسالة
                        $locationMessages[] = "تم تلبية {$deduct} {$product->unit} من المنتج {$product->name} من الموقع {$pl->location->name}";

                        // حركة المنتج
                        ProductMovement::create([
                            'product_id' => $item['product_id'],
                            'warehouse_id' => $warehouseId,
                            'type' => 'exit',
                            'reference_serial' => $this->generateSerialNumberPM(),
                            'prv_quantity' => $prvStockQuantity,
                            'note_quantity' => $deduct,
                            'after_quantity' => $stock->quantity,
                            'date' => $request->date,
                            'reference_type' => 'ExitNote',
                            'reference_id' => $exitNote->id,
                            'user_id' => $request->user()->id,
                            'notes' => "إخراج من الموقع {$pl->location->name}",
                        ]);

                        $quantityToSubtract -= $deduct;
                    }

                    // إنشاء عنصر سند الخروج
                    ExitNoteItem::create([
                        'exit_note_id' => $exitNote->id,
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $warehouseId,
                        'quantity' => $item['quantity'],
                        'notes' => $item['notes'] ?? null,
                    ]);

                    // عهدة إذا المنتج غير مستهلك
                    if (!$product->consumable) {
                        if (!$custody) {
                            $custody = Custody::create([
                                'user_id' => $requesterId,
                                'date' => $exitNote->date,
                                'notes' => 'عهدة تلقائية من سند الإخراج رقم: ' . $exitNote->serial_number,
                            ]);
                        }
                        $custodyItem = CustodyItem::create([
                            'custody_id' => $custody->id,
                            'product_id' => $item['product_id'],
                            'exit_note_id' => $exitNote->id,
                            'quantity' => $item['quantity'],
                            'notes' => $item['notes'] ?? null,
                            'room_id' => null,
                        ]);
                        $pendingCustodyItems[] = $custodyItem->load('product');
                    }
                }

                $materialRequest->update(['status' => 'delivered']);
            });

            $exitNote = ExitNote::with(['items.product', 'items.warehouse', 'materialRequest'])
                ->find($exitNote->id);

            return $this->successResponse(
                [
                    'exit_note' => $exitNote,
                    'pending_custody_items' => $pendingCustodyItems,
                    'requester_id' => $requesterId,
                    'location_messages' => $locationMessages,
                ],
                'تم إنشاء سند الخروج بنجاح.'
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
