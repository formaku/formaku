<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { ArrowDown, ArrowUp, Plus, Trash2 } from "lucide-vue-next";

type Link = { label: string; url: string };
type Navigation = {
  version: number;
  header: Link[];
  footer: Link[];
  footerText: string;
};
type EditableLink = Link & { key: number };
type EditableNavigation = Omit<Navigation, "header" | "footer"> & {
  header: EditableLink[];
  footer: EditableLink[];
};

const props = defineProps<{
  request: (path: string, method?: string, data?: unknown) => Promise<Navigation>;
}>();
const emit = defineEmits<{ dirty: [value: boolean] }>();
const maxLinks = 12;
const linkGroups = [
  { key: "header", label: "Menu nagłówka" },
  { key: "footer", label: "Linki stopki" },
] as const;
const settings = ref<EditableNavigation | null>(null);
const saved = ref("");
const busy = ref(false);
const conflict = ref(false);
const error = ref("");
const message = ref("");
let nextKey = 0;

function payload(source: EditableNavigation): Navigation {
  const serializeLinks = (links: EditableLink[]) => links.map(({ label, url }) => ({ label, url }));
  return {
    version: source.version,
    header: serializeLinks(source.header),
    footer: serializeLinks(source.footer),
    footerText: source.footerText,
  };
}

const dirty = computed(() => settings.value !== null && JSON.stringify(payload(settings.value)) !== saved.value);
watch(dirty, (value) => emit("dirty", value));

function applySettings(result: Navigation) {
  const editableLinks = (links: Link[]) => links.map((link) => ({ ...link, key: nextKey++ }));
  const current = {
    ...result,
    header: editableLinks(result.header),
    footer: editableLinks(result.footer),
  };
  saved.value = JSON.stringify(payload(current));
  settings.value = current;
  conflict.value = false;
}

async function run(action: () => Promise<void>) {
  busy.value = true;
  error.value = "";
  message.value = "";
  try {
    await action();
  } catch (failure) {
    if (failure instanceof Error && "status" in failure && failure.status === 409) {
      conflict.value = true;
      error.value = "Menu i stopka zostały zapisane w innej sesji. Twoje zmiany są nadal widoczne w formularzu.";
    } else {
      error.value = failure instanceof Error ? failure.message : "Operacja nie powiodła się.";
    }
  } finally {
    busy.value = false;
  }
}

function load() {
  return run(async () => applySettings(await props.request("/site-navigation")));
}

function reload() {
  if (dirty.value && !window.confirm("Odrzucić niezapisane zmiany i wczytać zapisaną wersję?")) return;
  return load();
}

function save() {
  const current = settings.value;
  if (!current || !dirty.value || busy.value || conflict.value) return;

  return run(async () => {
    applySettings(await props.request("/site-navigation", "PUT", payload(current)));
    message.value = "Zapisano menu i stopkę.";
  });
}

function move(links: EditableLink[], index: number, delta: number) {
  const target = index + delta;
  [links[index], links[target]] = [links[target], links[index]];
}

onMounted(load);
</script>

<template>
  <section aria-label="Menu i stopka" class="card card-border bg-base-100">
    <div class="card-body gap-5">
      <div>
        <h2 class="card-title">Menu i stopka</h2>
        <p class="mt-2 text-sm text-base-content/70">
          Zapisane zmiany są od razu widoczne na publicznych stronach.
        </p>
      </div>

      <p v-if="error" role="alert" class="alert alert-error">{{ error }}</p>
      <button v-if="conflict" type="button" class="btn self-start" :disabled="busy" @click="reload">
        Wczytaj zapisaną wersję
      </button>
      <p v-if="message" role="status">{{ message }}</p>
      <p v-if="busy && !settings" role="status">Wczytywanie menu i stopki…</p>
      <button v-if="error && !settings" type="button" class="btn self-start" :disabled="busy" @click="load">
        Spróbuj ponownie
      </button>

      <form v-if="settings" class="space-y-5" @submit.prevent="save">
        <p class="text-sm text-base-content/70">Użyj ścieżki, np. /p/kontakt, albo pełnego adresu HTTP lub HTTPS.</p>
        <fieldset
          v-for="group in linkGroups"
          :key="group.key"
          class="fieldset rounded-box border border-base-300 p-4"
          :disabled="busy"
        >
          <legend class="fieldset-legend">{{ group.label }}</legend>
          <ol v-if="settings[group.key].length" class="space-y-3">
            <li
              v-for="(link, index) in settings[group.key]"
              :key="link.key"
              class="space-y-2 border-b border-base-300 pb-3"
            >
              <div class="grid gap-3 sm:grid-cols-2">
                <label class="block min-w-0">
                  <span class="field-label">Etykieta linku {{ index + 1 }}</span>
                  <input v-model.trim="link.label" class="input w-full" maxlength="100" required />
                </label>
                <label class="block min-w-0">
                  <span class="field-label">Adres linku {{ index + 1 }}</span>
                  <input v-model.trim="link.url" class="input w-full" maxlength="2048" required />
                </label>
              </div>
              <div class="flex flex-wrap gap-2">
                <button
                  type="button"
                  class="btn btn-sm btn-square btn-ghost"
                  :aria-label="`Przesuń link ${index + 1} w górę`"
                  :disabled="index === 0"
                  @click="move(settings[group.key], index, -1)"
                >
                  <ArrowUp :size="16" aria-hidden="true" />
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-square btn-ghost"
                  :aria-label="`Przesuń link ${index + 1} w dół`"
                  :disabled="index === settings[group.key].length - 1"
                  @click="move(settings[group.key], index, 1)"
                >
                  <ArrowDown :size="16" aria-hidden="true" />
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-square btn-ghost"
                  :aria-label="`Usuń link ${index + 1}`"
                  @click="settings[group.key].splice(index, 1)"
                >
                  <Trash2 :size="16" aria-hidden="true" />
                </button>
              </div>
            </li>
          </ol>
          <p v-else class="text-sm text-base-content/70">Brak linków.</p>
          <button
            type="button"
            class="btn btn-sm mt-2 w-fit"
            :disabled="settings[group.key].length >= maxLinks"
            @click="settings[group.key].push({ key: nextKey++, label: '', url: '' })"
          >
            <Plus :size="16" aria-hidden="true" />Dodaj link
          </button>
        </fieldset>

        <label class="block">
          <span class="field-label">Tekst stopki</span>
          <textarea v-model="settings.footerText" class="textarea w-full" maxlength="500" :disabled="busy" />
        </label>
        <button type="submit" class="btn btn-primary" :disabled="busy || !dirty || conflict">
          Zapisz menu i stopkę
        </button>
      </form>
    </div>
  </section>
</template>
