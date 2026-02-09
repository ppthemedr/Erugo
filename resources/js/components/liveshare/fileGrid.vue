<script setup>
import { ref, computed } from 'vue'
import { FileText, Image, Film, Music, Archive, File, Trash2, Download, Tag, Check } from 'lucide-vue-next'
import { niceFileSize } from '../../utils'
import { downloadLiveshareFile } from '../../api'
import { store } from '../../store'
import FileTagEditor from './fileTagEditor.vue'

const props = defineProps({
  files: { type: Array, default: () => [] },
  liveshareLongId: { type: String, required: true },
  canRemoveFiles: { type: Boolean, default: false },
  canTagFiles: { type: Boolean, default: false },
  tags: { type: Array, default: () => [] }, // all available tags for the liveshare
  selectMode: { type: Boolean, default: false },
  selectedIds: { type: Array, default: () => [] }
})

const emit = defineEmits(['removeFile', 'fileTagsChanged', 'filterByTag', 'toggleSelect'])

const getFileIcon = (type) => {
  if (!type) return File
  if (type.startsWith('image/')) return Image
  if (type.startsWith('video/')) return Film
  if (type.startsWith('audio/')) return Music
  if (type.includes('zip') || type.includes('tar') || type.includes('rar') || type.includes('7z')) return Archive
  if (type.includes('text') || type.includes('pdf') || type.includes('document')) return FileText
  return File
}

const hasThumbnail = (file) => {
  return !!file.thumbnail_url
}

const thumbnailSrc = (file) => {
  return file.thumbnail_url + `?token=${store.jwt}`
}

const handleDownload = (file) => {
  const url = downloadLiveshareFile(props.liveshareLongId, file.id)
  const link = document.createElement('a')
  link.href = url + `?token=${store.jwt}`
  link.target = '_blank'
  link.download = file.original_name || file.name
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

// Track images that failed to load so we can show fallback icon
const failedThumbs = ref(new Set())

const onThumbError = (fileId) => {
  failedThumbs.value = new Set([...failedThumbs.value, fileId])
}

const showThumbnail = (file) => {
  return hasThumbnail(file) && !failedThumbs.value.has(file.id)
}

// Tag editor popover
const tagEditorFileId = ref(null)

const openTagEditor = (file) => {
  tagEditorFileId.value = tagEditorFileId.value === file.id ? null : file.id
}

const closeTagEditor = () => {
  tagEditorFileId.value = null
}

const handleFileTagsChanged = () => {
  emit('fileTagsChanged')
}

// Tag display helpers
const MAX_VISIBLE_TAGS = 3

const getVisibleTags = (file) => {
  const fileTags = file.tags || []
  // Show custom tags first, then auto
  const sorted = [...fileTags].sort((a, b) => {
    if (a.type === 'custom' && b.type !== 'custom') return -1
    if (a.type !== 'custom' && b.type === 'custom') return 1
    return 0
  })
  return sorted.slice(0, MAX_VISIBLE_TAGS)
}

const getOverflowCount = (file) => {
  return Math.max(0, (file.tags || []).length - MAX_VISIBLE_TAGS)
}

const handleTagPillClick = (tag) => {
  if (props.selectMode) return // don't filter when in select mode
  emit('filterByTag', tag.id)
}

const handleTileClick = (file) => {
  if (props.selectMode) {
    emit('toggleSelect', file.id)
  }
}

const isSelected = (fileId) => {
  return props.selectedIds.includes(fileId)
}
</script>

<template>
  <div class="file-grid" v-if="files.length > 0">
    <div
      class="file-tile"
      v-for="file in files"
      :key="file.id"
      :class="{
        'tile--select-mode': selectMode,
        'tile--selected': selectMode && isSelected(file.id)
      }"
      @click="handleTileClick(file)"
    >
      <!-- Thumbnail or icon placeholder -->
      <div class="tile-preview">
        <img
          v-if="showThumbnail(file)"
          :src="thumbnailSrc(file)"
          :alt="file.original_name || file.name"
          class="tile-thumb"
          loading="lazy"
          @error="onThumbError(file.id)"
        />
        <div class="tile-icon-fallback" v-else>
          <component :is="getFileIcon(file.type)" class="fallback-icon" />
        </div>

        <!-- Hover overlay (hidden in select mode) -->
        <div class="tile-overlay" v-if="!selectMode">
          <div class="tile-actions">
            <button class="tile-action-btn" @click="handleDownload(file)" title="Download">
              <Download />
            </button>
            <div class="tile-action-wrap" v-if="canTagFiles">
              <button
                class="tile-action-btn"
                @click.stop="openTagEditor(file)"
                title="Tag file"
              >
                <Tag />
              </button>
              <FileTagEditor
                v-if="tagEditorFileId === file.id"
                :liveshare-long-id="liveshareLongId"
                :file="file"
                :tags="tags"
                @close="closeTagEditor"
                @tagsChanged="handleFileTagsChanged"
              />
            </div>
            <button
              class="tile-action-btn tile-action-danger"
              @click="$emit('removeFile', file)"
              title="Remove file"
              v-if="canRemoveFiles"
            >
              <Trash2 />
            </button>
          </div>
          <div class="tile-info">
            <span class="tile-name" :title="file.original_name || file.name">
              {{ file.original_name || file.name }}
            </span>
            <span class="tile-size">{{ niceFileSize(file.size) }}</span>
          </div>
        </div>

        <!-- Selection indicator (visible in select mode) -->
        <div class="tile-select-indicator" v-if="selectMode">
          <div class="select-circle" :class="{ checked: isSelected(file.id) }">
            <Check v-if="isSelected(file.id)" />
          </div>
        </div>

        <!-- Tag pills (always visible, not just on hover) -->
        <div class="tile-tags" v-if="file.tags && file.tags.length > 0">
          <span
            v-for="tag in getVisibleTags(file)"
            :key="tag.id"
            class="tag-pill"
            :class="{ 'tag-pill--auto': tag.type === 'auto' }"
            :style="tag.color ? { background: tag.color } : {}"
            @click.stop="handleTagPillClick(tag)"
            :title="tag.name"
          >
            {{ tag.name }}
          </span>
          <span class="tag-pill tag-pill--overflow" v-if="getOverflowCount(file) > 0">
            +{{ getOverflowCount(file) }}
          </span>
        </div>
      </div>
    </div>
  </div>
  <div class="empty-state" v-else>
    <FileText class="empty-icon" />
    <p>No files yet</p>
  </div>
</template>

<style lang="scss" scoped>
.file-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 4px;
  width: 100%;
}

.file-tile {
  display: flex;
  flex-direction: column;
  border-radius: 3px;
  overflow: visible;
  background: var(--panel-section-background-color);
  transition: box-shadow 0.15s ease, transform 0.15s ease;

  &:hover {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);

    .tile-overlay {
      opacity: 1;
    }
  }

  &.tile--select-mode {
    cursor: pointer;

    &:hover {
      box-shadow: none;
    }
  }

  &.tile--selected {
    box-shadow: 0 0 0 3px var(--primary-button-background-color);
    border-radius: 3px;
    transform: scale(0.95);

    .tile-thumb {
      opacity: 0.75;
    }
  }
}

.tile-preview {
  position: relative;
  width: 100%;
  padding-top: 100%; // 1:1 aspect ratio
  overflow: visible;
  background: var(--panel-section-background-color-alt);
  border-radius: 3px;
}

.tile-thumb {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 3px;
}

.tile-icon-fallback {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;

  .fallback-icon {
    width: 36px;
    height: 36px;
    opacity: 0.35;
    color: var(--panel-section-text-color);
  }
}

.tile-overlay {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: linear-gradient(
    to bottom,
    rgba(0, 0, 0, 0.3) 0%,
    rgba(0, 0, 0, 0.1) 40%,
    rgba(0, 0, 0, 0.5) 100%
  );
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  opacity: 0;
  transition: opacity 0.15s ease;
  border-radius: 3px;
}

// Selection indicator
.tile-select-indicator {
  position: absolute;
  bottom: 8px;
  right: 8px;
  z-index: 10;
}

.select-circle {
  width: 26px;
  height: 26px;
  border-radius: 50%;
  border: 2px solid rgba(255, 255, 255, 0.8);
  background: rgba(0, 0, 0, 0.25);
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s ease;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.3);

  svg {
    width: 14px;
    height: 14px;
    color: white;
    margin: 0;
  }

  &.checked {
    background: var(--primary-button-background-color);
    border-color: var(--primary-button-background-color);
  }
}

.tile-actions {
  display: flex;
  gap: 8px;
}

.tile-action-wrap {
  position: relative;
  width: 36px;
  height: 36px;
}

.tile-action-btn {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border: none;
  background: rgba(255, 255, 255, 0.9);
  color: #333;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background 0.15s ease, transform 0.1s ease;
  padding: 0;

  svg {
    width: 16px;
    height: 16px;
    margin: 0;
  }

  &:hover {
    background: white;
    transform: scale(1.1);
  }

  &.tile-action-danger:hover {
    background: var(--color-danger);
    color: white;
  }
}

.tile-info {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  padding: 6px 8px;
  display: flex;
  flex-direction: column;
  gap: 1px;
  min-width: 0;
}

.tile-name {
  font-size: 0.7rem;
  font-weight: 500;
  color: white;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tile-size {
  font-size: 0.6rem;
  color: rgba(255, 255, 255, 0.7);
}

// Tag pills
.tile-tags {
  position: absolute;
  top: 4px;
  left: 4px;
  right: 4px;
  display: flex;
  flex-wrap: wrap;
  gap: 3px;
  pointer-events: auto;
  z-index: 5;
}

.tag-pill {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 10px;
  font-size: 0.6rem;
  font-weight: 500;
  color: white;
  background: var(--accent-color);
  cursor: pointer;
  max-width: 70px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
  transition: opacity 0.15s;
  line-height: 1.4;

  &:hover {
    opacity: 0.85;
  }

  &--auto {
    background: rgba(0, 0, 0, 0.4);
    backdrop-filter: blur(4px);
  }

  &--overflow {
    background: rgba(0, 0, 0, 0.4);
    backdrop-filter: blur(4px);
    cursor: default;

    &:hover {
      opacity: 1;
    }
  }
}

.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 60px 20px;
  color: var(--panel-section-text-color);
  opacity: 0.5;

  .empty-icon {
    width: 48px;
    height: 48px;
    margin-bottom: 10px;
  }

  p {
    font-size: 1rem;
    margin: 0;
  }
}
</style>
