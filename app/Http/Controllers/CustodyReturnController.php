<?php

namespace App\Http\Controllers;

use App\Models\CustodyItem;
use App\Models\CustodyReturn;
use App\Models\CustodyReturnItem;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductLocation;
use App\Models\ProductMovement;
use App\Models\Stock;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CustodyReturnController extends Controller
{
    use ApiResponse;

    public function createReturnRequest(Request $request)
    {
        $user = Auth::user();
// ماله داعي
//        if (!$user) {
//            return $this->unauthorizedResponse('الرجاء تسجيل الدخول.');
//        }

        $validator = Validator::make($request->all(), [
            'return_date' => 'required|date|before_or_equal:today',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.custody_item_id' => [
                'required',
                'exists:custody_items,id', // التأكد من أن عنصر العهدة موجود
                // التحقق من أن العنصر ليس مستهلكاً وأن العنصر يخص المستخدم المصادق عليه
                function ($attribute, $value, $fail) use ($user) {
                    $custodyItem = CustodyItem::with('product', 'custody') // جلب product و custody للعلاقات
                    ->find($value);

                    if (!$custodyItem) {
                        $fail('عنصر العهدة غير موجود.');
                        return;
                    }

                    // التحقق من أن عنصر العهدة يخص المستخدم المصادق عليه
                    if ($custodyItem->custody->user_id !== $user->id) {
                        $fail("عنصر العهدة (ID: {$custodyItem->id}) لا يخص المستخدم الحالي.");
                        return; // توقف هنا إذا لم يكن يخص المستخدم
                    }

                    // التحقق من أن العنصر ليس مستهلكاً
                    if ($custodyItem->product->consumable) {
                        $fail("لا يمكن إرجاع المنتج المستهلك ({$custodyItem->product->name}).");
                        return; // توقف هنا إذا كان مستهلكاً
                    }

                    //  التحقق من أن هذا CustodyItem ليس قيد المراجعة حالياً في طلب إرجاع آخر
                    $existsInPendingReturn = CustodyReturnItem::where('custody_item_id', $value)
                        ->whereHas('custodyReturn', function ($q) {
                            $q->where('status', 'pending');
                        })
                        ->where('warehouse_manager_status', 'pending_review') // فقط إذا كان العنصر نفسه قيد المراجعة
                        ->exists();

                    if ($existsInPendingReturn) {
                        $fail("{$custodyItem->product->name} قيد الإرجاع حالياً في طلب آخر بانتظار المراجعة.");
                        return;
                    }
                },
            ],
            'items.*.returned_quantity' => [
                'required',
                'numeric',
                'min:0.01',
                // التحقق من أن الكمية المرتجعة لا تتجاوز الكمية الأصلية المتبقية
                function ($attribute, $value, $fail) use ($request, $user) {
                    $index = explode('.', $attribute)[1];
                    $custodyItemId = $request->input("items.{$index}.custody_item_id");


                    $custodyItem = CustodyItem::find($custodyItemId);

                    if (!$custodyItem) {
                        // هذا الشرط يجب ألا يحدث أبداً إذا كانت التحققات السابقة تعمل بشكل صحيح
                        $fail('عنصر العهدة غير موجود.');
                        return;
                    }

                    // حساب الكمية المرتجعة سابقاً (وتم قبولها)
                    $previouslyReturnedQuantity = CustodyReturnItem::where('custody_item_id', $custodyItemId)
                        ->where('warehouse_manager_status', 'accepted') // فقط الكميات التي وافق عليها أمين المستودع
                        ->sum('returned_quantity');

                    $availableQuantity = $custodyItem->quantity - $previouslyReturnedQuantity;

                    if ($value > $availableQuantity) {
                        $fail("الكمية المرتجعة لـ {$custodyItem->product->name} (ID: {$custodyItem->id}) لا يمكن أن تتجاوز الكمية المتاحة للإرجاع ({$availableQuantity}).");
                    }
                },
            ],
            'items.*.warehouse_id' => 'required|exists:warehouses,id',
            'items.*.user_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        DB::beginTransaction();

        try {
            // إنشاء طلب الإرجاع الرئيسي
            $custodyReturn = CustodyReturn::create([
                'user_id' => $user->id,
                'return_date' => $request->return_date,
                'notes' => $request->notes,
                'status' => 'pending', // الحالة الأولية لطلب الإرجاع
            ]);

            $returnItemsToInsert = [];
            foreach ($request->items as $item) {
                // جلب CustodyItem هنا ليس فقط للتحقق، بل للحصول على بياناته مثل product
                $custodyItem = CustodyItem::find($item['custody_item_id']);

                $returnItemsToInsert[] = [
                    'custody_return_id' => $custodyReturn->id,
                    'custody_item_id' => $item['custody_item_id'],
                    'returned_quantity' => $item['returned_quantity'],
                    'warehouse_id' => $item['warehouse_id'],
                    'user_notes' => $item['user_notes'] ?? null,
                    'warehouse_manager_status' => 'pending_review', // حالة أولية لعنصر الإرجاع
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            CustodyReturnItem::insert($returnItemsToInsert); // إدخال جماعي للعناصر

            DB::commit();

            // تحميل العلاقات للاستجابة
            $custodyReturn->load('items.custodyItem.product', 'items.warehouse', 'user');

            return $this->successResponse($custodyReturn,
                'تم إنشاء طلب إرجاع العهدة بنجاح. بانتظار مراجعة أمين المستودع.',

            );

        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->errorResponse(
                'فشل في إنشاء طلب إرجاع العهدة: ' . $e->getMessage(),
                422
            );
        }

    }

    public function processCustodyReturnItem(Request $request,int $custodyReturnItemId)
    {
        $user= Auth::user();

        // 2. جلب عنصر طلب الإرجاع
        $custodyReturnItem =CustodyReturnItem::with('custodyReturn','custodyItem.product','warehouse')
            ->find($custodyReturnItemId);

        if(!$custodyReturnItem){
            return $this->notFoundResponse('عنصر طلب الإرجاع غير موجود');
        }

        // التحقق من أن العنصر لم تتم معالجته مسبقاً
        if($custodyReturnItem->warehouse_manager_status !=='pending_review'){
            return $this->errorResponse('هذا العنصر تمت معالجته مسبقاً',400);
        }

        // 3. التحقق من صحة المدخلات من أمين المستودع
        $validator=Validator::make($request->all(),[
            'warehouse_manager_status'=>[
                'required',
                Rule::in(['accepted','rejected','damaged','total_loss']), // الحالات المسموحة
            ],
            'warehouse_manager_notes'=>'nullable|string|max:1000',
            // الكمية المقبولة: مطلوبة فقط إذا كانت الحالة 'accepted'، ولا يمكن أن تتجاوز الكمية الأصلية المطلوبة للإرجاع
            'returned_quantity_accepted'=>[
                Rule::requiredIf($request->input('warehouse_manager_status') ==='accepted'),
                'numeric',
                'min:0',
                'max:' .$custodyReturnItem->returned_quantity,
            ],
            // التحقق من معرف الموقع
            'location_id'=>[
                Rule::requiredIf($request->input('warehouse_manager_status')==='accepted'),
                'exists:locations,id',
            ],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        DB::beginTransaction();

        try{
            $newStatus= $request->input('warehouse_manager_status');
            $notes = $request->input('warehouse_manager_notes');
            $acceptedQuantity = $request->input('returned_quantity_accepted', 0); // الافتراضي 0 إذا لم يتم تحديده

            // 4. تحديث حالة عنصر الإرجاع
            $custodyReturnItem->warehouse_manager_status = $newStatus;
            $custodyReturnItem->warehouse_manager_notes = $notes;
            $custodyReturnItem->returned_quantity_accepted = $acceptedQuantity; // حفظ الكمية المقبولة
            // حفظ الـ location_id في CustodyReturnItem إذا تم قبوله
            $custodyReturnItem->location_id=$request->input('location_id',null);
            $custodyReturnItem->save();

            // تحديث المخزون إذا كانت الحالة 'accepted'
            if($newStatus ==='accepted' && $acceptedQuantity > 0){
                // جلب Product ID و Warehouse ID من CustodyReturnItem
                $productId=$custodyReturnItem->custodyItem->product_id;
                $warehouseId=$custodyReturnItem->warehouse_id;
                $locationId=$request->input('location_id');// الموقع الذي اختاره أمين المستودع





                // <--- جديد: جلب المنتج والموقع للتحقق من السعة والوحدة
                $product = Product::find($productId);
                $location = Location::find($locationId);

                // التحقق من وجود المنتج والموقع (قد لا يكون ضرورياً جداً إذا كانت rules Validation قوية)
                if (!$product) {
                    throw new \Exception("المنتج (ID: " . $productId . ") غير موجود.");
                }
                if (!$location) {
                    throw new \Exception("الموقع (ID: " . $locationId . ") غير موجود.");
                }

                // التحقق من مطابقة المستودع: تأكد أن الموقع ينتمي للمستودع المحدد في الـ CustodyReturnItem
                if ($location->warehouse_id != $warehouseId) {
                    throw new \Exception("الموقع (ID: " . $locationId . ") لا ينتمي للمستودع المحدد (ID: " . $warehouseId . ").");
                }

                // التحقق من مطابقة نوع الوحدة بين المنتج والموقع
                if ($location->capacity_unit_type != $product->unit) {
                    throw new \Exception("لا يمكن إرجاع المنتج (وحدته: " . $product->unit . ") إلى الموقع (وحدته: " . $location->capacity_unit_type . "). يجب أن تتطابق الوحدات.");
                }

                // التحقق من السعة المتاحة في الموقع
                $currentUsedCapacity = $location->used_capacity_units;
                $locationCapacity = $location->capacity_units;
                $quantityToReturn = $acceptedQuantity;

                if (($currentUsedCapacity + $quantityToReturn) > $locationCapacity) {
                    $availableCapacity = $locationCapacity - $currentUsedCapacity;
                    throw new \Exception("الموقع '" . $location->name . "' لا يملك سعة كافية لاستيعاب الكمية المرتجعة. السعة المتاحة هي: " . $availableCapacity . " " . $location->capacity_unit_type . ". الكمية المراد إرجاعها: " . $quantityToReturn . " " . $product->unit . ".");
                }

                // <--- جديد: تحديث أو إنشاء سجل product_locations
                $productLocation = ProductLocation::firstOrNew([
                    'product_id' => $productId,
                    'location_id' => $locationId,
                ]);
                $productLocation->quantity += $quantityToReturn;
                // عند الإرجاع، لا نعرف بالضرورة internal_shelf_number
                // يمكن تركها null أو تحديثها إذا كانت الواجهة تسمح بذلك
                $productLocation->save();

                // <--- جديد: تحديث السعة المستخدمة للموقع
                $location->increment('used_capacity_units', $quantityToReturn);


                // 5. تحديث المخزون الإجمالي (Stocks)
                $stock = Stock::firstOrCreate(
                    ['product_id' => $productId, 'warehouse_id' => $warehouseId],
                    ['quantity' => 0]
                );

                // جلب الكمية السابقة قبل التحديث لـ ProductMovement
                $prvQuantity = $stock->quantity;

                // زيادة الكمية في المخزون
                $stock->increment('quantity', $acceptedQuantity);


                // 6. إنشاء حركة المنتج (ProductMovement) لإرجاع العهدة
                ProductMovement::create([
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'type' => 'custody_return', // نوع جديد للحركة
                    'reference_serial' => $this->generateSerialNumberPM(),
                    'prv_quantity' => $prvQuantity, // الكمية في Stocks قبل الإضافة
                    'note_quantity' => $acceptedQuantity, // الكمية المضافة
                    'after_quantity' => $stock->quantity, // الكمية في Stocks بعد الإضافة
                    'date' => now(), // تاريخ الحركة
                    'reference_type' => 'CustodyReturnItem', // يمكن ربطها بعنصر إرجاع العهدة
                    'reference_id' => $custodyReturnItem->id,
                    'user_id' => $user->id,
                    'notes' => 'إرجاع عهدة للمنتج ' . $product->name . ' إلى الموقع ' . $location->name,
                ]);
            }

            // تحديث حالة طلب الإرجاع الرئيسي (CustodyReturn) (يبقى كما هو)
            $custodyReturn = $custodyReturnItem->custodyReturn;
            $allReturnItems = $custodyReturn->items;

            $allProcessed = $allReturnItems->every(function ($item) {
                return $item->warehouse_manager_status !== 'pending_review';
            });

            if ($allProcessed) {
                $hasRejectedOrDamaged = $allReturnItems->some(function ($item) {
                    return in_array($item->warehouse_manager_status, ['rejected', 'damaged', 'total_loss']);
                });

                if ($hasRejectedOrDamaged) {
                    $custodyReturn->status = 'partially_completed';
                } else {
                    $custodyReturn->status = 'completed';
                }
                $custodyReturn->processed_by_warehouse_keeper_id = $user->id;
                $custodyReturn->processed_at = now();
                $custodyReturn->save();
            }

            DB::commit();

            return $this->successResponse(
                'تمت معالجة عنصر الإرجاع بنجاح.', // الرسالة أولاً
                $custodyReturnItem->load('custodyReturn', 'custodyItem.product', 'warehouse', 'location') // تحميل علاقة الموقع الجديد
            );



               /**
                // البحث عن سجل المخزون أو إنشائه إذا لم يكن موجوداً
                $stock=Stock::firstOrCreate(
                    ['product_id'=>$productId,'warehouse_id'=>$warehouseId],
                    ['quantity'=> 0]
                );

                // زيادة الكمية في المخزون
                $stock->increment('quantity',$acceptedQuantity);
            }

            //تحديث حالة طلب الإرجاع الرئيسي (CustodyReturn)
            $custodyReturn = $custodyReturnItem->custodyReturn;

            // جلب جميع عناصر هذا الطلب (بما في ذلك العنصر الذي تمت معالجته للتو)
            $allReturnItems= $custodyReturn->items;

            // التحقق مما إذا كانت جميع العناصر قد تمت مراجعتها (حالتها ليست 'pending_review')
            $allProcessed= $allReturnItems->every(function ($item){
                return $item->warehouse_manager_status !== 'pending_review';
            });

            if($allProcessed){
                // إذا تمت معالجة كل شيء، تحقق مما إذا كان هناك أي عناصر مرفوضة أو تالفة
                $hasRejectedOrDamaged = $allReturnItems->some(function ($item){
                    return in_array($item->warehouse_manager_status,['rejected','damaged','total_loss']);
                });

                if($hasRejectedOrDamaged){
                    $custodyReturn->status= 'partially_completed';// تمت معالجة جزئية
                } else {
                    $custodyReturn->status = 'completed';// تمت معالجة كاملة وكل شي مقبول
                }
                $custodyReturn->processed_by_warehouse_keeper_id = $user->id; // تعيين أمين المستودع الذي قام بالمعالجة
                $custodyReturn->processed_at=now();// تسجيل وقت المعالجة
                $custodyReturn->save();
            }
            DB::commit();
            return $this->successResponse(
                $custodyReturnItem->load('custodyReturn','custodyItem.product','warehouse'),// تحميل العلاقات للاستجابة
                'تمت معالجة عنصر الإرجاع بنجاح'
            );
                * */
        } catch (\Throwable $e){
            DB::rollBack();
            return $this->errorResponse(
                'فشل في معالجة عنصر الإرجاع: ' . $e->getMessage(),
                500 // 500 لخطأ داخلي في الخادم
            );
        }
    }

    public function index(Request $request)
    {
        $user = Auth::user();

        // يمكن إضافة بحث وتصفية هنا لاحقاً
        //$perPage = $request->input('per_page', 10); // عدد العناصر في كل صفحة

        $custodyReturns = CustodyReturn::with([
            'user', // المستخدم الذي قدم الطلب
            'items.custodyItem.product', // تفاصيل المنتج الأصلي
            'items.warehouse', // المستودع الذي تم الإرجاع إليه
            'items.location', //  تحميل تفاصيل الموقع الذي تم الإرجاع إليه
        ])
            ->latest() // ترتيب حسب الأحدث
            //->paginate($perPage)
            ->get();

        // معالجة حالة عدم وجود طلبات
        if ($custodyReturns->isEmpty()) {
            return $this->successResponse([],'لا توجد طلبات إرجاع حالياً.'); // إرجاع مصفوفة فارغة
        }
        return $this->successResponse($custodyReturns,'تم استرجاع طلبات الإرجاع بنجاح.' );
    }

    public function show(int $id)
    {
        $user = Auth::user();


        $custodyReturn = CustodyReturn::with([
            'user',
            'items.custodyItem.product',
            'items.warehouse',
            'items.location', //  تحميل تفاصيل الموقع الذي تم الإرجاع إليه
        ])->find($id);

        if (!$custodyReturn) {
            return $this->notFoundResponse('طلب الإرجاع غير موجود.');
        }

//        // التحقق من الصلاحية: هل هو صاحب الطلب أو أمين مستودع/مدير؟
//        if ($custodyReturn->user_id !== $user->id && !($user->is_warehouse_keeper ?? false)) { // عدّل الشرط ليناسب نظام صلاحياتك
//            return $this->forbiddenResponse('ليس لديك الصلاحية لعرض طلب الإرجاع هذا.');
//        }

        return $this->successResponse($custodyReturn,'تم استرجاع طلب الإرجاع بنجاح.');
    }

    public function myReturnRequests(Request $request)
    {
        $user = Auth::user();


        $myCustodyReturns = CustodyReturn::where('user_id', $user->id)
            ->with([
                // لا نحتاج لتحميل 'user' هنا لأنه هو نفسه المستخدم الحالي
                'items.custodyItem.product',
                'items.warehouse',
            ])
            ->latest()
            ->get();

        // معالجة حالة عدم وجود طلبات
        if ($myCustodyReturns->isEmpty()) {
            return $this->successResponse([],'لا توجد طلبات إرجاع خاصة بك حالياً.'); // إرجاع مصفوفة فارغة
        }

        return $this->successResponse($myCustodyReturns,'تم استرجاع طلبات الإرجاع الخاصة بك بنجاح.');
    }

    public function pendingReturnRequests(Request $request)
    {
        $user = Auth::user();


        // نبحث عن CustodyReturnItem التي حالتها 'pending_review'
        // ثم نحمل الـ CustodyReturn الرئيسي المرتبط بها والمستخدم.
        $pendingItems = CustodyReturnItem::where('warehouse_manager_status', 'pending_review')
            ->with([
                'custodyReturn.user', // طلب الإرجاع الرئيسي والمستخدم الذي قدمه
                'custodyItem.product', // تفاصيل المنتج الأصلي للعهدة
                'warehouse', // المستودع الذي من المفترض أن تعود إليه
            ])
            ->latest() // ترتيب حسب الأحدث
            ->get();

        // معالجة حالة عدم وجود طلبات
        if ($pendingItems->isEmpty()) {
            return $this->successResponse([],'لا توجد طلبات إرجاع معلقة حالياً.'); // إرجاع مصفوفة فارغة
        }

        return $this->successResponse($pendingItems,'تم استرجاع طلبات الإرجاع المعلقة بنجاح.');
    }


//
//    //لتوليد السيريال نمبر
//    private function generateSerialNumber(): string
//    {
//        $currentYear = date('Y');
//
//        // الحصول على آخر مذكرة لهذه السنة
//        $lastEntry = ExitNote::whereYear('created_at', $currentYear)
//            ->orderBy('id', 'desc')
//            ->first();
//
//        // تحديد الأرقام الجديدة
//        if (!$lastEntry) {
//            // أول مذكرة في السنة
//            $folderNumber = 1;
//            $noteNumber = 1;
//        } else {
//            // فك الترميز من السيريال السابق
//            $serial = trim($lastEntry->serial_number, '()');
//            list($lastFolderNumber, $lastNoteNumber) = explode('/', $serial);
//
//            $lastFolderNumber = (int)$lastFolderNumber;
//            $lastNoteNumber = (int)$lastNoteNumber;
//
//            // حساب الأرقام الجديدة
//            $noteNumber = $lastNoteNumber + 1;
//            $folderNumber = $lastFolderNumber;
//
//            if ($noteNumber % 50 == 1 && $noteNumber > 50) {
//                $folderNumber = floor($noteNumber / 50) + 1;
//            }
//        }
//
//        return "($folderNumber/$noteNumber)";
//    }

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

        return "($folderNumber/$noteNumber)";}


}







//        $user = Auth::user();
//
//        $validator = Validator::make($request->all(), [
//            'return_date' => 'required|date|before_or_equal:today',
//            'notes' => 'nullable|string|max:1000',
//            'items' => 'required|array|min:1',
//            'items.*.custody_item_id' => ['required', 'exists:custody_items,id',
//
//                /**
//                 * // التأكد من أن عنصر العهدة هذا يخص المستخدم المصادق عليه
//                 * Rule::exists('custody_items', 'id')->where(function ($query) use ($user) {
//                 * $query->whereHas('custody', function ($q) use ($user) {
//                 * $q->where('user_id', $user->id);
//                 * });
//                 * }),**/
//                // التأكد من أن حالة العنصر قابلة للإرجاع (accepted/pending_acceptance)
//                ///////////////////////////Rule::in(CustodyItem::where('id', request()->input('items.*.custody_item_id'))->pluck('status')->toArray()),
//                // التأكد من أن العنصر ليس مستهلك
//                // Rule::exists('products', 'id')->where(function ($query) {
//                //     $query->where('consumable', false); // هذا يعتمد على Product ID للعنصر
//                // }) // هذا التحقق يحتاج لتفكير أعمق لربطه بالـ custody_item_id ثم الـ product_id
//
//
//            ],
//            'items.*.returned_quantity' => ['required', 'numeric', 'min:0.01',
//
//
//                // التحقق من أن الكمية المرتجعة لا تتجاوز الكمية الأصلية المسندة في العهدة
//                function ($attribute, $value, $fail) use ($request, $user) {
//                    $index = explode('.', $attribute)[1]; // Get the index of the current item
//                    $custodyItemId = $request->input("items.{$index}.custody_item_id");
//                    $custodyItem = CustodyItem::where('id', $custodyItemId)
//                        ->whereHas('custody', function ($q) use ($user) {
//                            $q->where('user_id', $user->id);
//                        })
//                        ->first();
//
//                    if ($custodyItem && $value > $custodyItem->quantity) {
//                        $fail("الكمية المرتجعة لـ {$custodyItem->product->name} (ID: {$custodyItem->id}) لا يمكن أن تتجاوز الكمية المسندة ({$custodyItem->quantity}).");
//                    }
//                },
//            ],
//
//
//            'items.*.warehouse_id' => 'required|exists:warehouses,id',
//            'items.*.user_notes' => 'nullable|string|max:500',
//        ]);
//
//        if ($validator->fails()) {
//            return $this->validationErrorResponse($validator);
//        }
//
//        DB::beginTransaction();
//
//        try {
//            // إنشاء طلب الإرجاع الرئيسي
//            $custodyReturn = CustodyReturn::create([
//                'user_id' => $user->id,
//                'return_date' => $request->return_date,
//                'notes' => $request->notes,
//                'status' => 'pending', // الحالة الأولية لطلب الإرجاع
//            ]);
//
//            $returnItemsData = [];
//            foreach ($request->items as $item) {
//                // يمكنك جلب Product هنا للتأكد من أنه غير مستهلك
//                $custodyItem = CustodyItem::with('product')->find($item['custody_item_id']);
//                if ($custodyItem->product->consumable) {
//                    throw new \Exception("لا يمكن إرجاع المنتج المستهلك ({$custodyItem->product->name}).");
//                }
//
//                $returnItemsData[] = [
//                    'custody_return_id' => $custodyReturn->id,
//                    'custody_item_id' => $item['custody_item_id'],
//                    'returned_quantity' => $item['returned_quantity'],
//                    'warehouse_id' => $item['warehouse_id'],
//                    'user_notes' => $item['user_notes'] ?? null,
//                    'warehouse_manager_status' => 'pending_review', // حالة أولية لعنصر الإرجاع
//                    'created_at' => now(),
//                    'updated_at' => now(),
//                ];
//
//                // **اختياري:** تحديث حالة CustodyItem الأصلي إلى 'return_requested' أو ما شابه
//                // لكي لا يتمكن المستخدم من إرجاعه مرة أخرى بينما الطلب معلق.
//                // $custodyItem->update(['status' => 'return_requested']);
//            }
//
//            CustodyReturnItem::insert($returnItemsData); // إدخال جماعي للعناصر
//
//            DB::commit();
//
//            return $this->successResponse(
//                $custodyReturn->load('items.custodyItem.product', 'user'), // تحميل العلاقات للاستجابة
//                'تم إنشاء طلب إرجاع العهدة بنجاح. بانتظار مراجعة أمين المستودع.'
//            );
//
//        } catch (\Throwable $e) {
//            DB::rollBack();
//            return $this->errorResponse(
//                'فشل في إنشاء طلب إرجاع العهدة: ' . $e->getMessage(),
//                422
//            );
//        }





































































//        $user = Auth::user();
//
//        if (!$user) {
//            return $this->unauthorizedResponse('الرجاء تسجيل الدخول.');
//        }
//
//        $validator = Validator::make($request->all(), [
//            'return_date' => 'required|date|before_or_equal:today',
//            'notes' => 'nullable|string|max:1000',
//            'items' => 'required|array|min:1',
//            'items.*.custody_item_id' => [
//                'required',
//                'integer',
//                'exists:custody_items,id', // فقط التأكد أن العنصر موجود
//                // التحقق من أن عنصر العهدة هذا يخص المستخدم المصادق عليه
//                Rule::exists('custody_items', 'id')->where(function ($query) use ($user) {
//                    $query->whereHas('custody', function ($q) use ($user) {
//                        $q->where('user_id', $user->id);
//                    });
//                }),
//                // التحقق من أن العنصر ليس مستهلكاً
//                function ($attribute, $value, $fail) {
//                    $custodyItem = CustodyItem::find($value);
//                    if (!$custodyItem) { // للتأكد أنه تم العثور على العنصر
//                        $fail('عنصر العهدة غير موجود.');
//                        return;
//                    }
//                    if ($custodyItem->product->consumable) {
//                        $fail("لا يمكن إرجاع المنتج المستهلك ({$custodyItem->product->name}).");
//                    }
//                },
//
//                function ($attribute, $value, $fail) {
//                    $existsInPendingReturn = CustodyReturnItem::where('custody_item_id', $value)
//                        ->whereHas('custodyReturn', function($q) {
//                            $q->where('status', 'pending'); // أو 'processing' إذا كان لديك هذه الحالة لطلب الإرجاع
//                        })
//                        ->where('warehouse_manager_status', 'pending_review') // فقط إذا كان العنصر نفسه قيد المراجعة
//                        ->exists();
//
//                    if ($existsInPendingReturn) {
//                        $custodyItem = CustodyItem::find($value); // لجلب اسم المنتج للرسالة
//                        $productName = $custodyItem ? $custodyItem->product->name : 'هذا العنصر';
//                        $fail("{$productName} قيد الإرجاع حالياً في طلب آخر بانتظار المراجعة.");
//                    }
//                },
//            ],
//            'items.*.returned_quantity' => [
//                'required',
//                'numeric',
//                'min:0.01',
//                // التحقق من أن الكمية المرتجعة لا تتجاوز الكمية الأصلية المتبقية
//                function ($attribute, $value, $fail) use ($request, $user) {
//                    $index = explode('.', $attribute)[1];
//                    $custodyItemId = $request->input("items.{$index}.custody_item_id");
//
//                    $custodyItem = CustodyItem::where('id', $custodyItemId)
//                        ->whereHas('custody', function($q) use ($user) {
//                            $q->where('user_id', $user->id);
//                        })
//                        ->first();
//
//                    if (!$custodyItem) {
//                        $fail('عنصر العهدة غير موجود أو لا ينتمي للمستخدم الحالي.');
//                        return;
//                    }
//
//                    // حساب الكمية المرجعة سابقاً (وتم قبولها)
//                    // يجب أن نأخذ في الاعتبار فقط الكميات التي تم قبولها بنجاح كمرتجعة
//                    $previouslyReturnedQuantity = CustodyReturnItem::where('custody_item_id', $custodyItemId)
//                        ->where('warehouse_manager_status', 'accepted') // أو 'returned' حسب الحالة النهائية
//                        ->sum('returned_quantity');
//
//                    $availableQuantity = $custodyItem->quantity - $previouslyReturnedQuantity;
//
//                    if ($value > $availableQuantity) {
//                        $fail("الكمية المرتجعة لـ {$custodyItem->product->name} (ID: {$custodyItem->id}) لا يمكن أن تتجاوز الكمية المتاحة للإرجاع ({$availableQuantity}).");
//                    }
//                },
//            ],
//            'items.*.warehouse_id' => 'required|exists:warehouses,id',
//            'items.*.user_notes' => 'nullable|string|max:500',
//        ]);
//
//        if ($validator->fails()) {
//            return $this->validationErrorResponse($validator);
//        }
//
//        DB::beginTransaction();
//
//        try {
//            // إنشاء طلب الإرجاع الرئيسي
//            $custodyReturn = CustodyReturn::create([
//                'user_id' => $user->id,
//                'return_date' => $request->return_date,
//                'notes' => $request->notes,
//                'status' => 'pending', // الحالة الأولية لطلب الإرجاع
//            ]);
//
//            $returnItemsToInsert = [];
//            foreach ($request->items as $item) {
//                // جلب CustodyItem هنا ليس فقط للتحقق، بل للحصول على بياناته مثل product
//                $custodyItem = CustodyItem::with('product')->find($item['custody_item_id']);
//
//                $returnItemsToInsert[] = [
//                    'custody_return_id' => $custodyReturn->id,
//                    'custody_item_id' => $item['custody_item_id'],
//                    'returned_quantity' => $item['returned_quantity'],
//                    'warehouse_id' => $item['warehouse_id'],
//                    'user_notes' => $item['user_notes'] ?? null,
//                    'warehouse_manager_status' => 'pending_review', // حالة أولية لعنصر الإرجاع
//                    'created_at' => now(),
//                    'updated_at' => now(),
//                ];
//
//            }
//
//            CustodyReturnItem::insert($returnItemsToInsert); // إدخال جماعي للعناصر
//
//            DB::commit();
//
//            // تحميل العلاقات للاستجابة
//            $custodyReturn->load('items.custodyItem.product', 'items.warehouse', 'user');
//
//            return $this->successResponse(
//                'تم إنشاء طلب إرجاع العهدة بنجاح. بانتظار مراجعة أمين المستودع.',
//                $custodyReturn
//            );
//
//        } catch (\Throwable $e) {
//            DB::rollBack();
//            return $this->errorResponse(
//                'فشل في إنشاء طلب إرجاع العهدة: ' . $e->getMessage(),
//                422
//            );
//        }
//    }





