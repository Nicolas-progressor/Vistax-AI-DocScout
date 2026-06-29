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
            'legal_audit' => "Ты — корпоративный юрист Вистакс. Проведи жесткий аудит текста документа. Найди скрытые риски, штрафы, скрытые комиссии и кабальные условия. Ответь структурировано на русском языке.",
            'invoice_check' => "Ты — b2b-аудитор. Провери этот счет/инвойс на аномалии, ошибки в суммах и отсутствие реквизитов. Ответь кратко на русском языке.",
            'free_chat' => "Ты — ассистент по анализу документов. Отвечай на вопросы пользователя по контексту загруженного документа.",
            default => throw new \InvalidArgumentException("Неизвестный пресет: {$preset}"),
        };
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
        
        // Формируем полный промпт
        $prompt = trim("{$systemPrompt}\n\nДОКУМЕНТ ДЛЯ АНАЛИЗА:\n{$rawText}");
        
        return new StreamedResponse(function () use ($prompt, $model) {
            $client = Http::withOptions([
                'stream' => true,
                'timeout' => 300, // 5 минут на анализ
            ]);
            
            $response = $client->post("{$this->baseUrl}/api/generate", [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => true,
            ]);
            
            // Читаем стрим и отправляем токены на фронтенд
            $body = $response->getBody();
            
            while (!feof($body)) {
                $line = fgets($body);
                if ($line === false) {
                    break;
                }
                
                $data = json_decode(trim($line), true);
                if (isset($data['response'])) {
                    // Формат SSE: data: {"text": "символ"}
                    $sseData = json_encode(['text' => $data['response']]);
                    echo "data: {$sseData}\n\n";
                    ob_flush();
                    flush();
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Access-Control-Allow-Origin' => 'http://localhost:5173',
            'Connection' => 'keep-alive',
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
        $prompt = trim("{$systemPrompt}\n\nДОКУМЕНТ ДЛЯ АНАЛИЗА:\n{$rawText}");
        
        $response = Http::post("{$this->baseUrl}/api/generate", [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
        ]);
        
        $data = $response->json();
        return $data['response'] ?? '';
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
