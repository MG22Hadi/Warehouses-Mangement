<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\CustodyController;
use App\Http\Controllers\EntryNoteController;
use App\Http\Controllers\ExitNoteController;
use App\Http\Controllers\InstallationReportController;
use App\Http\Controllers\MaterialRequestController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\ScrapNoteController;
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
    Route::get('/EntryNote/{id}/details',[EntryNoteController::class,'show']);
    Route::post('/entryNote',[EntryNoteController::class,'store']);

    //EXIT NOTE
    Route::get('/allExitNote',[ExitNoteController::class,'index']);
    Route::get('/ExitNote/{id}/details',[ExitNoteController::class,'show']);
    Route::post('/exitNote',[ExitNoteController::class,'store']);

    //    WAREHOUSE
    Route::post('/warehouses/store', [WarehouseController::class, 'store']);
    Route::put('/warehouses/update/{id}', [WarehouseController::class, 'update']);
    Route::delete('/warehouses/destroy/{id}', [WarehouseController::class, 'destroy']);
    Route::get('/warehouses/index', [WarehouseController::class, 'index']);


    //    PRODUCTS
    Route::post('/products/store', [ProductController::class, 'store']);
    Route::put('products/update/{id}', [ProductController::class, 'update']);
    Route::delete('products/delete/{id}', [ProductController::class, 'destroy']);
    Route::get('/products',[ProductController::class,'index']);

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

    //ROOMS
    Route::post('/rooms/store', [RoomController::class, 'store']);
    Route::put('rooms/update/{id}', [RoomController::class, 'update']);
    Route::delete('rooms/delete/{id}', [RoomController::class, 'destroy']);
    Route::get('/rooms',[RoomController::class,'index']);

    //Scrap Note
    Route::get('/allScrapNote',[ScrapNoteController::class,'index']);
    Route::get('/scrapNote/{id}/details',[ScrapNoteController::class,'show']);
    Route::post('/scrapNote/store', [ScrapNoteController::class, 'store']);
    Route::put('scrapNotes/{id}/approve', [ScrapNoteController::class, 'approve']);
    Route::put('scrapNotes/{id}/reject', [ScrapNoteController::class, 'reject']);

    // InstallationReport
    Route::get('/allInstallationReport',[InstallationReportController::class,'index']);
    Route::get('/InstallationReport/{id}/details',[InstallationReportController::class,'show']);
    Route::post('/InstallationReport/store', [InstallationReportController::class, 'store']);

    // product Movement


});


