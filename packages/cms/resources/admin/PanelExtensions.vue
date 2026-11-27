<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from "vue";
import { t } from "./locale";
import PanelActionFields, {
  actionInput,
  emptyActionValues,
  type ActionField,
  type ActionInput,
} from "./PanelActionFields.vue";

type Cell = string | number | boolean | null;
type Row = Record<string, Cell>;
type RowAction = { action: string; label: string; input_key: string; fields: ActionField[] };
type Resource = {
  key: string;
  label: string;
  columns: { key: string; label: string }[];
  row_key?: string;
  row_actions?: RowAction[];
};
type Action = { key: string; label: string; fields: ActionField[] };
type ResourcePage = { data: Row[]; current_page: number; has_more: boolean };
type RowRun = {
  resource: Resource;
  action: RowAction;
  row: Row;
  id: string | number;
  values: ActionInput;
  busy: boolean;
  error: string;
  result: string | null;
};

const props = defineProps<{
  active: boolean;
  extensions: { resources: Resource[]; actions: Action[] };
  request: (path: string, method?: string, data?: { input: ActionInput; row?: string | number }) => Promise<unknown>;
}>();

const resourceKey = ref("");
const resource = computed(() =>
  props.extensions.resources.find((item) => item.key === resourceKey.value) ?? props.extensions.resources[0],
);
const resourcePage = ref<ResourcePage | null>(null);
const resourceLoading = ref(false);
const resourceError = ref(false);
// The list grows when its first page arrives. The action form below it is drawn after that request
// has finished, so the form does not move under the pointer.
const resourceSettled = ref(false);
let resourceRequest = 0;
let requestedPage = 1;

async function loadResource(page: number) {
  const key = resource.value?.key;
  if (!key) return;

  const requestId = ++resourceRequest;
  requestedPage = page;
  resourceLoading.value = true;
  resourceError.value = false;
  try {
    const response = await props.request(`/extensions/resources/${encodeURIComponent(key)}?page=${page}`) as ResourcePage;
    if (requestId === resourceRequest && key === resource.value?.key) {
      resourcePage.value = response;
    }
  } catch (error) {
    if (requestId === resourceRequest && key === resource.value?.key) {
      const status = (error as { status?: number })?.status;
      if (status === 401 || status === 403 || status === 404) {
        resourcePage.value = null;
      }
      resourceError.value = true;
    }
  } finally {
    if (requestId === resourceRequest) {
      resourceLoading.value = false;
      resourceSettled.value = true;
    }
  }
}

watch(
  [() => props.active, () => resource.value?.key],
  ([active, key], [, previousKey]) => {
    if (key !== previousKey) {
      resourcePage.value = null;
      resourceError.value = false;
    }
    if (active && key) void loadResource(1);
  },
  { immediate: true },
);

function displayCell(value: Cell | undefined): string | number {
  if (typeof value === "boolean") return value ? t("Tak") : t("Nie");
  return value ?? "—";
}

const rowActions = computed(() => (resource.value?.row_key ? resource.value.row_actions ?? [] : []));

// The API takes an integer or a non-empty string of up to 255 characters as the row identifier.
function rowId(row: Row, key = resource.value?.row_key): string | number | null {
  const value = key !== undefined && Object.hasOwn(row, key) ? row[key] : null;
  if (typeof value === "number") return Number.isSafeInteger(value) ? value : null;
  return typeof value === "string" && value.trim() !== "" && [...value].length <= 255 ? value : null;
}

const rowIds = computed(() => resourcePage.value?.data.map((row) => rowId(row)) ?? []);
const rowDialog = ref<HTMLDialogElement | null>(null);
const rowCloseButton = ref<HTMLButtonElement | null>(null);
const rowHeadingId = useId();
// Each opened dialog gets its own object. A response that arrives after the dialog was closed
// writes to that object and leaves a dialog opened later alone.
const rowRun = ref<RowRun | null>(null);
let rowOpen = false;
let rowTrigger: HTMLElement | null = null;

async function openRowAction(action: RowAction, row: Row, trigger: EventTarget | null) {
  const id = rowId(row);
  if (!resource.value || !rowDialog.value || id === null || rowOpen) return;

  rowOpen = true;
  rowTrigger = trigger instanceof HTMLElement ? trigger : null;
  rowRun.value = {
    resource: resource.value,
    action,
    row,
    id,
    values: emptyActionValues(action.fields),
    busy: false,
    error: "",
    result: null,
  };
  // The dialog takes its first focus from its content, so draw the content first.
  await nextTick();
  rowDialog.value?.showModal();
}

function closeRowAction() {
  // A sent request cannot be withdrawn, so the dialog stays until the answer arrives.
  if (!rowRun.value?.busy) rowDialog.value?.close();
}

function rowActionClosed() {
  // The content stays while the dialog fades out. The next opening replaces it.
  rowOpen = false;
  // Safari leaves a clicked button unfocused, so the browser has no element to return the focus to.
  if (rowTrigger?.isConnected) rowTrigger.focus();
  rowTrigger = null;
}

function rowFailure(error: unknown): string {
  const status = (error as { status?: number })?.status;
  if (status === 404) return t("Ten wiersz albo ta akcja nie są już dostępne.");
  // The API names the reason for a rejected request: a field value, a rule of the action or the resource's query.
  if (status !== undefined && status >= 400 && status < 500 && error instanceof Error && error.message) {
    return error.message;
  }
  return t("Nie udało się wykonać akcji.");
}

async function runRowAction() {
  const run = rowRun.value;
  if (!run || run.busy || run.result !== null) return;

  const input = actionInput(run.action.fields, run.values);
  if (input === null) {
    run.error = t("Sprawdź wartości pól akcji.");
    return;
  }

  run.busy = true;
  run.error = "";
  try {
    const { result } = await props.request(
      `/extensions/resources/${encodeURIComponent(run.resource.key)}/actions/${encodeURIComponent(run.action.action)}`,
      "POST",
      { row: run.id, input },
    ) as { result: unknown };
    run.result = JSON.stringify(result, null, 2) ?? "";
  } catch (error) {
    run.error = rowFailure(error);
  } finally {
    run.busy = false;
  }

  if (run.result !== null && rowRun.value === run) {
    await nextTick();
    rowCloseButton.value?.focus();
  }

  // The action may have changed the row or removed it from the list. Show the dialog the row as it is now.
  if (run.resource.key !== resource.value?.key) return;
  await loadResource(requestedPage);
  if (run.resource.key !== resource.value?.key) return;
  const current = resourcePage.value?.data.find((row) => rowId(row, run.resource.row_key) === run.id);
  if (current) run.row = current;
}

watch(() => props.active, (active) => {
  if (!active) rowDialog.value?.close();
});

const actionKey = ref("");
const action = computed(() =>
  props.extensions.actions.find((item) => item.key === actionKey.value) ?? props.extensions.actions[0],
);
const actionValues = ref<ActionInput>({});
const actionBusy = ref(false);
const actionError = ref("");
const actionResult = ref("");

watch(action, (selected, previous) => {
  if (selected?.key === previous?.key) return;
  actionValues.value = emptyActionValues(selected?.fields ?? []);
  actionError.value = "";
  actionResult.value = "";
}, { immediate: true });

async function runAction() {
  const selected = action.value;
  if (!selected || actionBusy.value) return;

  actionError.value = "";
  actionResult.value = "";
  const input = actionInput(selected.fields, actionValues.value);
  if (input === null) {
    actionError.value = "Sprawdź wartości pól akcji.";
    return;
  }

  actionBusy.value = true;
  try {
    const { result } = await props.request(
      `/extensions/actions/${encodeURIComponent(selected.key)}`,
      "POST",
      { input },
    ) as { result: unknown };
    if (selected.key === action.value?.key) {
      actionResult.value = JSON.stringify(result, null, 2) ?? "";
    }
  } catch (error) {
    if (selected.key === action.value?.key) {
      actionError.value = (error as { status?: number })?.status === 422
        ? "Sprawdź wartości pól akcji."
        : "Nie udało się wykonać akcji.";
    }
  } finally {
    actionBusy.value = false;
  }
}
</script>

<template>
  <div class="min-w-0 space-y-6">
    <section v-if="resource" class="card card-border bg-base-100" aria-labelledby="extension-resources-title">
      <div class="card-body min-w-0">
        <h2 id="extension-resources-title" class="card-title">{{ t("Zasoby") }}</h2>
        <label class="block">
          <span class="field-label">{{ t("Wybierz zasób") }}</span>
          <select class="select w-full max-w-md" :value="resource.key" @change="resourceKey = ($event.target as HTMLSelectElement).value">
            <option v-for="item in extensions.resources" :key="item.key" :value="item.key">{{ item.label }}</option>
          </select>
        </label>
        <div v-if="resourceError" class="alert alert-error sm:alert-horizontal" role="alert">
          <span>{{ t("Nie udało się wczytać zasobu.") }}</span>
          <button type="button" class="btn btn-sm" @click="loadResource(requestedPage)">{{ t("Spróbuj ponownie") }}</button>
        </div>
        <p v-if="resourceLoading && !resourcePage" aria-live="polite">{{ t("Wczytywanie zasobu…") }}</p>
        <template v-if="resourcePage">
          <div v-if="resourcePage.data.length" class="overflow-x-auto" :aria-busy="resourceLoading">
            <table class="table" :aria-label="resource.label">
              <thead>
                <tr>
                  <th v-for="column in resource.columns" :key="column.key" scope="col">{{ column.label }}</th>
                  <th v-if="rowActions.length" scope="col">{{ t("Akcje") }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, index) in resourcePage.data" :key="index">
                  <td v-for="column in resource.columns" :key="column.key" class="break-words">
                    {{ displayCell(Object.hasOwn(row, column.key) ? row[column.key] : undefined) }}
                  </td>
                  <td v-if="rowActions.length">
                    <div v-if="rowIds[index] !== null" class="flex flex-wrap gap-2">
                      <button
                        v-for="item in rowActions"
                        :key="item.action"
                        type="button"
                        class="btn btn-sm"
                        aria-haspopup="dialog"
                        :aria-label="`${item.label}: ${rowIds[index]}`"
                        @click="openRowAction(item, row, $event.currentTarget)"
                      >{{ item.label }}</button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else>{{ t("Brak dostępnych rekordów.") }}</p>
          <nav
            v-if="resourcePage.current_page > 1 || resourcePage.has_more"
            class="flex flex-wrap items-center gap-3"
            :aria-label="t('Strony zasobu')"
          >
            <button
              type="button"
              class="btn btn-sm"
              :disabled="resourceLoading || resourcePage.current_page <= 1"
              @click="loadResource(resourcePage.current_page - 1)"
            >{{ t("Poprzednia strona") }}</button>
            <span>{{ t("Strona {0}", resourcePage.current_page) }}</span>
            <button
              type="button"
              class="btn btn-sm"
              :disabled="resourceLoading || !resourcePage.has_more"
              @click="loadResource(resourcePage.current_page + 1)"
            >{{ t("Następna strona") }}</button>
          </nav>
        </template>
      </div>
    </section>

    <section
      v-if="action && (!resource || resourceSettled)"
      class="card card-border bg-base-100"
      aria-labelledby="extension-actions-title"
    >
      <div class="card-body min-w-0">
        <h2 id="extension-actions-title" class="card-title">{{ t("Akcje") }}</h2>
        <label class="block">
          <span class="field-label">{{ t("Wybierz akcję") }}</span>
          <select
            class="select w-full max-w-md"
            :value="action.key"
            :disabled="actionBusy"
            @change="actionKey = ($event.target as HTMLSelectElement).value"
          >
            <option v-for="item in extensions.actions" :key="item.key" :value="item.key">{{ item.label }}</option>
          </select>
        </label>
        <form :key="action.key" class="space-y-3" @submit.prevent="runAction">
          <PanelActionFields v-model="actionValues" :fields="action.fields" :disabled="actionBusy" />
          <button type="submit" class="btn" :disabled="actionBusy">
            {{ actionBusy ? t("Wykonywanie akcji…") : t("Uruchom akcję") }}
          </button>
        </form>
        <p v-if="actionError" role="alert" class="alert alert-error">{{ t(actionError) }}</p>
        <section v-if="actionResult" aria-labelledby="extension-action-result-title" class="space-y-2">
          <h3 id="extension-action-result-title" class="font-semibold" aria-live="polite">{{ t("Wynik akcji") }}</h3>
          <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-words rounded-box bg-base-200 p-4 text-sm">{{ actionResult }}</pre>
        </section>
      </div>
    </section>

    <Teleport to="body">
      <dialog
        ref="rowDialog"
        class="modal"
        :aria-labelledby="rowHeadingId"
        @cancel.prevent="closeRowAction"
        @close="rowActionClosed"
      >
        <form v-if="rowRun" class="modal-box space-y-4" @submit.prevent="runRowAction">
          <h2 :id="rowHeadingId" class="text-lg font-semibold">{{ rowRun.action.label }}</h2>
          <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            <template v-for="column in rowRun.resource.columns" :key="column.key">
              <dt class="font-medium">{{ column.label }}</dt>
              <dd class="min-w-0 wrap-anywhere">
                {{ displayCell(Object.hasOwn(rowRun.row, column.key) ? rowRun.row[column.key] : undefined) }}
              </dd>
            </template>
          </dl>
          <PanelActionFields
            v-if="rowRun.result === null"
            v-model="rowRun.values"
            :fields="rowRun.action.fields"
            :disabled="rowRun.busy"
            autofocus
          />
          <p v-if="rowRun.error" role="alert" class="alert alert-error">{{ rowRun.error }}</p>
          <p role="status" class="sr-only">{{ rowRun.result === null ? "" : t("Akcja wykonana.") }}</p>
          <section v-if="rowRun.result !== null" class="space-y-2">
            <h3 class="font-semibold">{{ t("Wynik akcji") }}</h3>
            <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-words rounded-box bg-base-200 p-4 text-sm">{{ rowRun.result }}</pre>
          </section>
          <div class="modal-action">
            <button
              ref="rowCloseButton"
              type="button"
              class="btn"
              :autofocus="!rowRun.action.fields.length"
              :disabled="rowRun.busy"
              @click="closeRowAction"
            >{{ rowRun.result === null ? t("Anuluj") : t("Zamknij") }}</button>
            <button v-if="rowRun.result === null" type="submit" class="btn btn-primary" :disabled="rowRun.busy">
              {{ rowRun.busy ? t("Wykonywanie akcji…") : t("Uruchom akcję") }}
            </button>
          </div>
        </form>
      </dialog>
    </Teleport>
  </div>
</template>
