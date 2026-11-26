<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { t, locale } from "./locale";
import PortalPlanEditor from "./PortalPlanEditor.vue";
import AgencyPackages from "./AgencyPackages.vue";
import PortalChecks from "./PortalChecks.vue";
import {
  stateLabels,
  type AgencyRequest,
  type PortalProject,
  type PortalPlan,
  type ProjectId,
} from "./agency";

const props = defineProps<{
  request: AgencyRequest;
  base: string;
  providers: Record<string, { portal_configured?: boolean }>;
}>();
const emit = defineEmits<{ dirty: [value: boolean]; applied: [] }>();
const projects = ref<{ id: ProjectId; name: string | null; state: PortalProject["state"] }[]>([]);
const current = ref<PortalProject | null>(null);
const edited = ref<PortalPlan | null>(null);
const changes = ref<{ stale: boolean; changes: { label: string; before: unknown; after: unknown }[] } | null>(
  null,
);
const brief = ref(""),
  materials = ref(""),
  provider = ref("reference"),
  budget = ref(100);
const busy = ref(false),
  error = ref(""),
  notice = ref(""),
  preview = ref(false);
const packagesOpen = ref(false);
const modified = computed(
  () =>
    current.value?.state === "review" &&
    JSON.stringify(edited.value) !== JSON.stringify(current.value.proposal),
);
const unsaved = computed(() => modified.value || (!current.value && !!(brief.value || materials.value)));
watch(unsaved, (value) => emit("dirty", value), { flush: "sync" });
const queued = computed(() => ["queued", "running"].includes(current.value?.state ?? ""));
let poll: ReturnType<typeof setTimeout> | undefined;
let active = true;

async function perform(action: () => Promise<void>) {
  if (busy.value) return;
  busy.value = true;
  error.value = "";
  notice.value = "";
  try {
    await action();
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : t("Operacja nie powiodła się");
  } finally {
    busy.value = false;
  }
}
async function list() {
  projects.value = (await props.request("/portal-projects")).projects;
}
async function show(project: PortalProject) {
  if (!active) return;
  current.value = project;
  edited.value = project.proposal ? JSON.parse(JSON.stringify(project.proposal)) : null;
  changes.value = null;
  preview.value = false;
  clearTimeout(poll);
  if (project.state === "review") {
    changes.value = await props.request(`/portal-projects/${project.id}/changes`);
  } else if (queued.value) {
    poll = setTimeout(() => {
      if (active && current.value?.id === project.id) void reload();
    }, 2400);
  }
}
async function reload() {
  const id = current.value?.id;
  if (id === undefined) return;
  await perform(async () => {
    await show(await props.request(`/portal-projects/${id}`));
    await list();
  });
}
function discard() {
  return !unsaved.value || window.confirm(t("Odrzucić niezapisane zmiany?"));
}
function selectProject(id: string) {
  if (!discard()) return;
  if (!id) {
    clearTimeout(poll);
    current.value = edited.value = changes.value = null;
    brief.value = materials.value = error.value = notice.value = "";
    return;
  }
  void perform(async () => {
    await show(await props.request("/portal-projects/" + id));
    brief.value = materials.value = "";
  });
}
function create() {
  void perform(async () => {
    const project = await props.request("/portal-projects", "POST", {
      brief: brief.value,
      materials: materials.value || undefined,
      provider: provider.value,
      budget_cents: provider.value === "reference" ? 0 : budget.value,
    });
    await show(project);
    brief.value = materials.value = "";
    await list();
  });
}
function save() {
  if (!current.value || !edited.value) return;
  const project = current.value;
  void perform(async () => {
    await show(
      await props.request(`/portal-projects/${project.id}`, "PUT", {
        version: project.version,
        proposal: edited.value,
      }),
    );
    await list();
    notice.value = t("Zapisano propozycję.");
  });
}
function act(action: "apply" | "refresh") {
  const project = current.value;
  if (!project || modified.value || (action === "apply" && !changes.value)) return;
  void perform(async () => {
    await show(
      await props.request(`/portal-projects/${project.id}/${action}`, "POST", { version: project.version }),
    );
    await list();
    if (action === "apply") {
      emit("applied");
      notice.value = t("Konfiguracja aktywna. Strony zapisano jako szkice.");
    }
  });
}
function retryBrief() {
  const project = current.value;
  if (!project || project.state !== "failed") return;
  selectProject("");
  brief.value = project.brief ?? "";
  materials.value = project.materials ?? "";
  budget.value = project.budget_cents;
  provider.value = props.providers[project.provider]?.portal_configured ? project.provider : "reference";
}
function money(cents: number) {
  return new Intl.NumberFormat(locale.value, { style: "currency", currency: "USD" }).format(cents / 100);
}
function display(value: unknown) {
  return value == null ? "—" : typeof value === "object" ? JSON.stringify(value, null, 2) : String(value);
}
function togglePackages() {
  packagesOpen.value = !packagesOpen.value;
}
onMounted(() => perform(list));
onBeforeUnmount(() => {
  active = false;
  clearTimeout(poll);
  emit("dirty", false);
});
</script>
<template>
  <section class="space-y-5 max-w-4xl">
    <div class="flex flex-wrap items-end gap-3">
      <label class="grow"
        ><span class="field-label">{{ t("Projekt portalu") }}</span>
        <select
          class="select w-full"
          :value="current?.id ?? ''"
          :disabled="busy"
          @change="
            selectProject(($event.target as HTMLSelectElement).value);
            ($event.target as HTMLSelectElement).value = String(current?.id ?? '');
          "
        >
          <option value="">{{ t("Nowy projekt") }}</option>
          <option v-for="project in projects" :key="project.id" :value="project.id">
            #{{ project.id }} · {{ project.name || t(stateLabels[project.state]) }}
          </option>
        </select>
      </label>
      <button class="btn" type="button" :aria-expanded="packagesOpen" @click="togglePackages">
        {{ t("Pakiety wdrożeniowe") }}
      </button>
    </div>
    <AgencyPackages
      v-if="packagesOpen"
      :request="request"
      :project-id="current?.state === 'applied' ? current.id : null"
      @applied="emit('applied')"
    />
    <p v-if="error" class="alert alert-error" role="alert">{{ error }}</p>
    <p v-if="notice" class="alert alert-success" role="status">{{ notice }}</p>
    <form
      v-if="!current"
      class="space-y-4 border border-base-300 rounded-box bg-base-100 p-5"
      @submit.prevent="create"
    >
      <label class="block"
        ><span class="field-label">{{ t("Brief klienta") }}</span>
        <textarea
          v-model="brief"
          class="textarea w-full"
          maxlength="8000"
          required
          rows="4"
          :placeholder="t('Jakie usługi oferuje firma i jak obsługuje zapytania?')"
        />
      </label>
      <label class="block"
        ><span class="field-label">{{ t("Zatwierdzone materiały firmy") }}</span
        ><textarea v-model="materials" maxlength="12000" class="textarea w-full" rows="3" />
      </label>
      <div class="grid gap-4 sm:grid-cols-2">
        <label
          ><span class="field-label">{{ t("Sposób przygotowania") }}</span
          ><select class="select w-full" v-model="provider">
            <option value="reference">{{ t("Edytowalny wzorzec B2B") }}</option>
            <option
              v-for="key in ['openai', 'gemini']"
              :key="key"
              :value="key"
              :disabled="!providers?.[key]?.portal_configured"
            >
              {{ key === "openai" ? "OpenAI" : "Gemini"
              }}{{ providers?.[key]?.portal_configured ? "" : " · " + t("Brak konfiguracji") }}
            </option>
          </select></label
        >
        <label v-if="provider !== 'reference'"
          ><span class="field-label">{{ t("Limit projektu w centach USD") }}</span>
          <input
            v-model.number="budget"
            type="number"
            class="input w-full"
            min="0"
            max="1000000"
            step="1"
            required
        /></label>
      </div>
      <p v-if="!Object.values(providers).some((config) => config.portal_configured)" class="text-sm">
        {{ t("Aby włączyć AI, skonfiguruj na serwerze model, klucz, limit tokenów i stawki za tokeny.") }}
      </p>
      <button type="submit" class="btn btn-primary" :disabled="busy || !brief.trim()">
        {{ t("Przygotuj propozycję") }}
      </button>
    </form>
    <template v-else>
      <details v-if="current.brief || current.materials" class="rounded-box border border-base-300 p-4">
        <summary class="cursor-pointer font-medium">{{ t("Brief i zatwierdzone materiały") }}</summary>
        <p class="mt-2 whitespace-pre-line">{{ current.brief }}</p>
        <p v-if="current.materials" class="mt-2 whitespace-pre-line">{{ current.materials }}</p>
      </details>
      <p class="text-sm" role="status">
        {{ t(stateLabels[current.state]) }} · {{ current.provider
        }}<template v-if="current.cost_cents != null">
          · {{ t("Szacowany koszt projektu") }} {{ money(current.cost_cents) }}</template
        >
      </p>
      <p v-if="current.error" role="alert" class="alert alert-error">{{ current.error }}</p>
      <button v-if="current.state === 'failed'" class="btn" @click="retryBrief">
        {{ t("Użyj ponownie tego briefu") }}
      </button>
      <button
        v-if="queued || current.state === 'review'"
        type="button"
        class="btn btn-sm"
        :disabled="busy"
        @click="discard() && reload()"
      >
        {{ t("Wczytaj zapisaną wersję") }}
      </button>
      <template v-if="current.state === 'review' && edited">
        <form class="space-y-4 border border-base-300 rounded-box bg-base-100 p-5" @submit.prevent="save">
          <PortalPlanEditor v-model="edited" />
          <div class="flex flex-wrap gap-2">
            <button type="submit" class="btn" :disabled="busy || !modified">
              {{ t("Zapisz poprawki") }}
            </button>
            <button type="button" class="btn" :aria-expanded="preview" @click="preview = !preview">
              {{ t("Podgląd propozycji") }}
            </button>
          </div>
        </form>
        <article v-if="preview" class="space-y-4 bg-base-100 border border-base-300 rounded-box p-5">
          <h2 class="text-xl">{{ edited.name }}</h2>
          <section v-for="(page, index) in edited.pages" :key="index" class="space-y-2">
            <h3 class="font-semibold">{{ page.title }}</h3>
            <p class="whitespace-pre-line">{{ page.body }}</p>
            <span>{{ page.cta }} → /portal</span>
            <a
              v-if="!modified"
              class="link block"
              :href="base + '/api/v1/portal-projects/' + current.id + '/preview/' + index"
              target="_blank"
              rel="noopener"
              >{{ t("Podgląd w nowej karcie") }}</a
            >
          </section>
          <p>
            <strong>{{ t("Formularz zapytania") }}</strong> ·
            {{ [t("Temat"), t("Opis"), ...edited.fields.map((f) => f.label)].join(" · ") }}
          </p>
          <p>
            <strong>{{ t("Przebieg obsługi") }}</strong> · {{ edited.states.join(" → ") }}
          </p>
        </article>
        <section class="space-y-3 border border-base-300 rounded-box p-5">
          <h2 class="text-lg">{{ t("Zmiany po aktywacji") }}</h2>
          <p v-if="modified">{{ t("Zapisz poprawki, aby porównać je z instalacją.") }}</p>
          <template v-else-if="changes">
            <p v-if="changes.stale" role="alert">
              {{ t("Konfiguracja instalacji uległa zmianie.") }}
              <button class="btn btn-sm" :disabled="busy" @click="act('refresh')">
                {{ t("Porównaj ponownie") }}
              </button>
            </p>
            <details v-for="change in changes.changes" :key="change.label">
              <summary class="cursor-pointer font-medium">{{ change.label }}</summary>
              <div class="grid gap-2 sm:grid-cols-2">
                <div v-for="(value, key) in { przed: change.before, po: change.after }" :key="key">
                  <strong>{{ key === "przed" ? t("Obecnie") : t("Po aktywacji") }}</strong>
                  <pre class="whitespace-pre-wrap wrap-anywhere text-xs">{{ display(value) }}</pre>
                </div>
              </div>
            </details>
            <button class="btn btn-primary" :disabled="busy || changes.stale" @click="act('apply')">
              {{ t("Aktywuj formularz i zapisz szkice stron") }}
            </button>
          </template>
        </section>
      </template>
      <section v-if="current.state === 'applied'" class="space-y-4">
        <h2 class="text-lg">{{ t("Strony projektu") }}</h2>
        <div v-for="page in current.pages" :key="page.id" class="flex flex-wrap items-center gap-3">
          <strong>{{ page.title }}</strong>
          <a
            class="link"
            :href="base + '/api/v1/records/' + page.id + '/preview'"
            target="_blank"
            rel="noopener"
            >{{ t("Podgląd") }}</a
          >
          <a class="link" :href="base + '?record=' + page.id">{{ t("Otwórz w edytorze") }}</a>
        </div>
        <PortalChecks :key="current.id" :request="request" :project-id="current.id" />
      </section>
    </template>
  </section>
</template>
