<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AfpController;
use App\Http\Controllers\IdsrController;
use App\Http\Controllers\SmsController;
use App\Http\Controllers\StartController;
use Illuminate\Support\Facades\Route;

/*
 * The pages call their API with absolute paths and no trailing slash (/api/...), so the paths are kept as they were.
 */

// ---------- sign-in and accounts ----------
Route::get('login', [AccountController::class, 'login']);
Route::get('logout', [AccountController::class, 'logout']);
Route::post('api/login', [AccountController::class, 'loginApi']);
Route::post('api/setup', [AccountController::class, 'loginApi']);
Route::post('api/password', [AccountController::class, 'passwordApi'])->middleware('api.auth:change');
Route::get('favicon.ico', fn () => redirect('/static/core/img/ghs-emblem.png'));

Route::middleware('page')->group(function () {
    Route::get('password', [AccountController::class, 'passwordPage']);
    Route::get('profile', [AccountController::class, 'profilePage']);
    Route::get('admin', [AccountController::class, 'accountsPage']);
    Route::get('start', [StartController::class, 'start']);
    Route::get('/', [AfpController::class, 'dashboard']);
    Route::get('index.html', [AfpController::class, 'dashboard']);
    Route::get('idsr', [IdsrController::class, 'page']);
});

Route::middleware('api.auth')->group(function () {
    Route::get('api/me', [AccountController::class, 'me']);
    Route::match(['get', 'post'], 'api/profile', [AccountController::class, 'profileApi']);
    Route::match(['get', 'post'], 'api/profile/photo', [AccountController::class, 'photoApi']);
    Route::match(['get', 'post'], 'api/admin/users', [AccountController::class, 'usersApi']);
    Route::post('api/region', [StartController::class, 'region']);

    // ---------- AFP 60-day follow-up ----------
    Route::get('api/data', [AfpController::class, 'data']);
    Route::get('api/schema', [AfpController::class, 'schema']);
    Route::get('api/next-epid', [AfpController::class, 'nextEpid']);
    Route::get('api/case', [AfpController::class, 'case']);
    Route::post('api/case/update', [AfpController::class, 'updateCase']);
    Route::post('api/case/delete', [AfpController::class, 'deleteCase']);
    Route::post('api/cases', [AfpController::class, 'newCase']);
    Route::post('api/import', [AfpController::class, 'importFile']);
    Route::get('api/register.xlsx', [AfpController::class, 'registerXlsx']);
    Route::post('api/report.xlsx', [AfpController::class, 'report']);

    // ---------- SMS to district officers ----------
    Route::get('api/sms/preview', [SmsController::class, 'preview']);
    Route::post('api/sms/send', [SmsController::class, 'send']);
    Route::get('api/sms/status', [SmsController::class, 'status']);
    Route::match(['get', 'post'], 'api/sms/officers', [SmsController::class, 'officers']);
    Route::match(['get', 'post'], 'api/sms/settings', [SmsController::class, 'settings']);
    Route::post('api/sms/test', [SmsController::class, 'test']);

    // ---------- Weekly IDSR ----------
    Route::get('api/idsr/data', [IdsrController::class, 'data']);
    Route::get('api/idsr/form.xlsx', [IdsrController::class, 'formXlsx']);
    Route::get('api/idsr/week.csv', [IdsrController::class, 'weekCsv']);
    Route::get('api/idsr/store.xlsx', [IdsrController::class, 'storeXlsx']);
    Route::post('api/idsr/week', [IdsrController::class, 'saveWeek']);
    Route::post('api/idsr/week/delete', [IdsrController::class, 'deleteWeek']);
    Route::post('api/idsr/import', [IdsrController::class, 'importFile']);
    Route::post('api/idsr/settings', [IdsrController::class, 'saveSettings']);
    Route::post('api/idsr/image', [IdsrController::class, 'image']);
    Route::get('api/idsr/dhims2', [IdsrController::class, 'dhims2Config']);
    Route::get('api/idsr/dhims2/datasets', [IdsrController::class, 'dhims2Datasets']);
    Route::get('api/idsr/dhims2/elements', [IdsrController::class, 'dhims2Elements']);
    Route::post('api/idsr/dhims2/connect', [IdsrController::class, 'dhims2Connect']);
    Route::post('api/idsr/dhims2/forget', [IdsrController::class, 'dhims2Forget']);
    Route::post('api/idsr/dhims2/mapping', [IdsrController::class, 'dhims2Mapping']);
    Route::post('api/idsr/dhims2/fetch', [IdsrController::class, 'dhims2Fetch']);
});
