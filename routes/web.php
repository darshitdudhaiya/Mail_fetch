<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SheetController;
use Illuminate\Support\Facades\Route;


Route::post('/auth/token', [AuthController::class, 'handleToken']);
Route::get('/auth/user', [AuthController::class, 'getCurrentUser']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::get('/emails/unreplied', [AuthController::class, 'getUnrepliedEmails']);
Route::get('/emails/{messageId}', [AuthController::class, 'getEmailDetails']);


Route::get('/sheet-data', [SheetController::class, 'getSheetData']);
