<script setup>
import { ref, onMounted } from 'vue'
import { getAllLiveshares, deleteLiveshare, setLiveshareLimits } from '../../api'
import {
  SquareArrowOutUpRight,
  Trash2,
  Rocket,
  FileText,
  User
} from 'lucide-vue-next'
import { useToast } from 'vue-toastification'
import { niceFileSize, niceDate } from '../../utils'

const toast = useToast()
const liveshares = ref([])
const loaded = ref(false)

onMounted(async () => {
  await loadLiveshares()
})

const loadLiveshares = async () => {
  try {
    liveshares.value = await getAllLiveshares()
  } catch (error) {
    toast.error('Failed to load liveshares')
  }
  loaded.value = true
}

const handleDeleteLiveshare = async (liveshare) => {
  const confirmed = confirm(`Are you sure you want to delete "${liveshare.name}"? This will permanently remove all files.`)
  if (!confirmed) return

  try {
    await deleteLiveshare(liveshare.long_id)
    toast.success('Liveshare deleted')
    await loadLiveshares()
  } catch (error) {
    toast.error(error.message || 'Failed to delete liveshare')
  }
}

const openWorkspace = (liveshare) => {
  window.open(`/liveshares/${liveshare.long_id}`, '_blank')
}
</script>

<template>
  <div>
    <table v-if="liveshares.length > 0">
      <thead>
        <tr>
          <th>Name</th>
          <th>Owner</th>
          <th>Files</th>
          <th>Created</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="ls in liveshares" :key="ls.id">
          <td>
            <div class="liveshare-name">
              <strong>{{ ls.name }}</strong>
              <span class="liveshare-desc" v-if="ls.description">{{ ls.description }}</span>
            </div>
          </td>
          <td>
            <div class="owner-cell" v-if="ls.owner">
              <User class="owner-icon" />
              {{ ls.owner.name }}
            </div>
          </td>
          <td>
            <div class="stat-cell">
              <FileText class="stat-icon" />
              {{ ls.file_count }} files
              <span class="stat-size">({{ niceFileSize(ls.size) }})</span>
            </div>
          </td>
          <td>{{ niceDate(ls.created_at) }}</td>
          <td>
            <div class="action-buttons">
              <button class="secondary" @click="openWorkspace(ls)" title="Open workspace">
                <SquareArrowOutUpRight />
                Open
              </button>
              <button
                class="clear-button icon-only"
                @click="handleDeleteLiveshare(ls)"
                title="Delete liveshare"
              >
                <Trash2 />
              </button>
            </div>
          </td>
        </tr>
      </tbody>
    </table>

    <div v-else-if="loaded" class="center-message">
      <Rocket />
      <p>No liveshares exist yet</p>
    </div>
    <div v-else class="center-message">
      <p>Loading...</p>
    </div>
  </div>
</template>

<style lang="scss" scoped>
.liveshare-name {
  display: flex;
  flex-direction: column;
  gap: 2px;

  strong {
    font-size: 0.95rem;
  }

  .liveshare-desc {
    font-size: 0.75rem;
    color: var(--panel-section-text-color);
    opacity: 0.7;
    max-width: 250px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

.owner-cell {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 0.85rem;

  .owner-icon {
    width: 14px;
    height: 14px;
    opacity: 0.6;
  }
}

.stat-cell {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 0.85rem;

  .stat-icon {
    width: 14px;
    height: 14px;
    opacity: 0.6;
  }

  .stat-size {
    font-size: 0.75rem;
    opacity: 0.6;
  }
}

.action-buttons {
  display: flex;
  gap: 5px;
  align-items: center;
}

.center-message {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  width: 100%;
  min-height: 300px;
  font-size: 1.5rem;
  color: var(--panel-section-text-color);
  svg {
    width: 4rem;
    height: 4rem;
    margin-right: 10px;
    margin-top: -20px;
  }
}
</style>
