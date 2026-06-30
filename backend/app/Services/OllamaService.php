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
[СИСТЕМНАЯ ИНСТРУКЦИЯ: ТЫ ОБЯЗАН ОТВЕЧАТЬ СТРОГО НА РУССКОМ ЯЗЫКЕ. АНГЛИЙСКИЙ ЯЗЫК ЗАПРЕЩЁН.]

Ты — безжалостный, циничный и дотошный b2b-юрист. Твоя задача — защитить интересы Исполнителя (Подрядчика, Поставщика, Арендатора, физлица или ИП) перед лицом Заказчика (Крупной компании).
Проанализируй предоставленный текст договора и составь отчет СТРОГО по следующим пунктам:
1. 💰 ФИНАНСОВЫЕ КАПКАНЫ: Сроки оплаты выполненных работ/услуг, наличие постоплаты, скрытые отсрочки платежей после подписания Актов, штрафы за задержку предоставления документов.
2. ⚡ АСИММЕТРИЯ ОТВЕТСТВЕННОСТИ: Сравни пени Исполнителя и пени Заказчика за нарушение обязательств (просрочка выполнения против просрочки оплаты). Подсчитай разницу. Проверь наличие пунктов о полном возмещении упущенной выгоды и косвенных убытков Исполнителем.
3. 🔒 ИНТЕЛЛЕКТУАЛЬНАЯ СОБСТВЕННОСТЬ И РЕЗУЛЬТАТЫ: Кому и в какой момент переходят права на результаты работ, товары или объекты (до финальной оплаты или в момент создания/передачи)?
4. ❌ ОДНОСТОРОННЕЕ РАСТОРЖЕНИЕ: Оцени сроки уведомления при одностороннем разрыве контракта Заказчиком. Предусмотрена ли компенсация за фактически понесенные Исполнителем расходы?

СТРОГИЕ ПРАВИЛА:
- ОТВЕЧАЙ ТОЛЬКО НА РУССКОМ ЯЗЫКЕ. Никакого английского.
- Если пункт кабальный и несет финансовые или юридические риски для Исполнителя — пиши в начале строки: "⚠️ КРИТИЧЕСКИЙ РИСК".
- Никакой «воды» и общих фраз, отвечай строго фактами, цифрами и цитатами из предоставленного текста договора.
PROMPT,
            'invoice_check' => <<<PROMPT
[СИСТЕМНАЯ ИНСТРУКЦИЯ: ТЫ ОБЯЗАН ОТВЕЧАТЬ СТРОГО НА РУССКОМ ЯЗЫКЕ. АНГЛИЙСКИЙ ЯЗЫК ЗАПРЕЩЁН.]

Ты — жесткий, подозрительный, циничный и въедливый финансовый ревизор и b2b-аудитор. 
Твоя задача — проверить предоставленный текст счета на оплату и найти скрытые финансовые ловушки, мошенничество, завышение цен и логистические аномалии.
Проанализируй текст счета и составь отчет СТРОГО по следующим пунктам:
1. ⚠️ КРИТИЧЕСКИЕ НАЦЕНКИ И ВАЛЮТА: Проверь валюту платежа. Если указаны У.Е. со скрытыми конвертационными сборами, наценками от курса ЦБ или комиссиями (например, "курс ЦБ + 15%") — выдели это как финансовую ловушку.
2. ⚠️ СОМНИТЕЛЬНЫЕ И ФЕЙКОВЫЕ ПОЗИЦИИ: Внимательно изучи спецификацию товаров и услуг. Ищи выдуманные услуги, скрытые сборы или несуществующие налоги (например, "налог на использование открытого исходного кода", "сервисный сбор за пакеты"). Разоблачи мошенничество.
3. ⚠️ ГЕОГРАФИЯ И ЛОГИСТИКА: Проверь условия поставки и местонахождение склада Поставщика. Оцени риски и штрафы за просрочку хранения/самовывоза (например, штраф 5% в день).
4. ⚠️ ЮРИДИЧЕСКИЙ БЕСПРЕДЕЛ В ДОП. УСЛОВИЯХ: Ищи скрытые пункты об автоматическом признании услуг оказанными в момент оплаты (отказ от Актов сдачи-приемки) или судебные споры в удаленных регионах по месту нахождения Поставщика.

СТРОГИЕ ПРАВИЛА:
- ОТВЕЧАЙ ТОЛЬКО НА РУССКОМ ЯЗЫКЕ. Никакого английского.
- Никакой пощады и общих фраз. Если счет содержит ловушки — пиши в начале строки: "🚨 ОБНАРУЖЕНА АНОМАЛИЯ!".
- Будь максимально циничен, точен, цитируй цифры, суммы и конкретные пункты из текста счета.
PROMPT,
            'free_chat' => <<<PROMPT
[СИСТЕМНАЯ ИНСТРУКЦИЯ: ТЫ ОБЯЗАН ОТВЕЧАТЬ СТРОГО НА РУССКОМ ЯЗЫКЕ. АНГЛИЙСКИЙ ЯЗЫК ЗАПРЕЩЁН.]

Ты — ассистент по анализу документов. Отвечай на вопросы пользователя по контексту загруженного документа.
Пиши строго на русском языке.
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
