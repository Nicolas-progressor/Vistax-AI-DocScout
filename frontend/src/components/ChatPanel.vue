<script setup lang="ts">
import { ref, computed, nextTick, watch, onMounted } from 'vue'
import { useDocumentStore } from '@/stores/documentStore'

const props = defineProps<{
  documentId: number
  isVisible: boolean
}>()

const documentStore = useDocumentStore()

const questionInput = ref('')
const messagesContainer = ref<HTMLElement | null>(null)
const localMessages = computed(() => documentStore.chatMessages

// Загружаем историю при монтировании
onMounted(async () => {
  if (props.documentId && documentStore.chatMessages.length === 0) {
    await documentStore.loadChatHistory(props.documentId)
  }
})

// Авто-скролл к последнему сообщению
async function scrollToBottom() {
  await nextTick()
  if (messagesContainer.value) {
    messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
  }
}

watch(() => localMessages.value.length, scrollToBottom)
watch(() => documentStore.isChatStreaming, scrollToBottom)

// Следим за сменой документа
watch(() => props.documentId, async (newId) => {
  if (newId) {
    await documentStore.loadChatHistory(newId)
  }
}))

// Авто-скролл к последнему сообщению
async function scrollToBottom() {
  await nextTick()
  if (messagesContainer.value) {
    messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
  }
}

watch(() => localMessages.value.length, scrollToBottom)
watch(() => documentStore.isChatStreaming, scrollToBottom)

async function sendMessage() {
  const question = questionInput.value.trim()
  if (!question || documentStore.isChatStreaming) return

  questionInput.value = ''

  try {
    await documentStore.sendChatMessage(
      props.documentId,
      question,
      (chunk: string) => {
        // Store сам управляет сообщениями
      }
    )
  } catch (e) {
    console.error('Chat error:', e)
  }
}

function handleKeyPress(event: KeyboardEvent) {
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault()
    sendMessage()
  }
}

// Форматирование markdown (базовое)
function formatMarkdown(text: string): string {
  return text
    .replace(/^### (.*$)/gim, '<h3 class="text-lg font-bold mt-3 mb-1">$1</h3>')
    .replace(/^## (.*$)/gim, '<h2 class="text-xl font-bold mt-4 mb-2">$1</h2>')
    .replace(/^# (.*$)/gim, '<h1 class="text-2xl font-bold mt-5 mb-3">$1</h1>')
    .replace(/^\* (.*$)/gim, '<li class="ml-4">$1</li>')
    .replace(/^- (.*$)/gim, '<li class="ml-4">$1</li>')
    .replace(/\*\*(.*?)\*\*/gim, '<strong>$1</strong>')
    .replace(/\*(.*?)\*/gim, '<em>$1</em>')
    .replace(/`(.*?)`/gim, '<code class="bg-gray-100 px-1 rounded text-sm">$1</code>')
    .replace(/\n/gim, '<br>')
}

function formatTime(date: Date): string {
  return new Intl.DateTimeFormat('ru-RU', {
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}
</script>

<template>
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden flex flex-col h-[600px]">
    <!-- Header -->
    <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
      <h3 class="font-semibold text-gray-800">
        💬 Чат с документом
      </h3>
      <p class="text-xs text-gray-500 mt-1">
        Задавайте вопросы по содержимому документа
      </p>
    </div>

    <!-- Messages Container -->
    <div
      ref="messagesContainer"
      class="flex-1 overflow-y-auto p-4 space-y-4 bg-gray-50"
    >
      <!-- Empty State -->
      <div
        v-if="localMessages.length === 0"
        class="flex flex-col items-center justify-center h-full text-gray-400"
      >
        <svg class="w-16 h-16 mb-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
        </svg>
        <p class="text-sm">Задайте вопрос по документу</p>
        <p class="text-xs mt-1">Например: "Какие есть риски?", "Найди штрафы"</p>
      </div>

      <!-- Messages -->
      <div
        v-for="message in localMessages"
        :key="message.id"
        class="flex"
        :class="message.role === 'user' ? 'justify-end' : 'justify-start'"
      >
        <div
          class="max-w-[80%] rounded-2xl px-4 py-3"
          :class="[
            message.role === 'user'
              ? 'bg-blue-600 text-white rounded-br-md'
              : 'bg-white border border-gray-200 text-gray-800 rounded-bl-md',
          ]"
        >
          <div
            class="text-sm leading-relaxed"
            v-html="formatMarkdown(message.content)"
          />
          <div
            class="text-xs mt-2 opacity-60"
            :class="message.role === 'user' ? 'text-blue-100' : 'text-gray-400'"
          >
            {{ formatTime(message.timestamp) }}
            <span v-if="message.isStreaming" class="ml-2 inline-block animate-pulse">▋</span>
          </div>
        </div>
      </div>

      <!-- Error State -->
      <div v-if="documentStore.error" class="flex justify-center">
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-2 rounded-lg text-sm">
          {{ documentStore.error }}
        </div>
      </div>
    </div>

    <!-- Input Area -->
    <div class="px-4 py-3 border-t border-gray-200 bg-white">
      <div class="flex space-x-3">
        <textarea
          v-model="questionInput"
          @keydown="handleKeyPress"
          placeholder="Введите ваш вопрос..."
          rows="2"
          class="flex-1 resize-none border border-gray-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent disabled:bg-gray-100"
          :disabled="documentStore.isChatStreaming"
        />
        <button
          @click="sendMessage"
          :disabled="!questionInput.trim() || documentStore.isChatStreaming"
          class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:bg-gray-300 disabled:cursor-not-allowed transition-colors flex items-center"
        >
          <span v-if="documentStore.isChatStreaming">
            <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
            </svg>
          </span>
          <span v-else>
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
            </svg>
          </span>
        </button>
      </div>
      <p class="text-xs text-gray-400 mt-2">
        Enter — отправить, Shift+Enter — новая строка
      </p>
    </div>
  </div>
</template>

<style scoped>
textarea {
  field-sizing: content;
  max-height: 120px;
}
</style>
