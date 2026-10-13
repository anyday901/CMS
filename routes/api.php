<?php

use App\Http\Controllers\Api\LicenseCheckController;
use Illuminate\Support\Facades\Route;

// Licensed software checks its key here (see docs/licensing.md).
Route::post('licenses/check', LicenseCheckController::class)->middleware('throttle:60,1')->name('api.licenses.check');
