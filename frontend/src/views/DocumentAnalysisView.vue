<script setup lang="ts">
import { ref } from 'vue'
import { useDocumentStore } from '@/stores/documentStore'
import FileUpload from '@/components/FileUpload.vue'
import AnalysisPanel from '@/components/AnalysisPanel.vue'

const documentStore = useDocumentStore()

const selectedPreset = ref<'legal_audit' | 'invoice_check' | 'free_chat'>('legal_audit')
const showAnalysis = ref(false)

const presetTabs = [
  { id: 'legal_audit', label: '⚖️ Юридический аудит', description: 'Поиск рисков и кабальных условий' },
  { id: 'invoice_check', label: '💰 Проверка счёта', description: 'Аномалии и ошибки в суммах' },
  { id: 'free_chat', label: '💬 Консультация', description: 'Вопросы по документу' },
] as const

function handleUploaded(documentId: number) {
  showAnalysis.value = true
}

function resetAnalysis() {
  documentStore.reset()
  showAnalysis.value = false
}
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <header class="bg-white border-b border-gray-200">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
        <div class="flex justify-between items-center">
          <div>
            <h1 class="text-2xl font-bold text-gray-900">
              🛡️ Vistax AI DocScout
            </h1>
            <p class="text-sm text-gray-500 mt-1">
              Локальный ИИ-анализ B2B-документов
            </p>
          </div>
          <button
            v-if="showAnalysis"
            @click="resetAnalysis"
            class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900 border border-gray-300 rounded-lg hover:bg-gray-50"
          >
            ← Новый документ
          </button>
        </div>
      </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <!-- Upload Section -->
      <div v-if="!showAnalysis" class="space-y-6">
        <!-- Preset Tabs -->
        <div class="flex space-x-2">
          <button
            v-for="preset in presetTabs"
            :key="preset.id"
            @click="selectedPreset = preset.id"
            class="flex-1 px-4 py-3 text-left rounded-xl border-2 transition-all"
            :class="[
              selectedPreset === preset.id
                ? 'border-blue-500 bg-blue-50'
                : 'border-gray-200 bg-white hover:border-gray-300',
            ]"
          >
            <div class="font-medium text-gray-900">{{ preset.label }}</div>
            <div class="text-sm text-gray-500 mt-1">{{ preset.description }}</div>
          </button>
        </div>

        <!-- File Upload -->
        <FileUpload
          :preset="selectedPreset"
          @uploaded="handleUploaded"
        />
      </div>

      <!-- Analysis Section -->
      <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Left Column: Document Info & Raw Text -->
        <div class="space-y-4">
          <!-- Document Metadata -->
          <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="font-semibold text-gray-800 mb-4">📄 Информация о документе</h3>
            <div class="space-y-2 text-sm">
              <div class="flex justify-between">
                <span class="text-gray-500">Имя файла:</span>
                <span class="text-gray-900 font-medium">{{ documentStore.currentDocument?.file_name }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-gray-500">ID:</span>
                <span class="text-gray-900 font-mono">{{ documentStore.currentDocument?.id }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-gray-500">Статус:</span>
                <span
                  class="px-2 py-0.5 rounded-full text-xs font-medium"
                  :class="documentStore.currentDocument?.cached
                    ? 'bg-green-100 text-green-700'
                    : 'bg-blue-100 text-blue-700'"
                >
                  {{ documentStore.currentDocument?.cached ? 'Из кэша' : 'Новый' }}
                </span>
              </div>
            </div>
          </div>

          <!-- Raw Text -->
          <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="font-semibold text-gray-800 mb-4">📝 Исходный текст</h3>
            <div class="max-h-96 overflow-y-auto text-sm text-gray-600 bg-gray-50 rounded-lg p-4 font-mono">
              {{ documentStore.currentDocument?.raw_text?.slice(0, 2000) || 'Нет данных' }}
              <span v-if="(documentStore.currentDocument?.raw_text?.length || 0) > 2000" class="text-gray-400">
                ... (показано первые 2000 символов)
              </span>
            </div>
          </div>
        </div>

        <!-- Right Column: AI Analysis -->
        <div>
          <AnalysisPanel
            v-if="documentStore.currentDocument"
            :document-id="documentStore.currentDocument.id"
            :preset="selectedPreset"
          />
        </div>
      </div>
    </main>
  </div>
</template>
