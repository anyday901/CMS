<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\BrandingAssetController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Help;
use App\Http\Controllers\ThemeAssetController;
use App\Http\Middleware\EnsureClientCanLogIn;
use App\Http\Middleware\RequireStaffTwoFactor;
use Illuminate\Support\Facades\Route;

// Platform: theme files, health, languages, and the admin pages for mail, appearance and the system.

Route::get('health', HealthController::class)->middleware('throttle:60,1')->name('health');
Route::get('theme-assets/{theme}/{path}', ThemeAssetController::class)->where('path', '.*')->middleware('throttle:600,1')->name('theme.asset');
Route::get('branding/logo', BrandingAssetController::class)->middleware('throttle:600,1')->name('branding.logo');

// Anyone can pick the language of the portal; signed-in clients keep it on their account.
Route::post('language', Help\LanguageController::class)->middleware(['throttle:30,1', EnsureClientCanLogIn::class])->name('language');

Route::prefix('admin/settings')->name('admin.')->middleware(['auth:web', RequireStaffTwoFactor::class])->group(function () {
    Route::middleware('can:manage-settings')->group(function () {
        Route::get('mail', [Admin\MailSettingsController::class, 'edit'])->name('mail.edit');
        Route::put('mail', [Admin\MailSettingsController::class, 'update'])->name('mail.update');
        Route::post('mail/test', [Admin\MailSettingsController::class, 'test'])->middleware('throttle:10,1')->name('mail.test');

        Route::get('appearance', [Admin\AppearanceController::class, 'edit'])->name('appearance.edit');
        Route::put('appearance', [Admin\AppearanceController::class, 'update'])->name('appearance.update');
        Route::delete('appearance/logo', [Admin\AppearanceController::class, 'removeLogo'])->name('appearance.logo.destroy');

        Route::get('languages', [Admin\LanguageTextController::class, 'index'])->name('languages.index');
        Route::get('languages/{locale}', [Admin\LanguageTextController::class, 'edit'])->name('languages.edit');
        Route::put('languages/{locale}', [Admin\LanguageTextController::class, 'update'])->name('languages.update');
    });

    Route::middleware('can:manage-system')->group(function () {
        Route::get('system', [Admin\SystemController::class, 'health'])->name('system.health');
        Route::get('maintenance', [Admin\SystemController::class, 'maintenance'])->name('maintenance.edit');
        Route::put('maintenance', [Admin\SystemController::class, 'updateMaintenance'])->name('maintenance.update');

        Route::get('backups', [Admin\BackupController::class, 'index'])->name('backups.index');
        Route::post('backups', [Admin\BackupController::class, 'store'])->middleware('throttle:6,1')->name('backups.store');
        Route::put('backups/settings', [Admin\BackupController::class, 'settings'])->name('backups.settings');
        Route::get('backups/{name}', [Admin\BackupController::class, 'download'])->name('backups.download');
        Route::delete('backups/{name}', [Admin\BackupController::class, 'destroy'])->name('backups.destroy');

        Route::get('updates', [Admin\SystemController::class, 'updates'])->name('updates.index');
        Route::post('updates/check', [Admin\SystemController::class, 'checkUpdates'])->middleware('throttle:10,1')->name('updates.check');
        Route::post('updates', [Admin\SystemController::class, 'runUpdate'])->middleware('throttle:3,1')->name('updates.run');

        Route::get('marketplace', [Admin\MarketplaceController::class, 'index'])->name('marketplace.index');
        Route::post('marketplace/install', [Admin\MarketplaceController::class, 'install'])->middleware('throttle:10,1')->name('marketplace.install');
        Route::post('marketplace/upload', [Admin\MarketplaceController::class, 'upload'])->middleware('throttle:10,1')->name('marketplace.upload');
        Route::delete('marketplace/{type}/{key}', [Admin\MarketplaceController::class, 'destroy'])->whereIn('type', ['addon', 'theme'])->name('marketplace.destroy');

        Route::get('whmcs-modules', [Admin\WhmcsModuleController::class, 'index'])->name('whmcs-modules.index');
        Route::post('whmcs-modules', [Admin\WhmcsModuleController::class, 'store'])->middleware('throttle:10,1')->name('whmcs-modules.store');
        Route::put('whmcs-modules/{module}', [Admin\WhmcsModuleController::class, 'update'])->name('whmcs-modules.update');
        Route::post('whmcs-modules/{module}/enable', [Admin\WhmcsModuleController::class, 'enable'])->middleware('throttle:10,1')->name('whmcs-modules.enable');
        Route::post('whmcs-modules/{module}/disable', [Admin\WhmcsModuleController::class, 'disable'])->name('whmcs-modules.disable');
        Route::delete('whmcs-modules/{module}', [Admin\WhmcsModuleController::class, 'destroy'])->name('whmcs-modules.destroy');
        Route::post('whmcs-modules/{module}/convert', [Admin\WhmcsModuleController::class, 'convert'])->middleware('throttle:10,1')->name('whmcs-modules.convert');
        Route::get('converted-modules/{key}/download', [Admin\WhmcsModuleController::class, 'download'])->where('key', '[a-z][a-z0-9_]*')->name('converted-modules.download');
        Route::delete('converted-modules/{key}', [Admin\WhmcsModuleController::class, 'destroyConverted'])->where('key', '[a-z][a-z0-9_]*')->name('converted-modules.destroy');
    });
});
