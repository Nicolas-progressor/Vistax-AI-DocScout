# 🛡️ Vistax AI DocScout

**Суверенная On-Premise система локального ИИ-анализа и автоматического аудита коммерческих B2B-документов**

---

## 📋 О проекте

Vistax AI DocScout — это изолированный программный комплекс для развёртывания внутри закрытого инфраструктурного контура компании. Система позволяет за 10 секунд загрузить документ и получить глубокий экспертный аудит рисков от локальной нейросети, работающей **без доступа в интернет**.

### Ключевые возможности

| Пресет | Описание |
|--------|----------|
| 🔍 **Legal Scout** | Юридический аудит: поиск скрытых штрафов, пеней, ловушек в условиях расторжения |
| 💰 **Finance Scout** | Бухгалтерский аудит: проверка счетов и инвойсов на аномалии и ошибки |
| 💬 **Smart Chat** | Консультация: Q&A-диалог по контексту загруженного документа |

---

## 🏗️ Архитектура

```
┌─────────────────────────────────────────────────────────────────┐
│                    Docker Network (Isolated)                    │
│                                                                 │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐      │
│  │   Frontend   │───►│    Nginx     │───►│   Backend    │      │
│  │  Vue 3 + Vite│    │   (Port 80)  │    │  PHP-FPM     │      │
│  │  :5173       │    │   Port 8000  │    │   :9000      │      │
│  └──────────────┘    └──────┬───────┘    └──────┬───────┘      │
│                             │                   │               │
│                             ▼                   ▼               │
│                    ┌──────────────┐    ┌──────────────┐         │
│                    │    Ollama    │    │  PostgreSQL  │         │
│                    │  gemma2:2b   │    │   16+        │         │
│                    │  llama3:8b   │    │  Redis       │         │
│                    └──────────────┘    └──────────────┘         │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Технологический стек

| Компонент | Технология |
|-----------|------------|
| Backend | PHP 8.3+ / Laravel 13 |
| Frontend | Vue 3 (Composition API) / Vite / Tailwind CSS |
| Database | PostgreSQL 16+ |
| AI Engine | Ollama (локальный) |
| Контейнеризация | Docker / Docker Compose |

---

## 🚀 Быстрый старт

### Предварительные требования

- Docker 24+
- Docker Compose 2.20+
- Node.js 20+ (для разработки фронтенда)
- PHP 8.3+ (для локальной разработки бэкенда)

### Установка

```bash
# 1. Клонировать репозиторий
git clone <repository-url>
cd vistax-ai-docscout

# 2. Скопировать переменные окружения
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env

# 3. Запустить контейнеры
docker-compose up -d

# 4. Запустить миграции
docker-compose exec backend php artisan migrate

# 5. Установить зависимости Ollama
docker-compose exec ollama ollama pull gemma2:2b
```

### 🔧 Установка зависимостей (локальная разработка)

```bash
# Backend
cd backend
composer install

# Frontend
cd frontend
npm install
```

### Доступ к сервисам

| Сервис | URL |
|--------|-----|
| Frontend | http://localhost:5173 |
| Backend API | http://localhost:8000 |
| PostgreSQL | localhost:5432 |
| Ollama | http://localhost:11434 |

---

## 📡 API Документация

### Загрузка документа

```http
POST /api/documents/upload
Content-Type: multipart/form-data

file: <pdf|txt|json> (max 10MB)
preset: legal_audit | invoice_check | free_chat
```

**Ответ:**
```json
{
  "id": 123,
  "file_name": "contract.pdf",
  "cached": false
}
```

### Анализ документа (SSE-стриминг)

```http
GET /api/documents/{id}/analyze
Accept: text/event-stream
```

**Формат SSE:**
```
data: {"text": "символ"}

data: {"text": "символ"}
```

---

## 🔐 Безопасность

- ✅ **100% On-Premise** — все данные внутри Docker-сети
- ✅ **Zero Data Leak** — нет вызовов внешних API
- ✅ **SHA-256 дедупликация** — интеллектуальное кэширование
- ✅ **Изолированный Ollama** — локальная LLM без доступа в интернет

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

## 📊 Производительность

| Метрика | Значение |
|---------|----------|
| Первичный анализ | 5–25 сек (зависит от модели) |
| Повторный анализ | < 100 мс (из кэша) |
| Макс. размер файла | 10 MB (~30 страниц) |
| Контекстное окно | 8k–32k токенов |

---

## 📄 Лицензия

© 2025 Vistax. Все права защищены.

---

## 📞 Контакты

**Команда:** NLP-Core-Team  
**Проект:** Vistax AI DocScout  
**Версия:** 1.0 MVP
