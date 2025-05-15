<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ExitNote;
use App\Models\ExitNoteItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
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

}
