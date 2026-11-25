<?php

use App\Modules\ServiceSlides\Http\Controllers\AccessController;
use App\Modules\ServiceSlides\Http\Controllers\DeckController;
use App\Modules\ServiceSlides\Http\Controllers\HistoryController;
use App\Modules\ServiceSlides\Http\Controllers\LibraryController;
use App\Modules\ServiceSlides\Http\Controllers\ServiceController;
use App\Modules\ServiceSlides\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

Route::get('library', [LibraryController::class, 'index'])->name('library');
Route::get('library/search', [LibraryController::class, 'search'])->name('library.search');
Route::post('library', [LibraryController::class, 'store'])->name('library.store');
Route::put('library/{source}', [LibraryController::class, 'update'])->name('library.update');
Route::get('library/{source}/download', [LibraryController::class, 'download'])->name('library.download');
Route::post('library/{source}/file', [LibraryController::class, 'replaceFile'])->name('library.file');
Route::delete('library/{source}', [LibraryController::class, 'archive'])->name('library.destroy');
Route::post('library/{source}/restore', [LibraryController::class, 'restore'])->name('library.restore');
Route::post('library/{source}/aliases', [LibraryController::class, 'storeAlias'])->name('library.aliases');
Route::delete('aliases/{alias}', [LibraryController::class, 'destroyAlias'])->name('aliases.destroy');

Route::get('templates', [TemplateController::class, 'index'])->name('templates');
Route::post('templates', [TemplateController::class, 'store'])->name('templates.store');
Route::put('templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
Route::put('templates/{template}/preset', [TemplateController::class, 'updatePreset'])->name('templates.preset');
Route::post('templates/{template}/file', [TemplateController::class, 'replaceFile'])->name('templates.file');
Route::patch('template-slides/{slide}', [TemplateController::class, 'updateSlide'])->name('template-slides.update');

Route::get('services', [ServiceController::class, 'index'])->name('services');
Route::post('services', [ServiceController::class, 'store'])->name('services.store');
Route::post('services/import', [ServiceController::class, 'import'])->name('services.import');
Route::get('services/{service}', [ServiceController::class, 'show'])->name('services.show');
Route::put('services/{service}', [ServiceController::class, 'update'])->name('services.update');
Route::delete('services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');
Route::post('services/{service}/decks', [ServiceController::class, 'storeDeck'])->name('services.decks.store');
Route::post('services/{service}/generate', [ServiceController::class, 'generateAll'])->name('services.generate');
Route::get('services/{service}/download', [ServiceController::class, 'downloadAll'])->name('services.download');

Route::put('decks/{deck}', [DeckController::class, 'update'])->name('decks.update');
Route::delete('decks/{deck}', [DeckController::class, 'destroy'])->name('decks.destroy');
Route::put('decks/{deck}/entries', [DeckController::class, 'updateEntries'])->name('decks.entries');
Route::post('decks/{deck}/reset', [DeckController::class, 'reset'])->name('decks.reset');
Route::post('decks/{deck}/fill', [DeckController::class, 'fill'])->name('decks.fill');
Route::post('decks/{deck}/generate', [DeckController::class, 'generate'])->name('decks.generate');
Route::get('decks/{deck}/download', [DeckController::class, 'download'])->name('decks.download');

Route::get('history', HistoryController::class)->name('history');

Route::get('settings', [AccessController::class, 'index'])->name('settings');
Route::delete('connections/{connection}', [AccessController::class, 'destroyConnection'])->name('connections.destroy');
