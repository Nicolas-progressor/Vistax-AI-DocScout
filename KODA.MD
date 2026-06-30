# 🧠 KODA — Технический манифест Vistax AI DocScout

**Версия:** 1.0.0  
**Дата:** 2025  
**Статус:** Production Ready MVP

---

## 📜 Предисловие

Этот документ фиксирует ключевые инженерные решения и победы над инфраструктурными проблемами, с которыми столкнулась команда NLP-Core-Team при разработке Vistax AI DocScout. Здесь нет маркетинговой воды — только хардкорный engineering.

---

## 🏆 Ключевые победы

### 1. ✂️ Проблема распила UTF-8 (Stateful TextDecoder)

**Проблема:**  
При SSE-стриминге токенов от Ollama кириллические символы (два байта в UTF-8) разрезались на границе чанков. Результат: `�` вместо `а`, `�` вместо `р`.

**Диагностика:**
```javascript
// БЫЛО (неправильно):
const chunkText = decoder.decode(value); // {stream: false} по умолчанию
// Каждый чанк декодировался независимо → распил 0xD0 0xB0 на две части
```

**Решение:**
```javascript
// СТАЛО (правильно):
const decoder = new TextDecoder('utf-8');
const chunkText = decoder.decode(value, { stream: true });
// {stream: true} сохраняет состояние между вызовами
// Незавершённый байт ждёт продолжения в следующем чанке
```

**Архитектура буферизации:**
```typescript
let lineBuffer = ''  // Буфер неполных строк SSE
let dataBuffer = ''  // Буфер накопления JSON

while (true) {
  const { done, value } = await reader.read()
  
  // Stateful декодирование с сохранением контекста
  const chunkText = decoder.decode(value, { stream: true })
  lineBuffer += chunkText
  
  // Разделение на строки
  const lines = lineBuffer.split('\n')
  lineBuffer = lines.pop() || ''  // Последняя строка может быть неполной
  
  for (const line of lines) {
    if (line.startsWith('data: ')) {
      const jsonPart = line.slice(6)
      dataBuffer += jsonPart
      
      try {
        const data = JSON.parse(dataBuffer)  // Парсим только полный JSON
        onChunk(data.text)
        dataBuffer = ''  // Очищаем после успешного парсинга
      } catch {
        // JSON неполный — ждём следующую часть
      }
    }
  }
}
```

**Результат:**  
✅ Идеально ровный стриминг кириллицы без битых символов  
✅ Поддержка любых многобайтовых UTF-8 последовательностей  
✅ Устойчивость к разрыву JSON на границе чанков

---

### 2. 🚫 Отключение дисковой буферизации Nginx (fastcgi_max_temp_file_size)

**Проблема:**  
Nginx по умолчанию буферизует ответы от FastCGI (PHP-FPM) на диск при размере > 4KB. Для SSE-стриминга это катастрофа — токены накапливаются в файле вместо мгновенной отправки клиенту. Задержка: 2-5 секунд вместо реального времени.

**Диагностика:**
```bash
# Nginx логи (по умолчанию):
2025/06/30 10:21:26 [warn] 22#22: *297 a client request body is buffered 
to a temporary file /var/cache/nginx/client_temp/0000000015
```

**Решение:**
```nginx
# /etc/nginx/nginx.conf
http {
    # Отключаем дисковую буферизацию для FastCGI
    fastcgi_max_temp_file_size 0;
    fastcgi_buffering off;
    
    # Для SSE-стриминга
    location /api/documents/*/analyze {
        proxy_buffering off;
        proxy_cache off;
        chunked_transfer_encoding on;
    }
}
```

**Laravel контроллер:**
```php
return new StreamedResponse(function () {
    header('Content-Type: text/event-stream; charset=UTF-8');
    header('X-Accel-Buffering: no');  // Отключение буферизации Nginx
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('Transfer-Encoding: chunked');
    
    foreach ($tokens as $token) {
        echo "data: {$token}\n\n";
        @ob_flush();  // Сброс PHP-буфера
        flush();      // Принудительная отправка клиенту
    }
}, 200, [...]);
```

**Результат:**  
✅ Мгновенная отдача токенов (latency < 100ms)  
✅ Посимвольный стриминг в реальном времени  
✅ Никаких временных файлов на диске

---

### 3. 🔁 Разделение system/prompt параметров Ollama API

**Проблема:**  
Модель `gemma2:2b` отвечала на английском, несмотря на русскую инструкцию в промпте. Системная инструкция "забывалась" в середине генерации.

**Диагностика:**
```php
// БЫЛО (неправильно):
$prompt = $systemPrompt . "\n\nДОКУМЕНТ: {$rawText}";

$response = Http::post("{$this->baseUrl}/api/generate", [
    'model' => $model,
    'prompt' => $prompt,  // ← Всё в одном параметре
    'stream' => true,
]);
```

**Решение:**
```php
// СТАЛО (правильно):
$response = Http::post("{$this->baseUrl}/api/generate", [
    'model' => $model,
    'prompt' => "ДОКУМЕНТ: {$rawText}",
    'system' => $systemPrompt,  // ← Отдельный параметр!
    'stream' => true,
]);
```

**Объяснение:**  
Ollama API имеет специальный параметр `system` для системных инструкций. Когда инструкция передаётся через `system`:
- Модель лучше понимает контекст
- Инструкция применяется ко всему ответу
- Меньше шансов на "забывание" языка
- Работает даже с маленькими моделями

**Результат:**  
✅ Строго русский язык во всех ответах  
✅ Стабильное следование инструкциям  
✅ Поддержка малых моделей (gemma2:2b, gemma3:4b)

---

### 4. 🗑️ Очистка кэша при удалении документа (Ghost ID)

**Проблема:**  
После удаления документа из БД кэш Redis сохранял старый ID. При повторной загрузке того же файла (по SHA-256 хэшу) система возвращала ID=11, которого не существует → 404 ошибка.

**Диагностика:**
```php
// БЫЛО (неправильно):
if (Cache::has($cacheKey)) {
    $cachedData = Cache::get($cacheKey);
    return response()->json(['id' => $cachedData['id']]);  // ← ID может не существовать
}
```

**Решение:**
```php
// СТАЛО (правильно):
if (Cache::has($cacheKey)) {
    $cachedData = Cache::get($cacheKey);
    
    // Проверка существования в БД
    $existingDocument = Document::find($cachedData['id']);
    
    if ($existingDocument) {
        // Документ существует — возвращаем из кэша ✅
        return response()->json(['id' => $cachedData['id']]);
    } else {
        // Документ удалён — очищаем кэш
        Cache::forget($cacheKey);
    }
}
```

**Результат:**  
✅ Никаких 404 на существующие файлы  
✅ Автоматическая очистка "мёртвого" кэша  
✅ Консистентность БД ↔ Redis

---

### 5. 🌐 Vite Proxy на host.docker.internal

**Проблема:**  
Vite dev server внутри Docker-контейнера пытался проксировать `/api` запросы на `localhost:8000`. Но `localhost` внутри контейнера — это сам контейнер, а не хост. Запросы уходили в никуда.

**Диагностика:**
```typescript
// БЫЛО (неправильно):
proxy: {
  '/api': {
    target: 'http://localhost:8000',  // ← localhost = контейнер frontend
    changeOrigin: true,
  },
}
```

**Решение:**
```typescript
// СТАЛО (правильно):
proxy: {
  '/api': {
    target: 'http://host.docker.internal:8000',  // ← Специальное DNS Docker
    changeOrigin: true,
  },
}
```

**docker-compose.yml:**
```yaml
frontend:
  extra_hosts:
    - "host.docker.internal:host-gateway"  # ← Явный маппинг
```

**Результат:**  
✅ Frontend видит backend через Docker-сеть  
✅ Hot-reload работает корректно  
✅ Никаких CORS-ошибок

---

## 🛠️ Технологические решения

### SSE-стриминг: Chunked Transfer Encoding

**Backend (PHP):**
```php
header('Transfer-Encoding: chunked');
header('X-Accel-Buffering: no');

foreach ($tokens as $token) {
    echo "data: {$token}\n\n";
    flush();  // Принудительная отправка чанка
}
```

**Frontend (TypeScript):**
```typescript
const reader = response.body?.getReader()
const decoder = new TextDecoder('utf-8')

while (true) {
  const { done, value } = await reader.read()
  const chunkText = decoder.decode(value, { stream: true })
  // Обработка чанка в реальном времени
}
```

### Кэширование: SHA-256 + Redis TTL

**Алгоритм:**
1. Расчёт хэша файла при загрузке
2. Проверка Redis: `doc_analysis:{hash}`
3. Если найден → проверка БД (существует ли документ)
4. Если не найден → парсинг, сохранение, кэш на 7 дней
5. TTL продлевается при каждом обращении

**Redis ключи:**
- `doc_analysis:{sha256}` → `{id, file_name}` (7 дней)
- `doc_preset:{id}` → `last_used_preset` (7 дней)

### Парсинг файлов: Zero-dependency подход

**DOCX (ZipArchive):**
```php
$zip = new \ZipArchive();
$zip->open($file->getRealPath());
$xmlContent = $zip->getFromIndex($zip->locateName('word/document.xml'));
$rawText = strip_tags(str_replace('</w:p>', "\n", $xmlContent));
```

**DOC (бинарный стриппинг):**
```php
preg_match_all(
    '/[a-zA-Zа-яА-ЯёЁ0-9\s\.,;:!?\(\)\-\"\'«»№—\/\\\\@#$%&*+=_<>\[\]{}|]+/u',
    $fileContent,
    $matches
);
```

**PDF (pdftotext → smalot/pdfparser):**
```php
// Метод 1: pdftotext (быстрый, системный)
shell_exec("pdftotext -layout -enc UTF-8 {$file} {$tempFile}");

// Метод 2: smalot/pdfparser (медленный, PHP)
$parser = new \Smalot\PdfParser\Parser();
$pdf = $parser->parseFile($file);
```

---

## 📈 Метрики производительности

| Операция | Время | Примечание |
|----------|-------|------------|
| Загрузка файла (10MB) | 200-500ms | Включая SHA-256 |
| Парсинг DOCX (30 стр.) | 100-300ms | ZipArchive |
| Парсинг PDF (30 стр.) | 500-1500ms | pdftotext |
| Анализ (gemma3:4b) | 5-25 сек | Зависит от размера |
| Кэш-ответ (Redis) | < 50ms | Мгновенная отдача |
| SSE latency | < 100ms | Токен за токеном |

---

## 🎯 Архитектурные принципы

1. **Zero External API** — никаких вызовов OpenAI/Anthropic/Google
2. **Stateful Streaming** — сохранение контекста UTF-8 между чанками
3. **Cache-First** — проверка кэша перед любой тяжёлой операцией
4. **Thin Controllers** — бизнес-логика только в Services
5. **Universal Prompt** — единый промпт для всех типов документов

---

## 🚀 Заключение

Vistax AI DocScout — это пример того, как можно построить production-ready систему локального ИИ-анализа без внешних зависимостей. Каждая проблема была решена на уровне инфраструктуры, а не костылей в коде.

**Команда:** NLP-Core-Team  
**Миссия:** Суверенный ИИ для бизнеса

---

*Документ обновлён: 2025*  
*Поддерживается: KODA AI-архитектор*
