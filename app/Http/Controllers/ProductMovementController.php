<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductMovement;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;


class ProductMovementController extends Controller
{
    use ApiResponse;
    //عرض كل حركة المادة
    public function showProductMovement($id)
    {
        try {
            $movements = ProductMovement::where('product_id', $id)
                ->orderBy('date', 'desc')
                ->get();

            return $this->successResponse($movements, 'تم جلب حركة المادة بنجاح');

        }catch (\Exception $e){
            return $this->handleExceptionResponse($e);
        }
    }

    // عرض حركة منتج محدد حسب الشهر
    public function getMovementsByMonth(Request $request, $productId)
    {
        $validator = Validator::make($request->all(), [
            'year' => 'required|integer|min:2000|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $year = $request->input('year');
            $month = $request->input('month');

            $movements = ProductMovement::where('product_id', $productId)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->orderBy('date', 'desc')
                ->get();

            return $this->successResponse(
                $movements,
                'تم جلب حركات المنتج لشهر ' . $month . '-' . $year . ' بنجاح'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                message: 'فشل في جلب حركات المنتج: ' . $e->getMessage(),
                code: 500,
                internalCode: 'PRODUCT_MOVEMENTS_FETCH_FAILED'
            );
        }
    }

    public function getMonthlyProductBalances(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'year' => 'required|integer|min:2000|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $year = $request->input('year');
            $month = $request->input('month');
            $startDate = "{$year}-{$month}-01";
            $endDate = date("Y-m-t", strtotime($startDate));
            $previousMonthEnd = date("Y-m-d", strtotime($startDate . ' -1 day'));

            // الحصول على الرصيد الافتتاحي - الطريقة المعدلة
            $openingBalances = ProductMovement::select('product_id', 'after_quantity', 'date')
                ->whereDate('date', '<=', $previousMonthEnd)
                ->orderBy('product_id')
                ->orderBy('date', 'desc')
                ->get()
                ->groupBy('product_id')
                ->map(function ($movements) {
                    return (object)['opening_balance' => $movements->first()->after_quantity ?? 0];
                });

            $products = Product::with(['movements' => function($query) use ($startDate, $endDate) {
                $query->whereDate('date', '>=', $startDate)
                    ->whereDate('date', '<=', $endDate)
                    ->orderBy('date', 'asc');
            }])
                ->get();

            $result = $products->map(function ($product) use ($openingBalances) {
                $openingBalance = $openingBalances[$product->id]->opening_balance ?? 0;

                $monthlyMovements = $product->movements;

                $totalIn = $monthlyMovements->whereIn('type', ['entry', 'receive'])->sum('note_quantity');
                $totalOut = $monthlyMovements->whereIn('type', ['exit', 'scrap' ,'install'])->sum('note_quantity');

                return [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'opening_balance' => $openingBalance,
                    'total_in' => $totalIn,
                    'total_out' => $totalOut,
                    'closing_balance' => $openingBalance + ($totalIn - $totalOut),
                ];
            });

            return $this->successResponse(
                $result,
                'تم جلب أرصدة المنتجات لشهر ' . $month . '-' . $year . ' بنجاح'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                message: 'فشل في جلب أرصدة المنتجات: ' . $e->getMessage(),
                code: 500,
                internalCode: 'PRODUCT_BALANCES_FETCH_FAILED'
            );
        }
    }
}

