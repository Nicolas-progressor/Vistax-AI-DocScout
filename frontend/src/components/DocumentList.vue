<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useDocumentStore } from '@/stores/documentStore'

const documentStore = useDocumentStore()

const emit = defineEmits<{
  select: [documentId: number]
}>()

// Кэш статусов анализа: { [docId]: { legal_audit: boolean, invoice_check: boolean, free_chat: boolean } }
const analysisStatus = ref<Record<number, Record<string, boolean>>>({})

onMounted(async () => {
  if (documentStore.documentList.length === 0) {
    await documentStore.fetchDocumentList()
  }
})

async function handleSelect(id: number) {
  await documentStore.selectDocument(id)
  emit('select', id)
}

async function loadAnalysisStatus(id: number) {
  try {
    const response = await fetch(`/api/documents/${id}`)
    const data = await response.json()
    
    const status: Record<string, boolean> = {
      legal_audit: false,
      invoice_check: false,
      free_chat: false,
    }
    
    if (data.analyses) {
      for (const analysis of data.analyses) {
        status[analysis.preset] = true
      }
    }
    
    analysisStatus.value[id] = status
  } catch (e) {
    console.error('Failed to load analysis status:', e)
  }
}

function getIcon(fileName: string): string {
  const ext = fileName.split('.').pop()?.toLowerCase()
  switch (ext) {
    case 'pdf': return '📄'
    case 'txt': return '📝'
    case 'json': return '📋'
    default: return '📁'
  }
}

function getStatusIcon(docId: number): string {
  const status = analysisStatus.value[docId]
  if (!status) return '⏳'
  
  const hasAny = Object.values(status).some(v => v)
  return hasAny ? '✅' : '⏳'
}
</script>

<template>
  <div class="h-full flex flex-col">
    <!-- Header -->
    <div class="px-5 py-4 border-b border-gray-200 bg-gradient-to-r from-gray-50 to-white">
      <div class="flex items-center justify-between mb-1">
        <h3 class="font-bold text-gray-900">
          Документы
        </h3>
        <span class="text-xs font-medium text-gray-500 bg-gray-200 px-2 py-0.5 rounded-full">
          {{ documentStore.documentList.length }}
        </span>
      </div>
      <p class="text-xs text-gray-500">
        История загрузок
      </p>
    </div>

    <!-- Document List -->
    <div class="flex-1 overflow-y-auto p-3">
      <!-- Loading State -->
      <div v-if="documentStore.isListLoading" class="space-y-2">
        <div class="h-14 bg-gray-100 rounded-lg animate-pulse"></div>
        <div class="h-14 bg-gray-100 rounded-lg animate-pulse"></div>
        <div class="h-14 bg-gray-100 rounded-lg animate-pulse"></div>
      </div>

      <!-- Empty State -->
      <div
        v-else-if="documentStore.documentList.length === 0"
        class="h-full flex flex-col items-center justify-center text-gray-400 px-4"
      >
        <div class="text-4xl mb-3">📭</div>
        <p class="text-sm font-medium text-gray-600">Пусто</p>
        <p class="text-xs text-gray-400 mt-1 text-center">
          Загрузите документ<br>для начала работы
        </p>
      </div>

      <!-- Document Items -->
      <div v-else class="space-y-1.5">
        <button
          v-for="doc in documentStore.documentList"
          :key="doc.id"
          @click="() => { handleSelect(doc.id); loadAnalysisStatus(doc.id) }"
          class="w-full p-3 text-left rounded-lg transition-all hover:bg-gray-50 group"
          :class="[
            documentStore.currentDocument?.id === doc.id
              ? 'bg-blue-50 ring-2 ring-blue-200'
              : '',
          ]"
        >
          <div class="flex items-start space-x-3">
            <span class="text-2xl flex-shrink-0 mt-0.5">
              {{ getIcon(doc.file_name) }}
            </span>
            <div class="flex-1 min-w-0">
              <div class="flex items-center justify-between">
                <p class="text-sm font-medium text-gray-900 truncate group-hover:text-blue-600 transition-colors">
                  {{ doc.file_name }}
                </p>
                <span class="text-xs ml-2 flex-shrink-0">
                  {{ getStatusIcon(doc.id) }}
                </span>
              </div>
              <p class="text-xs text-gray-500 mt-1">
                {{ doc.created_at_formatted }}
              </p>
            </div>
          </div>
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Custom scrollbar */
.overflow-y-auto::-webkit-scrollbar {
  width: 4px;
}
.overflow-y-auto::-webkit-scrollbar-track {
  background: transparent;
}
.overflow-y-auto::-webkit-scrollbar-thumb {
  background: #e2e8f0;
  border-radius: 2px;
}
.overflow-y-auto::-webkit-scrollbar-thumb:hover {
  background: #cbd5e1;
}
</style>
