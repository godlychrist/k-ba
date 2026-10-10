<?php

use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\MovementController;
use App\Http\Controllers\StatementController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/auth/{provider}/connect', [ExpenseController::class, 'connect'])->middleware('throttle:10,1');
Route::get('/auth/{provider}/callback', [ExpenseController::class, 'callback'])->middleware('throttle:10,1');
Route::get('/api/session', [ExpenseController::class, 'session']);
Route::middleware(['auth', 'throttle:120,1'])->group(function () {
    Route::get('/api/dashboard', [ExpenseController::class, 'dashboard']);
    Route::post('/api/sync', [ExpenseController::class, 'sync']);
    Route::post('/api/logout', [ExpenseController::class, 'logout']);
    Route::post('/api/statements/{id}/confirm', [StatementController::class, 'confirm']);
    Route::get('/api/statements/{id}/download', [StatementController::class, 'download']);
    Route::post('/api/balance-adjustments', [StatementController::class, 'adjust']);
    Route::post('/api/debit-card-links', [StatementController::class, 'linkCard']);
    Route::post('/api/movements', [MovementController::class, 'create']);
    Route::post('/api/movements/{id}', [MovementController::class, 'update']);
});
