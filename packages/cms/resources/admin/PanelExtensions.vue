<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { t } from "./locale";

type Cell = string | number | boolean | null;
type Field = { key: string; label: string; type: "text" | "integer" | "boolean"; required?: boolean };
type Resource = { key: string; label: string; columns: { key: string; label: string }[] };
type Action = { key: string; label: string; fields: Field[] };
type ResourcePage = { data: Record<string, Cell>[]; current_page: number; has_more: boolean };
type Input = Record<string, Exclude<Cell, null>>;

const props = defineProps<{
  active: boolean;
  extensions: { resources: Resource[]; actions: Action[] };
  request: (path: string, method?: string, data?: { input: Input }) => Promise<unknown>;
}>();

const resourceKey = ref("");
const resource = computed(() =>
  props.extensions.resources.find((item) => item.key === resourceKey.value) ?? props.extensions.resources[0],
);
const resourcePage = ref<ResourcePage | null>(null);
const resourceLoading = ref(false);
const resourceError = ref(false);
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
  } catch {
    if (requestId === resourceRequest && key === resource.value?.key) {
      resourceError.value = true;
    }
  } finally {
    if (requestId === resourceRequest) {
      resourceLoading.value = false;
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

const actionKey = ref("");
const action = computed(() =>
  props.extensions.actions.find((item) => item.key === actionKey.value) ?? props.extensions.actions[0],
);
const actionValues = ref<Input>({});
const actionBusy = ref(false);
const actionError = ref("");
const actionResult = ref("");

watch(action, (selected, previous) => {
  if (selected?.key === previous?.key) return;
  actionValues.value = Object.fromEntries((selected?.fields ?? []).map((field) => [field.key, ""]));
  actionError.value = "";
  actionResult.value = "";
}, { immediate: true });

async function runAction() {
  const selected = action.value;
  if (!selected || actionBusy.value) return;

  actionError.value = "";
  actionResult.value = "";
  const input: Input = Object.fromEntries(
    selected.fields.flatMap((field) => {
      const value = actionValues.value[field.key];
      return value === undefined || (typeof value === "string" && value.trim() === "") ? [] : [[field.key, value]];
    }),
  );
  if (selected.fields.some((field) => {
    const value = Object.hasOwn(input, field.key) ? input[field.key] : undefined;
    return (field.required && value === undefined) ||
      (field.type === "integer" && value !== undefined && !Number.isSafeInteger(value));
  })) {
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
        <p v-if="resourceLoading" aria-live="polite">{{ t("Wczytywanie zasobu…") }}</p>
        <template v-if="resourcePage">
          <div v-if="resourcePage.data.length" class="overflow-x-auto">
            <table class="table" :aria-label="resource.label">
              <thead>
                <tr>
                  <th v-for="column in resource.columns" :key="column.key" scope="col">{{ column.label }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, index) in resourcePage.data" :key="index">
                  <td v-for="column in resource.columns" :key="column.key" class="break-words">
                    {{ displayCell(Object.hasOwn(row, column.key) ? row[column.key] : undefined) }}
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

    <section v-if="action" class="card card-border bg-base-100" aria-labelledby="extension-actions-title">
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
          <label v-for="field in action.fields" :key="field.key" class="block">
            <span class="field-label">{{ field.label }}</span>
            <select
              v-if="field.type === 'boolean'"
              v-model="actionValues[field.key]"
              class="select w-full max-w-md"
              :required="field.required"
              :disabled="actionBusy"
            >
              <option value="">{{ t("Wybierz wartość") }}</option>
              <option :value="true">{{ t("Tak") }}</option>
              <option :value="false">{{ t("Nie") }}</option>
            </select>
            <input
              v-else
              v-model="actionValues[field.key]"
              class="input w-full max-w-md"
              :type="field.type === 'integer' ? 'number' : 'text'"
              :step="field.type === 'integer' ? 1 : undefined"
              :maxlength="field.type === 'text' ? 2000 : undefined"
              :required="field.required"
              :disabled="actionBusy"
            />
          </label>
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
  </div>
</template>
