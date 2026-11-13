<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { t } from "./locale";

type Check = { code: string; severity: "warning" | "error"; count: number };
type Report = { version: number; revision_id: number; checks: Check[] };
const props = defineProps<{
  recordId: number;
  version: number;
  dirty: boolean;
  request: (path: string) => Promise<Report>;
}>();

const labels: Record<string, string> = {
  seo_description: "Uzupełnij opis SEO.",
  image_alt: "Sprawdź opisy alternatywne obrazów.",
  link: "Uzupełnij adresy lub etykiety odnośników.",
  heading_empty: "Uzupełnij puste nagłówki.",
  heading_order: "Sprawdź kolejność poziomów nagłówków.",
  invalid_content: "Nie można wyrenderować treści szkicu.",
  translation_unpublished: "W tej grupie nie ma opublikowanej innej wersji językowej.",
};

const report = ref<Report | null>(null);
const failed = ref(false);
const loading = ref(false);
let requestId = 0;
watch(() => [props.recordId, props.version, props.dirty], () => {
  requestId++;
  report.value = null;
  failed.value = false;
  loading.value = false;
}, { flush: "sync" });

const visibleReport = computed(() => props.dirty ? null : report.value);

async function check() {
  const id = ++requestId;
  loading.value = true;
  failed.value = false;
  report.value = null;
  try {
    const result = await props.request(`/records/${props.recordId}/publication-check?version=${props.version}`);
    if (id === requestId && result.version === props.version) report.value = result;
  } catch {
    if (id === requestId) failed.value = true;
  } finally {
    if (id === requestId) loading.value = false;
  }
}
</script>

<template>
  <section class="card card-border mb-5 bg-base-100" :aria-label='t("Kontrola przed publikacją")'>
    <div class="card-body gap-3">
      <h2 class="card-title">{{ t("Kontrola przed publikacją") }}</h2>
      <p v-if="dirty">{{ t("Zapisz zmiany, aby sprawdzić szkic.") }}</p>
      <button type="button" class="btn btn-outline self-start" :disabled="dirty || loading" @click="check">
        {{ t("Sprawdź zapisany szkic") }}
      </button>
      <div aria-live="polite">
        <p v-if="loading">{{ t("Sprawdzanie szkicu…") }}</p>
        <p v-else-if="failed" role="alert">{{ t("Nie udało się sprawdzić szkicu. Wczytaj bieżącą wersję i spróbuj ponownie.") }}</p>
        <template v-else-if="visibleReport">
          <p v-if="visibleReport.checks.length === 0">{{ t("Kontrola nie wykryła problemów.") }}</p>
          <ul v-else class="space-y-2">
            <li v-for="issue in visibleReport.checks" :key="issue.code"
              class="alert alert-soft" :class="issue.severity === 'error' ? 'alert-error' : 'alert-warning'">
              <span>
                <strong>{{ issue.severity === "error" ? t("Błąd") : t("Zalecenie") }}</strong>
                {{ t(labels[issue.code] ?? "Sprawdź szkic.") }}
                <span v-if="issue.count > 1">({{ issue.count }})</span>
              </span>
            </li>
          </ul>
        </template>
      </div>
    </div>
  </section>
</template>
