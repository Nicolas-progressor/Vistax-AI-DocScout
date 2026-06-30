# 🛡️ Vistax AI DocScout

**Суверенный On-Premise ИИ-аудитор коммерческих B2B-документов**

[![PHP](https://img.shields.io/badge/PHP-8.3+-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com/)
[![Vue 3](https://img.shields.io/badge/Vue-3.5-4FC08D?style=for-the-badge&logo=vue.js&logoColor=white)](https://vuejs.org/)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=for-the-badge&logo=postgresql&logoColor=white)](https://www.postgresql.org/)
[![Ollama](https://img.shields.io/badge/Ollama-Locally-000000?style=for-the-badge&logo=ollama&logoColor=white)](https://ollama.ai/)

---

## 📋 Описание

**Vistax AI DocScout** — это полностью изолированная система локального ИИ-анализа и аудита коммерческих документов. Предназначена для защиты интересов исполнителей (подрядчиков, поставщиков, ИП) при работе с B2B-контрактами, договорами, счетами и инвойсами.

### 🔐 Ключевые преимущества

| Преимущество | Описание |
|--------------|----------|
| **🏠 On-Premise** | Полная изоляция в Docker. Никаких внешних API (OpenAI/Anthropic/Google) |
| **🇷🇺 Русский язык** | Модель `gemma3:4b` с отличной поддержкой кириллицы |
| **⚡ Стриминг** | Посимвольная отдача токенов в реальном времени (SSE) |
| **📄 5 форматов** | PDF, DOCX, DOC, TXT, JSON до 10MB |
| **🔒 SHA-256 кэш** | Мгновенная отдача анализа при повторной загрузке |
| **🎯 Универсальность** | Единый промпт для всех типов документов |

---

## 🏗️ Архитектура

### Технологический стек

```
┌─────────────────────────────────────────────────────────────┐
│                      Frontend (Vue 3)                        │
│  TypeScript + Composition API + Pinia + Tailwind CSS         │
│  Stateful UTF-8 TextDecoder для кириллицы                    │
└─────────────────────────────────────────────────────────────┘
                           │
                           │ SSE Stream (Server-Sent Events)
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                    Nginx Reverse Proxy                       │
│  fastcgi_max_temp_file_size 0 (отключение дисковой буфериз.) │
└─────────────────────────────────────────────────────────────┘
                           │
                           │ FastCGI (PHP-FPM)
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                   Backend (Laravel 13)                       │
│  PHP 8.3 + Laravel AI SDK + Redis Cache                      │
│  FileParserService (ZipArchive для DOCX, pdftotext для PDF)  │
└─────────────────────────────────────────────────────────────┘
                           │
                           │ HTTP POST /api/generate
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                    AI Engine (Ollama)                        │
│  gemma3:4b — сбалансированная модель с русским языком        │
│  num_ctx: 16384 токенов (полный контекст договора)           │
└─────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                  Database (PostgreSQL 16)                    │
│  documents + document_analyses (индексированные связи)       │
└─────────────────────────────────────────────────────────────┘
```

### Движение данных при анализе

```
1. Загрузка файла → POST /api/documents/upload
   ├── Расчёт SHA-256 хэша
   ├── Проверка кэша (Redis, 7 дней TTL)
   ├── Парсинг текста (FileParserService)
   └── Сохранение в БД (documents)

2. Запуск анализа → GET /api/documents/{id}/analyze
   ├── Проверка кэша результатов (document_analyses)
   ├── Выбор системного промпта (универсальный)
   ├── Запрос к Ollama с 'stream' => true
   └── SSE-стриминг токенов на фронтенд

3. Стриминг SSE (Server-Sent Events)
   ├── Backend: Chunked Transfer Encoding
   ├── Nginx: fastcgi_max_temp_file_size 0
   ├── Frontend: ReadableStream + TextDecoder('utf-8', {stream: true})
   └── Реактивное обновление UI (Vue 3 reactivity)
```

---

## 🚀 Быстрый старт

### Требования

- Docker Desktop (Windows/Mac) или Docker + Docker Compose (Linux)
- 8 GB RAM (рекомендуется 16 GB для комфортной работы)
- 10 GB свободного места на диске

### Установка в 3 команды

```bash
# 1. Клонирование репозитория
git clone https://github.com/your-org/vistax-ai-docscout.git
cd vistax-ai-docscout

# 2. Запуск всех сервисов (модель скачается автоматически)
docker compose up -d --build

# 3. Открыть в браузере
# Frontend: http://localhost:5173
# Backend API: http://localhost:8000/api
```

### 📊 Что происходит при запуске

| Сервис | Порт | Описание |
|--------|------|----------|
| `vistax-frontend` | 5173 | Vue 3 Vite dev server |
| `vistax-backend` | 9000 | Laravel PHP-FPM |
| `vistax-nginx` | 8000 | Reverse proxy |
| `vistax-postgres` | 5432 | PostgreSQL 16 |
| `vistax-redis` | 6379 | Redis 7 (кэш) |
| `vistax-ollama` | 11434 | Ollama AI engine |

**Первый запуск:** ~5-10 минут (скачивание модели `gemma3:4b`, ~3.3 GB)

---

## 📖 Использование

### 1. Загрузка документа

Перетащите файл в зону загрузки или выберите через диалог:
- **Поддерживаемые форматы:** PDF, DOCX, DOC, TXT, JSON
- **Макс. размер:** 10 MB (~30 страниц)

### 2. Анализ

Анализ запускается **автоматически** после загрузки:
- ⏱️ **Время анализа:** 5–25 секунд (зависит от размера документа)
- 📝 **Структура отчёта:** 4 универсальных блока рисков
- 🇷🇺 **Язык:** Строго русский

### 3. Результат

ИИ выделит критические риски с маркером **⚠️ КРИТИЧЕСКИЙ РИСК:**

1. 💰 **Финансовые капканы** — скрытые комиссии, отсрочки, У.Е.
2. ⚡ **Асимметрия ответственности** — штрафы сторон, упущенная выгода
3. 🔒 **Права и логистика** — переход прав, удалённые склады
4. ❌ **Условия расторжения** — односторонний разрыв, третейские суды

---

## 🔧 Конфигурация

### Переменные окружения

**Backend (.env)**
```env
APP_ENV=local
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_DATABASE=vistax_docscout
DB_USERNAME=postgres
DB_PASSWORD=secret
OLLAMA_BASE_URL=http://ollama:11434
```

**Frontend (.env)**
```env
VITE_API_URL=http://host.docker.internal:8000/api
```

### Смена модели

Для использования другой модели (например, `llama3:8b`):

```bash
# 1. Скачать модель
docker compose exec ollama ollama pull llama3:8b

# 2. Изменить конфиг
# backend/config/ollama.php: 'default_model' => 'llama3:8b'

# 3. Перезапустить backend
docker compose restart backend
```

---

## 🧪 Тестирование

```bash
# Backend
docker compose exec backend php -l app/Services/OllamaService.php
docker compose exec backend php artisan migrate:status

# Frontend
docker compose exec frontend npm run build

# Проверка Ollama
curl http://localhost:11434/api/tags
```

---

## 🔐 Безопасность

- ✅ **Никаких внешних API** — вся обработка внутри Docker-сети
- ✅ **Изоляция данных** — PostgreSQL и Redis недоступны извне
- ✅ **Хэширование файлов** — SHA-256 для идентификации
- ✅ **Очистка кэша** — TTL 7 дней для Redis

---

## 📁 Структура проекта

```
vistax-ai-docscout/
├── backend/
│   ├── app/
│   │   ├── Models/
│   │   ├── Services/
│   │   │   ├── OllamaService.php
│   │   │   └── FileParserService.php
│   │   └── Http/Controllers/
│   ├── database/migrations/
│   ├── routes/api.php
│   └── storage/app/documents/
├── frontend/
│   └── src/
│       ├── components/
│       ├── composables/
│       ├── stores/
│       └── views/
├── docker-compose.yml
├── KODA.MD
├── README.md
└── concept.md
```

---

## 🧪 Разработка

### Backend

```bash
cd backend
composer install
php artisan serve
php artisan migrate:fresh --seed
php -l app/Services/OllamaService.php  # валидация синтаксиса
```

### Frontend

```bash
cd frontend
npm install
npm run dev
npm run build
```

### Docker

```bash
docker-compose up -d
docker-compose logs -f backend
docker-compose logs -f ollama
docker-compose down --volumes  # полный сброс
```

---

## 📁 Структура проекта

```
vistax-ai-docscout/
├── backend/
│   ├── app/
│   │   ├── Http/Controllers/      # API контроллеры
│   │   ├── Models/                 # Eloquent модели
│   │   └── Services/               # Бизнес-логика
│   │       ├── FileParserService.php
│   │       └── OllamaService.php
│   ├── database/migrations/        # Миграции БД
│   ├── routes/api.php              # API роуты
│   └── storage/app/documents/      # Загруженные файлы
├── frontend/
│   └── src/
│       ├── components/             # Vue компоненты
│       ├── stores/                 # Pinia store
│       ├── views/                  # Страницы
│       └── main.ts
├── docker-compose.yml              # Оркестрация контейнеров
├── README.md                       # Этот файл
```

---

## 📊 Производительность

| Метрика | Значение |
|---------|----------|
| Первичный анализ | 5–25 сек |
| Повторный анализ (кэш) | < 100 мс |
| Макс. размер файла | 10 MB |
| Контекст модели | 16,384 токена |
| Скорость стриминга | ~40 токенов/сек |

---

## 🔒 Лицензия

© 2026 Вистакс. Все права защищены.

---

## 📞 Контакты

- **Проект:** Vistax AI DocScout
- **Версия:** 1.0.0 (MVP)
- **Команда:** Vistax

---

**Разработано с ❤️ для суверенного ИИ-анализа документов**

---
