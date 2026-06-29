<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentAnalysis;
use App\Services\FileParserService;
use App\Services\OllamaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(
        private readonly FileParserService $parserService,
        private readonly OllamaService $ollamaService,
    ) {}
    
    /**
     * Загрузка документа
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240|mimes:pdf,txt,json',
            'preset' => 'required|in:legal_audit,invoice_check,free_chat',
        ]);
        
        $file = $request->file('file');
        $preset = $request->input('preset');
        
        // Расчёт хэша
        $fileHash = hash_file('sha256', $file->getRealPath());
        
        // Проверка на дубликат
        $existingDocument = Document::where('file_hash', $fileHash)->first();
        
        if ($existingDocument) {
            // Дубликат найден — продлеваем TTL кэша
            Cache::tags(['document_' . $existingDocument->id])->touch(3600);
            
            return response()->json([
                'id' => $existingDocument->id,
                'file_name' => $existingDocument->file_name,
                'cached' => true,
            ]);
        }
        
        // Новый файл — парсим и сохраняем
        $parsedData = $this->parserService->parse($file);
        
        $document = Document::create([
            'file_name' => $file->getClientOriginalName(),
            'file_hash' => $parsedData['file_hash'],
            'file_path' => $parsedData['file_path'],
            'raw_text' => $parsedData['raw_text'],
        ]);
        
        return response()->json([
            'id' => $document->id,
            'file_name' => $document->file_name,
            'cached' => false,
        ]);
    }
    
    /**
     * Анализ документа с SSE-стримингом
     */
    public function analyze(Document $document, Request $request): StreamedResponse
    {
        $preset = $request->query('preset', 'legal_audit');
        $model = $request->query('model', 'gemma2:2b');
        
        // Проверка на закэшированный результат
        $cacheKey = "analysis_{$document->id}_{$preset}_{$model}";
        $cachedResult = Cache::get($cacheKey);
        
        if ($cachedResult) {
            // Возвращаем закэшированный результат как SSE
            return new StreamedResponse(function () use ($cachedResult) {
                foreach (str_split($cachedResult) as $char) {
                    $sseData = json_encode(['text' => $char]);
                    echo "data: {$sseData}\n\n";
                    ob_flush();
                    flush();
                    usleep(1000); // Имитация стриминга
                }
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Access-Control-Allow-Origin' => 'http://localhost:5173',
                'Connection' => 'keep-alive',
            ]);
        }
        
        // Запускаем стриминг от Ollama
        return $this->ollamaService->streamAnalysis(
            $document->raw_text,
            $preset,
            $model
        );
    }
    
    /**
     * Получение информации о документе
     */
    public function show(Document $document): JsonResponse
    {
        return response()->json([
            'id' => $document->id,
            'file_name' => $document->file_name,
            'raw_text' => $document->raw_text,
            'created_at' => $document->created_at->toIso8601String(),
            'analyses' => $document->analyses()->get(['id', 'preset', 'ai_model', 'created_at']),
        ]);
    }
}
