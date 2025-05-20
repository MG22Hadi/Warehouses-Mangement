<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InstallationMaterial;
use App\Models\InstallationReport;
use App\Models\Product;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InstallationReportController extends Controller
{
    //
    use ApiResponse;
    public function index(Request $request)
    {
        try {
            // فلترة حسب النوع (مع إضافة خيار all)
            $type = $request->query('type', 'all'); // all, stock_usage, purchase

            $query = InstallationReport::with(['materials', 'createdBy', 'approvedBy'])
                ->orderBy('date', 'desc');

            // تطبيق الفلتر إذا لم يكن all
            if ($type !== 'all' && in_array($type, ['stock_usage', 'purchase'])) {
                $query->where('type', $type);
            }

            // فلترة حسب التاريخ إذا موجودة
            if ($request->has('from_date')) {
                $query->where('date', '>=', $request->from_date);
            }

            if ($request->has('to_date')) {
                $query->where('date', '<=', $request->to_date);
            }

            // فلترة حسب حالة الاعتماد إذا موجودة
            if ($request->has('status')) {
                if ($request->status === 'approved') {
                    $query->whereNotNull('approved_by');
                } elseif ($request->status === 'pending') {
                    $query->whereNull('approved_by');
                }
            }

            $reports = $query->paginate(15);

            // تنسيق البيانات للإرسال
            $formattedReports = $reports->map(function ($report) {
                return [
                    'id' => $report->id,
                    'serial_number' => $report->serial_number,
                    'type' => $report->type,
                    'type_name' => $this->getTypeName($report->type),
                    'date' => $report->date,
                    'location' => $report->location,
                    'status' => $report->approved_by ? 'معتمدة' : 'قيد الانتظار',
                    'created_by' => $report->createdBy->name ?? 'غير معروف',
                    'approved_by' => $report->approvedBy->name ?? 'لم يتم الاعتماد بعد',
                    'materials_count' => $report->materials->count(),
                    'total_quantity' => $report->materials->sum('quantity'),
                    'total_cost' => $report->materials->sum('total_price'),
                    'created_at' => $report->created_at->format('Y-m-d H:i'),
                ];
            });

            return $this->successResponse([
                'reports' => $formattedReports,
                'pagination' => [
                    'total' => $reports->total(),
                    'current_page' => $reports->currentPage(),
                    'per_page' => $reports->perPage(),
                    'last_page' => $reports->lastPage(),
                ],
                'filters' => [
                    'current_type' => $type,
                    'available_types' => [
                        ['value' => 'all', 'label' => 'الكل'],
                        ['value' => 'stock_usage', 'label' => 'استخدام من المستودع'],
                        ['value' => 'purchase', 'label' => 'شراء']
                    ]
                ]
            ], 'تم جلب التقارير بنجاح');

        } catch (\Exception $e) {
            return $this->errorResponse(
                message: 'فشل في جلب تقارير التركيب: ' . $e->getMessage(),
                code: 500,
                internalCode: 'INSTALLATION_REPORTS_FETCH_FAILED'
            );
        }
    }

// دالة مساعدة للحصول على اسم النوع
    private function getTypeName($type)
    {
        $types = [
            'stock_usage' => 'استخدام من المستودع',
            'purchase' => 'شراء'
        ];

        return $types[$type] ?? 'غير معروف';
    }


//    public function store(Request $request)
//    {
//        $validator = Validator::make($request->all(), [
//            'location' => 'required|string|max:500',
//            'type' => 'required|in:purchase,stock_usage',
//            'date' => 'required|date',
//            'notes' => 'nullable|string|max:1000',
//            'materials' => 'required|array|min:1',
//            'materials.*.product_id' => 'required|exists:products,id',
//            'materials.*.quantity' => 'required|numeric|min:0.01',
//            'materials.*.notes' => 'nullable|string|max:500',
//            'materials.*.unit_price' => 'required_if:type,purchase|numeric|min:0',
//        ], [
//            'materials.*.unit_price.required_if' => 'سعر الوحدة مطلوب لنوع الشراء'
//        ]);
//
//        if ($validator->fails()) {
//            return $this->validationErrorResponse($validator);
//        }
//
//        try {
//            $installationReport = null;
//
//            DB::transaction(function () use ($request, &$installationReport) {
//                // إنشاء تقرير التركيب
//                $installationReport = InstallationReport::create([
//                    'created_by' => $request->user()->id,
//                    'approved_by' => null,
//                    'serial_number' => $this->generateInstallationSerialNumber(),
//                    'location' => $request->location,
//                    'type' => $request->type,
//                    'date' => $request->date,
//                    'notes' => $request->notes,
//                ]);
//
//                foreach ($request->materials as $material) {
//                     if($request->type === 'purchase' ) {
//
//                         $unitPrice = $material['unit_price'] ;
//                         $totalPrice = $material['quantity'] * $unitPrice;
//                     }
//                    // التحقق من المخزون فقط لنوع "استخدام من المخزون"
//                    else if ($request->type === 'stock_usage') {
//                        $product = $material['product'];
//                        $availableQuantity = DB::table('stocks')
//                            ->where('product_id', $material['product_id'])
//                            ->sum('quantity');
//
//                        if ($availableQuantity < $material['quantity']) {
//                            throw new \Exception("الكمية غير متوفرة في المخزون للمنتج: {$product->name} (المتاح: {$availableQuantity})");
//                        }
//
//                        // خصم الكمية من المخزون
//                        DB::table('stocks')
//                            ->where('product_id', $material['product_id'])
//                            ->decrement('quantity', $material['quantity']);
//                    }
//
//                    InstallationMaterial::create([
//                        'installation_report_id' => $installationReport->id,
//                        'product_id' => $material['product_id'] ?? null,
//                        'product_name' => $product->name,
//                        'quantity' => $material['quantity'],
//                        'unit_price' => $unitPrice ?? null,
//                        'total_price' => $totalPrice ?? null ,
//                        'notes' => $material['notes'] ?? null,
//                    ]);
//                }
//            });
//
//            return $this->successResponse(
//                $installationReport->load(['materials.product', 'createdBy']),
//                'تم إنشاء تقرير التركيب بنجاح'
//            );
//
//        } catch (\Exception $e) {
//            return $this->errorResponse(
//                message: 'فشل في إنشاء تقرير التركيب: ' . $e->getMessage(),
//                code: 422,
//                internalCode: 'INSTALLATION_REPORT_CREATION_FAILED'
//            );
//        }
//    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'location' => 'required|string|max:500',
            'type' => 'required|in:purchase,stock_usage',
            'date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
            'materials' => 'required|array|min:1',
            'materials.*.quantity' => 'required|numeric|min:0.01',
            'materials.*.unit_price' => 'required_if:type,purchase|numeric|min:0',
            'materials.*.product_name' => 'required_if:type,purchase|string|max:255',
            'materials.*.product_id' => 'required_if:type,stock_usage|nullable|exists:products,id',
            'materials.*.notes' => 'nullable|string|max:500',
        ], [
            'materials.*.product_name.required_if' => 'اسم المنتج مطلوب لنوع الشراء',
            'materials.*.product_id.required_if' => 'معرف المنتج مطلوب لنوع الاستخدام من المخزون'
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $installationReport = null;

            DB::transaction(function () use ($request, &$installationReport) {
                // إنشاء تقرير التركيب
                $installationReport = InstallationReport::create([
                    'created_by' => $request->user()->id,
                    'approved_by' => null,
                    'serial_number' => $this->generateInstallationSerialNumber(),
                    'location' => $request->location,
                    'type' => $request->type,
                    'date' => $request->date,
                    'notes' => $request->notes,
                ]);

                foreach ($request->materials as $material) {
                    $productName = $material['product_name'] ?? null;
                    $productId = $material['product_id'] ?? null;
                    $unitPrice = $request->type === 'purchase' ? $material['unit_price'] : 0;
                    $totalPrice = $material['quantity'] * $unitPrice;

                    // التحقق من المخزون فقط لنوع "استخدام من المخزون"
                    if ($request->type === 'stock_usage') {
                        if (!$productId) {
                            throw new \Exception("معرف المنتج مطلوب لنوع 'استخدام من المخزون'");
                        }

                        $availableQuantity = DB::table('stocks')
                            ->where('product_id', $productId)
                            ->sum('quantity');

                        if ($availableQuantity < $material['quantity']) {
                            throw new \Exception("الكمية غير متوفرة في المخزون (المتاح: {$availableQuantity})");
                        }

                        // خصم الكمية من المخزون
                        DB::table('stocks')
                            ->where('product_id', $productId)
                            ->decrement('quantity', $material['quantity']);

                        $productName = Product::find($productId)->name;
                    }

                    // إنشاء مادة التركيب
                    InstallationMaterial::create([
                        'installation_report_id' => $installationReport->id,
                        'product_id' => $productId, // قد يكون null للشراء
                        'product_name' => $productName,
                        'quantity' => $material['quantity'],
                        'unit_price' => $unitPrice,
                        'total_price' => $totalPrice,
                        'notes' => $material['notes'] ?? null,
                    ]);
                }
            });

            return $this->successResponse(
                $installationReport->load('materials'),
                'تم إنشاء تقرير التركيب بنجاح'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                message: 'فشل في إنشاء تقرير التركيب: ' . $e->getMessage(),
                code: 422,
                internalCode: 'INSTALLATION_REPORT_CREATION_FAILED'
            );
        }
    }

// دالة مساعدة لإنشاء رقم مسلسل
    private function generateInstallationSerialNumber()
    {
        return 'IR-' . date('Ymd') . '-' . str_pad(InstallationReport::count() + 1, 4, '0', STR_PAD_LEFT);
    }

    public function show($id)
    {
        try {
            // جلب التقرير مع جميع العلاقات المطلوبة
            $report = InstallationReport::with([
                'materials' => function($query) {
                    $query->select([
                        'id',
                        'installation_report_id',
                        'product_id',
                        'product_name',
                        'quantity',
                        'unit_price',
                        'total_price',
                        'notes',
                        'created_at'
                    ]);
                },
                'createdBy:id,name,email',
                'approvedBy:id,name,email'
            ])->findOrFail($id);

            // حساب الإجماليات
            $totalQuantity = $report->materials->sum('quantity');
            $totalCost = $report->materials->sum('total_price');

            // تنسيق البيانات للإرسال
            $formattedReport = [
                'id' => $report->id,
                'serial_number' => $report->serial_number,
                'type' => $report->type,
                'type_name' => $this->getTypeName($report->type),
                'date' => $report->date,
                'location' => $report->location,
                'notes' => $report->notes,
                'status' => $report->approved_by ? 'معتمدة' : 'قيد الانتظار',
                'created_at' => $report->created_at->format('Y-m-d H:i'),
                'created_by' => [
                    'id' => $report->createdBy->id ?? null,
                    'name' => $report->createdBy->name ?? 'غير معروف',
                    'email' => $report->createdBy->email ?? null
                ],
                'approved_by' => $report->approved_by ? [
                    'id' => $report->approvedBy->id,
                    'name' => $report->approvedBy->name,
                    'email' => $report->approvedBy->email
                ] : null,
                'approved_at' => $report->approved_at?->format('Y-m-d H:i'),
                'materials' => $report->materials->map(function($material) {
                    return [
                        'id' => $material->id,
                        'product_id' => $material->product_id,
                        'product_name' => $material->product_name,
                        'quantity' => $material->quantity,
                        'unit_price' => $material->unit_price,
                        'total_price' => $material->total_price,
                        'notes' => $material->notes,
                        'added_at' => $material->created_at->format('Y-m-d H:i')
                    ];
                }),
                'summary' => [
                    'materials_count' => $report->materials->count(),
                    'total_quantity' => $totalQuantity,
                    'total_cost' => $totalCost,
                    'average_unit_price' => $totalQuantity > 0 ? $totalCost / $totalQuantity : 0
                ]
            ];

            return $this->successResponse(
                $formattedReport,
                'تم جلب تفاصيل ضبط التركيب بنجاح'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                message: 'فشل في جلب تفاصيل ضبط التركيب: ' . $e->getMessage(),
                code: 404,
                internalCode: 'INSTALLATION_REPORT_NOT_FOUND'
            );
        }
    }







}
