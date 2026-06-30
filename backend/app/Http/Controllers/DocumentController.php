<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentAnalysis;
use App\Services\FileParserService;
use App\Services\OllamaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
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
            'file' => 'required|file|max:10240|mimes:pdf,txt,json,docx,doc',
        ], [
            'file.mimes' => 'Неподдерживаемый формат файла. Разрешены: PDF, TXT, JSON, DOCX, DOC',
        ]);
        
        $file = $request->file('file');
        
        \Log::info('Document upload', [
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
        ]);
        
        // Расчёт хэша
        $fileHash = hash_file('sha256', $file->getRealPath());
        
        // Проверка на дубликат через кэш
        $cacheKey = "doc_analysis:{$fileHash}";
        
        if (Cache::has($cacheKey)) {
            // Дубликат найден — проверяем, существует ли документ в БД
            $cachedData = Cache::get($cacheKey);
            
            $existingDocument = Document::find($cachedData['id']);
            
            if ($existingDocument) {
                // Документ существует — продлеваем TTL
                Cache::put($cacheKey, $cachedData, now()->addDays(7));
                
                // Продление TTL напрямую в Redis
                Redis::expire(config('cache.prefix') . $cacheKey, 604800);
                
                return response()->json([
                    'id' => $cachedData['id'],
                    'file_name' => $cachedData['file_name'],
                    'cached' => true,
                ]);
            } else {
                // Документ удалён — очищаем кэш
                Cache::forget($cacheKey);
            }
        }
        
        // Новый файл — парсим и сохраняем
        $parsedData = $this->parserService->parse($file);
        
        $document = Document::create([
            'file_name' => $file->getClientOriginalName(),
            'file_hash' => $parsedData['file_hash'],
            'file_path' => $parsedData['file_path'],
            'raw_text' => $parsedData['raw_text'],
        ]);
        
        // Сохраняем в кэш для быстрого поиска дубликатов
        Cache::put($cacheKey, [
            'id' => $document->id,
            'file_name' => $document->file_name,
        ], now()->addDays(7));
        
        return response()->json([
            'id' => $document->id,
            'file_name' => $document->file_name,
            'cached' => false,
        ]);
    }
        
    /**
     * Анализ документа с SSE-стримингом
     */
    public function analyze(Request $request, int $document): StreamedResponse
    {
        $model = $request->query('model', 'gemma3:4b');
        
        $documentModel = Document::findOrFail($document);
        
        // Проверка на закэшированный результат в БД
        $analysis = DocumentAnalysis::where('document_id', $document)
            ->where('preset', 'universal')
            ->where('ai_model', $model)
            ->first();
        
        if ($analysis && $analysis->result_text) {
            // Возвращаем сохранённый результат как SSE
            return new StreamedResponse(function () use ($analysis) {
                header('Content-Type: text/event-stream; charset=UTF-8');
                
                foreach (mb_str_split($analysis->result_text, 1, 'UTF-8') as $char) {
                    $sseData = json_encode(['text' => $char], JSON_UNESCAPED_UNICODE);
                    echo "data: {$sseData}\n\n";
                    @ob_flush();
                    flush();
                    usleep(1000);
                }
            }, 200, [
                'Content-Type' => 'text/event-stream; charset=UTF-8',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
                'Access-Control-Allow-Origin' => 'http://localhost:5173',
                'Connection' => 'keep-alive',
            ]);
        }
        
        // Запускаем стриминг от Ollama с сохранением результата
        return $this->ollamaService->streamAnalysisWithSave(
            $documentModel->raw_text,
            $model,
            $document
        );
    }
    
    /**
     * Получение информации о документе с сохранёнными анализами
     */
    public function show(int $document): JsonResponse
    {
        $documentModel = Document::findOrFail($document);
        
        // Получаем все сохранённые анализы для этого документа
        $analyses = DocumentAnalysis::where('document_id', $document)
            ->get(['id', 'preset', 'ai_model', 'result_text', 'created_at', 'updated_at']);
        
        return response()->json([
            'id' => $documentModel->id,
            'file_name' => $documentModel->file_name,
            'raw_text' => $documentModel->raw_text,
            'created_at' => $documentModel->created_at->toIso8601String(),
            'analyses' => $analyses->map(fn($analysis) => [
                'id' => $analysis->id,
                'preset' => $analysis->preset,
                'ai_model' => $analysis->ai_model,
                'result_text' => $analysis->result_text,
                'created_at' => $analysis->created_at->toIso8601String(),
                'updated_at' => $analysis->updated_at->toIso8601String(),
            ]),
        ]);
    }
        
    /**
     * Список всех документов (для навигации)
     */
    public function index(): JsonResponse
    {
        $documents = Document::orderByDesc('created_at')
            ->get(['id', 'file_name', 'created_at']);
        
        return response()->json([
            'documents' => $documents->map(fn($doc) => [
                'id' => $doc->id,
                'file_name' => $doc->file_name,
                'created_at' => $doc->created_at->toIso8601String(),
                'created_at_formatted' => $doc->created_at->format('d.m.Y H:i'),
            ]),
        ]);
    }
        
    /**
     * Удаление документа
     */
    public function destroy(int $document): JsonResponse
    {
        $documentModel = Document::findOrFail($document);
        
        // Удаляем файл из storage
        if (file_exists($documentModel->file_path)) {
            unlink($documentModel->file_path);
        }
        
        // Удаляем запись из БД (анализы удалятся каскадом)
        $documentModel->delete();
        
        // Очищаем кэш
        $cacheKey = "doc_analysis:{$documentModel->file_hash}";
        Cache::forget($cacheKey);
        
        $presetCacheKey = "doc_preset:{$document}";
        Cache::forget($presetCacheKey);
        
        return response()->json([
            'success' => true,
            'message' => 'Документ успешно удалён',
        ]);
    }
    
    /**
     * Чат с документом (вопрос-ответ с SSE-стримингом)
     */
    public function chat(Request $request, int $document): StreamedResponse
    {
        $request->validate([
            'question' => 'required|string|max:2000',
        ]);
        
        $question = $request->input('question');
        $documentModel = Document::findOrFail($document);
        
        // Формируем промпт с контекстом документа
        $systemPrompt = <<<PROMPT
Ты — ассистент по анализу документов. Отвечай на вопросы пользователя по контексту загруженного документа.
Будь точен, цитируй конкретные пункты и цифры из текста. Если не знаешь ответа — скажи честно.
Пиши строго на русском языке.
PROMPT;
        
        $prompt = trim("{$systemPrompt}\n\nДОКУМЕНТ:\n{$documentModel->raw_text}\n\nВОПРОС ПОЛЬЗОВАТЕЛЯ:\n{$question}");
        
        return $this->ollamaService->streamChat($prompt);
    }
}
    
