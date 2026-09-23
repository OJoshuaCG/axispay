<?php

use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Language switcher target (<x-language-switcher>). POST: it changes state.
Route::post('/locale', LocaleController::class)->name('locale.update');

// Design-system preview: local environment only.
if (app()->environment('local')) {
    Route::view('/design-system', 'design-system')->name('design-system');
}
