<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DepartmentController extends Controller
{
    use ApiResponse;
    // CREATE - إضافة قسم جديد
    public function store(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'manager_id' => 'required|exists:managers,id',
                'description' => 'nullable|string',
            ]);

            $department = Department::create($validated);

            return $this->successResponse($department, 'تم إنشاء القسم بنجاح', 201);
        }, 3); // 3 = عدد محاولات إعادة التنفيذ إذا صار Deadlock
    }

    // READ - جلب جميع الأقسام
    public function index()
    {
        try {
            $departments = Department::with('manager')->get();
            return $this->successResponse($departments, 'تم جلب الأقسام بنجاح');
        } catch (\Throwable $e) {
            return $this->handleExceptionResponse($e, 'فشل في جلب الأقسام');
        }
    }

    // READ - جلب قسم واحد حسب ID
    public function show($id)
    {
        try {
            $department = Department::with('manager')->find($id);

            if (!$department) {
                return $this->notFoundResponse('القسم غير موجود');
            }

            return $this->successResponse($department, 'تم جلب بيانات القسم بنجاح');
        } catch (\Throwable $e) {
            return $this->handleExceptionResponse($e, 'فشل في جلب بيانات القسم');
        }
    }

    // UPDATE - تحديث بيانات قسم
    public function update(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $department = Department::find($id);

            if (!$department) {
                return $this->notFoundResponse('القسم غير موجود');
            }

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'manager_id' => 'sometimes|exists:managers,id',
                'description' => 'nullable|string',
            ]);

            $department->update($validated);

            return $this->successResponse($department, 'تم تحديث بيانات القسم بنجاح');
        });
    }

    // DELETE - حذف قسم
    public function destroy($id)
    {
        return DB::transaction(function () use ($id) {
            $department = Department::find($id);

            if (!$department) {
                return $this->notFoundResponse('القسم غير موجود');
            }

            $department->delete();

            return $this->successResponse(null,'تم حذف القسم بنجاح');
        });
    }
}
