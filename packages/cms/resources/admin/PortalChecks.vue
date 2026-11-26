<script setup lang="ts">
import { ref } from "vue";
import { t } from "./locale";
import type { AgencyRequest, ProjectId } from "./agency";
const props = defineProps<{ request: AgencyRequest; projectId?: ProjectId }>();
type Report = {
  passed: boolean;
  static_passed: boolean;
  checks: {
    key: string;
    label: string;
    detail: string;
    status: "pass" | "fail" | "warning";
    path?: string;
  }[];
  browser?: {
    status: "not_run" | "queued" | "running" | "passed" | "failed" | "stale";
    available: boolean;
    detail?: string;
    checked_at?: string;
    command?: string;
  };
};
const labels = { pass: "Zaliczono", fail: "Niezaliczono", warning: "Do sprawdzenia" };
const browserLabels = {
  queued: "W kolejce",
  running: "Test w toku",
  not_run: "Brak wyniku testu przeglądarkowego",
  passed: "Test przeglądarkowy zaliczony",
  failed: "Test przeglądarkowy nieudany",
  stale: "Wynik pochodzi ze starszej konfiguracji",
};
const report = ref<Report | null>(null),
  error = ref(""),
  busy = ref(false);
async function check(launch: boolean = false) {
  if (busy.value) return;
  busy.value = true;
  error.value = "";
  report.value = null;
  try {
    const scope = props.projectId === undefined ? { installed: true } : { project_id: props.projectId };
    if (launch) await props.request("/agency/check", "POST", scope);
    report.value = await props.request(
      "/agency/check?" +
        (props.projectId === undefined ? "installed=1" : "project_id=" + encodeURIComponent(props.projectId)),
    );
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : t("Operacja nie powiodła się");
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <section class="space-y-4 rounded-box border border-base-300 bg-base-100 p-5">
    <button class="btn" :disabled="busy" @click="check()">{{ t("Sprawdź portal") }}</button>
    <p v-if="error" role="alert" class="alert alert-error">{{ error }}</p>
    <template v-if="report">
      <h3 class="font-semibold">{{ t("Odbiór konfiguracji") }}</h3>
      <ul class="space-y-2">
        <li v-for="check in report.checks" :key="check.key">
          <strong>{{ t(labels[check.status]) }} · {{ check.label }}</strong>
          <p>{{ check.detail }}</p>
          <a
            v-if="check.path?.startsWith('/') && !check.path.startsWith('//')"
            :href="check.path"
            class="link"
            >{{ t("Otwórz ekran") }}</a
          >
        </li>
      </ul>
      <div v-if="report.browser" class="border-t border-base-300 pt-3">
        <strong>{{ t(browserLabels[report.browser.status]) }}</strong>
        <p v-if="report.browser.detail">{{ report.browser.detail }}</p>
        <button
          v-if="report.browser.available"
          class="btn btn-sm"
          :disabled="busy || !report.static_passed || ['queued', 'running'].includes(report.browser.status)"
          @click="check(true)"
        >
          {{ t("Uruchom test przeglądarkowy") }}
        </button>
        <time v-if="report.browser.checked_at" class="block">{{ report.browser.checked_at }}</time>
        <p v-if="report.browser.command">{{ t("Polecenie dla środowiska odbiorowego") }}</p>
        <code v-if="report.browser.command" class="break-all">{{ report.browser.command }}</code>
      </div>
    </template>
  </section>
</template>
