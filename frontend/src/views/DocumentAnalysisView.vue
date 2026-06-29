<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useDocumentStore } from '@/stores/documentStore'
import FileUpload from '@/components/FileUpload.vue'
import AnalysisPanel from '@/components/AnalysisPanel.vue'
import ChatPanel from '@/components/ChatPanel.vue'
import DocumentList from '@/components/DocumentList.vue'

const documentStore = useDocumentStore()

// Список загружается в DocumentList.vue при монтировании компонента

const selectedPreset = ref<'legal_audit' | 'invoice_check' | 'free_chat'>('legal_audit')
const showAnalysis = ref(false)
const activeTab = ref<'analysis' | 'chat'>('analysis')
const isSidebarCollapsed = ref(false)

const presetTabs = [
  { id: 'legal_audit', label: 'Юридический аудит', description: 'Поиск рисков и кабальных условий', icon: '⚖️', color: 'blue' },
  { id: 'invoice_check', label: 'Проверка счёта', description: 'Аномалии и ошибки в суммах', icon: '💰', color: 'green' },
  { id: 'free_chat', label: 'Консультация', description: 'Вопросы по документу', icon: '💬', color: 'purple' },
] as const

const rightTabs = [
  { id: 'analysis', label: '📊 Результат анализа', icon: '📊' },
  { id: 'chat', label: '💬 Чат с документом', icon: '💬' },
] as const

const hasDocuments = computed(() => documentStore.documentList.length > 0)

function handleUploaded(documentId: number) {
  showAnalysis.value = true
  activeTab.value = selectedPreset.value === 'free_chat' ? 'chat' : 'analysis'
}

function resetAnalysis() {
  documentStore.reset()
  documentStore.resetChat()
  showAnalysis.value = false
  activeTab.value = 'analysis'
}

function handleDocumentSelect() {
  showAnalysis.value = true
}

function toggleSidebar() {
  isSidebarCollapsed.value = !isSidebarCollapsed.value
}
</script>

<template>
  <div class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-blue-50 flex flex-col">
    <!-- Header -->
    <header class="bg-white/80 backdrop-blur-md border-b border-gray-200 flex-shrink-0 sticky top-0 z-50">
      <div class="px-6 py-4">
        <div class="flex justify-between items-center">
          <!-- Logo -->
          <div class="flex items-center space-x-4 group cursor-pointer" @click="resetAnalysis">
            <div class="relative">
              <span class="text-4xl filter group-hover:drop-shadow-lg transition-all duration-300">🛡️</span>
              <div class="absolute -inset-1 bg-blue-500/10 rounded-full blur group-hover:bg-blue-500/20 transition-all"></div>
            </div>
            <div>
              <h1 class="text-xl font-bold bg-gradient-to-r from-gray-900 to-gray-600 bg-clip-text text-transparent">
                Vistax AI DocScout
              </h1>
              <p class="text-xs text-gray-500 font-medium">
                Локальный ИИ-анализ B2B-документов
              </p>
            </div>
          </div>
          
          <!-- Actions -->
          <div class="flex items-center space-x-3">
            <Transition name="slide">
              <button
                v-if="showAnalysis"
                @click="resetAnalysis"
                class="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 rounded-xl transition-all shadow-md hover:shadow-lg hover:-translate-y-0.5 active:translate-y-0"
              >
                <span class="flex items-center space-x-2">
                  <span>🏠</span>
                  <span>На главную</span>
                </span>
              </button>
            </Transition>
          </div>
        </div>
      </div>
    </header>

    <!-- Main Content with Sidebar -->
    <div class="flex flex-1 overflow-hidden relative">
      <!-- Left Sidebar: Document List -->
      <aside
        class="flex-shrink-0 transition-all duration-500 ease-out"
        :class="isSidebarCollapsed ? 'w-0 opacity-0' : 'w-80 opacity-100'"
      >
        <div class="h-full flex flex-col border-r border-gray-200 bg-white/50 backdrop-blur-sm">
          <!-- Sidebar Toggle Header -->
          <div class="px-4 py-3 border-b border-gray-200 bg-gradient-to-r from-gray-50 to-white">
            <div class="flex items-center justify-between">
              <div class="flex items-center space-x-2">
                <span class="text-lg">📚</span>
                <span class="font-semibold text-gray-800">Документы</span>
                <span class="text-xs font-bold text-blue-600 bg-blue-100 px-2 py-0.5 rounded-full">
                  {{ documentStore.documentList.length }}
                </span>
              </div>
              <button
                @click="toggleSidebar"
                class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-all"
                title="Свернуть панель"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
                </svg>
              </button>
            </div>
          </div>
          
          <!-- Document List Component -->
          <div class="flex-1 overflow-hidden">
            <DocumentList @select="handleDocumentSelect" />
          </div>
        </div>
      </aside>

      <!-- Sidebar Toggle Button (when collapsed) -->
      <Transition name="fade">
        <button
          v-if="isSidebarCollapsed && hasDocuments"
          @click="toggleSidebar"
          class="absolute left-0 top-1/2 -translate-y-1/2 z-20 bg-white border border-gray-200 rounded-r-xl p-3 shadow-lg hover:shadow-xl hover:bg-gray-50 transition-all group"
          title="Развернуть документы"
        >
          <svg class="w-5 h-5 text-gray-600 group-hover:text-blue-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7" />
          </svg>
        </button>
      </Transition>

      <!-- Main Content Area -->
      <main class="flex-1 overflow-y-auto p-6 scroll-smooth">
        <!-- Upload Section -->
        <Transition name="fade" mode="out-in">
          <div v-if="!showAnalysis" class="max-w-5xl mx-auto">
            <!-- Hero Section -->
            <div class="text-center mb-10">
              <h2 class="text-3xl font-bold text-gray-900 mb-3">
                Выберите тип анализа
              </h2>
              <p class="text-gray-500 text-lg max-w-xl mx-auto">
                Загрузите документ и получите детальный ИИ-анализ за считанные секунды
              </p>
            </div>
            
            <!-- Preset Cards -->
            <div class="grid md:grid-cols-3 gap-5 mb-8">
              <button
                v-for="preset in presetTabs"
                :key="preset.id"
                @click="selectedPreset = preset.id"
                class="group relative p-6 text-left rounded-2xl border-2 transition-all duration-300 hover:shadow-xl hover:-translate-y-1 overflow-hidden"
                :class="[
                  selectedPreset === preset.id
                    ? `border-${preset.color}-500 bg-gradient-to-br from-${preset.color}-50 to-white ring-2 ring-${preset.color}-200 shadow-lg`
                    : 'border-gray-200 bg-white hover:border-gray-300',
                ]"
              >
                <!-- Background Pattern -->
                <div class="absolute top-0 right-0 w-24 h-24 opacity-5 group-hover:opacity-10 transition-opacity">
                  <div :class="`bg-${preset.color}-500 rounded-full blur-2xl`"></div>
                </div>
                
                <div class="relative">
                  <div class="text-4xl mb-4 transform group-hover:scale-110 transition-transform duration-300">
                    {{ preset.icon }}
                  </div>
                  <div class="font-bold text-lg text-gray-900 mb-2 group-hover:text-blue-600 transition-colors">
                    {{ preset.label }}
                  </div>
                  <div class="text-sm text-gray-500 leading-relaxed">
                    {{ preset.description }}
                  </div>
                </div>
              </button>
            </div>

            <!-- File Upload -->
            <div class="max-w-2xl mx-auto">
              <FileUpload
                :preset="selectedPreset"
                @uploaded="handleUploaded"
              />
            </div>
          </div>

          <!-- Analysis Section -->
          <div v-else class="max-w-[1600px] mx-auto h-full">
            <div class="grid grid-cols-12 gap-6">
              <!-- Document Info Column -->
              <div class="col-span-12 lg:col-span-5 space-y-4">
                <!-- Document Metadata -->
                <div class="bg-white/80 backdrop-blur rounded-2xl shadow-lg border border-gray-200/50 p-6 hover:shadow-xl transition-shadow duration-300">
                  <div class="flex items-center space-x-3 mb-5">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white shadow-md">
                      📄
                    </div>
                    <h3 class="font-bold text-lg text-gray-900">О документе</h3>
                  </div>
                  <div class="space-y-0 divide-y divide-gray-100">
                    <div class="flex justify-between py-3">
                      <span class="text-gray-500 text-sm">Файл</span>
                      <span class="text-gray-900 font-medium text-sm text-right max-w-[200px] truncate">{{ documentStore.currentDocument?.file_name }}</span>
                    </div>
                    <div class="flex justify-between py-3">
                      <span class="text-gray-500 text-sm">ID</span>
                      <span class="text-gray-900 font-mono text-sm bg-gray-100 px-2 py-0.5 rounded">{{ documentStore.currentDocument?.id }}</span>
                    </div>
                    <div class="flex justify-between py-3">
                      <span class="text-gray-500 text-sm">Статус</span>
                      <span
                        class="px-3 py-1 rounded-full text-xs font-bold"
                        :class="documentStore.currentDocument?.cached
                          ? 'bg-gradient-to-r from-green-400 to-green-500 text-white shadow-sm'
                          : 'bg-gradient-to-r from-blue-400 to-blue-500 text-white shadow-sm'"
                      >
                        {{ documentStore.currentDocument?.cached ? '✓ Из кэша' : '✨ Новый' }}
                      </span>
                    </div>
                  </div>
                </div>
                
                <!-- Raw Text -->
                <div class="bg-white/80 backdrop-blur rounded-2xl shadow-lg border border-gray-200/50 p-6 hover:shadow-xl transition-shadow duration-300">
                  <div class="flex items-center space-x-3 mb-4">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500 to-purple-600 flex items-center justify-center text-white shadow-md">
                      📝
                    </div>
                    <h3 class="font-bold text-lg text-gray-900">Текст документа</h3>
                  </div>
                  <div class="max-h-[400px] overflow-y-auto text-sm text-gray-600 bg-gradient-to-b from-gray-50 to-white rounded-xl p-4 font-mono leading-relaxed border border-gray-200 shadow-inner">
                    {{ documentStore.currentDocument?.raw_text?.slice(0, 2000) || 'Нет данных' }}
                    <div v-if="(documentStore.currentDocument?.raw_text?.length || 0) > 2000" class="text-gray-400 mt-4 pt-4 border-t border-gray-200 text-center italic">
                      ... показано первые 2000 символов
                    </div>
                  </div>
                </div>
              </div>

              <!-- Analysis/Chat Column -->
              <div class="col-span-12 lg:col-span-7 flex flex-col">
                <!-- Tab Buttons -->
                <div class="flex mb-4 bg-white/60 backdrop-blur rounded-2xl p-1.5 shadow-md border border-gray-200/50">
                  <button
                    v-for="tab in rightTabs"
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    class="flex-1 px-5 py-3.5 text-sm font-semibold rounded-xl transition-all duration-300 flex items-center justify-center space-x-2"
                    :class="[
                      activeTab === tab.id
                        ? 'bg-white text-gray-900 shadow-lg scale-[1.02]'
                        : 'text-gray-600 hover:text-gray-900 hover:bg-white/50',
                    ]"
                  >
                    <span class="text-lg">{{ tab.icon }}</span>
                    <span>{{ tab.label.replace(/[📊💬]/g, '').trim() }}</span>
                  </button>
                </div>
                
                <!-- Tab Content -->
                <div class="flex-1 min-h-[550px]">
                  <Transition name="fade" mode="out-in">
                    <AnalysisPanel
                      v-show="activeTab === 'analysis'"
                      :document-id="documentStore.currentDocument?.id || 0"
                      :preset="selectedPreset"
                    />
                  </Transition>
                  <Transition name="fade" mode="out-in">
                    <ChatPanel
                      v-show="activeTab === 'chat'"
                      :document-id="documentStore.currentDocument?.id || 0"
                      :is-visible="showAnalysis"
                    />
                  </Transition>
                </div>
              </div>
            </div>
          </div>
        </Transition>
      </main>
    </div>
  </div>
</template>

<style scoped>
/* Slide Transition */
.slide-enter-active,
.slide-leave-active {
  transition: all 0.3s ease;
}
.slide-enter-from {
  transform: translateX(20px);
  opacity: 0;
}
.slide-leave-to {
  transform: translateX(20px);
  opacity: 0;
}

/* Fade Transition */
.fade-enter-active,
.fade-leave-active {
  transition: all 0.3s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

/* Custom scrollbar */
main::-webkit-scrollbar {
  width: 10px;
}
main::-webkit-scrollbar-track {
  background: linear-gradient(to bottom, #f1f5f9, #e2e8f0);
}
main::-webkit-scrollbar-thumb {
  background: linear-gradient(to bottom, #94a3b8, #64748b);
  border-radius: 5px;
}
main::-webkit-scrollbar-thumb:hover {
  background: linear-gradient(to bottom, #64748b, #475569);
}
</style>
