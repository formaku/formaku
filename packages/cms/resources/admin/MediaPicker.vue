<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId } from "vue";
import { FileText } from "lucide-vue-next";

type MediaPage = {
  data: { id: number; name: string; mime: string; visibility: "public" | "private" }[];
  current_page: number;
  last_page: number;
};

const props = defineProps<{
  selectedId?: number | string | null;
  mode?: "file" | "image";
  descriptionId?: string;
}>();
const emit = defineEmits<{ select: [id: number | undefined] }>();
const isFilePicker = computed(() => props.mode === "file");
const title = computed(() => isFilePicker.value ? "Wybierz plik" : "Wybierz obraz");
const prefix = (
  document.querySelector('meta[name="formaku-prefix"]') as HTMLMetaElement
).content;
const headingId = useId();
const trigger = ref<HTMLButtonElement | null>(null);
const dialog = ref<HTMLDialogElement | null>(null);
const page = ref<MediaPage | null>(null);
const loading = ref(false);
const failedPage = ref<number | null>(null);
let controller: AbortController | undefined;

async function loadPage(number: number) {
  controller?.abort();
  const request = new AbortController();
  controller = request;
  loading.value = true;
  failedPage.value = null;

  try {
    const query = new URLSearchParams({ page: String(number) });
    if (!isFilePicker.value) query.set("kind", "public-image");
    const response = await fetch(
      `${prefix}/api/v1/media?${query}`,
      { headers: { Accept: "application/json" }, signal: request.signal },
    );
    if (!response.ok) throw new Error();

    const result: MediaPage = await response.json();
    if (controller === request) page.value = result;
  } catch {
    if (controller === request) failedPage.value = number;
  } finally {
    if (controller === request) {
      loading.value = false;
      controller = undefined;
    }
  }
}

function openPicker() {
  if (!dialog.value || dialog.value.open) return;
  page.value = null;
  dialog.value.showModal();
  void loadPage(1);
}

function closePicker() {
  controller?.abort();
  controller = undefined;
  dialog.value?.close();
  trigger.value?.focus();
}

function selectMedia(id: number) {
  emit("select", id);
  closePicker();
}

function removeMedia() {
  emit("select", undefined);
  trigger.value?.focus();
}

onBeforeUnmount(() => controller?.abort());
</script>

<template>
  <div class="flex flex-wrap items-center gap-2">
    <button
      ref="trigger"
      type="button"
      class="btn btn-sm"
      aria-haspopup="dialog"
      :aria-describedby="descriptionId"
      @click="openPicker"
    >
      {{ title }}
    </button>
    <template v-if="props.selectedId">
      <span class="text-sm text-base-content/70">{{ isFilePicker ? "Plik przypisany" : "Obraz przypisany" }}</span>
      <button type="button" class="btn btn-sm btn-ghost" @click="removeMedia">
        {{ isFilePicker ? "Usuń przypisanie pliku" : "Usuń przypisanie obrazu" }}
      </button>
    </template>
  </div>
  <Teleport to="body">
    <dialog
      ref="dialog"
      class="modal"
      :aria-labelledby="headingId"
      @cancel.prevent="closePicker"
    >
      <div class="modal-box max-w-2xl space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-2">
          <h2 :id="headingId" class="text-lg font-semibold">{{ title }}</h2>
          <button type="button" class="btn btn-sm" autofocus @click="closePicker">
            Anuluj
          </button>
        </header>
        <p v-if="loading" role="status">{{ isFilePicker ? "Wczytywanie plików…" : "Wczytywanie obrazów…" }}</p>
        <div v-else-if="failedPage !== null" class="alert alert-error">
          <span role="alert">{{ isFilePicker ? "Nie udało się wczytać plików." : "Nie udało się wczytać obrazów." }}</span>
          <button type="button" class="btn btn-sm" @click="loadPage(failedPage)">
            Spróbuj ponownie
          </button>
        </div>
        <p v-else-if="page && page.data.length === 0">{{ isFilePicker ? "Brak plików." : "Brak publicznych obrazów." }}</p>
        <div v-if="page?.data.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3">
          <button
            v-for="file in page.data"
            :key="file.id"
            type="button"
            class="btn h-auto min-w-0 flex-col gap-2 p-2"
            :class="{ 'btn-active': String(props.selectedId) === String(file.id) }"
            :aria-current="String(props.selectedId) === String(file.id) ? 'true' : undefined"
            :aria-label="isFilePicker ? file.name : undefined"
            :aria-describedby="isFilePicker ? `${headingId}-file-${file.id}` : undefined"
            :disabled="loading"
            @click="selectMedia(file.id)"
          >
            <img
              v-if="!isFilePicker || file.mime.startsWith('image/')"
              :src="`${prefix}/media/${file.id}`"
              alt=""
              loading="lazy"
              class="aspect-video w-full rounded-field bg-base-200 object-contain"
            />
            <span v-else class="flex aspect-video w-full items-center justify-center rounded-field bg-base-200" aria-hidden="true">
              <FileText :size="32" />
            </span>
            <span class="w-full wrap-anywhere">{{ file.name }}</span>
            <span v-if="isFilePicker" :id="`${headingId}-file-${file.id}`" class="badge badge-sm">
              {{ file.visibility === "public" ? "Publiczny" : "Prywatny" }}
            </span>
          </button>
        </div>
        <nav
          v-if="page && page.last_page > 1"
          :aria-label="isFilePicker ? 'Strony plików' : 'Strony obrazów'"
          class="flex flex-wrap items-center gap-2"
        >
          <button
            type="button"
            class="btn btn-sm"
            :disabled="loading || page.current_page <= 1"
            @click="loadPage(page.current_page - 1)"
          >
            Poprzednia
          </button>
          <span aria-live="polite">Strona {{ page.current_page }} z {{ page.last_page }}</span>
          <button
            type="button"
            class="btn btn-sm"
            :disabled="loading || page.current_page >= page.last_page"
            @click="loadPage(page.current_page + 1)"
          >
            Następna
          </button>
        </nav>
      </div>
    </dialog>
  </Teleport>
</template>
