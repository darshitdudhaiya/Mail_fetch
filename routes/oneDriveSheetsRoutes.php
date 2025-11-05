<?php

use App\Http\Controllers\OneDriveSheetController;
use Illuminate\Support\Facades\Route;

Route::prefix('sheets')->group(function () {
    Route::get('/sheet-data', [OneDriveSheetController::class, 'downloadExcelFile']);
});
