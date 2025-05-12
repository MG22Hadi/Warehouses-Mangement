<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;


class WarehouseController extends Controller
{
    use ApiResponse;

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'type' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $warehouse = Warehouse::create([
                'name' => $validated['name'],
                'location' => $validated['location'],
                'type' => $validated['type'] ?? null,
            ]);

            DB::commit();

            return $this->successResponse(
                ['warehouse' => $warehouse],
                'تم إنشاء المستودع بنجاح',
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->errorResponse('فشل في إنشاء المستودع : ' . $e->getMessage(), 500);
        }
    }
}
