<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useDocumentStore } from '@/stores/documentStore'

const documentStore = useDocumentStore()

const emit = defineEmits<{
  select: [documentId: number]
}>()

// Кэш статусов анализа: { [docId]: { legal_audit: boolean, invoice_check: boolean, free_chat: boolean } }
const analysisStatus = ref<Record<number, Record<string, boolean>>>({})
const deletingId = ref<number | null>(null)

onMounted(async () => {
  if (documentStore.documentList.length === 0) {
    await documentStore.fetchDocumentList()
  }
})

async function handleSelect(id: number) {
  documentStore.selectDocument(id)
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

async function handleDelete(id: number, fileName: string, event: Event) {
  event.stopPropagation()
  
  if (!confirm(`Удалить документ "${fileName}"? Это действие нельзя отменить.`)) {
    return
  }
  
  deletingId.value = id
  
  try {
    await documentStore.deleteDocument(id)
  } catch (e) {
    console.error('Delete error:', e)
    alert('Ошибка при удалении документа')
  } finally {
    deletingId.value = null
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
        <div
          v-for="doc in documentStore.documentList"
          :key="doc.id"
          class="group relative flex items-center"
        >
          <!-- Document Button -->
          <button
            @click="handleSelect(doc.id)"
            class="flex-1 p-3 pr-10 text-left rounded-lg transition-all hover:bg-gray-50"
            :class="[
              documentStore.currentDocument?.id === doc.id
                ? 'bg-blue-50 ring-2 ring-blue-200'
                : '',
            ]"
          >
            <div class="flex items-center space-x-3">
              <!-- Icon -->
              <span class="text-2xl flex-shrink-0">
                {{ getIcon(doc.file_name) }}
              </span>
              
              <!-- Content -->
              <div class="flex-1 min-w-0">
                <div class="flex items-center space-x-2">
                  <p class="text-sm font-medium text-gray-900 truncate group-hover:text-blue-600 transition-colors">
                    {{ doc.file_name }}
                  </p>
                  <!-- Status Icon -->
                  <span
                    class="text-xs flex-shrink-0"
                    :class="getStatusIcon(doc.id) === '✅' ? 'text-green-600' : 'text-gray-400'"
                  >
                    {{ getStatusIcon(doc.id) }}
                  </span>
                </div>
                <p class="text-xs text-gray-500 mt-0.5">
                  {{ doc.created_at_formatted }}
                </p>
              </div>
            </div>
          </button>
          
          <!-- Delete Button (отдельно справа) -->
          <button
            @click="(e) => handleDelete(doc.id, doc.file_name, e)"
            :disabled="deletingId === doc.id"
            class="absolute right-1.5 p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-all opacity-0 group-hover:opacity-100 disabled:opacity-50"
            title="Удалить документ"
            style="z-index: 10;"
          >
            <svg
              v-if="deletingId !== doc.id"
              class="w-4 h-4"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
              />
            </svg>
            <svg
              v-else
              class="w-4 h-4 animate-spin"
              fill="none"
              viewBox="0 0 24 24"
            >
              <circle
                class="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                stroke-width="4"
              />
              <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
              />
            </svg>
          </button>
        </div>
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
