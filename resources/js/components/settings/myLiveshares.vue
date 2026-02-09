<script setup>
import { ref, onMounted } from 'vue'
import { getLiveshares, createLiveshare, deleteLiveshare } from '../../api'
import {
  SquareArrowOutUpRight,
  Plus,
  Trash2,
  Rocket,
  Users,
  FileText,
  Loader2
} from 'lucide-vue-next'
import { useToast } from 'vue-toastification'
import { niceFileSize, niceDate } from '../../utils'

const toast = useToast()
const liveshares = ref([])
const loaded = ref(false)
const creating = ref(false)

const showCreateForm = ref(false)
const newName = ref('')
const newDescription = ref('')

onMounted(async () => {
  await loadLiveshares()
})

const loadLiveshares = async () => {
  try {
    liveshares.value = await getLiveshares()
  } catch (error) {
    toast.error('Failed to load liveshares')
  }
  loaded.value = true
}

const handleCreateLiveshare = async () => {
  if (!newName.value.trim()) {
    toast.error('Name is required')
    return
  }

  creating.value = true
  try {
    await createLiveshare(newName.value.trim(), newDescription.value.trim() || null)
    toast.success('Liveshare created')
    newName.value = ''
    newDescription.value = ''
    showCreateForm.value = false
    await loadLiveshares()
  } catch (error) {
    toast.error(error.message || 'Failed to create liveshare')
  }
  creating.value = false
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

const roleBadgeClass = (role) => {
  return {
    'role-badge': true,
    'role-owner': role === 'owner',
    'role-manager': role === 'manager',
    'role-collaborator': role === 'collaborator',
    'role-viewer': role === 'viewer'
  }
}
</script>

<template>
  <div>
    <!-- Create button -->
    <div class="create-section" v-if="!showCreateForm">
      <button class="secondary" @click="showCreateForm = true">
        <Plus />
        Create Liveshare
      </button>
    </div>

    <!-- Create form -->
    <div class="create-form" v-if="showCreateForm">
      <h4>Create a new Liveshare</h4>
      <div class="form-group">
        <label>Name</label>
        <input
          type="text"
          v-model="newName"
          placeholder="My Liveshare"
          maxlength="255"
          @keyup.enter="handleCreateLiveshare"
        />
      </div>
      <div class="form-group">
        <label>Description (optional)</label>
        <textarea
          v-model="newDescription"
          placeholder="A short description of this liveshare..."
          maxlength="1000"
          rows="2"
        ></textarea>
      </div>
      <div class="form-actions">
        <button @click="handleCreateLiveshare" :disabled="creating || !newName.trim()">
          <Loader2 v-if="creating" class="spin" />
          <Plus v-else />
          Create
        </button>
        <button class="secondary" @click="showCreateForm = false" :disabled="creating">
          Cancel
        </button>
      </div>
    </div>

    <!-- Liveshares list -->
    <table v-if="liveshares.length > 0">
      <thead>
        <tr>
          <th>Name</th>
          <th>Role</th>
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
            <span :class="roleBadgeClass(ls.my_role)">{{ ls.my_role }}</span>
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
                v-if="ls.my_role === 'owner'"
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
      <p>No liveshares yet</p>
    </div>
    <div v-else class="center-message">
      <p>Loading...</p>
    </div>
  </div>
</template>

<style lang="scss" scoped>
.create-section {
  margin-bottom: 20px;
}

.create-form {
  background: var(--panel-section-background-color);
  border-radius: 8px;
  padding: 20px;
  margin-bottom: 20px;

  h4 {
    margin: 0 0 15px 0;
    color: var(--panel-section-text-color);
  }

  .form-group {
    margin-bottom: 12px;

    label {
      display: block;
      margin-bottom: 4px;
      font-size: 0.85rem;
      font-weight: 500;
      color: var(--panel-section-text-color);
    }

    input,
    textarea {
      width: 100%;
      box-sizing: border-box;
    }

    textarea {
      resize: vertical;
    }
  }

  .form-actions {
    display: flex;
    gap: 10px;
    margin-top: 15px;
  }
}

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

.role-badge {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 12px;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: capitalize;
}

.role-owner {
  background: var(--accent-color);
  color: var(--panel-section-text-color);
}

.role-manager {
  background: var(--panel-section-background-color-alt);
  color: var(--panel-section-text-color);
}

.role-collaborator {
  background: var(--panel-section-background-color-alt);
  color: var(--panel-section-text-color);
}

.role-viewer {
  background: var(--panel-section-background-color);
  color: var(--panel-section-text-color);
  opacity: 0.8;
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

.spin {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}
</style>
