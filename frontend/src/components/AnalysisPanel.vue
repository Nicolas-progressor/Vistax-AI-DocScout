<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useDocumentStore } from '@/stores/documentStore'

const props = defineProps<{
  documentId: number
  preset: 'legal_audit' | 'invoice_check' | 'free_chat'
}>()

const documentStore = useDocumentStore()

const streamedText = ref('')
const isAnalyzing = ref(false)

// Форматирование markdown (базовое)
const formattedText = computed(() => {
  return streamedText.value
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
  isAnalyzing.value = true
  streamedText.value = ''

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
  } finally {
    isAnalyzing.value = false
  }
}

onMounted(() => {
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
      <h3 class="font-semibold text-gray-800">
        ИИ-Анализ
      </h3>
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
        <span
          v-else-if="documentStore.analysisResult.isComplete"
          class="text-sm text-green-600"
        >
          ✓ Готово
        </span>
      </div>
    </div>

    <!-- Content -->
    <div class="p-6">
      <div
        v-if="streamedText"
        class="prose prose-sm max-w-none"
        v-html="formattedText"
      />

      <!-- Empty State -->
      <div v-else-if="isAnalyzing" class="space-y-3">
        <div class="h-4 bg-gray-200 rounded animate-pulse"></div>
        <div class="h-4 bg-gray-200 rounded animate-pulse w-5/6"></div>
        <div class="h-4 bg-gray-200 rounded animate-pulse w-4/6"></div>
      </div>

      <!-- Error State -->
      <div v-if="documentStore.error" class="text-red-600 text-sm">
        {{ documentStore.error }}
        <button
          @click="retryAnalysis"
          class="ml-2 text-red-700 underline"
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
