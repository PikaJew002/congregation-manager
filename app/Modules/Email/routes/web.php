<?php

use App\Modules\Email\Http\Controllers\EmailController;
use App\Modules\Email\Http\Controllers\EmailSettingsController;
use App\Modules\Email\Http\Controllers\RecipientController;
use App\Modules\Email\Http\Controllers\RunController;
use Illuminate\Support\Facades\Route;

Route::get('/', [EmailController::class, 'index'])->name('index');
Route::get('new', [EmailController::class, 'create'])->name('create');
Route::post('/', [EmailController::class, 'store'])->name('store');
Route::post('preview', [EmailController::class, 'preview'])->name('preview');
Route::get('{email}/edit', [EmailController::class, 'edit'])->whereNumber('email')->name('edit');
Route::put('{email}', [EmailController::class, 'update'])->whereNumber('email')->name('update');
Route::delete('{email}', [EmailController::class, 'destroy'])->whereNumber('email')->name('destroy');
Route::post('{email}/duplicate', [EmailController::class, 'duplicate'])->whereNumber('email')->name('duplicate');
Route::post('{email}/test', [EmailController::class, 'sendTest'])->whereNumber('email')->name('test');

Route::get('settings', [EmailSettingsController::class, 'edit'])->name('settings');
Route::put('settings', [EmailSettingsController::class, 'update'])->name('settings.update');

Route::get('recipients', [RecipientController::class, 'index'])->name('recipients');
Route::post('recipients', [RecipientController::class, 'store'])->name('recipients.store');
Route::patch('recipients/{recipient}', [RecipientController::class, 'update'])->name('recipients.update');
Route::delete('recipients/{recipient}', [RecipientController::class, 'destroy'])->name('recipients.destroy');

Route::get('runs', [RunController::class, 'index'])->name('runs');
Route::get('runs/{run}', [RunController::class, 'show'])->name('runs.show');
Route::post('runs/{run}/cancel', [RunController::class, 'cancel'])->name('runs.cancel');
Route::post('runs/{run}/retry', [RunController::class, 'retry'])->name('runs.retry');
Route::post('runs/{run}/resume', [RunController::class, 'resume'])->name('runs.resume');
