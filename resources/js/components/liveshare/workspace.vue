<script setup>
import { ref, onMounted, onBeforeUnmount, computed, watch, nextTick } from 'vue'
import { getLiveshare, updateLiveshare, getLiveshareAvatarUrl, getLiveshareFiles, getLiveshareTags, downloadLiveshareFiles } from '../../api'
import { store } from '../../store'
import {
  ArrowLeft,
  Loader2,
  Save,
  Edit3,
  X,
  Users,
  UserPlus,
  Search,
  Tags,
  Filter,
  Plus,
  Check,
  Download,
  ListChecks
} from 'lucide-vue-next'
import { useToast } from 'vue-toastification'

import FileGrid from './fileGrid.vue'
import LiveshareUploader from './liveshareUploader.vue'
import MemberManager from './memberManager.vue'
import TagManager from './tagManager.vue'

const props = defineProps({
  liveshareCode: { type: String, required: true }
})

const toast = useToast()
const liveshare = ref(null)
const loading = ref(true)
const error = ref(null)

// Edit mode
const editing = ref(false)
const editName = ref('')
const editDescription = ref('')
const saving = ref(false)

// Search and filter state
const searchQuery = ref('')
const activeTagFilters = ref([]) // tag IDs
const activeTypeFilter = ref('') // media type name, e.g. 'image'
const filteredFiles = ref([])
const tags = ref([])
let searchDebounceTimer = null

const mediaTypes = [
  { name: 'image', label: 'Images' },
  { name: 'video', label: 'Videos' },
  { name: 'audio', label: 'Audio' },
  { name: 'document', label: 'Documents' },
  { name: 'archive', label: 'Archives' },
  { name: 'ebook', label: 'Ebooks' },
]

// Tag management sheet
const tagManagerRef = ref(null)
const tagManagerSheetOpen = ref(false)

const loadLiveshare = async () => {
  loading.value = true
  error.value = null
  try {
    liveshare.value = await getLiveshare(props.liveshareCode)
    filteredFiles.value = liveshare.value.files || []
    await loadTags()
  } catch (err) {
    error.value = err.message || 'Failed to load liveshare'
  }
  loading.value = false
}

const loadTags = async () => {
  try {
    tags.value = await getLiveshareTags(props.liveshareCode)
  } catch (err) {
    // Non-fatal -- tags just won't show
    tags.value = []
  }
}

const loadFilteredFiles = async () => {
  try {
    const params = {}
    if (searchQuery.value.trim()) params.search = searchQuery.value.trim()
    if (activeTagFilters.value.length) params.tags = activeTagFilters.value
    if (activeTypeFilter.value) params.type = activeTypeFilter.value
    filteredFiles.value = await getLiveshareFiles(props.liveshareCode, params)
  } catch (err) {
    toast.error(err.message || 'Failed to load files')
  }
}

const hasActiveFilters = computed(() => {
  return searchQuery.value.trim() !== '' || activeTagFilters.value.length > 0 || activeTypeFilter.value !== ''
})

const activeFilterCount = computed(() => {
  let count = 0
  if (activeTagFilters.value.length) count += activeTagFilters.value.length
  if (activeTypeFilter.value) count += 1
  return count
})

const customTags = computed(() => {
  return tags.value.filter(t => t.type === 'custom')
})

// Filter panel
const filterPanelOpen = ref(false)
const filterPanelRef = ref(null)
const filterTagSearch = ref('')
const filterTagSearchInput = ref(null)

const filteredCustomTags = computed(() => {
  const q = filterTagSearch.value.trim().toLowerCase()
  if (!q) return customTags.value
  return customTags.value.filter(t => t.name.toLowerCase().includes(q))
})

const toggleFilterPanel = () => {
  filterPanelOpen.value = !filterPanelOpen.value
  if (filterPanelOpen.value) {
    filterTagSearch.value = ''
    nextTick(() => {
      if (filterTagSearchInput.value) {
        filterTagSearchInput.value.focus()
      }
    })
  }
}

const closeFilterPanel = () => {
  filterPanelOpen.value = false
}

const handleFilterPanelClickOutside = (e) => {
  if (filterPanelRef.value && !filterPanelRef.value.contains(e.target)) {
    closeFilterPanel()
  }
}

onMounted(async () => {
  document.addEventListener('click', handleFilterPanelClickOutside, true)
  await loadLiveshare()
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleFilterPanelClickOutside, true)
})

const onSearchInput = () => {
  clearTimeout(searchDebounceTimer)
  searchDebounceTimer = setTimeout(() => {
    loadFilteredFiles()
  }, 300)
}

const clearSearch = () => {
  searchQuery.value = ''
  loadFilteredFiles()
}

const toggleTypeFilter = (typeName) => {
  if (activeTypeFilter.value === typeName) {
    activeTypeFilter.value = ''
  } else {
    activeTypeFilter.value = typeName
  }
  loadFilteredFiles()
}

const toggleTagFilter = (tagId) => {
  const idx = activeTagFilters.value.indexOf(tagId)
  if (idx >= 0) {
    activeTagFilters.value.splice(idx, 1)
  } else {
    activeTagFilters.value.push(tagId)
  }
  loadFilteredFiles()
}

const clearAllFilters = () => {
  searchQuery.value = ''
  activeTagFilters.value = []
  activeTypeFilter.value = ''
  filterTagSearch.value = ''
  loadFilteredFiles()
}

const getTagColor = (tag) => {
  return tag.color || null
}

const myRole = computed(() => {
  return liveshare.value?.my_role || null
})

const canManage = computed(() => {
  return myRole.value === 'owner' || myRole.value === 'manager' || store.admin
})

const canAddFiles = computed(() => {
  return myRole.value === 'owner' || myRole.value === 'manager' || myRole.value === 'collaborator' || store.admin
})

const canRemoveFiles = computed(() => {
  return canManage.value
})

const startEditing = () => {
  editName.value = liveshare.value.name
  editDescription.value = liveshare.value.description || ''
  editing.value = true
}

const cancelEditing = () => {
  editing.value = false
}

const saveEdits = async () => {
  if (!editName.value.trim()) {
    toast.error('Name is required')
    return
  }

  saving.value = true
  try {
    const updated = await updateLiveshare(props.liveshareCode, {
      name: editName.value.trim(),
      description: editDescription.value.trim() || null
    })
    liveshare.value.name = updated.name
    liveshare.value.description = updated.description
    editing.value = false
    toast.success('Liveshare updated')
  } catch (err) {
    toast.error(err.message || 'Failed to update liveshare')
  }
  saving.value = false
}

const handleFileRemove = async (file) => {
  const { removeLiveshareFile } = await import('../../api')
  const confirmed = confirm(`Remove "${file.original_name || file.name}"?`)
  if (!confirmed) return

  try {
    await removeLiveshareFile(props.liveshareCode, file.id)
    toast.success('File removed')
    await loadLiveshare()
  } catch (err) {
    toast.error(err.message || 'Failed to remove file')
  }
}

const handleFilesAdded = async () => {
  await loadLiveshare()
}

const handleTagsChanged = async () => {
  await loadTags()
  await loadFilteredFiles()
}

const handleFileTagsChanged = async () => {
  await loadFilteredFiles()
}

const openTagManagerSheet = () => {
  tagManagerSheetOpen.value = true
}

const closeTagManagerSheet = () => {
  tagManagerSheetOpen.value = false
}

const tagManagerSheetClickOutside = (e) => {
  if (e.target === e.currentTarget) {
    closeTagManagerSheet()
  }
}

const onTagFilterFromGrid = (tagId) => {
  if (!activeTagFilters.value.includes(tagId)) {
    activeTagFilters.value.push(tagId)
    loadFilteredFiles()
  }
}

// Select mode
const selectMode = ref(false)
const selectedFileIds = ref([])

const toggleSelectMode = () => {
  selectMode.value = !selectMode.value
  if (!selectMode.value) {
    selectedFileIds.value = []
  }
}

const handleToggleSelect = (fileId) => {
  const idx = selectedFileIds.value.indexOf(fileId)
  if (idx >= 0) {
    selectedFileIds.value.splice(idx, 1)
  } else {
    selectedFileIds.value.push(fileId)
  }
}

// Members sheet
const memberManagerRef = ref(null)
const membersSheetOpen = ref(false)

const openMembersSheet = () => {
  membersSheetOpen.value = true
}

const closeMembersSheet = () => {
  membersSheetOpen.value = false
}

const membersSheetClickOutside = (e) => {
  if (e.target === e.currentTarget) {
    closeMembersSheet()
  }
}

const handleMembersChanged = async () => {
  await loadLiveshare()
}

const openCreateInvite = () => {
  memberManagerRef.value?.openCreateInvite()
}

const avatarUrl = computed(() => {
  if (!liveshare.value) return null
  return getLiveshareAvatarUrl(props.liveshareCode)
})

const initials = computed(() => {
  if (!liveshare.value?.name) return ''
  return liveshare.value.name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map(w => w[0].toUpperCase())
    .join('')
})

const avatarFailed = ref(false)
const onAvatarError = () => {
  avatarFailed.value = true
}

// Member avatar stack
const MAX_AVATARS = 20

const allPeople = computed(() => {
  if (!liveshare.value) return []

  const people = []

  // Add owner
  if (liveshare.value.owner) {
    people.push({
      id: liveshare.value.owner.id,
      name: liveshare.value.owner.name,
      role: 'owner'
    })
  }

  // Add members
  if (liveshare.value.members) {
    for (const member of liveshare.value.members) {
      if (member.user) {
        people.push({
          id: member.user.id,
          name: member.user.name,
          role: member.role
        })
      }
    }
  }

  // Move current user to the end (rightmost = on top)
  const idx = people.findIndex(p => p.id === store.userId)
  if (idx > -1) {
    const [current] = people.splice(idx, 1)
    people.push(current)
  }

  return people
})

const visibleAvatars = computed(() => {
  const all = allPeople.value
  if (all.length <= MAX_AVATARS) return all
  return all.slice(all.length - MAX_AVATARS)
})

const overflowCount = computed(() => {
  return Math.max(0, allPeople.value.length - MAX_AVATARS)
})

const getUserInitials = (name) => {
  if (!name) return '?'
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map(w => w[0].toUpperCase())
    .join('')
}

const goHome = () => {
  window.location.href = '/'
}

// Bulk download filtered files
const downloading = ref(false)

const handleBulkDownload = async () => {
  downloading.value = true
  try {
    const body = {}
    if (selectedFileIds.value.length > 0) {
      body.fileIds = [...selectedFileIds.value]
    } else {
      if (searchQuery.value.trim()) body.search = searchQuery.value.trim()
      if (activeTagFilters.value.length) body.tags = activeTagFilters.value.join(',')
      if (activeTypeFilter.value) body.type = activeTypeFilter.value
    }

    const url = downloadLiveshareFiles(props.liveshareCode)
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${store.jwt}`
      },
      body: JSON.stringify(body)
    })

    if (!response.ok) {
      const data = await response.json()
      throw new Error(data.message || 'Download failed')
    }

    const blob = await response.blob()
    const downloadUrl = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = downloadUrl
    const datePart = new Date().toISOString().slice(0, 10)
    const safeName = (liveshare.value?.name || 'liveshare').replace(/[^a-zA-Z0-9_-]/g, '_')
    a.download = `${safeName}_${datePart}.zip`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(downloadUrl)
  } catch (err) {
    toast.error(err.message || 'Failed to download files')
  }
  downloading.value = false
}
</script>

<template>
  <div class="liveshare-workspace">
    <!-- Loading -->
    <div class="workspace-loading" v-if="loading">
      <Loader2 class="spin" />
      <span>Loading liveshare...</span>
    </div>

    <!-- Error -->
    <div class="workspace-error" v-else-if="error">
      <p>{{ error }}</p>
      <button class="secondary" @click="goHome">Go Home</button>
    </div>

    <!-- Not logged in -->
    <div class="workspace-error" v-else-if="!store.isLoggedIn()">
      <p>You must be logged in to view this liveshare.</p>
      <button class="secondary" @click="goHome">Go to Login</button>
    </div>

    <!-- Workspace -->
    <div class="workspace-content" v-else-if="liveshare">
      <!-- Header -->
      <div class="workspace-header">
        <!-- Top row: everything on one line, vertically centred -->
        <div class="header-top" v-if="!editing">
          <button class="secondary icon-only" @click="goHome" title="Back to home">
            <ArrowLeft />
          </button>
          <div class="liveshare-avatar">
            <img
              v-if="avatarUrl && !avatarFailed"
              :src="avatarUrl"
              alt=""
              @error="onAvatarError"
            />
            <span class="avatar-initials">{{ initials }}</span>
          </div>
          <h1>{{ liveshare.name }}</h1>
          <button
            class="secondary icon-only edit-btn"
            @click="startEditing"
            v-if="canManage"
            title="Edit"
          >
            <Edit3 />
          </button>
          <div class="header-spacer"></div>
          <button
            class="select-btn secondary icon-only"
            :class="{ active: selectMode }"
            @click="toggleSelectMode"
            title="Select files"
          >
            <ListChecks />
            <span class="select-badge" v-if="selectedFileIds.length > 0">{{ selectedFileIds.length }}</span>
          </button>
          <div class="filter-anchor" ref="filterPanelRef" @click.stop>
            <button
              class="filter-btn secondary icon-only"
              :class="{ active: activeFilterCount > 0 }"
              @click="toggleFilterPanel"
              title="Filters"
            >
              <Filter />
              <span class="filter-badge" v-if="activeFilterCount > 0">{{ activeFilterCount }}</span>
            </button>
            <div class="filter-panel" v-if="filterPanelOpen">
              <div class="filter-panel-header">
                <span class="filter-panel-title">Filters</span>
                <button
                  v-if="activeFilterCount > 0"
                  class="filter-panel-clear"
                  @click="clearAllFilters"
                >
                  Clear all
                </button>
              </div>
              <div class="filter-panel-body">
                <!-- Media type section -->
                <div class="filter-section">
                  <div class="filter-section-label">File type</div>
                  <div
                    v-for="mt in mediaTypes"
                    :key="mt.name"
                    class="filter-row"
                    :class="{ active: activeTypeFilter === mt.name }"
                    @click="toggleTypeFilter(mt.name)"
                  >
                    <span class="filter-row-name">{{ mt.label }}</span>
                    <Check class="filter-check" v-if="activeTypeFilter === mt.name" />
                  </div>
                </div>
                <!-- Custom tags section -->
                <div class="filter-section" v-if="customTags.length > 0">
                  <div class="filter-section-label">Custom tags</div>
                  <div class="filter-tag-search">
                    <Search class="filter-tag-search-icon" />
                    <input
                      ref="filterTagSearchInput"
                      type="text"
                      v-model="filterTagSearch"
                      placeholder="Search tags..."
                      class="filter-tag-search-input"
                    />
                  </div>
                  <div
                    v-for="tag in filteredCustomTags"
                    :key="tag.id"
                    class="filter-row"
                    :class="{ active: activeTagFilters.includes(tag.id) }"
                    @click="toggleTagFilter(tag.id)"
                  >
                    <span class="filter-row-dot" :style="{ background: tag.color || '#999' }"></span>
                    <span class="filter-row-name">{{ tag.name }}</span>
                    <Check class="filter-check" v-if="activeTagFilters.includes(tag.id)" />
                  </div>
                  <div class="filter-no-results" v-if="filteredCustomTags.length === 0">
                    No tags match your search
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="header-search-wrap">
            <Search class="header-search-icon" />
            <input
              type="text"
              v-model="searchQuery"
              @input="onSearchInput"
              placeholder="Search files..."
              class="header-search-input"
            />
            <button
              v-if="searchQuery"
              class="header-search-clear"
              @click="clearSearch"
              title="Clear search"
            >
              <X />
            </button>
          </div>
          <div class="avatar-stack">
            <span class="avatar-overflow" v-if="overflowCount > 0">+{{ overflowCount }} more</span>
            <div
              class="avatar-circle"
              v-for="(person, index) in visibleAvatars"
              :key="person.id"
              :style="{ zIndex: index }"
              :title="person.name + ' (' + person.role + ')'"
            >
              {{ getUserInitials(person.name) }}
            </div>
          </div>
          <button
            class="members-btn secondary icon-only"
            @click="openMembersSheet"
            title="Members"
          >
            <Users />
          </button>
          <button
            v-if="canManage"
            class="members-btn secondary icon-only"
            style="margin-left: -10px;"
            @click="openTagManagerSheet"
            title="Manage Tags"
          >
            <Tags />
          </button>
        </div>
        <!-- Edit mode row -->
        <div class="header-top header-top--editing" v-else>
          <button class="secondary icon-only" @click="goHome" title="Back to home">
            <ArrowLeft />
          </button>
          <div class="header-edit">
            <div class="edit-fields">
              <input
                type="text"
                v-model="editName"
                placeholder="Liveshare name"
                class="edit-name-input"
                @keyup.enter="saveEdits"
              />
              <input
                type="text"
                v-model="editDescription"
                placeholder="Description (optional)"
                class="edit-desc-input"
                @keyup.enter="saveEdits"
              />
            </div>
            <div class="edit-actions">
              <button @click="saveEdits" :disabled="saving || !editName.trim()">
                <Loader2 v-if="saving" class="spin" />
                <Save v-else />
                Save
              </button>
              <button class="secondary" @click="cancelEditing" :disabled="saving">
                <X />
                Cancel
              </button>
            </div>
          </div>
        </div>
        <!-- Description below the top row -->
        <p class="header-description" v-if="!editing && liveshare.description">{{ liveshare.description }}</p>
      </div>

      <!-- Main content area -->
      <div class="workspace-main">
        <!-- Files section -->
        <div class="workspace-files">
          <div class="workspace-files-list">
            <FileGrid
              :files="filteredFiles"
              :liveshare-long-id="liveshareCode"
              :can-remove-files="canRemoveFiles"
              :can-tag-files="canAddFiles"
              :tags="tags"
              :select-mode="selectMode"
              :selected-ids="selectedFileIds"
              @removeFile="handleFileRemove"
              @fileTagsChanged="handleFileTagsChanged"
              @filterByTag="onTagFilterFromGrid"
              @toggleSelect="handleToggleSelect"
            />
          </div>
        </div>

        <!-- Floating action buttons -->
        <div class="workspace-fabs" v-if="canAddFiles || hasActiveFilters || selectedFileIds.length > 0">
          <button
            v-if="hasActiveFilters || selectedFileIds.length > 0"
            class="fab-button fab-download"
            :class="{ 'fab-loading': downloading }"
            :disabled="downloading"
            @click="handleBulkDownload"
            :title="selectedFileIds.length > 0 ? `Download ${selectedFileIds.length} selected` : 'Download filtered files'"
          >
            <Loader2 v-if="downloading" class="spin" />
            <Download v-else />
          </button>
          <LiveshareUploader
            v-if="canAddFiles"
            :liveshare-long-id="liveshareCode"
            @filesAdded="handleFilesAdded"
          />
        </div>
      </div>

      <!-- Tag manager slide-up sheet -->
      <div
        class="members-sheet-overlay"
        :class="{ active: tagManagerSheetOpen }"
        @click="tagManagerSheetClickOutside"
      >
        <div class="members-sheet">
          <div class="members-sheet-header">
            <h2><Tags /> Manage Tags</h2>
            <div class="members-sheet-header-actions">
              <button class="secondary" @click="tagManagerRef?.openCreateForm()">
                <Plus />
                New Tag
              </button>
              <button class="secondary icon-only" @click="closeTagManagerSheet">
                <X />
              </button>
            </div>
          </div>
          <div class="members-sheet-body">
            <TagManager
              ref="tagManagerRef"
              :liveshare-long-id="liveshareCode"
              :tags="tags"
              @tagsChanged="handleTagsChanged"
            />
          </div>
        </div>
      </div>

      <!-- Members slide-up sheet -->
      <div
        class="members-sheet-overlay"
        :class="{ active: membersSheetOpen }"
        @click="membersSheetClickOutside"
      >
        <div class="members-sheet">
          <div class="members-sheet-header">
            <h2><Users /> Members &amp; Invites</h2>
            <div class="members-sheet-header-actions">
              <button v-if="canManage" class="secondary" @click="openCreateInvite">
                <UserPlus />
                Create Invite
              </button>
              <button class="secondary icon-only" @click="closeMembersSheet">
                <X />
              </button>
            </div>
          </div>
          <div class="members-sheet-body">
            <MemberManager
              ref="memberManagerRef"
              :liveshare-long-id="liveshareCode"
              :members="liveshare.members || []"
              :owner="liveshare.owner"
              :can-manage="canManage"
              @membersChanged="handleMembersChanged"
            />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" scoped>
.liveshare-workspace {
  width: 100%;
  height: calc(100% - 20px);
  display: flex;
  flex-direction: column;
  color: var(--panel-section-text-color);
}

.workspace-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 60px 20px;
  font-size: 1.1rem;
  color: white;

  .spin {
    width: 24px;
    height: 24px;
    animation: spin 1s linear infinite;
  }
}

.workspace-error {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 15px;
  padding: 60px 20px;
  color: white;

  p {
    font-size: 1.1rem;
    margin: 0;
  }
}

.workspace-content {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

.workspace-header {
  padding: 20px;
  background: var(--panel-header-background-color);
  border-radius: 8px;
  flex-shrink: 0;

  .header-top {
    display: flex;
    align-items: center;
    gap: 10px;

    &.header-top--editing {
      align-items: flex-start;
    }
  }

  .liveshare-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    overflow: hidden;
    position: relative;
    flex-shrink: 0;
    background: var(--accent-color);

    img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .avatar-initials {
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.8rem;
      font-weight: 700;
      color: white;
      text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
      pointer-events: none;
    }
  }

  h1 {
    font-size: 1.4rem;
    margin: 0;
    color: var(--panel-header-text-color);
    white-space: nowrap;
  }

  .edit-btn {
    opacity: 0.4;
    transition: opacity 0.15s;

    &:hover {
      opacity: 1;
    }
  }

  .header-spacer {
    flex: 1;
  }

  .header-description {
    font-size: 0.85rem;
    color: var(--panel-section-text-color);
    opacity: 0.6;
    margin: 8px 0 0 0;
    padding-left: 46px;
  }

  .header-edit {
    display: flex;
    flex-direction: column;
    gap: 10px;
    flex: 1;
    max-width: 400px;

    .edit-fields {
      display: flex;
      flex-direction: column;
      gap: 8px;

      input {
        height: 36px;
        margin: 0;
        box-sizing: border-box;
        border-radius: var(--panel-border-radius);
      }

      .edit-name-input {
        font-size: 1.1rem;
        font-weight: 600;
      }

      .edit-desc-input {
        font-size: 0.85rem;
      }
    }

    .edit-actions {
      display: flex;
      gap: 8px;
    }
  }

  .header-search-wrap {
    position: relative;
    width: 300px;
    flex-shrink: 0;

    .header-search-icon {
      position: absolute;
      left: 10px;
      top: 50%;
      transform: translateY(-50%);
      width: 16px;
      height: 16px;
      opacity: 0.4;
      pointer-events: none;
      color: var(--input-text-color);
    }

    .header-search-input {
      width: 100%;
      height: 36px;
      padding: 0 34px;
      font-size: 0.85rem;
      border: 1px solid var(--input-border-color);
      background: var(--input-background-color);
      color: var(--input-text-color);
      border-radius: var(--panel-border-radius);
      box-sizing: border-box;
      margin: 0;
      outline: none;
      transition: border-color 0.15s ease;

      &::placeholder {
        color: var(--input-placeholder-color);
      }

      &:focus {
        border-color: var(--input-border-color-focus);
      }
    }

    .header-search-clear {
      position: absolute;
      right: 4px;
      top: 50%;
      transform: translateY(-50%);
      width: 28px;
      height: 28px;
      border: none;
      background: none;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      opacity: 0.4;
      color: var(--input-text-color);
      padding: 0;

      &:hover {
        opacity: 1;
      }

      svg {
        width: 14px;
        height: 14px;
        margin: 0;
      }
    }
  }

  .avatar-stack {
    display: flex;
    align-items: center;
    justify-content: flex-end;

    .avatar-overflow {
      font-size: 0.75rem;
      color: var(--panel-section-text-color);
      opacity: 0.6;
      margin-right: 8px;
      white-space: nowrap;
    }

    .avatar-circle {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: var(--primary-button-background-color);
      color: white;
      font-size: 0.65rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      margin-left: -16px;
      border: 2px solid var(--panel-background-color);
      text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
      cursor: default;
      position: relative;

      &:first-of-type {
        margin-left: 0;
      }
    }
  }

  .members-btn {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;

    svg {
      width: 16px;
      height: 16px;
      margin: 0;
    }
  }
}

// Select mode button
.select-btn {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  padding: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  position: relative;

  svg {
    width: 16px;
    height: 16px;
    margin: 0;
  }

  &.active {
    background: var(--primary-button-background-color);
    color: var(--primary-button-text-color);

    &:hover {
      background: var(--primary-button-background-color-hover);
      color: var(--primary-button-text-color-hover);
    }
  }

  .select-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    min-width: 18px;
    height: 18px;
    border-radius: 50%;
    background: var(--secondary-button-background-color);
    color: var(--secondary-button-text-color);
    font-size: 0.65rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
  }
}

// Filter button & panel
.filter-anchor {
  position: relative;
  flex-shrink: 0;
  width: 36px;
  height: 36px;
}

.filter-btn {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  padding: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;

  svg {
    width: 16px;
    height: 16px;
    margin: 0;
  }

  &.active {
    background: var(--primary-button-background-color);
    color: var(--primary-button-text-color);

    &:hover {
      background: var(--primary-button-background-color-hover);
      color: var(--primary-button-text-color-hover);
    }
  }

  .filter-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    min-width: 18px;
    height: 18px;
    border-radius: 50%;
    background: var(--secondary-button-background-color);
    color: var(--secondary-button-text-color);
    font-size: 0.65rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
  }
}

.filter-panel {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  z-index: 100;
  width: 240px;
  background: var(--panel-background-color);
  border-radius: var(--panel-border-radius);
  box-shadow: 0 6px 24px rgba(0, 0, 0, 0.25);
  overflow: hidden;
}

.filter-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 12px;
  border-bottom: 1px solid var(--input-border-color);
  background: var(--panel-header-background-color);


  .filter-panel-title {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--panel-header-text-color);
  }

  .filter-panel-clear {
    border: none;
    background: none;
    color: var(--accent-color);
    font-size: 0.75rem;
    cursor: pointer;
    padding: 0;

    &:hover {
      text-decoration: underline;
    }
  }
}

.filter-panel-body {
  max-height: 360px;
  overflow-y: auto;
  padding: 6px 0;
}

.filter-section {
  padding: 0 6px;

  & + .filter-section {
    margin-top: 4px;
    padding-top: 8px;
    border-top: 1px solid var(--input-border-color);
  }

  .filter-section-label {
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--panel-section-text-color);
    opacity: 0.5;
    padding: 4px 8px 6px;
  }
}

.filter-tag-search {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 4px 8px;
  margin-bottom: 4px;

  .filter-tag-search-icon {
    width: 14px;
    height: 14px;
    flex-shrink: 0;
    color: var(--panel-section-text-color);
    opacity: 0.4;
  }

  .filter-tag-search-input {
    flex: 1;
    border: none;
    background: transparent;
    color: var(--panel-section-text-color);
    font-size: 0.8rem;
    padding: 2px 0;
    margin: 0;
    height: auto;
    outline: none;

    &::placeholder {
      color: var(--panel-section-text-color);
      opacity: 0.35;
    }
  }
}

.filter-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 7px 8px;
  border-radius: 4px;
  cursor: pointer;
  transition: background 0.1s;

  &:hover {
    background: var(--panel-section-background-color);
  }

  &.active {
    background: var(--panel-section-background-color-alt);

    .filter-row-name {
      font-weight: 600;
    }
  }

  .filter-row-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
  }

  .filter-row-name {
    flex: 1;
    font-size: 0.8rem;
    color: var(--panel-section-text-color);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .filter-check {
    width: 14px;
    height: 14px;
    color: var(--accent-color);
    flex-shrink: 0;
    margin: 0;
  }
}

.filter-no-results {
  padding: 14px 8px;
  text-align: center;
  font-size: 0.8rem;
  color: var(--panel-section-text-color);
  opacity: 0.4;
}

.workspace-main {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  margin-top: 10px;
  position: relative;
}

.workspace-files {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 0;
  background: var(--panel-background-color);
  border-radius: 8px;
  padding: 20px;
}

.workspace-fabs {
  position: absolute;
  bottom: 20px;
  right: 20px;
  z-index: 10;
  display: flex;
  flex-direction: row;
  align-items: flex-end;
  gap: 10px;
}

.fab-download {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  margin: 0;
  box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease, opacity 0.2s ease;

  svg {
    width: 24px;
    height: 24px;
    margin: 0;
  }

  &:hover:not(:disabled) {
    transform: scale(1.08);
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.25);
  }

  &:active:not(:disabled) {
    transform: scale(0.96);
  }

  &.fab-loading {
    opacity: 0.7;
    cursor: wait;
  }
}

// Members slide-up sheet
.members-sheet-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: var(--overlay-background-color);
  backdrop-filter: blur(10px);
  z-index: 230;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.3s ease;

  &.active {
    opacity: 1;
    pointer-events: auto;

    .members-sheet {
      transform: translate(-50%, 0%);
    }
  }
}

.members-sheet {
  position: absolute;
  bottom: 0;
  left: 50%;
  transform: translate(-50%, 100%);
  width: min(900px, 100vw);
  max-height: 80vh;
  background: var(--panel-background-color);
  color: var(--panel-text-color);
  border-radius: 10px 10px 0 0;
  box-shadow: 0 0 100px 0 rgba(0, 0, 0, 0.5);
  display: flex;
  flex-direction: column;
  transition: transform 0.3s ease;
}

.members-sheet-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20px 20px 10px;
  flex-shrink: 0;
  margin-bottom: 10px;

  h2 {
    margin: 0;
    font-size: 1.2rem;
    color: var(--panel-text-color);
    display: flex;
    align-items: center;
    gap: 10px;

    svg {
      width: 22px;
      height: 22px;
    }
  }

  .members-sheet-header-actions {
    display: flex;
    align-items: center;
    gap: 8px;
  }
}

.members-sheet-body {
  padding: 0 20px 20px;
  overflow-y: auto;
  flex: 1;
  min-height: 0;
}

.workspace-files-list {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
}

.spin {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
</style>
