<?php

use App\Modules\WeeklyEmail\Http\Controllers\WeeklyEmailController;
use Illuminate\Support\Facades\Route;

Route::get('recipients', [WeeklyEmailController::class, 'recipients'])->name('recipients');
Route::post('recipients', [WeeklyEmailController::class, 'storeRecipients'])->name('recipients.store');
Route::patch('recipients/{recipient}', [WeeklyEmailController::class, 'updateRecipient'])->name('recipients.update');
Route::delete('recipients/{recipient}', [WeeklyEmailController::class, 'destroyRecipient'])->name('recipients.destroy');

Route::get('template', [WeeklyEmailController::class, 'template'])->name('template');
Route::put('template', [WeeklyEmailController::class, 'updateTemplate'])->name('template.update');
Route::post('template/preview', [WeeklyEmailController::class, 'previewTemplate'])->name('template.preview');
Route::post('template/test', [WeeklyEmailController::class, 'sendTest'])->name('template.test');

Route::get('schedule', [WeeklyEmailController::class, 'schedule'])->name('schedule');
Route::put('schedule', [WeeklyEmailController::class, 'updateSchedule'])->name('schedule.update');

Route::get('runs', [WeeklyEmailController::class, 'runs'])->name('runs');
Route::get('runs/{run}', [WeeklyEmailController::class, 'showRun'])->name('runs.show');
Route::post('runs/{run}/cancel', [WeeklyEmailController::class, 'cancelRun'])->name('runs.cancel');
Route::post('runs/{run}/retry', [WeeklyEmailController::class, 'retryRun'])->name('runs.retry');
Route::post('runs/{run}/resume', [WeeklyEmailController::class, 'resumeRun'])->name('runs.resume');
