<?php

namespace App\Http\Controllers;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    //
    public function register(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'password' => 'required|string|min:6|confirmed',
            'type'     => ['required', Rule::in(['user', 'manager', 'warehouseKeeper'])],
            'email'    => 'nullable|email|unique:users|unique:managers|unique:warehouse_keepers',
            'phone'    => 'nullable|string|unique:users|unique:managers|unique:warehouse_keepers',
        ]);

        if (!$request->email && !$request->phone) {
            return response()->json(['message' => 'Email or phone is required.'], 422);
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
        if ($request->type =='user' ) {
            $request->validate([
                'department_id' => 'required|exists:departments,id',
                'job_title'     => 'required|string|max:255',
            ]);
            $data['department_id'] = $request->department_id;
            $data['role'] = $request->role;
            $data['job_title'] = $request->job_title;
        }

        $user = $modelClass::create($data);


        $token = $user->createToken('auth_token', [$request->type])->plainTextToken;

        return response()->json([
            'message'      => 'Registered successfully',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $user,
            'role'         => $request->type,
        ], 201);
    }

    public function login(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'login'    => 'required', // هذا سيكون إما إيميل أو رقم
            'password' => 'required',
            'type'     => 'required|in:user,manager,warehouseKeeper',
        ]);

        $guard = $request->type;

        // تحديد ما إذا كان login هو إيميل أو رقم
        $login_type = filter_var($request->login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';


        // نحدد أي موديل نستخدم حسب النوع
        $models = [
            'user'        => \App\Models\User::class,
            'admin'       => \App\Models\Manager::class,
            'warehouseKeeper' => \App\Models\WarehouseKeeper::class,
        ];

        $modelClass = $models[$request->type];

        $user = $modelClass::where($login_type, $request->login)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // abilities to restrict access per type
        $abilities = [
            'manager'        => ['manager'],
            'warehouseKeeper'  => ['warehouseKeeper'],
            'user'         => ['user'],
        ];

        $token = $user->createToken('auth_token', $abilities[$guard])->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $user,
            'role'         => $guard
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }
}
