<?php

namespace App\Http\Controllers;

use App\Models\CalendarNote;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CalendarNoteController extends Controller
{
    use ApiResponse;

    public function index()
    {
        try{
            $notes=CalendarNote::orderBy('note_date','asc')->get();
            return $this->successResponse($notes,
                'تم جلب ملاحظات التقويم بنجاح');
        }catch (\Throwable $e){
            return $this->handleExceptionResponse($e);
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
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

            $note = CalendarNote::where('note_date', $date)->first();

            if(!$note){
                return $this->notFoundResponse('لا توجد ملاحظة لهذا التاريخ');
            }
            return $this->successResponse($note, 'تم جلب الملاحظة بنجاح');
        }catch (\Throwable $e) {
            return $this->handleExceptionResponse($e);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(CalendarNote $calendarNote)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, CalendarNote $calendarNote)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(CalendarNote $calendarNote)
    {
        //
    }
}
