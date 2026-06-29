<?php

use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes for Vistax AI DocScout
|--------------------------------------------------------------------------
*/

// Загрузка документа
Route::post('/documents/upload', [DocumentController::class, 'upload']);

// Анализ документа (SSE-стриминг)
Route::get('/documents/{document}/analyze', [DocumentController::class, 'analyze']);

// Информация о документе
Route::get('/documents/{document}', [DocumentController::class, 'show']);
