<?php

namespace App\Http\Controllers;

use App\Models\CalendarNote;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CalendarNoteController extends Controller
{
    use ApiResponse;

    public function index()
    {
        try{
            $notes=CalendarNote::where('user_id', Auth::id())->get();//orderBy('note_date','asc')->get();
            return $this->successResponse($notes,
                'تم جلب ملاحظات التقويم بنجاح');
        }catch (\Throwable $e){
            return $this->handleExceptionResponse($e);
        }
    }


    public function store(Request $request)
    {
        $validator= Validator::make($request->all(),[
            'note_date'=>'required|date',
            'noteContent'=>'required|string'
        ]);

        if($validator->fails()){
            return $this->validationErrorResponse($validator);
        }
        $note = CalendarNote::updateOrCreate(
            [
                'note_date' => $request->note_date,
                'user_id' => Auth::id()
            ],
            ['noteContent' => $request->noteContent]
        );
        return $this->successResponse($note,'تم حفظ الملاحظة بنجاح',201);
    }


    public function show($date)
    {
        try {
            $validator = Validator::make(
                ['date' => $date],
                ['date' => 'required|date']
            );


        if ($validator->fails()){
            return $this->validationErrorResponse($validator,'تاريخ غير صالح');
        }

            $note = CalendarNote::where('note_date', $date)
                ->where('user_id',Auth::id())
                ->first();

            if(!$note){
                return $this->notFoundResponse('لا توجد ملاحظة لهذا التاريخ');
            }
            return $this->successResponse($note, 'تم جلب الملاحظة بنجاح');
        }catch (\Throwable $e) {
            return $this->handleExceptionResponse($e);
        }
    }


    public function update(Request $request, $date)
    {
        DB::beginTransaction();
        try{
            $validator= Validator::make($request->all(),[
                'noteContent'=>'required|string|max:2000'
            ]);

            if ($validator->fails()){
                DB::rollBack();
                return $this->validationErrorResponse($validator);
            }
            $note = CalendarNote::where('note_date',$date)
                ->where('user_id',Auth::id())
                ->first();

            if(!$note){
                DB::rollBack();
                return $this->notFoundResponse('الملاحظة غير موجودة');
            }

            $note->update(['noteContent'=>$request->noteContent]);

            DB::commit();

            return $this->successResponse($note,'تم تحديث الملاحظة بنجاح',201);
        }catch (\Throwable $e){
            DB::rollBack();
            return $this->handleExceptionResponse($e,'فشل في تحديث الملاحظة');
        }
    }


    public function destroy($date)
    {
        DB::beginTransaction();
        try {
            $validator=Validator::make(['date' => $date],[
                'date'=>'required|date'
            ]);

            if ($validator->fails()){
                DB::rollBack();
                return $this->validationErrorResponse($validator);
            }

            $note=CalendarNote::where('note_date',$date)
                   ->where('user_id',Auth::id())
                   ->first();

            if (!$note){
                DB::rollBack();
                return $this->notFoundResponse('الملاحظة غير موجودة');
            }

            $note->delete();
            DB::commit();

            return $this->successResponse(null,'تم حذف الملاحظة بنجاح',201);
        }catch (\Throwable $e) {
            DB::rollBack();
            return $this->handleExceptionResponse($e, 'فشل في حذف الملاحظة');
        }
    }
}
