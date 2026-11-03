<?php

use App\Http\Controllers\InstallController;
use Illuminate\Support\Facades\Route;

// Setup from the browser (no sessions or cookies: it runs before the database exists). Needs ?token=INSTALL_TOKEN.
Route::get('install', [InstallController::class, 'show']);
Route::post('install', [InstallController::class, 'run']);
