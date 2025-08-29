<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\WarehouseKeeper;
use App\Models\Manager;
use App\Models\Supplier;
use App\Models\Product;
use App\Services\NotificationService;
use App\Traits\ApiResponse;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PurchaseRequestController extends Controller
{
    use ApiResponse;


    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    //اندكس عام بدون ستيتوس ولو مرقت ستيتوس بصير فلترة
    public function index(Request $request)
    {
        $query = PurchaseRequest::with(['createdBy', 'manager', 'supplier', 'items.product']);

        // 💡 الشرط الجديد: إذا كان هناك متغير status في الرابط، قم بالفلترة
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $purchaseRequests = $query->get();
        $count = $purchaseRequests->count();

        $data = [
            'count' => $count,
            'purchase_requests' => $purchaseRequests,
        ];

        return $this->successResponse(
            $data,
            'تم جلب قائمة طلبات الشراء بنجاح.'
        );
    }



    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'created_by' => 'required|exists:warehouse_keepers,id', // ⚠️ يجب أن يكون ID أمين المستودع موجوداً
            'supplier_id' => 'required|exists:suppliers,id',
            'request_date' => 'required|date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity_requested' => 'required|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        // ⚠️ الخطوة الحاسمة: جلب المدير عبر العلاقات
//        try {
//            // نجد أمين المستودع بناءً على ID المرسل في الطلب
//            $warehouseKeeper = WarehouseKeeper::findOrFail($request->created_by);
//
//            // نصل إلى المدير عبر سلسلة العلاقات: أمين المستودع -> المستودع -> القسم -> المدير
//            $managerId = $warehouseKeeper->warehouse->department->manager_id;
//
//        } catch (Exception $e) {
//            // هذا الخطأ سيحدث إذا كانت إحدى العلاقات غير موجودة (مثل warehouse_id في جدول warehouse_keepers فارغ)
//            return $this->errorResponse(
//                'فشل في تحديد مدير القسم المرتبط بأمين المستودع: ' . $e->getMessage(),
//                404,
//                [],
//                'MANAGER_NOT_FOUND'
//            );
//        }
        try {
            $warehouseKeeper = WarehouseKeeper::findOrFail($request->created_by);

            $warehouse = $warehouseKeeper->warehouse->first(); // اختر أول مستودع
            if (!$warehouse) {
                throw new \Exception('لا يوجد مستودع مرتبط بأمين المستودع.');
            }

            $department = $warehouse->department;
            if (!$department) {
                throw new \Exception('لا يوجد قسم مرتبط بالمستودع.');
            }

            $manager = $department->manager;
            if (!$manager) {
                throw new \Exception('لا يوجد مدير مرتبط بالقسم.');
            }

            $managerId = $manager->id;

        } catch (\Exception $e) {
            return $this->errorResponse(
                'فشل في تحديد مدير القسم المرتبط بأمين المستودع: ' . $e->getMessage(),
                404,
                [],
                'MANAGER_NOT_FOUND'
            );
        }


        try {
            DB::transaction(function () use ($request, $managerId, &$purchaseRequest) {
                $purchaseRequest = PurchaseRequest::create([
                    'created_by' => $request->created_by,
                    'manager_id' => $managerId,
                    'supplier_id' => $request->supplier_id,
                    'serial_number' => $this->generateSerialNumber(),
                    'status' => 'pending',
                    'request_date' => $request->request_date,
                    'notes' => $request->notes,
                ]);

                foreach ($request->items as $item) {
                    PurchaseRequestItem::create([
                        'purchase_request_id' => $purchaseRequest->id,
                        'product_id' => $item['product_id'],
                        'quantity_requested' => $item['quantity_requested'],
                        'notes' => $item['notes'] ?? null,
                    ]);
                }
            });

            $purchaseRequest->load(['createdBy.warehouse.department.manager', 'supplier', 'items.product']);

            // 🔔 إشعار للمدير المحدد
            $manager = $purchaseRequest->manager;
            if ($manager) {
                // تأكد من أن notificationService معرف ومتاح
                if (isset($this->notificationService)) {
                    $this->notificationService->notify(
                        $manager,
                        'طلب شراء جديد',
                        'يوجد طلب شراء جديد بانتظار المراجعة (رقم: ' . $purchaseRequest->serial_number . ')',
                        'purchase_request',
                        $purchaseRequest->id
                    );
                }
            }

            return $this->successResponse(
                $purchaseRequest,
                'تم إنشاء طلب الشراء بنجاح وإرسال إشعار للمدير .',
                201
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'فشل في إنشاء طلب الشراء: ' . $e->getMessage(),
                500,
                [],
                'PURCHASE_REQUEST_CREATION_FAILED'
            );
        }
    }


    public function show($id)
    {
        $purchaseRequest = PurchaseRequest::with(['createdBy', 'manager', 'supplier', 'items.product'])->find($id);

        if (!$purchaseRequest) {
            return $this->notFoundResponse('طلب الشراء غير موجود.');
        }

        return $this->successResponse(
            $purchaseRequest,
            'تم جلب بيانات طلب الشراء بنجاح.'
        );
    }


    public function update(Request $request, $id)
    {
        $purchaseRequest = PurchaseRequest::find($id);

        if (!$purchaseRequest) {
            return $this->notFoundResponse('طلب الشراء غير موجود.');
        }

        if ($purchaseRequest->status !== 'pending') {
            return $this->errorResponse(
                'لا يمكن تعديل طلب شراء حالته ليست قيد الانتظار.',
                400,
                [],
                'UPDATE_NOT_ALLOWED'
            );
        }

        $validator = Validator::make($request->all(), [
            'supplier_id' => 'sometimes|required|exists:suppliers,id',
            'request_date' => 'sometimes|required|date',
            'notes' => 'nullable|string',
            'items' => 'sometimes|required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity_requested' => 'required|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            DB::transaction(function () use ($request, $purchaseRequest) {
                $purchaseRequest->update([
                    'supplier_id' => $request->supplier_id ?? $purchaseRequest->supplier_id,
                    'request_date' => $request->request_date ?? $purchaseRequest->request_date,
                    'notes' => $request->notes ?? $purchaseRequest->notes,
                ]);

                if ($request->has('items')) {
                    $purchaseRequest->items()->delete();
                    foreach ($request->items as $item) {
                        PurchaseRequestItem::create([
                            'purchase_request_id' => $purchaseRequest->id,
                            'product_id' => $item['product_id'],
                            'quantity_requested' => $item['quantity_requested'],
                            'notes' => $item['notes'] ?? null,
                        ]);
                    }
                }
            });

            $purchaseRequest->load(['createdBy', 'manager', 'supplier', 'items.product']);

            return $this->successResponse(
                $purchaseRequest,
                'تم تعديل طلب الشراء بنجاح.'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'فشل في تعديل طلب الشراء: ' . $e->getMessage(),
                500,
                [],
                'PURCHASE_REQUEST_UPDATE_FAILED'
            );
        }
    }


    public function approve($id)
    {
        $purchaseRequest = PurchaseRequest::find($id);
        $user = Auth::user();

        if (!$purchaseRequest) {
            return $this->notFoundResponse('طلب الشراء غير موجود.');
        }

        if ($purchaseRequest->status !== 'pending') {
            return $this->errorResponse(
                'لا يمكن الموافقة على طلب شراء حالته ليست قيد الانتظار.',
                400,
                [],
                'APPROVE_NOT_ALLOWED'
            );
        }

        try {
            DB::transaction(function () use ($purchaseRequest, $user) {
                $purchaseRequest->update([
                    'status' => 'approved',
                    'manager_id' => $user->id,
                ]);

                foreach ($purchaseRequest->items as $item) {
                    $item->update(['quantity_approved' => $item->quantity_requested]);
                }
            });

            $purchaseRequest->load(['createdBy', 'manager', 'supplier', 'items.product']);

            // 🔔 إشعار للـ warehouseKeeper (المنشئ)
            $creator = $purchaseRequest->createdBy;
            if ($creator) {
                $this->notificationService->notify(
                    $creator,
                    'الموافقة على طلب شراء',
                    'تمت الموافقة على طلب الشراء الخاص بك (رقم: ' . $purchaseRequest->serial_number . ').',
                    'purchase_request',
                    $purchaseRequest->id
                );
            }

            return $this->successResponse(
                $purchaseRequest,
                'تمت الموافقة على طلب الشراء بنجاح وإرسال إشعار لأمين المستودع .'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'فشل في الموافقة على طلب الشراء: ' . $e->getMessage(),
                500,
                [],
                'PURCHASE_REQUEST_APPROVAL_FAILED'
            );
        }
    }


    public function reject($id)
    {
        $purchaseRequest = PurchaseRequest::find($id);
        $user = Auth::user();

        if (!$purchaseRequest) {
            return $this->notFoundResponse('طلب الشراء غير موجود.');
        }

        if ($purchaseRequest->status !== 'pending') {
            return $this->errorResponse(
                'لا يمكن رفض طلب شراء حالته ليست قيد الانتظار.',
                400,
                [],
                'REJECT_NOT_ALLOWED'
            );
        }

        try {
            DB::transaction(function () use ($purchaseRequest, $user) {
                $purchaseRequest->update([
                    'status' => 'rejected',
                    'manager_id' => $user->id,
                ]);
            });

            $purchaseRequest->load(['createdBy', 'manager', 'supplier', 'items.product']);

            // 🔔 إشعار للـ warehouseKeeper (المنشئ)
            $creator = $purchaseRequest->createdBy;
            if ($creator) {
                $this->notificationService->notify(
                    $creator,
                    'رفض طلب شراء',
                    'تم رفض طلب الشراء الخاص بك (رقم: ' . $purchaseRequest->serial_number . ').',
                    'purchase_request',
                    $purchaseRequest->id
                );
            }

            return $this->successResponse(
                $purchaseRequest,
                'تم رفض طلب الشراء بنجاح وإرسال إشعار لأمين المستودع بالرفض .'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'فشل في رفض طلب الشراء: ' . $e->getMessage(),
                500,
                [],
                'PURCHASE_REQUEST_REJECTION_FAILED'
            );
        }
    }


    public function myRequests()
    {
        $user = Auth::user();

        // التحقق مما إذا كان المستخدم موجودًا ومن نوع warehouseKeeper
        if (!$user || $user->getMorphClass() !== 'App\Models\WarehouseKeeper') {
            return $this->unauthorizedResponse('هذه الميزة متاحة فقط لأمناء المستودعات.');
        }

        $purchaseRequests = PurchaseRequest::with(['createdBy', 'manager', 'supplier', 'items.product'])
            ->where('created_by', $user->id)
            ->get();

        $count = $purchaseRequests->count();

        $data = [
            'count' => $count,
            'purchase_requests' => $purchaseRequests,
        ];

        return $this->successResponse(
            $data,
            'تم جلب طلبات الشراء الخاصة بك بنجاح.'
        );
    }


    private function generateSerialNumber()
    {
        return 'PR-' . now()->format('Y') . '-' . str_pad(PurchaseRequest::count() + 1, 4, '0', STR_PAD_LEFT);
    }
}
