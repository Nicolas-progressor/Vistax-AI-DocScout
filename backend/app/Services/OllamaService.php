<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OllamaService
{
    /**
     * Базовый URL Ollama (внутри Docker-сети)
     */
    private readonly string $baseUrl;
    
    public function __construct()
    {
        $this->baseUrl = config('ollama.base_url', 'http://ollama:11434');
    }
    
    /**
     * Универсальный системный промпт для анализа документов
     */
    private function getSystemPrompt(): string
    {
        return <<<PROMPT
IMPORTANT: Answer ONLY in Russian language. English is FORBIDDEN.

Ты — циничный, опытный и крайне въедливый b2b-юрист и финансовый ревизор. Твоя единственная задача — защитить интересы Исполнителя (принимающей стороны, Подрядчика, Поставщика, физлица или ИП) перед лицом Заказчика (крупной компании, контрагента) в предоставленном документе.

ОТВЕЧАЙ СТРОГО НА РУССКОМ ЯЗЫКЕ!

ТВОЯ ЗАДАЧА:
1. Самостоятельно определи тип и назначение предоставленного документа.
2. Посимвольно проанализируй текст и выяви ЛЮБЫЕ скрытые ловушки, кабальные условия, зоны правовой асимметрии, скрытые финансовые потери или размытые формулировки, которые могут навредить Исполнителю.

ФОРМАТ ОТВЕТА (СТРОГИЙ MARKDOWN):
- [Тип документа]: Укажи, что за документ перед тобой (Договор, Счет, Соглашение, Акт и т.д.).
- [Краткая суть]: 1-2 предложения о предмете документа.
- [Выявленные риски]: Выводи риски списком. Если пункт несет угрозу финансового или юридического характера для Исполнителя — ОБЯЗАТЕЛЬНО начни строку строго с маркера "⚠️ КРИТИЧЕСКИЙ РИСК:".
- [Рекомендации]: Что конкретно нужно изменить, убрать или переписать в тексте, чтобы обезопасить Исполнителя.

ПРАВИЛА:
- Пиши ТОЛЬКО по-русски, без общих фраз, "воды" и банального пересказа текста.
- Всегда цитируй конкретные цифры, сроки, суммы, проценты и номера пунктов из текста документа для подтверждения риска.
- Если документ абсолютно чист и не несет рисков — прямо напиши об этом.
PROMPT;
    }
    
    /**
     * Стриминг анализа с сохранением результата в БД
     */
    public function streamAnalysisWithSave(
        string $rawText,
        string $model = 'gemma3:4b',
        ?int $documentId = null
    ): StreamedResponse {
        $systemPrompt = $this->getSystemPrompt();
        
        return new StreamedResponse(function () use ($systemPrompt, $rawText, $model, $documentId) {
            if (ob_get_level()) {
                ob_end_clean();
            }
            ob_implicit_flush(true);
            
            $client = Http::withOptions([
                'stream' => true,
                'timeout' => 300,
            ]);
            
            // Разделяем системную инструкцию и пользовательский промпт
            // Ollama лучше понимает system параметр для инструкций
            $response = $client->post("{$this->baseUrl}/api/generate", [
                'model' => $model,
                'prompt' => "ДОКУМЕНТ ДЛЯ АНАЛИЗА:\n{$rawText}",
                'system' => $systemPrompt,
                'stream' => true,
                'temperature' => 0.2,
                'top_p' => 0.1,
                'num_ctx' => 16384,
                'num_predict' => 8192,
            ]);
            
            $body = $response->getBody();
            
            header('Content-Type: text/event-stream; charset=UTF-8');
            header('X-Accel-Buffering: no');
            
            $lineBuffer = '';
            $fullResult = ''; // Накопление результата для сохранения
            
            while (!$body->eof()) {
                $char = $body->read(1);
                
                if ($char === '' || $char === false) {
                    break;
                }
                
                if ($char === "\n") {
                    $line = trim($lineBuffer);
                    $lineBuffer = '';
                    
                    if (empty($line)) {
                        continue;
                    }
                    
                    $data = json_decode($line, true);
                    if (isset($data['response'])) {
                        $fullResult .= $data['response'];
                        $sseData = json_encode(['text' => $data['response']], JSON_UNESCAPED_UNICODE);
                        echo "data: {$sseData}\n\n";
                        @ob_flush();
                        flush();
                    }
                } else {
                    $lineBuffer .= $char;
                }
            }
            
            // Обработка остатка
            if (!empty($lineBuffer)) {
                $line = trim($lineBuffer);
                $data = json_decode($line, true);
                if (isset($data['response'])) {
                    $fullResult .= $data['response'];
                    $sseData = json_encode(['text' => $data['response']], JSON_UNESCAPED_UNICODE);
                    echo "data: {$sseData}\n\n";
                    @ob_flush();
                    flush();
                }
            }
            
            // Сохраняем результат в БД
            if (!empty($fullResult)) {
                \App\Models\DocumentAnalysis::updateOrCreate(
                    [
                        'document_id' => $documentId,
                        'preset' => 'universal',
                        'ai_model' => $model,
                    ],
                    ['result_text' => $fullResult]
                );
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Access-Control-Allow-Origin' => 'http://localhost:5173',
            'Connection' => 'keep-alive',
            'Transfer-Encoding' => 'chunked',
        ]);
    }
    
    /**
     * Стриминг анализа (без сохранения)
     */
    public function streamAnalysis(
        string $rawText,
        string $model = 'gemma3:4b'
    ): StreamedResponse {
        $systemPrompt = $this->getSystemPrompt();
        
        return new StreamedResponse(function () use ($systemPrompt, $rawText, $model) {
            // Отключаем всю буферизацию PHP для SSE
            if (ob_get_level()) {
                ob_end_clean();
            }
            ob_implicit_flush(true);
            
            $client = Http::withOptions([
                'stream' => true,
                'timeout' => 300, // 5 минут на анализ
            ]);
            
            $response = $client->post("{$this->baseUrl}/api/generate", [
                'model' => $model,
                'prompt' => "ДОКУМЕНТ ДЛЯ АНАЛИЗА:\n{$rawText}",
                'system' => $systemPrompt,
                'stream' => true,
                // Highload-параметры для стабильной генерации без деградации
                'temperature' => 0.2,          // Минимум креативности
                'top_p' => 0.1,                // Фокус на вероятных токенах
                'num_ctx' => 16384,            // Окно чтения договора
                'num_predict' => 8192,         // Гарантированное окно генерации
            ]);
            
            // Читаем стрим и отправляем токены на фронтенд
            $body = $response->getBody();
            
            // Жесткая UTF-8 кодировка для кириллицы
            header('Content-Type: text/event-stream; charset=UTF-8');
            header('X-Accel-Buffering: no');
            
            // Буфер для сборки строк от Ollama (Guzzle Stream не поддерживает fgets)
            $lineBuffer = '';
            
            // Читаем по 1 байту для поиска \n
            while (!$body->eof()) {
                $char = $body->read(1);
                
                if ($char === '' || $char === false) {
                    break;
                }
                
                // Накопление строки до \n
                if ($char === "\n") {
                    $line = trim($lineBuffer);
                    $lineBuffer = '';
                    
                    if (empty($line)) {
                        continue;
                    }
                    
                    $data = json_decode($line, true);
                    if (isset($data['response'])) {
                        // Формат SSE: data: {"text": "символ"}
                        // JSON_UNESCAPED_UNICODE сохраняет русские символы
                        $sseData = json_encode(['text' => $data['response']], JSON_UNESCAPED_UNICODE);
                        echo "data: {$sseData}\n\n";
                        @ob_flush();
                        flush();
                    }
                } else {
                    $lineBuffer .= $char;
                }
            }
            
            // Обработка остатка буфера (если нет \n в конце)
            if (!empty($lineBuffer)) {
                $line = trim($lineBuffer);
                $data = json_decode($line, true);
                if (isset($data['response'])) {
                    $sseData = json_encode(['text' => $data['response']], JSON_UNESCAPED_UNICODE);
                    echo "data: {$sseData}\n\n";
                    @ob_flush();
                    flush();
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Access-Control-Allow-Origin' => 'http://localhost:5173',
            'Connection' => 'keep-alive',
            'Transfer-Encoding' => 'chunked',
        ]);
    }
    
    /**
     * Синхронный запрос к Ollama (для кэширования результата)
     */
    public function analyzeSync(
        string $rawText,
        string $model = 'gemma3:4b'
    ): string {
        $systemPrompt = $this->getSystemPrompt();
        
        $response = Http::post("{$this->baseUrl}/api/generate", [
            'model' => $model,
            'prompt' => "ДОКУМЕНТ ДЛЯ АНАЛИЗА:\n{$rawText}",
            'system' => $systemPrompt,
            'stream' => false,
            // Highload-параметры для стабильной генерации без деградации
            'temperature' => 0.2,          // Минимум креативности
            'top_p' => 0.1,                // Фокус на вероятных токенах
            'num_ctx' => 16384,            // Окно чтения договора
            'num_predict' => 4096,         // Гарантированное окно генерации
        ]);
        
        $data = $response->json();
        return $data['response'] ?? '';
    }
    
    /**
     * Стриминг чата (для вопросов пользователя)
     */
    public function streamChat(string $prompt): StreamedResponse
    {
        return new StreamedResponse(function () use ($prompt) {
            // Отключаем всю буферизацию PHP для SSE
            if (ob_get_level()) {
                ob_end_clean();
            }
            ob_implicit_flush(true);
            
            $client = Http::withOptions([
                'stream' => true,
                'timeout' => 120, // 2 минуты на ответ
            ]);
            
            // Для чата используем system инструкцию
            $systemPrompt = "Ты — ассистент по анализу документов. Отвечай на вопросы пользователя по контексту загруженного документа. Пиши строго на русском языке.";
            
            $response = $client->post("{$this->baseUrl}/api/generate", [
                'model' => 'gemma2:2b',
                'prompt' => $prompt,
                'system' => $systemPrompt,
                'stream' => true,
                // Параметры для чата
                'temperature' => 0.3,          // Чуть больше креативности для диалога
                'top_p' => 0.2,
                'num_ctx' => 16384,
                'num_predict' => 2048,
            ]);
            
            $body = $response->getBody();
            
            // Жесткая UTF-8 кодировка для кириллицы
            header('Content-Type: text/event-stream; charset=UTF-8');
            header('X-Accel-Buffering: no');
            
            // Буфер для сборки строк от Ollama
            $lineBuffer = '';
            
            while (!$body->eof()) {
                $char = $body->read(1);
                
                if ($char === '' || $char === false) {
                    break;
                }
                
                if ($char === "\n") {
                    $line = trim($lineBuffer);
                    $lineBuffer = '';
                    
                    if (empty($line)) {
                        continue;
                    }
                    
                    $data = json_decode($line, true);
                    if (isset($data['response'])) {
                        $sseData = json_encode(['text' => $data['response']], JSON_UNESCAPED_UNICODE);
                        echo "data: {$sseData}\n\n";
                        @ob_flush();
                        flush();
                    }
                } else {
                    $lineBuffer .= $char;
                }
            }
            
            // Обработка остатка буфера
            if (!empty($lineBuffer)) {
                $line = trim($lineBuffer);
                $data = json_decode($line, true);
                if (isset($data['response'])) {
                    $sseData = json_encode(['text' => $data['response']], JSON_UNESCAPED_UNICODE);
                    echo "data: {$sseData}\n\n";
                    @ob_flush();
                    flush();
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Access-Control-Allow-Origin' => 'http://localhost:5173',
            'Connection' => 'keep-alive',
            'Transfer-Encoding' => 'chunked',
        ]);
    }
    
    /**
     * Проверка доступности Ollama
     */
    public function isAvailable(): bool
    {
        try {
            $response = Http::get("{$this->baseUrl}/api/tags");
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
