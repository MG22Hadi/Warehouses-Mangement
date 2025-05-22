<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ExitNote;
use App\Models\ExitNoteItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\Product;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MaterialRequestController extends Controller
{
    //
    use ApiResponse;

    public function index()
    {
        try {
            $r = MaterialRequest::all();

            return $this->successResponse($r, 'تم جلب المذكرات مع عدد الأصناف بنجاح');
        } catch (\Exception $e) {
            return $this->handleExceptionResponse($e);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'warehouse_keeper_id' => 'required|exists:warehouse_keepers,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity_requested' => 'required|numeric|min:0.01',
            'items.*.notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $result = DB::transaction(function () use ($request) {
                // توليد الرقم التسلسلي
                $serialNumber = 'MR-' . date('YmdHis') . '-' . Str::random(4);

                if (!DB::table('users')->where('id', $request->user()->id)->exists()) {
                    return $this->errorResponse(
                        message: 'المستخدم الحالي غير موجود في جدول users',
                        code: 422,
                        internalCode: 'USER_NOT_FOUND'
                    );
                }

                $materialRequest = MaterialRequest::create([
                    'requested_by' => $request->user()->id,
                    'warehouse_keeper_id' => $request->warehouse_keeper_id,
                    'status' => 'pending', // الحالة الافتراضية
                    'serial_number' => $serialNumber,
                    'date' => $request->date,
                ]);

                foreach ($request->items as $item) {
                    MaterialRequestItem::create([
                        'material_request_id' => $materialRequest->id,
                        'product_id' => $item['product_id'],
                        'quantity_requested' => $item['quantity_requested'],
                        'notes' => $item['notes'] ?? null,
                    ]);
                }

                return [
                    'material_request' => $materialRequest->load('items'),
                    'message' => 'تم إنشاء طلب المواد بنجاح'
                ];
            });

            return $this->successResponse($result['material_request'], $result['message'], 201);

        } catch (\Exception $e) {
            return $this->errorResponse(
                message: 'فشل في إنشاء طلب المواد: ' . $e->getMessage(),
                code: 500,
                errors: ['trace' => $e->getTraceAsString()],
                internalCode: 'MATERIAL_REQUEST_CREATION_FAILED'
            );
        }
    }

    public function pendingRequests()
    {
        try {
            // جلب الطلبات مع العلاقات والعدد
            $pendingRequests = MaterialRequest::withCount('items')
                ->where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->get();

            // تعديل هيكل البيانات للإرجاع
            $formattedRequests = $pendingRequests->map(function ($request) {
                return [
                    'id' => $request->id,
                    'serial_number' => $request->serial_number,
                    'date' => $request->date,
                    'requested_by' => $request->requestedBy->name,
                    'warehouse_keeper' => $request->warehouseKeeper->name,
                    'items_count' => $request->items_count,
                    'created_at' => $request->created_at->format('Y-m-d H:i')
                ];
            });

            return $this->successResponse($formattedRequests, 'تم جلب الطلبات المعلقة بنجاح');

        } catch (\Exception $e) {
            return $this->handleExceptionResponse($e);
        }
    }

    /**
     * الموافقة على طلب مواد
     *
     * @param int $id معرّف طلب المواد
     * @param \Illuminate\Http\Request $request
     *
     */

    public function approveRequest($id){
        try{
            $materialRequest=MaterialRequest::findOrFail($id);
                //             التحقق من أن الطلب في حالة انتظار
                if ($materialRequest->status != 'pending') {
                    throw new \Exception('لا يمكن الموافقة على طلب غير معلق');
                }
                $q=$materialRequest->quantity_requested;

                $materialRequest->update(['status' => 'approved',
                                        'quantity_approved'=>$q]);

            return $this->successResponse($materialRequest,'تم الموافقة على الطلب',201);
        }catch (\Exception $e){
            return $this->errorResponse(
                message: 'فشل في إنشاء طلب المواد: ' . $e->getMessage(),
                code: 500,
                errors: ['trace' => $e->getTraceAsString()],
                internalCode: 'MATERIAL_REQUEST_CREATION_FAILED'
            );
        }
    }

    public function editRequest($id, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:material_request_items,id,material_request_id,'.$id,
            'items.*.quantity_approved' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {

            DB::transaction(function () use ($id, $request) {

                $materialRequest = MaterialRequest::with('items')->findOrFail($id);

                // التحقق من أن الطلب في حالة انتظار
                if ($materialRequest->status != 'pending') {
                    throw new \Exception('لا يمكن تعديل على طلب غير معلق');
                }

                // تحديث حالة الطلب
                $materialRequest->update([
                    'status' => 'approved',
                    'approved_by' => null,
                    'approved_at' => now(),
                    'manager_notes' => $request->notes
                ]);

                // تحديث الكميات المعتمدة لكل مادة
                foreach ($request->items as $itemData) {
                    $item = $materialRequest->items->find($itemData['id']);

                    if ($itemData['quantity_approved'] > $item->quantity_requested) {
                        throw new \Exception("الكمية المعتمدة لا يمكن أن تكون أكبر من المطلوبة للمادة: {$item->product->name}");
                    }

                    $item->update([
                        'quantity_approved' => $itemData['quantity_approved'],
                        'approval_notes' => $itemData['notes'] ?? null
                    ]);
                }

            });

            return $this->successResponse(
                MaterialRequest::with(['items.product', 'approvedBy'])->find($id),
                'تم تعديل والموافقة على طلب المواد بنجاح'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                message: 'فشل في اعتماد الطلب: ' . $e->getMessage(),
                code: 422,
                internalCode: 'APPROVAL_FAILED'
            );
        }
    }

    public function rejectRequest($id)
    {
        // تغيير status إلى 'rejected'
        // مع إمكانية إضافة سبب الرفض

        try {
            DB::transaction(function () use ($id) {
                $materialRequest = MaterialRequest::with(['requestedBy', 'items'])->findOrFail($id);

                // التحقق من أن الطلب قابل للرفض (في حالة pending)
                if ($materialRequest->status != 'pending') {
                    throw new \Exception('لا يمكن رفض طلب غير معلق');
                }

                // تحديث حالة الطلب
                $materialRequest->update([
                    'status' => 'rejected',
                ]);

            });

            return $this->successResponse(
                MaterialRequest::find($id),
                'تم رفض طلب المواد بنجاح'
            );

        }catch(\Exception $e){
            return $this->errorResponse(
                message: 'فشل في اعتماد الطلب: ' . $e->getMessage(),
                code: 422,
                internalCode: 'APPROVAL_FAILED'
            );
        }

    }


    public function showRequest($id)
    {
        // عرض تفاصيل طلب معين مع جميع items
    }

}
