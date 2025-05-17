<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\CalendarNoteController;
use App\Http\Controllers\CustodyController;
use App\Http\Controllers\EntryNoteController;
use App\Http\Controllers\ExitNoteController;
use App\Http\Controllers\MaterialRequestController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\WarehouseController;
use App\Models\EntryNote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


Route::post('/login', [AuthController::class, 'login']);

Route::post('/register', [AuthController::class, 'register']);


Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/products/search', [ProductController::class, 'search']);
    Route::get('/products/{id}/details', [ProductController::class, 'details']);


    //ENTRY NOTE

    Route::get('/allEntryNote',[EntryNoteController::class,'index']);
    Route::post('/entryNote',[EntryNoteController::class,'store']);

    //EXIT NOTE
    Route::get('/allExitNote',[ExitNoteController::class,'index']);
    Route::post('/exitNote',[ExitNoteController::class,'store']);

    //    WAREHOUSE
    Route::post('/warehouses/store', [WarehouseController::class, 'store']);
    Route::put('/warehouses/update/{id}', [WarehouseController::class, 'update']);
    Route::delete('/warehouses/destroy/{id}', [WarehouseController::class, 'destroy']);
    Route::get('/warehouses/index', [WarehouseController::class, 'index']);
    Route::get('warehouses/show/{id}', [WarehouseController::class, 'show']);


    //    PRODUCTS
    Route::post('/products/store', [ProductController::class, 'store']);
    Route::put('products/update/{id}', [ProductController::class, 'update']);
    Route::delete('products/delete/{id}', [ProductController::class, 'destroy']);
    Route::get('/products',[ProductController::class,'index']);
    Route::get('products/show/{id}', [ProductController::class, 'show']);


    //MATERIAL REQUEST
    Route::post('/MRequest',[MaterialRequestController::class,'store']);
    Route::get('/allRequestMaterial',[MaterialRequestController::class,'index']);
    Route::get('/pendingRequestMaterial',[MaterialRequestController::class,'pendingRequests']);
    Route::put('/materialRequests/{id}/edit', [MaterialRequestController::class, 'editRequest']);
    Route::put('/materialRequests/{id}/reject', [MaterialRequestController::class, 'rejectRequest']);
    Route::put('/materialRequests/{id}/approve', [MaterialRequestController::class, 'approveRequest']);



    //CUSTODY
    Route::post('/custody/store', [CustodyController::class, 'store']);

    //BUILDINGS
    Route::post('/buildings/store', [BuildingController::class, 'store']);
    Route::put('buildings/update/{id}', [BuildingController::class, 'update']);
    Route::delete('buildings/delete/{id}', [BuildingController::class, 'destroy']);
    Route::get('/buildings',[BuildingController::class,'index']);
    Route::get('buildings/show/{id}', [BuildingController::class, 'show']);


    //ROOMS
    Route::post('/rooms/store', [RoomController::class, 'store']);
    Route::put('rooms/update/{id}', [RoomController::class, 'update']);
    Route::delete('rooms/delete/{id}', [RoomController::class, 'destroy']);
    Route::get('/rooms',[RoomController::class,'index']);
    Route::get('rooms/show/{id}', [RoomController::class, 'show']);

    //CALENDAR
    Route::get('/calendar', [CalendarNoteController::class, 'index']);
    Route::post('/calendar/store', [CalendarNoteController::class, 'store']);
    Route::get('/calendar/show/{date}', [CalendarNoteController::class, 'show']);
    Route::put('/calendar/update/{date}', [CalendarNoteController::class, 'update']);
    Route::delete('/calendar/destroy/{date}', [CalendarNoteController::class, 'destroy']);

});
