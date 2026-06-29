<?php

echo "Тест анализа документа через Ollama\n";
echo "===================================\n\n";

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';

// Получаем сервис
$ollamaService = $app->make(\App\Services\OllamaService::class);

// Проверка доступности
echo "1. Проверка доступности Ollama...\n";
if ($ollamaService->isAvailable()) {
    echo "   ✅ Ollama доступен\n";
} else {
    echo "   ❌ Ollama недоступен\n";
    exit(1);
}

// Тестовый текст документа
$testText = <<<TEXT
ДОГОВОР ПОДРЯДА № 123

1. Заказчик обязуется оплатить работу в размере 100 000 рублей.
2. Исполнитель выполняет работу до 31.12.2025.
3. Штраф за просрочку: 0.5% от суммы договора за каждый день просрочки.
4. Конфиденциальность: стороны не разглашают условия договора третьим лицам.
5. force majeure: стороны освобождаются от ответственности при форс-мажоре.
TEXT;

echo "\n2. Тест анализа (legal_audit)...\n";
echo "   Текст: " . strlen($testText) . " символов\n";

$preset = 'legal_audit';
$model = 'gemma2:2b';

echo "\n3. Запрос к Ollama (preset: $preset, model: $model)...\n\n";

// Тест стриминга
$ollamaService->streamAnalysis($testText, $preset, $model);

echo "\n\n✅ Тест завершен\n";
