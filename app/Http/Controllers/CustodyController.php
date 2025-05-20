<?php

namespace App\Http\Controllers;

use App\Models\Custody;
use App\Models\CustodyItem;
use App\Models\Room;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;


class CustodyController extends Controller
{
    use ApiResponse;

    public function store(Request $request)
    {
        $validator= validator($request->all(),[
            'user_id'=>'required|exists:users,id',
            'room_id'=>'nullable|exists:rooms,id',
            'date'=>'required|date',
            'notes'=>'nullable|string',
            'items'=>'required|array|min:1',
            'items.*.product_id'=>'required|exists:products,id',
            'items.*.exit_note_id'=>'required|exists:exit_notes,id',
            'items.*.quantity'=>'required|numeric|min:0.01',
            'items.*.notes'=>'nullable|string',
        ]);

        if($validator->fails()){
            return $this->validationErrorResponse($validator);
        }
        DB::beginTransaction();
        try{
            $custody= Custody::create([
                'user_id'=>$request->user_id,
                'room_id'=>$request->room_id,
                'date'=>$request->date,
                'notes'=>$request->notes
            ]);
            foreach ($request->items as $item){
                CustodyItem::create([
                    'custody_id'=>$custody->id,
                    'product_id'=>$item['product_id'],
                    'exit_note_id'=>$item['exit_note_id'],
                    'quantity'=>$item['quantity'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }
            DB::commit();

            return $this->successResponse(
                $custody->load('items'),
                'تم إنشاء العهدة بنجاح',
                201
            );
        }catch (\Throwable $e){
            DB::rollBack();
            return $this->handleExceptionResponse($e);
        }
    }

    public function showAllForUser(Request $request)
    {
       $user=$request->user();
       $custodies= Custody::with('items.product')
           ->where('user_id',$user->id)
           ->get();

        if ($custodies->isEmpty()) {
            return $this->successResponse(null, 'لا يوجد لديك أي عهد حالياً.');
        }

        return $this->successResponse($custodies, 'تم استرداد العهد بنجاح.');
    }

    public function showSpecific(Request $request, $custodyId)
    {
        $user = $request->user();

        try {
            $custody = Custody::with('items.product')
                ->where('id', $custodyId)
                ->where('user_id', $user->id) // تأكد من أن المستخدم المصادق عليه هو صاحب العهدة
                ->get();

            if ($custody->isEmpty()) {
                return $this->notFoundResponse('العهدة غير موجودة أو لا تملك صلاحية عرضها.');
            }

            return $this->successResponse($custody, 'تم استرداد العهدة بنجاح.');

        } catch (\Throwable $e) {
            return $this->notFoundResponse('العهدة غير موجودة أو لا تملك صلاحية عرضها.');
        }
    }


    // نجيب كل العهد الموجودة في جدول العهد يعني لكل المستخدمين
    public function showAll(Request $request)
    {
        /**
        $user = $request->user();

        // **فحص الصلاحيات باستخدام الترايت**
        // استخدام UnauthorizedResponse من الترايت لإرجاع استجابة 403 بشكل موحد
        // (يفترض أن لديك طريقة لتعريف الصلاحيات، هنا استخدمنا isAdmin كمثال)
        if (!isset($user->isAdmin) || !$user->isAdmin) {
            return $this->unauthorizedResponse('ليس لديك صلاحية لعرض جميع العهد.');
        }

         * */
        $allCustodies = Custody::with('user', 'items.product')->get();

        // يمكنك أيضاً إضافة فحص هنا إذا كانت لا توجد أي عهدة على الإطلاق
        if ($allCustodies->isEmpty()) {
            return $this->successResponse(null, 'لا توجد أي عهد مسجلة حالياً.');
        }

        return $this->successResponse($allCustodies, 'تم استرداد جميع العهد بنجاح.');
    }

    public function showRoomCustodies(Request $request, Room $room)
    {
        // إذا كنت تريد تطبيق صلاحيات هنا (مثلاً، فقط المسؤولون يمكنهم رؤية عهد الغرف)
        // $user = $request->user();
        // if (!$user->isAdmin) {
        //     return $this->unauthorizedResponse('ليس لديك صلاحية لعرض عهد هذه الغرفة.');
        // }

        try {
            $custodies = Custody::with([
                'room.building', // جلب الغرفة والمبنى المرتبط بها
                'user',          // جلب المستخدم صاحب العهدة
                'items.product'  // جلب عناصر العهدة والمنتجات المرتبطة بها
            ])
                ->where('room_id', $room->id)
                ->get();


            if ($custodies->isEmpty()) {
                return $this->successResponse(null, 'لا توجد أي عهد مسجلة لهذه الغرفة حالياً.', 200);
            }

            // إرجاع البيانات
            return $this->successResponse($custodies, 'تم استرداد عهد الغرفة بنجاح.', 200);

        }
//        catch (ModelNotFoundException $e) {
            // هذا الخطأ لن يحدث هنا لأن Model Binding يعالج عدم وجود الغرفة
//             ولكن يمكن استخدامه إذا كنت لا تستخدم Model Binding وتستدعي findOrFail() يدوياً
//            return $this->notFoundResponse('الغرفة غير موجودة.', 404);
//        }
        catch (\Throwable $e) {
            return $this->handleExceptionResponse($e);
        }
    }
}
