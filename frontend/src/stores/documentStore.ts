import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

export interface Document {
  id: number
  file_name: string
  raw_text?: string
  cached?: boolean
  created_at?: string
}

export interface AnalysisResult {
  text: string
  isStreaming: boolean
  isComplete: boolean
}

export const useDocumentStore = defineStore('document', () => {
  // State
  const currentDocument = ref<Document | null>(null)
  const analysisResult = ref<AnalysisResult>({
    text: '',
    isStreaming: false,
    isComplete: false,
  })
  const error = ref<string | null>(null)
  const isLoading = ref(false)

  // Computed
  const hasDocument = computed(() => currentDocument.value !== null)

  // Actions
  async function uploadDocument(file: File, preset: string): Promise<Document> {
    isLoading.value = true
    error.value = null

    try {
      const formData = new FormData()
      formData.append('file', file)
      formData.append('preset', preset)

      const response = await axios.post('/api/documents/upload', formData, {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
      })

      currentDocument.value = response.data
      return response.data
    } catch (e: any) {
      error.value = e.response?.data?.message || 'Ошибка загрузки файла'
      throw e
    } finally {
      isLoading.value = false
    }
  }

  async function fetchDocument(id: number): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      const response = await axios.get(`/api/documents/${id}`)
      currentDocument.value = response.data
    } catch (e: any) {
      error.value = e.response?.data?.message || 'Ошибка загрузки документа'
      throw e
    } finally {
      isLoading.value = false
    }
  }

  async function analyzeDocument(
    id: number,
    preset: string,
    onChunk: (text: string) => void
  ): Promise<void> {
    analysisResult.value = {
      text: '',
      isStreaming: true,
      isComplete: false,
    }
    error.value = null

    try {
      const response = await fetch(`/api/documents/${id}/analyze?preset=${preset}`)

      if (!response.ok) {
        throw new Error('Ошибка анализа документа')
      }

      const reader = response.body?.getReader()
      const decoder = new TextDecoder()

      if (!reader) {
        throw new Error('Не удалось получить поток данных')
      }

      while (true) {
        const { done, value } = await reader.read()

        if (done) {
          analysisResult.value.isStreaming = false
          analysisResult.value.isComplete = true
          break
        }

        const chunk = decoder.decode(value)
        const lines = chunk.split('\n')

        for (const line of lines) {
          if (line.startsWith('data: ')) {
            try {
              const data = JSON.parse(line.slice(6))
              if (data.text) {
                analysisResult.value.text += data.text
                onChunk(data.text)
              }
            } catch {
              // Пропускаем невалидный JSON
            }
          }
        }
      }
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

  return {
    // State
    currentDocument,
    analysisResult,
    error,
    isLoading,
    // Computed
    hasDocument,
    // Actions
    uploadDocument,
    fetchDocument,
    analyzeDocument,
    reset,
  }
})
