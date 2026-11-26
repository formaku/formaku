<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { t } from "./locale";
import PortalChecks from "./PortalChecks.vue";
import type { AgencyRequest, ProjectId } from "./agency";
const props = defineProps<{ request: AgencyRequest; projectId: ProjectId | null }>();
const emit = defineEmits<{ applied: [] }>();
type Change = {
  key: string;
  status: "new" | "update" | "unchanged" | "local" | "conflict" | "retained" | "remove";
  reason?: string;
};
const labels = {
  new: "Nowy element",
  update: "Aktualizacja",
  unchanged: "Bez zmian",
  local: "Lokalna modyfikacja",
  retained: "Zachowano dane",
  remove: "Usunięcie konfiguracji",
  conflict: "Konflikt",
};
const includeFieldText = ref(false);
const revision = ref(1),
  name = ref(""),
  body = ref("");
const imported = ref<object | null>(null);
const preview = ref<{ token: string; changes: Change[]; can_apply: boolean } | null>(null);
const deployments = ref<{ id: ProjectId; revision: number; can_rollback: boolean }[]>([]);
const busy = ref(false),
  error = ref(""),
  message = ref("");
const selectedTarget = computed(() =>
  name.value.trim() || body.value.trim()
    ? {
        ...(name.value.trim() ? { name: name.value.trim() } : {}),
        ...(body.value.trim() ? { body: body.value.trim() } : {}),
      }
    : undefined,
);
watch(
  [imported, name, body],
  () => {
    preview.value = null;
  },
  { flush: "sync" },
);
async function list() {
  deployments.value = (await props.request("/agency/packages")).deployments;
}
async function run(action: () => Promise<void>) {
  if (busy.value) return;
  busy.value = true;
  error.value = message.value = "";
  try {
    await action();
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : t("Operacja nie powiodła się");
  } finally {
    busy.value = false;
  }
}
function download() {
  void run(async () => {
    const pkg = await props.request("/agency/packages/export", "POST", {
      proposal_id: props.projectId,
      revision: revision.value,
      include_field_text: includeFieldText.value,
    });
    const url = URL.createObjectURL(new Blob([JSON.stringify(pkg, null, 2)], { type: "application/json" }));
    const link = document.createElement("a");
    link.href = url;
    link.download = `formaku-b2b-v${revision.value}.json`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    message.value = t("Przygotowano pakiet.");
  });
}
let fileSelection = 0;
async function readFile(event: Event) {
  const selection = ++fileSelection;
  const file = (event.currentTarget as HTMLInputElement).files?.[0];
  imported.value = preview.value = null;
  error.value = message.value = "";
  if (!file) return;
  try {
    if (file.size > 1024 * 1024) throw new Error(t("Pakiet przekracza 1 MiB."));
    const data: unknown = JSON.parse(await file.text());
    if (!data || Array.isArray(data) || typeof data !== "object")
      throw new Error(t("Niepoprawny plik pakietu."));
    if (selection === fileSelection) imported.value = data;
  } catch (failure) {
    if (selection === fileSelection)
      error.value = failure instanceof SyntaxError ? t("Niepoprawny plik pakietu.") : String(failure);
  }
}
function inspect() {
  void run(async () => {
    preview.value = null;
    preview.value = await props.request("/agency/packages/preview", "POST", {
      package: imported.value,
      target: selectedTarget.value,
    });
  });
}
function apply() {
  if (!preview.value?.can_apply) return;
  void run(async () => {
    const token = preview.value?.token;
    preview.value = null;
    await props.request("/agency/packages/apply", "POST", {
      package: imported.value,
      target: selectedTarget.value,
      token,
    });
    emit("applied");
    message.value = t("Wdrożono konfigurację pakietu.");
    await list();
  });
}
function rollback(id: ProjectId) {
  if (!window.confirm(t("Przywrócić konfigurację sprzed tej aktualizacji?"))) return;
  void run(async () => {
    await props.request(`/agency/packages/${id}/rollback`, "POST", {});
    preview.value = null;
    emit("applied");
    message.value = t("Przywrócono konfigurację.");
    await list();
  });
}
onMounted(() => run(list));
</script>
<template>
  <section class="space-y-4 rounded-box border border-base-300 bg-base-100 p-5">
    <h2 class="text-xl">{{ t("Pakiety wdrożeniowe") }}</h2>
    <form v-if="projectId" class="flex flex-wrap items-end gap-3" @submit.prevent="download">
      <label
        ><span class="field-label">{{ t("Wersja pakietu") }}</span
        ><input type="number" class="input w-28" min="1" step="1" required v-model.number="revision"
      /></label>
      <label class="flex items-center gap-2"
        ><input class="checkbox checkbox-sm" type="checkbox" v-model="includeFieldText" />{{
          t("Zachowaj zatwierdzone etykiety i opcje formularza")
        }}</label
      >
      <button class="btn" :disabled="busy">{{ t("Eksportuj pakiet") }}</button>
    </form>
    <fieldset class="fieldset">
      <legend class="fieldset-legend">{{ t("Wdrożenie na tej instalacji") }}</legend>
      <label
        ><span class="field-label">{{ t("Pakiet JSON") }}</span
        ><input
          type="file"
          accept=".json,application/json"
          class="file-input w-full"
          :disabled="busy"
          @change="readFile"
      /></label>
      <label
        ><span class="field-label">{{ t("Nazwa firmy docelowej") }}</span
        ><input class="input w-full" v-model="name" :disabled="busy"
      /></label>
      <label
        ><span class="field-label">{{ t("Opis usług firmy docelowej") }}</span
        ><textarea class="textarea w-full" v-model="body" :disabled="busy" />
      </label>
      <button type="button" class="btn justify-self-start" :disabled="busy || !imported" @click="inspect">
        {{ t("Porównaj z tą instalacją") }}
      </button>
    </fieldset>
    <div v-if="preview" class="space-y-2" aria-live="polite">
      <ul>
        <li v-for="change in preview.changes" :key="change.key">
          {{ change.key }} · {{ t(labels[change.status] ?? change.status)
          }}<span v-if="change.reason"> · {{ change.reason }}</span>
        </li>
      </ul>
      <button class="btn" :disabled="busy || !preview.can_apply" @click="apply">
        {{ t("Zastosuj pakiet") }}
      </button>
    </div>
    <div v-if="deployments.length" class="space-y-2">
      <h3 class="font-semibold">{{ t("Historia wdrożeń pakietów") }}</h3>
      <div v-for="item in deployments" :key="item.id" class="flex flex-wrap items-center gap-3">
        <span>#{{ item.id }} · {{ t("Wersja pakietu") }} {{ item.revision }}</span>
        <button v-if="item.can_rollback" class="btn btn-sm" :disabled="busy" @click="rollback(item.id)">
          {{ t("Cofnij tę aktualizację") }}
        </button>
      </div>
    </div>
    <PortalChecks v-if="deployments.length" :request="request" />
    <p v-if="error" role="alert" class="alert alert-error">{{ error }}</p>
    <p v-if="message" role="status">{{ message }}</p>
  </section>
</template>
