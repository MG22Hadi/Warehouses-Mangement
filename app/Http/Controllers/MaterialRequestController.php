<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Traits\ApiResponse;

class MaterialRequestController extends Controller
{
    use ApiResponse;

    public function index()
    {
        try {
            $requests = MaterialRequest::with(['manager', 'requestedBy', 'warehouseKeeper'])->get();

            return $this->successResponse($requests, 'تم جلب المذكرات مع عدد الأصناف بنجاح');
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
                $user = $request->user()->load('department.manager');

                if (!$user || !$user->department || !$user->department->manager) {
                    // رجّع استثناء بدل response
                    throw new \Exception('المستخدم أو المدير غير موجود');
                }

                $serialNumber = 'MR-' . date('YmdHis') . '-' . Str::random(4);

                $materialRequest = MaterialRequest::create([
                    'requested_by' => $user->id,
                    'manager_id' => $user->department->manager->id,
                    'warehouse_keeper_id' => $request->warehouse_keeper_id,
                    'status' => 'pending',
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
                    'material_request' => $materialRequest->load(['items', 'manager']),
                    'message' => 'تم إنشاء طلب المواد بنجاح'
                ];
            });

            // هنا بس تبني response
            return $this->successResponse(
                $result['material_request'],
                $result['message'],
                201
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'فشل في إنشاء طلب المواد: ' . $e->getMessage(),
                500,
                ['trace' => $e->getTraceAsString()],
                'MATERIAL_REQUEST_CREATION_FAILED'
            );
        }
    }


    public function pendingRequests()
    {
        try {
            $pendingRequests = MaterialRequest::with(['manager', 'requestedBy', 'warehouseKeeper'])
                ->withCount('items')
                ->where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->get();

            $formatted = $pendingRequests->map(function ($r) {
                return [
                    'id' => $r->id,
                    'serial_number' => $r->serial_number,
                    'date' => $r->date,
                    'requested_by' => $r->requestedBy->name,
                    'warehouse_keeper' => $r->warehouseKeeper->name,
                    'items_count' => $r->items_count,
                    'created_at' => $r->created_at->format('Y-m-d H:i'),
                    'manager' => [
                        'id' => $r->manager->id,
                        'name' => $r->manager->name,
                        'email' => $r->manager->email ?? null
                    ]
                ];
            });

            return $this->successResponse($formatted, 'تم جلب الطلبات المعلقة بنجاح');

        } catch (\Exception $e) {
            return $this->handleExceptionResponse($e);
        }
    }

    public function approveRequest($id)
    {
        try {
            $requestModel = MaterialRequest::with(['items', 'manager', 'requestedBy'])->find($id);

            if (!$requestModel) {
                return $this->errorResponse('الطلب غير موجود', 404, [], 'REQUEST_NOT_FOUND');
            }

            if ($requestModel->status != 'pending') {
                return $this->errorResponse('لا يمكن الموافقة على طلب غير معلق', 422, [], 'INVALID_STATUS');
            }

            $requestModel->update(['status' => 'approved']);

            foreach ($requestModel->items as $item) {
                $item->update([
                    'quantity_approved' => $item->quantity_requested,
                    'approval_notes' => $item->notes ?? null
                ]);
            }

            return $this->successResponse($requestModel, 'تم الموافقة على الطلب', 201);

        } catch (\Exception $e) {
            return $this->errorResponse(
                'فشل في الموافقة على الطلب: ' . $e->getMessage(),
                500,
                ['trace' => $e->getTraceAsString()],
                'APPROVAL_FAILED'
            );
        }
    }

    public function editRequest($id, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:material_request_items,id,material_request_id,' . $id,
            'items.*.quantity_approved' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            DB::transaction(function () use ($id, $request) {
                $requestModel = MaterialRequest::with('items')->find($id);

                if (!$requestModel) {
                    throw new \Exception('الطلب غير موجود');
                }

                if ($requestModel->status != 'pending') {
                    throw new \Exception('لا يمكن تعديل طلب غير معلق');
                }

                $requestModel->update([
                    'status' => 'approved',
                    'approved_by' => null,
                    'approved_at' => now(),
                    'manager_notes' => $request->notes
                ]);

                foreach ($request->items as $itemData) {
                    $item = $requestModel->items->find($itemData['id']);
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
                MaterialRequest::with(['items.product', 'approvedBy', 'manager', 'requestedBy'])->find($id),
                'تم تعديل والموافقة على طلب المواد بنجاح'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'فشل في اعتماد الطلب: ' . $e->getMessage(),
                422,
                ['trace' => $e->getTraceAsString()],
                'APPROVAL_FAILED'
            );
        }
    }

    public function rejectRequest($id)
    {
        try {
            DB::transaction(function () use ($id) {
                $requestModel = MaterialRequest::with(['requestedBy', 'items', 'manager'])->find($id);

                if (!$requestModel) {
                    throw new \Exception('الطلب غير موجود');
                }

                if ($requestModel->status != 'pending') {
                    throw new \Exception('لا يمكن رفض طلب غير معلق');
                }

                $requestModel->update(['status' => 'rejected']);
            });

            return $this->successResponse(
                MaterialRequest::with(['manager', 'requestedBy', 'items.product'])->find($id),
                'تم رفض طلب المواد بنجاح'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'فشل في رفض الطلب: ' . $e->getMessage(),
                422,
                ['trace' => $e->getTraceAsString()],
                'REJECTION_FAILED'
            );
        }
    }

    public function showRequest($id)
    {
        try {
            $requestModel = MaterialRequest::with([
                'items.product',
                'requestedBy',
                'warehouseKeeper',
                'manager',
                'approvedBy'
            ])->find($id);

            if (!$requestModel) {
                return $this->errorResponse('الطلب غير موجود', 404, [], 'REQUEST_NOT_FOUND');
            }

            return $this->successResponse($requestModel, 'تم جلب تفاصيل الطلب بنجاح');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'فشل في جلب تفاصيل الطلب: ' . $e->getMessage(),
                422,
                ['trace' => $e->getTraceAsString()],
                'SHOW_REQUEST_FAILED'
            );
        }
    }

}

