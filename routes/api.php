<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EntryNoteController;
use App\Http\Controllers\ExitNoteController;
use App\Http\Controllers\MaterialRequestController;
use App\Http\Controllers\ProductController;
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

    Route::get('/allEntryNote',[EntryNoteController::class,'index']);
    Route::post('/entryNote',[EntryNoteController::class,'store']);

    Route::get('/allExitNote',[ExitNoteController::class,'index']);
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

    Route::post('/MRequest',[MaterialRequestController::class,'store']);
    Route::get('/allRequestMaterial',[ExitNoteController::class,'index']);
});


