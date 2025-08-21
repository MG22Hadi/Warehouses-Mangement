<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InstallationMaterial;
use App\Models\InstallationReport;
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

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'location' => 'required|string|max:500',
            'type' => 'required|in:purchase,stock_usage',
            'date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
            'materials' => 'required|array|min:1',
            'materials.*.quantity' => 'required|numeric|min:0.01',
            'materials.*.notes' => 'nullable|string|max:500',
            'materials.*.unit_price' => 'required_if:type,purchase|nullable|numeric|min:0',
            'materials.*.product_name' => 'required_if:type,purchase|string|max:255',
            'materials.*.product_id' => 'required_if:type,stock_usage|nullable|exists:products,id',
            // 🚫 أزلنا location_id
        ], [
            'materials.*.product_name.required_if' => 'اسم المنتج مطلوب لنوع الشراء',
            'materials.*.product_id.required_if' => 'معرف المنتج مطلوب لنوع الاستخدام من المخزون',
            'materials.*.unit_price.required_if' => 'سعر الوحدة مطلوب لنوع الشراء',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $installationReport = null;
            $user = Auth::user();
            $locationMessages = [];

            DB::transaction(function () use ($request, &$installationReport, $user, &$locationMessages) {
                // إنشاء التقرير
                $installationReport = InstallationReport::create([
                    'created_by' => $user->id,
                    'manager_id' => null,
                    'status' => 'pending',
                    'serial_number' => $this->generateInstallationSerialNumber(),
                    'location' => $request->location,
                    'type' => $request->type,
                    'date' => $request->date,
                    'notes' => $request->notes,
                ]);

                foreach ($request->materials as $material) {
                    $productId = $material['product_id'] ?? null;
                    $quantity = $material['quantity'];
                    $unitPrice = $material['unit_price'] ?? null;
                    $productName = $material['product_name'] ?? null;
                    $totalPrice = $unitPrice !== null ? $quantity * $unitPrice : null;

                    if ($request->type === 'stock_usage') {
                        if (!$productId) {
                            throw new \Exception("معرف المنتج مطلوب لنوع 'استخدام من المخزون'");
                        }

                        $product = Product::findOrFail($productId);
                        $productName = $product->name;

                        // 🔎 ابحث عن أول موقع متوفر فيه الكمية
                        $productLocation = ProductLocation::where('product_id', $productId)
                            ->where('quantity', '>=', $quantity)
                            ->first();

                        if (!$productLocation) {
                            throw new \Exception("الكمية المطلوبة ({$quantity}) من المنتج '{$product->name}' غير متوفرة في أي موقع.");
                        }

                        $location = $productLocation->location;

                        // خصم الكمية
                        $productLocation->decrement('quantity', $quantity);
                        $location->decrement('used_capacity_units', $quantity);

                        // رسالة توضح من أي موقع تم الخصم
                        $locationMessages[] = "تم خصم {$quantity} {$product->unit} من المنتج '{$product->name}' من الموقع '{$location->name}'.";
                    }

                    // إنشاء المادة بدون location_id
                    InstallationMaterial::create([
                        'installation_report_id' => $installationReport->id,
                        'product_id' => $productId,
                        'product_name' => $productName,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'total_price' => $totalPrice,
                        'notes' => $material['notes'] ?? null,
                    ]);
                }
            });

            return $this->successResponse(
                [
                    'report' => $installationReport->load('materials'),
                    'location_messages' => $locationMessages,
                ],
                'تم إنشاء تقرير التركيب بنجاح. بانتظار موافقة المدير.'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                message: 'فشل في إنشاء تقرير التركيب: ' . $e->getMessage(),
                code: 422,
                internalCode: 'INSTALLATION_REPORT_CREATION_FAILED'
            );
        }
    }

    public function approve(Request $request, $id)
    {
        try {
            $report = InstallationReport::with('materials')->findOrFail($id);
            $user = Auth::user();

            // 1. التحقق من حالة التقرير (يجب أن يكون قيد الانتظار)
            if ($report->status !== 'pending') {
                return $this->errorResponse(
                    'لا يمكن الموافقة على تقرير ليس قيد الانتظار.',
                    400,
                    'REPORT_NOT_PENDING'
                );
            }
            // 2. التحقق من صلاحيات المستخدم (يجب أن يكون مدير)
            // 💡 أضف هنا التحقق من دور المستخدم، مثلا:
            // if (!$user->hasRole('manager')) { ... }

            DB::transaction(function () use ($report, $user) {
                // 3. خصم الكميات وتسجيل الحركات فقط إذا كان نوع التقرير 'stock_usage'
                if ($report->type === 'stock_usage') {
                    foreach ($report->materials as $material) {
                        // 💡 هنا يجب أن يكون لديك عمود location_id في جدول installation_materials
                        $productLocation = ProductLocation::where('product_id', $material->product_id)
                            ->where('location_id', $material->location_id) // 💡 تأكد من وجود هذا العمود
                            ->first();

                        if (!$productLocation || $productLocation->quantity < $material->quantity) {
                            $availableQuantity = $productLocation ? $productLocation->quantity : 0;
                            throw new \Exception("الكمية غير متوفرة في الموقع المحدد للمنتج: {$material->product->name} (المتاح: {$availableQuantity})");
                        }

                        $productLocation->decrement('quantity', $material->quantity);
                        if ($productLocation->quantity <= 0) {
                            $productLocation->delete();
                        }

                        $location = Location::find($material->location_id);
                        $location->decrement('used_capacity_units', $material->quantity);

                        $stock = Stock::firstOrCreate(
                            ['product_id' => $material->product_id, 'warehouse_id' => $location->warehouse_id],
                            ['quantity' => 0]
                        );
                        $prvQuantity = $stock->quantity;
                        $stock->decrement('quantity', $material->quantity);

                        ProductMovement::create([
                            'product_id' => $material->product_id,
                            'warehouse_id' => $location->warehouse_id,
                            'type' => 'install',
                            'reference_serial' => $report->serial_number,
                            'prv_quantity' => $prvQuantity,
                            'note_quantity' => $material->quantity,
                            'after_quantity' => $stock->quantity,
                            'date' => now(),
                            'reference_type' => 'InstallationReport',
                            'reference_id' => $report->id,
                            'user_id' => $user->id,
                            'notes' => 'استخدام المنتج ' . $material->product_name . ' في تقرير تركيب ' . $report->serial_number,
                        ]);
                    }
                }

                // 4. تحديث حالة التقرير إلى "معتمد" وتعيين المدير
                $report->update([
                    'status' => 'approved',
                    'manager_id' => $user->id,
                ]);
            });

            return $this->successResponse(
                $report->load('materials'),
                'تمت الموافقة على تقرير التركيب بنجاح.'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                message: 'فشل في الموافقة على تقرير التركيب: ' . $e->getMessage(),
                code: 422,
                internalCode: 'INSTALLATION_REPORT_APPROVAL_FAILED'
            );
        }
    }

    public function reject(Request $request, $id)
    {
        try {
            $report = InstallationReport::findOrFail($id);
            $user = Auth::user();

            // 1. التحقق من حالة التقرير (يجب أن يكون قيد الانتظار)
            if ($report->status !== 'pending') {
                return $this->errorResponse(
                    'لا يمكن رفض تقرير ليس قيد الانتظار.',
                    400,
                    'REPORT_NOT_PENDING'
                );
            }
            // 2. التحقق من صلاحيات المستخدم (يجب أن يكون مدير)
            // 💡 أضف هنا التحقق من دور المستخدم

            DB::transaction(function () use ($report, $user) {
                // 3. تحديث حالة التقرير إلى "مرفوض" وتعيين المدير
                $report->update([
                    'status' => 'rejected',
                    'manager_id' => $user->id,
                ]);
            });

            return $this->successResponse(
                null,
                'تم رفض تقرير التركيب بنجاح.'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                message: 'فشل في رفض تقرير التركيب: ' . $e->getMessage(),
                code: 422,
                internalCode: 'INSTALLATION_REPORT_REJECTION_FAILED'
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
