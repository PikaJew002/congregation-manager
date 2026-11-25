<?php

use App\Modules\ZoomRecordings\Http\Controllers\ZoomRecordingController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ZoomRecordingController::class, 'index'])->name('index');
Route::patch('recordings/{recording}', [ZoomRecordingController::class, 'update'])->name('update');
Route::delete('recordings/{recording}', [ZoomRecordingController::class, 'destroy'])->name('destroy');
Route::post('recordings/{recording}/retry', [ZoomRecordingController::class, 'retry'])->name('retry');
Route::get('settings', [ZoomRecordingController::class, 'settings'])->name('settings');
Route::put('settings', [ZoomRecordingController::class, 'updateSettings'])->name('settings.update');
