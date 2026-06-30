<?php

use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes for Vistax AI DocScout
|--------------------------------------------------------------------------
*/

// Список документов (навигация)
Route::get('/documents', [DocumentController::class, 'index']);

// Загрузка документа
Route::post('/documents/upload', [DocumentController::class, 'upload']);

// Информация о документе
Route::get('/documents/{document:int}', [DocumentController::class, 'show']);

// Анализ документа (SSE-стриминг)
Route::get('/documents/{document:int}/analyze', [DocumentController::class, 'analyze']);

// Чат с документом (вопрос-ответ, SSE-стриминг)
Route::post('/documents/{document:int}/chat', [DocumentController::class, 'chat']);

// История чата для документа
Route::get('/documents/{document:int}/chat/history', [DocumentController::class, 'chatHistory']);

// Удаление документа
Route::delete('/documents/{document:int}', [DocumentController::class, 'destroy']);
