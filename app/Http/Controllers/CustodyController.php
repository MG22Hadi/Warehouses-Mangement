<?php

namespace App\Http\Controllers;

use App\Models\Custody;
use App\Models\CustodyItem;
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
}
