<script setup lang="ts">
import { t } from "./locale";
import { computed, onBeforeUnmount, ref, useId } from "vue";

type RecordId = number | string;
type RelationOption = { id: number; label: string };
type RelationPage = {
  data: RelationOption[];
  current_page: number;
  last_page: number;
  selected?: RelationOption[];
};

const props = defineProps<{
  modelValue?: unknown;
  target?: number;
  label: string;
  privateField?: boolean;
}>();
const emit = defineEmits<{ "update:modelValue": [ids: RecordId[]] }>();
const prefix = (
  document.querySelector('meta[name="formaku-prefix"]') as HTMLMetaElement
).content;
const fieldId = useId();
const headingId = useId();
const trigger = ref<HTMLButtonElement | null>(null);
const dialog = ref<HTMLDialogElement | null>(null);
const page = ref<RelationPage | null>(null);
const selection = ref(new Map<number, RecordId>());
const labels = ref(new Map<number, string>());
const loading = ref(false);
const failedPage = ref<number | null>(null);
let controller: AbortController | undefined;

const savedSelection = computed(() => {
  const values: unknown[] = Array.isArray(props.modelValue)
    ? props.modelValue
    : [props.modelValue];
  const entries = new Map<number, RecordId>();
  for (const value of values) {
    if (typeof value !== "number" && typeof value !== "string") continue;
    const id = Number(value);
    if (Number.isSafeInteger(id) && id > 0) entries.set(id, value);
  }
  return entries;
});

async function loadPage(number: number) {
  if (!props.target) return;
  controller?.abort();
  const request = new AbortController();
  controller = request;
  loading.value = true;
  failedPage.value = null;
  const query = new URLSearchParams({ page: String(number) });
  for (const id of Array.from(selection.value.keys()).slice(0, 100)) {
    query.append("selected[]", String(id));
  }

  try {
    const response = await fetch(
      `${prefix}/api/v1/collections/${props.target}/relation-options?${query}`,
      { headers: { Accept: "application/json" }, signal: request.signal },
    );
    if (!response.ok) throw new Error(t("Nie udało się wczytać rekordów."));

    const result: RelationPage = await response.json();
    if (controller !== request) return;
    page.value = result;
    for (const item of [...result.data, ...(result.selected ?? [])]) {
      labels.value.set(item.id, item.label);
    }
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
  labels.value.clear();
  selection.value = new Map(savedSelection.value);
  dialog.value.showModal();
  void loadPage(1);
}

function closePicker() {
  controller?.abort();
  controller = undefined;
  dialog.value?.close();
  trigger.value?.focus();
}

function toggleRecord(id: number, checked: boolean) {
  if (checked) selection.value.set(id, savedSelection.value.get(id) ?? id);
  else selection.value.delete(id);
}

function applySelection() {
  if (
    selection.value.size !== savedSelection.value.size ||
    [...selection.value.keys()].some((id) => !savedSelection.value.has(id))
  ) {
    emit("update:modelValue", [...selection.value.values()]);
  }
  closePicker();
}

onBeforeUnmount(() => controller?.abort());
</script>

<template>
  <div>
    <span :id="fieldId" class="field-label">
      {{ label }}<span v-if="privateField" class="text-secondary"> {{ t("· wewnętrzne") }}</span>
    </span>
    <div class="flex flex-wrap items-center gap-3">
      <button
        ref="trigger"
        type="button"
        class="btn btn-sm"
        aria-haspopup="dialog"
        :aria-describedby="fieldId"
        :disabled="!target"
        @click="openPicker"
      >
        {{ t("Wybierz rekordy") }}
      </button>
      <span v-if="savedSelection.size" class="text-sm text-base-content/70">
        {{ t("Powiązane rekordy:") }} {{ savedSelection.size }}
      </span>
    </div>
  </div>
  <Teleport to="body">
    <dialog
      ref="dialog"
      class="modal"
      :aria-labelledby="headingId"
      @cancel.prevent="closePicker"
    >
      <form method="dialog" class="modal-box max-w-2xl space-y-4" @submit.prevent="applySelection">
        <h2 :id="headingId" class="text-lg font-semibold">{{ label }}</h2>
        <section v-if="selection.size" :aria-label='t("Wybrane rekordy")'>
          <p class="field-label">{{ t("Wybrane rekordy:") }} {{ selection.size }}</p>
          <ul class="max-h-32 space-y-1 overflow-y-auto">
            <li v-for="([id], index) in selection" :key="id" class="flex flex-wrap items-center gap-2">
              <span class="min-w-0 wrap-anywhere">{{ labels.get(id) ?? t("Powiązany rekord {0}", index + 1) }}</span>
              <button
                type="button"
                class="btn btn-xs btn-ghost"
                :aria-label="t('Usuń z wyboru {0}', labels.get(id) ?? t('powiązany rekord {0}', index + 1))"
                @click="toggleRecord(id, false)"
              >
                {{ t("Usuń") }}
              </button>
            </li>
          </ul>
        </section>
        <p v-if="loading" role="status">{{ t("Wczytywanie rekordów…") }}</p>
        <div v-else-if="failedPage !== null" class="alert alert-error">
          <span role="alert">{{ t("Nie udało się wczytać rekordów.") }}</span>
          <button type="button" class="btn btn-sm" @click="loadPage(failedPage)">
            {{ t("Spróbuj ponownie") }}
          </button>
        </div>
        <p v-else-if="page && page.data.length === 0">{{ t("Brak dostępnych rekordów.") }}</p>
        <nav
          v-if="page && page.last_page > 1"
          :aria-label='t("Strony rekordów")'
          class="flex flex-wrap items-center gap-2"
        >
          <button type="button" class="btn btn-sm" :disabled="loading || page.current_page <= 1" @click="loadPage(page.current_page - 1)">
            {{ t("Poprzednia") }}
          </button>
          <span aria-live="polite">{{ t("Strona") }} {{ page.current_page }} z {{ page.last_page }}</span>
          <button type="button" class="btn btn-sm" :disabled="loading || page.current_page >= page.last_page" @click="loadPage(page.current_page + 1)">
            {{ t("Następna") }}
          </button>
        </nav>
        <ul v-if="page?.data.length" class="max-h-64 space-y-1 overflow-y-auto">
          <li v-for="item in page.data" :key="item.id">
            <label class="flex items-center gap-3 rounded-field p-2 hover:bg-base-200">
              <input
                type="checkbox"
                class="checkbox checkbox-sm"
                :checked="selection.has(item.id)"
                :disabled="loading"
                @change="toggleRecord(item.id, ($event.target as HTMLInputElement).checked)"
              />
              <span class="min-w-0 wrap-anywhere">{{ item.label }}</span>
            </label>
          </li>
        </ul>
        <div class="modal-action flex-wrap">
          <button type="button" class="btn btn-sm" autofocus @click="closePicker">{{ t("Anuluj") }}</button>
          <button type="submit" class="btn btn-sm btn-primary">{{ t("Zastosuj wybór") }}</button>
        </div>
      </form>
    </dialog>
  </Teleport>
</template>
