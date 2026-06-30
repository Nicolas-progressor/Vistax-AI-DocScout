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
     * Системные промпты для разных пресетов
     */
    private function getSystemPrompt(string $preset): string
    {
        return match ($preset) {
            'legal_audit' => <<<PROMPT
IMPORTANT: Answer ONLY in Russian language. English is FORBIDDEN.

Ты — опытный b2b-юрист. Проанализируй договор и найди риски для Исполнителя.

ОТВЕЧАЙ СТРОГО НА РУССКОМ ЯЗЫКЕ!

Пункты для анализа:
1. ФИНАНСОВЫЕ РИСКИ: сроки оплаты, постоплата, штрафы
2. ОТВЕТСТВЕННОСТЬ: сравни пени Исполнителя и Заказчика
3. ПРАВА НА РЕЗУЛЬТАТЫ: кому переходят права
4. РАСТОРЖЕНИЕ: условия разрыва контракта

Правила:
- Пиши ТОЛЬКО по-русски
- Если риск — начни строку с "⚠️ РИСК:"
- Цитируй цифры и пункты договора
- Без общих фраз
PROMPT,
            'invoice_check' => <<<PROMPT
IMPORTANT: Answer ONLY in Russian language. English is FORBIDDEN.

Ты — финансовый аудитор. Проверь счет на ошибки и мошенничество.

ОТВЕЧАЙ СТРОГО НА РУССКОМ ЯЗЫКЕ!

Пункты для анализа:
1. ВАЛЮТА И НАЦЕНКИ: проверь У.Е., скрытые комиссии
2. ФЕИКОВЫЕ ПОЗИЦИИ: выдуманные услуги, налоги
3. ЛОГИСТИКА: условия поставки, штрафы
4. ЮРИДИЧЕСКИЕ ЛОВУШКИ: отказ от Актов, удаленные суды

Правила:
- Пиши ТОЛЬКО по-русски
- Если аномалия — начни строку с "🚨 АНОМАЛИЯ:"
- Цитируй суммы и пункты
- Без общих фраз
PROMPT,
            'free_chat' => <<<PROMPT
IMPORTANT: Answer ONLY in Russian language. English is FORBIDDEN.

Ты — ассистент по документам. Отвечай на вопросы по тексту.

ОТВЕЧАЙ СТРОГО НА РУССКОМ ЯЗЫКЕ!
PROMPT,
            default => throw new \InvalidArgumentException("Неизвестный пресет: {$preset}"),
        };
    }
    
    /**
     * Запрос к Ollama с SSE-стримингом и сохранением результата
     */
    public function streamAnalysisWithSave(
        string $rawText,
        string $preset,
        string $model,
        int $documentId
    ): StreamedResponse {
        $systemPrompt = $this->getSystemPrompt($preset);
        
        return new StreamedResponse(function () use ($systemPrompt, $rawText, $model, $preset, $documentId) {
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
                        'preset' => $preset,
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
     * Запрос к Ollama с SSE-стримингом
     */
    public function streamAnalysis(
        string $rawText,
        string $preset,
        string $model = 'gemma2:2b'
    ): StreamedResponse {
        $systemPrompt = $this->getSystemPrompt($preset);
        
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
        string $preset,
        string $model = 'gemma2:2b'
    ): string {
        $systemPrompt = $this->getSystemPrompt($preset);
        
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
