<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { t } from "./locale";

type TranslationLink = { locale: string | null; group: string | null; version: number };
const props = defineProps<{
  recordId: number;
  request: (path: string, method?: string, data?: TranslationLink) => Promise<TranslationLink>;
}>();
const emit = defineEmits<{ dirty: [value: boolean] }>();
const errors = {
  load: "Nie udało się wczytać powiązania językowego.",
  save: "Nie udało się zapisać powiązania językowego.",
  conflict: "Powiązanie językowe zmieniło się w innej sesji. Wczytaj zapisaną wersję.",
  pair: "Wpisz oba pola albo wyczyść je, aby usunąć powiązanie.",
  locale: "Wpisz poprawny kod języka, np. pl lub en-GB.",
  group: "Klucz grupy może zawierać litery, cyfry i pojedyncze myślniki.",
  invalid: "Sprawdź kod języka i klucz grupy. W grupie może być tylko jedna strona w danym języku.",
} as const;

const saved = ref<TranslationLink | null>(null);
const locale = ref("");
const group = ref("");
const busy = ref(false);
const unavailable = ref(false);
const error = ref<keyof typeof errors | null>(null);
const savedMessage = ref(false);
const normalizedLocale = computed(() => locale.value.trim() || null);
const normalizedGroup = computed(() => group.value.trim().toLowerCase() || null);
const dirty = computed(() => !unavailable.value && !!saved.value &&
  (normalizedLocale.value !== saved.value.locale || normalizedGroup.value !== saved.value.group));
watch(dirty, (value) => emit("dirty", value));

let active = true;
onBeforeUnmount(() => {
  active = false;
});

function apply(link: TranslationLink) {
  saved.value = link;
  locale.value = link.locale ?? "";
  group.value = link.group ?? "";
}

async function run(operation: () => Promise<TranslationLink>, saving = false) {
  busy.value = true;
  error.value = null;
  savedMessage.value = false;
  try {
    const link = await operation();
    if (!active) return;
    apply(link);
    savedMessage.value = saving;
  } catch (failure) {
    if (!active) return;
    const status = failure instanceof Error && "status" in failure ? failure.status : null;
    if (status === 403 || status === 404) unavailable.value = true;
    else if (saving && status === 409) error.value = "conflict";
    else if (saving && status === 422) error.value = "invalid";
    else error.value = saving ? "save" : "load";
  } finally {
    if (active) busy.value = false;
  }
}

function load() {
  if (busy.value) return;
  if (dirty.value && !window.confirm(t("Odrzucić niezapisane zmiany i wczytać zapisaną wersję?"))) return;
  return run(() => props.request(`/records/${props.recordId}/translations`));
}

function save() {
  if (!saved.value || !dirty.value || busy.value || error.value === "conflict") return;
  const locale = normalizedLocale.value;
  const group = normalizedGroup.value;
  savedMessage.value = false;
  if ((locale === null) !== (group === null)) {
    error.value = "pair";
    return;
  }
  if (locale && !/^[a-z]{2,3}(?:-[a-z]{4})?(?:-(?:[a-z]{2}|[0-9]{3}))?$/i.test(locale)) {
    error.value = "locale";
    return;
  }
  if (group && (group.length > 100 || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(group))) {
    error.value = "group";
    return;
  }
  const payload = { locale, group, version: saved.value.version };
  return run(() => props.request(`/records/${props.recordId}/translations`, "PUT", payload), true);
}

onMounted(load);
</script>

<template>
  <section v-if="!unavailable && (saved || error)" :aria-label='t("Wersje językowe")' class="card card-border mb-6 bg-base-100">
    <div class="card-body gap-4">
      <h2 class="card-title">{{ t("Wersje językowe") }}</h2>
      <p v-if="error" role="alert" class="alert alert-error">{{ t(errors[error]) }}</p>
      <button v-if="error === 'conflict'" type="button" class="btn self-start" :disabled="busy" @click="load">
        {{ t("Wczytaj zapisaną wersję") }}
      </button>
      <button v-else-if="!saved && error" type="button" class="btn self-start" :disabled="busy" @click="load">
        {{ t("Spróbuj ponownie") }}
      </button>
      <p v-if="savedMessage" role="status">{{ t("Zapisano powiązanie językowe.") }}</p>

      <form v-if="saved" class="space-y-4" @submit.prevent="save">
        <p class="text-sm text-base-content/70">
          {{ t("Użyj tego samego klucza dla wszystkich wersji językowych strony. Wyczyść oba pola, aby usunąć powiązanie.") }}
        </p>
        <div class="grid gap-3 sm:grid-cols-2">
          <label>
            <span class="field-label">{{ t("Język strony") }}</span>
            <input
              v-model="locale"
              class="input w-full"
              maxlength="12"
              placeholder="en-GB"
              autocapitalize="off"
              spellcheck="false"
              :disabled="busy"
            />
          </label>
          <label>
            <span class="field-label">{{ t("Klucz grupy tłumaczeń") }}</span>
            <input
              v-model="group"
              class="input w-full"
              maxlength="100"
              autocapitalize="off"
              spellcheck="false"
              :disabled="busy"
            />
          </label>
        </div>
        <button type="submit" class="btn" :disabled="busy || !dirty || error === 'conflict'">
          {{ t("Zapisz powiązanie") }}
        </button>
      </form>
    </div>
  </section>
</template>
