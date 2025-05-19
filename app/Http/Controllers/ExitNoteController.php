<?php

namespace App\Http\Controllers;

use App\Models\Custody;
use App\Models\CustodyItem;
use App\Models\EntryNote;
use App\Models\ExitNote;
use App\Models\ExitNoteItem;
use App\Models\MaterialRequest;
use App\Models\Product;
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
            $notes = ExitNote::withCount('items') // هنا نستخدم withCount بدلاً of with
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
                    $warehouseStock = DB::table('warehouse_product')
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
                    DB::table('warehouse_product')
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
            $custody = null; // لتعقب سجل العهدة إذا تم إنشاؤه

            DB::transaction(function () use ($request, &$exitNote, &$custody) {
                // جلب طلب المواد مع العناصر والكميات المعتمدة
                $materialRequest = MaterialRequest::with(['requestedBy', 'items' => function($query) {
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

                    // جلب معلومات المنتج للتحقق من أنه غير قابل للاستهلاك
                    $product = Product::findOrFail($requestItem['product_id']);

                    // إنشاء سجل عهدة إذا كان المنتج غير قابل للاستهلاك ولم يتم إنشاء سجل عهدة بالفعل
                    if (!$product->consumable && !$custody) {
                        $custody = Custody::create([
                            'user_id' => $materialRequest->requestedBy->id, // ربط العهدة بالشخص الذي طلب المواد
                            'date' => now()->toDateString(), // يمكنك استخدام تاريخ سند الإخراج أو تاريخ الإنشاء الحالي
                            'notes' => 'عهدة تلقائية عند إخراج مواد غير مستهلكة لسند الإخراج رقم: ' . $this->generateSerialNumber(), // إضافة ملاحظة مرجعية
                        ]);
                    }
                }

                // إنشاء سند الخروج
                $exitNote = ExitNote::create([
                    'material_request_id' => $request->material_request_id,
                    'created_by' => $request->user()->id,
                    'serial_number' => $this->generateSerialNumber(),
                    'date' => $request->date,
                ]);

                // إضافة عناصر سند الخروج مع تحديث المخزون وإنشاء عناصر العهدة للمواد غير المستهلكة
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

                    // إنشاء عنصر عهدة إذا كان المنتج غير قابل للاستهلاك
                    $product = Product::findOrFail($item['product_id']);
                    if (!$product->consumable && $custody) {
                        CustodyItem::create([
                            'custody_id' => $custody->id,
                            'product_id' => $item['product_id'],
                            'exit_note_id' => $exitNote->id,
                            'quantity' => $item['quantity'],
                            'notes' => $item['notes'] ?? null,
                        ]);
                    }
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
}
