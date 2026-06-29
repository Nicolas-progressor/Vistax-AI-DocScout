# 📦 Установка и настройка Vistax AI DocScout

Пошаговое руководство по развёртыванию системы.

---

## Вариант 1: Docker (Рекомендуется)

### Шаг 1: Подготовка окружения

```bash
# Скопировать файлы окружения
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env
```

### Шаг 2: Генерация APP_KEY

```bash
docker-compose run --rm backend php artisan key:generate
```

### Шаг 3: Запуск контейнеров

```bash
docker-compose up -d
```

### Шаг 4: Миграция БД

```bash
# Дождаться готовности PostgreSQL
docker-compose logs -f postgres

# Запустить миграции
docker-compose exec backend php artisan migrate
```

### Шаг 5: Загрузка модели Ollama

```bash
# Дождаться запуска Ollama (проверить: docker-compose logs ollama)
docker-compose exec ollama ollama pull gemma2:2b
```

### Шаг 6: Проверка

- Frontend: http://localhost:5173
- Backend API: http://localhost:8000/api/documents
- Ollama: http://localhost:11434/api/tags

---

## Вариант 2: Локальная разработка

### Backend

```bash
cd backend

# Установить зависимости
composer install

# Скопировать .env
cp .env.example .env

# Сгенерировать ключ
php artisan key:generate

# Настроить .env (DB_CONNECTION=pgsql, DB_HOST=localhost)

# Запустить миграции
php artisan migrate

# Запустить сервер
php artisan serve
```

### Frontend

```bash
cd frontend

# Установить зависимости
npm install

# Запустить dev-сервер
npm run dev
```

### Ollama (локально)

```bash
# Установить Ollama: https://ollama.ai

# Загрузить модель
ollama pull gemma2:2b

# Запустить сервер (по умолчанию на :11434)
ollama serve
```

---

## 🔧 Конфигурация

### Переменные окружения Backend (.env)

```env
APP_NAME="Vistax AI DocScout"
APP_ENV=local
APP_DEBUG=true

DB_CONNECTION=pgsql
DB_HOST=localhost  # или postgres для Docker
DB_DATABASE=vistax_docscout
DB_USERNAME=postgres
DB_PASSWORD=secret

OLLAMA_BASE_URL=http://localhost:11434  # или http://ollama:11434 для Docker
OLLAMA_DEFAULT_MODEL=gemma2:2b
```

### Переменные окружения Frontend (.env)

```env
VITE_API_URL=http://localhost:8000/api
```

---

## 🐛 Диагностика проблем

### Ollama не отвечает

```bash
# Проверить статус
curl http://localhost:11434/api/tags

# Перезапустить контейнер
docker-compose restart ollama

# Проверить логи
docker-compose logs ollama
```

### Ошибки БД

```bash
# Пересоздать БД
docker-compose down -v
docker-compose up -d postgres
docker-compose exec backend php artisan migrate:fresh
```

### Frontend не видит API

Проверить proxy в `frontend/vite.config.ts`:
```ts
proxy: {
  '/api': {
    target: 'http://localhost:8000',
    changeOrigin: true,
  },
}
```

---

## 📊 Проверка работоспособности

### 1. Тест API

```bash
# Проверить доступность API
curl http://localhost:8000/api/documents

# Проверить Ollama
curl http://localhost:11434/api/tags
```

### 2. Тест загрузки файла

```bash
curl -X POST http://localhost:8000/api/documents/upload \
  -F "file=@test.pdf" \
  -F "preset=legal_audit"
```

### 3. Тест анализа (SSE)

```bash
curl http://localhost:8000/api/documents/1/analyze?preset=legal_audit
```

---

## 🧹 Сброс и очистка

```bash
# Полностью очистить проект
docker-compose down --volumes --rmi all

# Очистить кэш Laravel
docker-compose exec backend php artisan cache:clear
docker-compose exec backend php artisan config:clear

# Пересобрать фронтенд
cd frontend && rm -rf node_modules && npm install
```

---

**Версия:** 1.0  
**Обновлено:** 2025
