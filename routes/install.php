<?php

use App\Http\Controllers\InstallController;
use Illuminate\Support\Facades\Route;

// The web installer, loaded without the web middleware: a new install may have no database for sessions yet.
// It answers until the first staff account exists.
Route::prefix('install')->name('install.')->controller(InstallController::class)->group(function () {
    Route::get('/', 'show')->name('show');
    Route::post('database', 'database')->name('database');
    Route::post('finish', 'finish')->name('finish');
});
