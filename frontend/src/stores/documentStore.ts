import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

export interface Document {
  id: number
  file_name: string
  raw_text: string
  created_at: string
  cached?: boolean
  last_preset?: 'legal_audit' | 'invoice_check' | 'free_chat'
}

export interface DocumentListItem {
  id: number
  file_name: string
  created_at: string
  created_at_formatted: string
}

export interface AnalysisResult {
  text: string
  isStreaming: boolean
  isComplete: boolean
}

export interface SavedAnalysis {
  id: number
  preset: 'legal_audit' | 'invoice_check' | 'free_chat'
  ai_model: string
  result_text: string
  created_at: string
  updated_at: string
}

export interface ChatMessage {
  id: number
  role: 'user' | 'assistant'
  content: string
  timestamp: Date
  isStreaming?: boolean
}

export interface DocumentWithAnalysis extends Document {
  analyses?: SavedAnalysis[]
}

export const useDocumentStore = defineStore('document', () => {
  // State
  const currentDocument = ref<DocumentWithAnalysis | null>(null)
  const documentList = ref<DocumentListItem[]>([])
  const savedAnalyses = ref<SavedAnalysis[]>([])
  const analysisResult = ref<AnalysisResult>({
    text: '',
    isStreaming: false,
    isComplete: false,
  })
  const chatMessages = ref<ChatMessage[]>([])
  const isChatStreaming = ref(false)
  const error = ref<string | null>(null)
  const isLoading = ref(false)
  const isListLoading = ref(false)

  // Computed
  const hasDocument = computed(() => currentDocument.value !== null)

  // Actions
  async function uploadDocument(file: File): Promise<Document> {
    isLoading.value = true
    error.value = null

    try {
      const formData = new FormData()
      formData.append('file', file)

      const response = await axios.post('/api/documents/upload', formData, {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
      })

      const documentId = response.data.id
      
      // Сначала обновляем список документов
      await fetchDocumentList()
      
      // Затем загружаем документ (теперь он точно есть в БД)
      try {
        await fetchDocument(documentId)
      } catch (fetchError: any) {
        // Если документ не найден (404) — используем данные из upload
        if (fetchError.response?.status === 404) {
          currentDocument.value = {
            id: documentId,
            file_name: response.data.file_name,
            raw_text: '',
            created_at: new Date().toISOString(),
            cached: response.data.cached,
          }
        } else {
          throw fetchError
        }
      }
      
      // Добавляем флаг cached из ответа upload
      if (currentDocument.value) {
        currentDocument.value.cached = response.data.cached
      }
      
      return response.data
    } catch (e: any) {
      error.value = e.response?.data?.message || 'Ошибка загрузки файла'
      throw e
    } finally {
      isLoading.value = false
    }
  }

  async function fetchDocumentList(): Promise<void> {
    isListLoading.value = true
    error.value = null

    try {
      const response = await axios.get('/api/documents')
      documentList.value = response.data.documents
    } catch (e: any) {
      error.value = e.response?.data?.message || 'Ошибка загрузки списка документов'
      throw e
    } finally {
      isListLoading.value = false
    }
  }

  async function fetchDocument(id: number): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      const response = await axios.get(`/api/documents/${id}`)
      currentDocument.value = response.data
      
      // Сохраняем анализы если они есть
      if (response.data.analyses && response.data.analyses.length > 0) {
        savedAnalyses.value = response.data.analyses
      }
    } catch (e: any) {
      error.value = e.response?.data?.message || 'Ошибка загрузки документа'
      throw e
    } finally {
      isLoading.value = false
    }
  }

  async function selectDocument(id: number): Promise<void> {
    // Очищаем текущее состояние
    analysisResult.value = {
      text: '',
      isStreaming: false,
      isComplete: false,
    }
    savedAnalyses.value = []
    
    // Загружаем выбранный документ с анализами
    await fetchDocument(id)
    
    // Загружаем историю чата
    await loadChatHistory(id)
  }

  async function analyzeDocument(
    id: number,
    onChunk: (text: string) => void
  ): Promise<void> {
    analysisResult.value = {
      text: '',
      isStreaming: true,
      isComplete: false,
    }
    error.value = null

    try {
      const response = await fetch(`/api/documents/${id}/analyze`)

      if (!response.ok) {
        throw new Error('Ошибка анализа документа')
      }

      const reader = response.body?.getReader()

      if (!reader) {
        throw new Error('Не удалось получить поток данных')
      }

      // Stateful UTF-8 декодер для корректной работы с кириллицей
      const decoder = new TextDecoder('utf-8')
      
      // Буфер для неполных строк SSE (защита от разреза JSON посередине)
      let lineBuffer = ''
      
      // Буфер для накопления текста из одного data:-блока (если JSON разрезан)
      let dataBuffer = ''

      while (true) {
        const { done, value } = await reader.read()

        if (done) {
          // Финальная декодировка остатка
          const finalChunk = decoder.decode(new Uint8Array(), { stream: false })
          if (finalChunk) {
            lineBuffer += finalChunk
          }
          // Обрабатываем остатки
          processLineBuffer(lineBuffer, dataBuffer, (text) => {
            analysisResult.value.text += text
            onChunk(text)
          })
          break
        }

        // МАГИЯ: Сохраняем состояние многобайтового потока кириллицы
        const chunkText = decoder.decode(value, { stream: true })
        lineBuffer += chunkText

        // Разделяем на строки по '\n'
        const lines = lineBuffer.split('\n')
        
        // Последняя строка может быть неполной - оставляем в буфере
        lineBuffer = lines.pop() || ''

        for (const line of lines) {
          const trimmedLine = line.trim()
          
          // Пропускаем пустые строки (разделители SSE-сообщений)
          if (!trimmedLine) {
            continue
          }
          
          if (trimmedLine.startsWith('data: ')) {
            // Извлекаем JSON после 'data: '
            const jsonPart = trimmedLine.slice(6)
            dataBuffer += jsonPart
            
            // Пытаемся распарсить накопленный JSON
            try {
              const data = JSON.parse(dataBuffer)
              if (data.text) {
                analysisResult.value.text += data.text
                onChunk(data.text)
              }
              // Очищаем буфер после успешного парсинга
              dataBuffer = ''
            } catch {
              // JSON неполный - ждём следующую часть
              // Не выбрасываем ошибку, просто продолжаем накапливать
            }
          } else if (trimmedLine.startsWith('data:')) {
            // Вариант без пробела: 'data:{}'
            const jsonPart = trimmedLine.slice(5)
            dataBuffer += jsonPart
            
            try {
              const data = JSON.parse(dataBuffer)
              if (data.text) {
                analysisResult.value.text += data.text
                onChunk(data.text)
              }
              dataBuffer = ''
            } catch {
              // Ждём продолжения
            }
          }
        }
      }

      analysisResult.value.isStreaming = false
      analysisResult.value.isComplete = true
    } catch (e: any) {
      error.value = e.message || 'Ошибка анализа'
      analysisResult.value.isStreaming = false
      throw e
    }
  }

  function reset(): void {
    currentDocument.value = null
    analysisResult.value = {
      text: '',
      isStreaming: false,
      isComplete: false,
    }
    error.value = null
  }

  /**
   * Обработка остатка буфера после завершения потока
   */
  function processLineBuffer(
    lineBuffer: string,
    dataBuffer: string,
    onText: (text: string) => void
  ): void {
    const lines = lineBuffer.split('\n')
    
    for (const line of lines) {
      const trimmedLine = line.trim()
      if (!trimmedLine) continue
      
      if (trimmedLine.startsWith('data: ')) {
        const jsonPart = trimmedLine.slice(6)
        const fullJson = dataBuffer + jsonPart
        
        try {
          const data = JSON.parse(fullJson)
          if (data.text) {
            onText(data.text)
          }
        } catch {
          // Игнорируем битые данные в конце потока
        }
      } else if (trimmedLine.startsWith('data:')) {
        const jsonPart = trimmedLine.slice(5)
        const fullJson = dataBuffer + jsonPart
        
        try {
          const data = JSON.parse(fullJson)
          if (data.text) {
            onText(data.text)
          }
        } catch {
          // Игнорируем
        }
      }
    }
  }

  async function sendChatMessage(
    id: number,
    question: string,
    onChunk: (text: string) => void
  ): Promise<void> {
    isChatStreaming.value = true
    error.value = null

    try {
      // Формируем историю диалога (последние 10 сообщений для контекста)
      const history = chatMessages.value
        .filter(m => !m.isStreaming && m.content)
        .slice(-10)
        .map(m => ({
          role: m.role,
          content: m.content,
        }))

      // Добавляем вопрос пользователя в историю
      const userMessage: ChatMessage = {
        id: Date.now(),
        role: 'user',
        content: question,
        timestamp: new Date(),
        isStreaming: false,
      }
      chatMessages.value.push(userMessage)

      // Создаём временное сообщение ассистента
      const assistantMessage: ChatMessage = {
        id: Date.now() + 1,
        role: 'assistant',
        content: '',
        timestamp: new Date(),
        isStreaming: true,
      }
      chatMessages.value.push(assistantMessage)

      const response = await fetch(`/api/documents/${id}/chat`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ question, history }),
      })

      if (!response.ok) {
        // Удаляем временные сообщения при ошибке
        chatMessages.value.pop()
        chatMessages.value.pop()
        throw new Error('Ошибка отправки вопроса')
      }

      const reader = response.body?.getReader()

      if (!reader) {
        throw new Error('Не удалось получить поток данных')
      }

      const decoder = new TextDecoder('utf-8')
      let lineBuffer = ''
      let dataBuffer = ''
      let fullText = ''

      while (true) {
        const { done, value } = await reader.read()

        if (done) {
          const finalChunk = decoder.decode(new Uint8Array(), { stream: false })
          if (finalChunk) {
            lineBuffer += finalChunk
          }
          processLineBuffer(lineBuffer, dataBuffer, (text) => {
            fullText += text
            onChunk(text)
          })
          break
        }

        const chunkText = decoder.decode(value, { stream: true })
        lineBuffer += chunkText

        const lines = lineBuffer.split('\n')
        lineBuffer = lines.pop() || ''

        for (const line of lines) {
          const trimmedLine = line.trim()
          
          if (!trimmedLine) {
            continue
          }
          
          if (trimmedLine.startsWith('data: ')) {
            const jsonPart = trimmedLine.slice(6)
            dataBuffer += jsonPart
            
            try {
              const data = JSON.parse(dataBuffer)
              if (data.text) {
                fullText += data.text
                onChunk(data.text)
              }
              dataBuffer = ''
            } catch {
              // Ждём продолжения
            }
          } else if (trimmedLine.startsWith('data:')) {
            const jsonPart = trimmedLine.slice(5)
            dataBuffer += jsonPart
            
            try {
              const data = JSON.parse(dataBuffer)
              if (data.text) {
                fullText += data.text
                onChunk(data.text)
              }
              dataBuffer = ''
            } catch {
              // Ждём продолжения
            }
          }
        }
      }

      // Обновляем сообщение ассистента с полным текстом
      const lastMsg = chatMessages.value[chatMessages.value.length - 1]
      if (lastMsg && lastMsg.role === 'assistant') {
        lastMsg.content = fullText
        lastMsg.isStreaming = false
      }

      isChatStreaming.value = false
    } catch (e: any) {
      error.value = e.message || 'Ошибка чата'
      isChatStreaming.value = false
      throw e
    }
  }

  function resetChat(): void {
    chatMessages.value = []
    isChatStreaming.value = false
    error.value = null
  }

  /**
   * Загрузка истории чата для документа
   */
  async function loadChatHistory(id: number): Promise<void> {
    try {
      const response = await axios.get(`/api/documents/${id}/chat/history`)
      
      if (response.data && response.data.chats && Array.isArray(response.data.chats) && response.data.chats.length > 0) {
        // Создаём НОВЫЙ массив для принудительного обновления Vue реактивности
        const newMessages = response.data.chats.map((chat: any) => ({
          id: chat.id,
          role: chat.role as 'user' | 'assistant',
          content: chat.content || '',
          timestamp: chat.created_at ? new Date(chat.created_at) : new Date(),
          isStreaming: false,
        }))
        
        // Принудительное обновление через splice
        chatMessages.value.splice(0, chatMessages.value.length, ...newMessages)
      } else {
        chatMessages.value.splice(0, chatMessages.value.length)
      }
    } catch (e: any) {
      console.error('Ошибка загрузки истории чата:', e)
      error.value = 'Не удалось загрузить историю чата'
    }
  }

  function getSavedAnalysis(preset: string): string | null {
    const analysis = savedAnalyses.value.find(a => a.preset === preset)
    return analysis?.result_text || null
  }

  function hasSavedAnalysis(preset: string): boolean {
    return savedAnalyses.value.some(a => a.preset === preset)
  }

  async function deleteDocument(id: number): Promise<void> {
    error.value = null

    try {
      await axios.delete(`/api/documents/${id}`)
      
      // Удаляем из списка
      documentList.value = documentList.value.filter(doc => doc.id !== id)
      
      // Если удалили текущий документ — сбрасываем
      if (currentDocument.value?.id === id) {
        reset()
        resetChat()
        savedAnalyses.value = []
      }
    } catch (e: any) {
      error.value = e.response?.data?.message || 'Ошибка удаления документа'
      throw e
    }
  }

  return {
    // State
    currentDocument,
    documentList,
    savedAnalyses,
    analysisResult,
    chatMessages,
    isChatStreaming,
    error,
    isLoading,
    isListLoading,
    // Computed
    hasDocument,
    // Actions
    uploadDocument,
    fetchDocumentList,
    fetchDocument,
    selectDocument,
    analyzeDocument,
    sendChatMessage,
    loadChatHistory,
    reset,
    resetChat,
    getSavedAnalysis,
    hasSavedAnalysis,
    deleteDocument,
  }
})
