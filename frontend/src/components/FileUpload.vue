<script setup lang="ts">
import { ref } from 'vue'
import { useDocumentStore } from '@/stores/documentStore'

const props = defineProps<{
  preset: 'legal_audit' | 'invoice_check' | 'free_chat'
}>()

const emit = defineEmits<{
  (e: 'uploaded', documentId: number): void
}>()

const documentStore = useDocumentStore()

const isDragging = ref(false)
const uploadProgress = ref(0)

const presetLabels: Record<string, string> = {
  legal_audit: 'Юридический аудит',
  invoice_check: 'Проверка счёта',
  free_chat: 'Консультация',
}

async function handleFile(file: File) {
  try {
    const result = await documentStore.uploadDocument(file, props.preset)
    emit('uploaded', result.id)
  } catch (e) {
    console.error('Upload error:', e)
  }
}

function onDrop(e: DragEvent) {
  e.preventDefault()
  isDragging.value = false

  const files = e.dataTransfer?.files
  if (files && files.length > 0) {
    handleFile(files[0])
  }
}

function onDragOver(e: DragEvent) {
  e.preventDefault()
  isDragging.value = true
}

function onDragLeave() {
  isDragging.value = false
}

function onFileSelect(e: Event) {
  const target = e.target as HTMLInputElement
  const files = target.files
  if (files && files.length > 0) {
    handleFile(files[0])
  }
}
</script>

<template>
  <div
    class="border-2 border-dashed rounded-xl p-12 text-center transition-all duration-300"
    :class="[
      isDragging
        ? 'border-blue-500 bg-blue-50 scale-105'
        : 'border-gray-300 hover:border-gray-400 hover:bg-gray-50',
    ]"
    @drop="onDrop"
    @dragover="onDragOver"
    @dragleave="onDragLeave"
  >
    <div class="space-y-4">
      <!-- Icon -->
      <div class="flex justify-center">
        <svg
          class="w-16 h-16 text-gray-400"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="1.5"
            d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"
          />
        </svg>
      </div>

      <!-- Text -->
      <div>
        <p class="text-lg font-medium text-gray-700">
          Перетащите файл сюда
        </p>
        <p class="text-sm text-gray-500 mt-1">
          или нажмите для выбора (PDF, TXT, JSON до 10MB)
        </p>
      </div>

      <!-- Preset Badge -->
      <div class="inline-block px-4 py-2 bg-blue-100 text-blue-700 rounded-full text-sm font-medium">
        {{ presetLabels[preset] }}
      </div>

      <!-- Hidden File Input -->
      <input
        type="file"
        accept=".pdf,.txt,.json"
        class="hidden"
        @change="onFileSelect"
      />
    </div>

    <!-- Loading State -->
    <div v-if="documentStore.isLoading" class="mt-6">
      <div class="animate-pulse flex space-x-2 justify-center">
        <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce"></div>
        <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0.1s"></div>
        <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
      </div>
    </div>
  </div>
</template>
