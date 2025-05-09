<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use App\Traits\ApiResponse;

class AuthController extends Controller
{
    use ApiResponse;

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'password' => 'required|string|min:6|confirmed',
            'type'     => ['required', Rule::in(['user', 'manager', 'warehouseKeeper'])],
            'email'    => 'nullable|email|unique:users|unique:managers|unique:warehouse_keepers',
            'phone'    => 'nullable|string|unique:users|unique:managers|unique:warehouse_keepers',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        if (!$request->email && !$request->phone) {
            return $this->errorResponse('Email or phone is required', 422, [], 'AUTH_001');
        }

        $models = [
            'user'             => \App\Models\User::class,
            'manager'          => \App\Models\Manager::class,
            'warehouseKeeper'  => \App\Models\WarehouseKeeper::class,
        ];

        $modelClass = $models[$request->type];

        $data = [
            'name'     => $request->name,
            'email'    => $request->email,
            'phone'    => $request->phone,
            'password' => Hash::make($request->password),
        ];

        // إضافة الحقول الخاصة بالمستخدم
        if ($request->type == 'user') {
            $userValidator = Validator::make($request->all(), [
                'department_id' => 'required|exists:departments,id',
                'job_title'     => 'required|string|max:255',
            ]);

            if ($userValidator->fails()) {
                return $this->validationErrorResponse($userValidator);
            }

            $data['department_id'] = $request->department_id;
            $data['role'] = $request->role;
            $data['job_title'] = $request->job_title;
        }

        try {
            $user = $modelClass::create($data);
            $token = $user->createToken('auth_token', [$request->type])->plainTextToken;

            $responseData = [
                'access_token' => $token,
                'token_type'   => 'Bearer',
                'user'         => $user,
                'role'         => $request->type,
            ];

            return $this->successResponse($responseData, 'Registered successfully', 201);
        } catch (\Exception $e) {
            return $this->handleExceptionResponse($e, 'Registration failed');
        }
    }

    public function login(Request $request)
    {
        $request->validate([
            'login'    => 'required',
            'password' => 'required',
            'type'     => 'required|in:user,manager,warehouseKeeper',
            'platform' => 'required|in:web,mobile',
        ]);

        $login_type = filter_var($request->login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        $models = [
            'user'             => \App\Models\User::class,
            'manager'          => \App\Models\Manager::class,
            'warehouseKeeper'  => \App\Models\WarehouseKeeper::class,
        ];

        $modelClass = $models[$request->type];

        $user = $modelClass::where($login_type, $request->login)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->unauthorizedResponse('بيانات الدخول غير صحيحة');
        }

        // تحقق من صلاحية نوع المستخدم للمنصة
        $platformAccess = [
            'web' => ['manager', 'warehouseKeeper'],
            'mobile' => ['user', 'warehouseKeeper'],
        ];

        if (!in_array($request->type, $platformAccess[$request->platform])) {
            return $this->unauthorizedResponse('هذا النوع غير مصرح له بالدخول من هذه المنصة');
        }

        $token = $user->createToken('auth_token', [$request->type])->plainTextToken;

        return $this->successResponse([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $user,
            'role'         => $request->type,
            'platform'     => $request->platform,
        ], 'تم تسجيل الدخول بنجاح');
    }


    public function logout(Request $request)
    {
        try {
            $request->user()->currentAccessToken()->delete();
            return $this->successMessage('تم تسجيل الخروج بنجاح');
        } catch (\Exception $e) {
            return $this->handleExceptionResponse($e, 'Logout failed');
        }
    }
}
