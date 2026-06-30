<script setup lang="ts">
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { useDocumentStore } from '@/stores/documentStore'

const props = defineProps<{
  documentId: number
  preset: 'legal_audit' | 'invoice_check' | 'free_chat'
}>()

const documentStore = useDocumentStore()

const streamedText = ref('')
const isAnalyzing = ref(false)
const hasError = ref(false)
const forceShowEmpty = ref(false)

// Проверяем наличие сохранённого анализа
const savedAnalysis = computed(() => {
  return documentStore.getSavedAnalysis(props.preset)
})

const hasSavedAnalysis = computed(() => {
  return documentStore.hasSavedAnalysis(props.preset)
})

// Форматирование markdown (базовое)
const formattedText = computed(() => {
  const text = savedAnalysis.value || streamedText.value
  if (!text && !isAnalyzing.value) {
    return ''
  }
  return text
    .replace(/^### (.*$)/gim, '<h3 class="text-xl font-bold mt-4 mb-2">$1</h3>')
    .replace(/^## (.*$)/gim, '<h2 class="text-2xl font-bold mt-6 mb-3">$1</h2>')
    .replace(/^# (.*$)/gim, '<h1 class="text-3xl font-bold mt-8 mb-4">$1</h1>')
    .replace(/^\* (.*$)/gim, '<li class="ml-4">$1</li>')
    .replace(/^- (.*$)/gim, '<li class="ml-4">$1</li>')
    .replace(/\*\*(.*?)\*\*/gim, '<strong>$1</strong>')
    .replace(/\*(.*?)\*/gim, '<em>$1</em>')
    .replace(/`(.*?)`/gim, '<code class="bg-gray-100 px-1 rounded">$1</code>')
    .replace(/\n/gim, '<br>')
})

async function startAnalysis() {
  // Сбрасываем состояние
  streamedText.value = ''
  forceShowEmpty.value = false
  
  // Проверка на валидный documentId
  if (!props.documentId || props.documentId <= 0) {
    console.warn('AnalysisPanel: Invalid documentId', props.documentId)
    isAnalyzing.value = false
    hasError.value = false
    return
  }

  // Если есть сохранённый анализ, используем его
  if (hasSavedAnalysis.value) {
    isAnalyzing.value = false
    hasError.value = false
    return
  }

  isAnalyzing.value = true
  hasError.value = false

  try {
    await documentStore.analyzeDocument(
      props.documentId,
      props.preset,
      (chunk: string) => {
        streamedText.value += chunk
      }
    )
  } catch (e) {
    console.error('Analysis error:', e)
    hasError.value = true
  } finally {
    isAnalyzing.value = false
  }
}

// Запускаем анализ при монтировании или изменении preset/documentId
onMounted(() => {
  nextTick(() => {
    startAnalysis()
  })
})

watch([() => props.preset, () => props.documentId], async () => {
  // Небольшая задержка для корректного обновления UI
  await nextTick()
  startAnalysis()
})

// Retry handler
function retryAnalysis() {
  startAnalysis()
}
</script>

<template>
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <!-- Header -->
    <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
      <div class="flex items-center space-x-2">
        <h3 class="font-semibold text-gray-800">
          ИИ-Анализ
        </h3>
        <span
          v-if="hasSavedAnalysis"
          class="px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 rounded-full"
        >
          ✓ Из кэша
        </span>
      </div>
      <div class="flex items-center space-x-2">
        <span
          v-if="isAnalyzing"
          class="flex items-center text-sm text-blue-600"
        >
          <svg class="animate-spin h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
          </svg>
          Анализ...
        </span>
      </div>
    </div>

    <!-- Content -->
    <div class="p-6">
      <!-- Показываем результат (сохранённый или streamed) -->
      <div
        v-if="formattedText && !forceShowEmpty"
        class="prose prose-sm max-w-none"
        v-html="formattedText"
      />

      <!-- Empty State: Анализ ещё не проводился -->
      <div
        v-else-if="!isAnalyzing && !hasSavedAnalysis && !streamedText"
        class="flex flex-col items-center justify-center py-12 text-center"
      >
        <div class="w-16 h-16 mb-4 rounded-full bg-gradient-to-br from-blue-100 to-blue-200 flex items-center justify-center">
          <span class="text-3xl">🔍</span>
        </div>
        <h4 class="text-lg font-semibold text-gray-900 mb-2">
          Анализ ещё не проводился
        </h4>
        <p class="text-sm text-gray-500 mb-6 max-w-sm">
          Выберите тип анализа слева и получите детальный разбор документа
        </p>
        <button
          @click="startAnalysis"
          class="px-6 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 rounded-xl transition-all shadow-md hover:shadow-lg hover:-translate-y-0.5"
        >
          Запустить анализ
        </button>
      </div>

      <!-- Loading State -->
      <div v-else-if="isAnalyzing" class="space-y-3">
        <div class="h-4 bg-gray-200 rounded animate-pulse"></div>
        <div class="h-4 bg-gray-200 rounded animate-pulse w-5/6"></div>
        <div class="h-4 bg-gray-200 rounded animate-pulse w-4/6"></div>
      </div>

      <!-- Error State -->
      <div v-if="hasError" class="flex flex-col items-center justify-center py-12 text-center">
        <div class="w-16 h-16 mb-4 rounded-full bg-gradient-to-br from-red-100 to-red-200 flex items-center justify-center">
          <span class="text-3xl">⚠️</span>
        </div>
        <h4 class="text-lg font-semibold text-gray-900 mb-2">
          Ошибка при анализе
        </h4>
        <p class="text-sm text-gray-500 mb-6">
          Произошла ошибка при выполнении анализа
        </p>
        <button
          @click="retryAnalysis"
          class="px-6 py-3 text-sm font-semibold text-white bg-gradient-to-r from-red-600 to-red-700 hover:from-red-700 hover:to-red-800 rounded-xl transition-all shadow-md hover:shadow-lg"
        >
          Повторить
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.prose h1, .prose h2, .prose h3 {
  color: #1f2937;
}
.prose li {
  margin-top: 0.5rem;
  margin-bottom: 0.5rem;
}
</style>
