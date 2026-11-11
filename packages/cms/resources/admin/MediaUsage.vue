<script setup lang="ts">
import { t } from "./locale";
import { onBeforeUnmount, ref, useId } from "vue";

type UsagePage = {
  data: { id: number; collection: string; label: string; draft: boolean; published: boolean }[];
  current_page: number;
  last_page: number;
  total: number;
};

const props = defineProps<{ api: string; id: number; name: string }>();
const headingId = useId();
const trigger = ref<HTMLButtonElement | null>(null);
const dialog = ref<HTMLDialogElement | null>(null);
const page = ref<UsagePage | null>(null);
const pending = ref(false);
const failedPage = ref<number | null>(null);
let controller: AbortController | undefined;

async function loadPage(number: number) {
  controller?.abort();
  const request = new AbortController();
  controller = request;
  pending.value = true;
  failedPage.value = null;

  try {
    const response = await fetch(`${props.api}/media/${props.id}/usage?page=${number}`, {
      headers: { Accept: "application/json" },
      signal: request.signal,
    });
    if (!response.ok) throw new Error();
    const result: UsagePage = await response.json();
    if (controller === request) page.value = result;
  } catch {
    if (controller === request) failedPage.value = number;
  } finally {
    if (controller === request) {
      pending.value = false;
      controller = undefined;
    }
  }
}

function open() {
  if (!dialog.value || dialog.value.open) return;
  page.value = null;
  dialog.value.showModal();
  void loadPage(1);
}

function close() {
  controller?.abort();
  controller = undefined;
  dialog.value?.close();
  trigger.value?.focus();
}

onBeforeUnmount(() => controller?.abort());
</script>

<template>
  <button ref="trigger" type="button" class="btn btn-sm btn-ghost mt-3" aria-haspopup="dialog" @click="open">
    {{ t("Użycie pliku") }}
  </button>
  <Teleport to="body">
    <dialog ref="dialog" class="modal" :aria-labelledby="headingId" @cancel.prevent="close">
      <div class="modal-box max-w-xl space-y-4">
        <header class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <h2 :id="headingId" class="text-lg font-semibold">{{ t("Użycie pliku") }}</h2>
            <p class="wrap-anywhere text-sm text-base-content/70">{{ name }}</p>
          </div>
          <button type="button" class="btn btn-sm" autofocus @click="close">{{ t("Zamknij") }}</button>
        </header>
        <p v-if="pending" role="status">{{ t("Wczytywanie użycia…") }}</p>
        <div v-else-if="failedPage !== null" class="alert alert-error">
          <span role="alert">{{ t("Nie udało się wczytać użycia pliku.") }}</span>
          <button type="button" class="btn btn-sm" @click="loadPage(failedPage)">{{ t("Spróbuj ponownie") }}</button>
        </div>
        <template v-else-if="page">
          <p v-if="page.total === 0">{{ t("Brak użycia w dostępnych rekordach.") }}</p>
          <template v-else>
            <p class="text-sm text-base-content/70">{{ t("Dostępne rekordy:") }} {{ page.total }}</p>
            <ul class="divide-y divide-base-300">
              <li v-for="item in page.data" :key="item.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                <div class="min-w-0 wrap-anywhere">
                  <p class="font-medium">{{ item.label }}</p>
                  <p class="text-xs text-base-content/70">{{ item.collection }}</p>
                </div>
                <div class="flex flex-wrap gap-1">
                  <span v-if="item.draft" class="badge badge-sm">{{ t("Szkic") }}</span>
                  <span v-if="item.published" class="badge badge-sm">{{ t("Publikacja") }}</span>
                </div>
              </li>
            </ul>
          </template>
        </template>
        <nav v-if="page && page.last_page > 1" class="flex flex-wrap items-center gap-2" :aria-label='t("Strony użycia pliku")'>
          <button type="button" class="btn btn-sm" :disabled="pending || page.current_page <= 1" @click="loadPage(page.current_page - 1)">
            {{ t("Poprzednia") }}
          </button>
          <span aria-live="polite">{{ t("Strona") }} {{ page.current_page }} z {{ page.last_page }}</span>
          <button type="button" class="btn btn-sm" :disabled="pending || page.current_page >= page.last_page" @click="loadPage(page.current_page + 1)">
            {{ t("Następna") }}
          </button>
        </nav>
      </div>
    </dialog>
  </Teleport>
</template>
