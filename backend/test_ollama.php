<?php

echo "Тест Ollama API\n";
echo "==============\n\n";

// 1. Проверка доступности
echo "1. Проверка доступности...\n";
$tags = @file_get_contents('http://ollama:11434/api/tags');
if ($tags) {
    $data = json_decode($tags, true);
    echo "   ✅ Ollama доступен\n";
    echo "   Модели: " . implode(', ', array_column($data['models'], 'name')) . "\n";
} else {
    echo "   ❌ Ollama недоступен\n";
    exit(1);
}

echo "\n2. Тест генерации (gemma2:2b)...\n";

$payload = json_encode([
    'model' => 'gemma2:2b',
    'prompt' => 'Скажи только одно слово: Привет',
    'stream' => false,
]);

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 60,
    ]
]);

$response = @file_get_contents('http://ollama:11434/api/generate', false, $context);

if ($response) {
    $data = json_decode($response, true);
    if (isset($data['response'])) {
        echo "   ✅ Генерация успешна\n";
        echo "   Ответ: " . trim($data['response']) . "\n";
        echo "   Время: " . round($data['total_duration'] / 1e9, 2) . " сек\n";
    } else {
        echo "   ❌ Ошибка: " . ($data['error'] ?? 'Неизвестная ошибка') . "\n";
    }
} else {
    echo "   ❌ Нет ответа от Ollama\n";
}

echo "\n✅ Тест завершен\n";
