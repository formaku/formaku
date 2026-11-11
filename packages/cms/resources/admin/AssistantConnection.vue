<script setup lang="ts">
import { locale, t } from "./locale";
import { computed, onMounted, ref } from "vue";

type Connection = {
  collection_id: number;
  can_publish: boolean;
  status: "pending" | "active" | "expired";
  expires_at: string | null;
};
type Settings = {
  collections: { id: number; name: string }[];
  can_publish: boolean;
  connection: Connection | null;
};
const props = defineProps<{
  base: string;
  request: (path: string, method?: string, data?: unknown) => Promise<any>;
}>();
const settings = ref<Settings | null>(null);
const collectionId = ref<number | null>(null);
const canPublish = ref(false);
const code = ref("");
const busy = ref(false);
const error = ref("");
const message = ref("");
const connection = computed(() => settings.value?.connection);
const collectionName = computed(() =>
  settings.value?.collections.find((item) => item.id === connection.value?.collection_id)?.name,
);
const apiUrl = new URL(props.base + "/assistant/v1", window.location.origin);
const quotedUrl = "'" + apiUrl.href.replaceAll("'", "'\\''") + "'";
const command = "node formaku-assistant.mjs setup " + quotedUrl;

async function run(action: () => Promise<void>) {
  busy.value = true;
  error.value = "";
  message.value = "";
  try {
    await action();
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : t("Operacja nie powiodła się.");
  } finally {
    busy.value = false;
  }
}

async function refresh() {
  settings.value = await props.request("/assistant");
  const choices = settings.value?.collections ?? [];
  if (!choices.some((item) => item.id === collectionId.value)) {
    collectionId.value = choices.find((item) => item.id === connection.value?.collection_id)?.id
      ?? choices[0]?.id ?? null;
  }
  if (connection.value?.status !== "pending") code.value = "";
}

function pair() {
  return run(async () => {
    const response = await props.request("/assistant/pairing", "POST", {
      collection_id: collectionId.value,
      can_publish: canPublish.value,
    });
    code.value = response.code;
    if (settings.value && collectionId.value !== null) {
      settings.value.connection = {
        collection_id: collectionId.value,
        can_publish: canPublish.value,
        status: "pending",
        expires_at: response.expires_at,
      };
    }
  });
}

function revoke() {
  return run(async () => {
    await props.request("/assistant", "DELETE");
    code.value = "";
    if (settings.value) settings.value.connection = null;
    message.value = t("Dostęp asystenta został cofnięty.");
  });
}

async function copy(value: string) {
  try {
    await navigator.clipboard.writeText(value);
    error.value = "";
    message.value = t("Skopiowano.");
  } catch {
    message.value = "";
    error.value = t("Skopiuj tekst ręcznie.");
  }
}

onMounted(() => run(refresh));
</script>

<template>
  <section class="card card-border bg-base-100 max-w-3xl">
    <div class="card-body gap-5">
      <div class="space-y-2">
        <h2 class="card-title">{{ t("Połącz własnego asystenta") }}</h2>
        <p>
          {{ t("Po sparowaniu zlecisz tworzenie i edycję stron w rozmowie z Codexem.\n          Asystent uzyska dostęp do szkiców w wybranej publicznej kolekcji.") }}
        </p>
      </div>

      <p v-if="error" class="alert alert-error" role="alert">{{ error }}</p>
      <p v-if="message" role="status">{{ message }}</p>
      <p v-if="busy && !settings" role="status">{{ t("Sprawdzanie połączenia…") }}</p>

      <div v-if="connection" class="rounded-box border border-base-300 p-4 space-y-3">
        <div class="sm:flex sm:items-center sm:gap-3">
          <span class="badge badge-outline">{{
            { pending: t("Oczekuje na sparowanie"), active: t("Połączono"), expired: t("Dostęp wygasł") }[connection.status]
          }}</span>
          <span>{{ collectionName ?? t("Kolekcja niedostępna") }} · {{ connection.can_publish ? t("Edycja i publikacja") : t("Szkice") }}</span>
        </div>
        <p v-if="connection.status === 'active' && collectionName" class="text-sm">
          {{ t("W Codexie możesz teraz napisać „Utwórz szkic strony Kontakt”.") }}
        </p>
        <p v-if="connection.expires_at" class="text-sm text-base-content/70">
          {{ t("Ważność do") }} {{ new Date(connection.expires_at).toLocaleString(locale) }}
        </p>
        <div class="sm:flex sm:gap-2 space-y-2 sm:space-y-0">
          <button class="btn btn-sm" :disabled="busy" @click="run(refresh)">{{ t("Odśwież stan") }}</button>
          <button class="btn btn-sm btn-error btn-outline" :disabled="busy" @click="revoke">{{ t("Cofnij dostęp") }}</button>
        </div>
      </div>

      <ol v-if="settings?.collections.length" class="list-decimal ps-5 space-y-5">
        <li class="space-y-2">
          <p>{{ t("Pobierz adapter i otwórz terminal w folderze pobranego pliku. Adapter wymaga Node.js 20 lub nowszego.") }}</p>
          <a class="btn btn-sm" :href="base + '/api/v1/assistant/adapter'" download>{{ t("Pobierz adapter MCP") }}</a>
        </li>
        <li>
          <form class="space-y-3" @submit.prevent="pair">
            <fieldset class="fieldset">
              <legend class="fieldset-legend">{{ t("Kolekcja dostępna dla asystenta") }}</legend>
              <select v-model="collectionId" class="select w-full sm:w-auto" :aria-label='t("Kolekcja dostępna dla asystenta")' required>
                <option v-for="collection in settings.collections" :key="collection.id" :value="collection.id">
                  {{ collection.name }}
                </option>
              </select>
            </fieldset>
            <label v-if="settings.can_publish" class="label text-wrap text-base-content">
              <input v-model="canPublish" type="checkbox" class="checkbox checkbox-sm" />
              {{ t("Zezwól asystentowi na publikację na moje polecenie") }}
            </label>
            <p class="text-sm text-base-content/70">
              {{ t("Kod jest jednorazowy i ważny przez 5 minut. Dostęp trwa 30 dni.") }}
              {{ connection ? t("Nowy kod od razu zastąpi obecne połączenie.") : "" }}
            </p>
            <button class="btn btn-primary" :disabled="busy || collectionId === null">{{ t("Wygeneruj kod parowania") }}</button>
          </form>
        </li>
        <li class="space-y-3">
          <p>{{ t("Uruchom polecenie, wklej kod w terminalu i ponownie otwórz Codex.") }}</p>
          <div class="sm:flex sm:gap-2 space-y-2 sm:space-y-0">
            <input class="input font-mono w-full" :aria-label='t("Polecenie parowania")' readonly :value="command" />
            <button class="btn" @click="copy(command)">{{ t("Kopiuj polecenie") }}</button>
          </div>
          <div v-if="code" class="sm:flex sm:gap-2 space-y-2 sm:space-y-0">
            <input class="input font-mono w-full" :aria-label='t("Kod parowania")' autocomplete="off" readonly :value="code" />
            <button class="btn" @click="copy(code)">{{ t("Kopiuj kod") }}</button>
          </div>
          <p v-if="code" class="text-sm text-base-content/70">{{ t("Wklej kod tylko w terminalu.") }}</p>
        </li>
      </ol>
      <p v-else-if="settings">
        {{ t("Dodaj publiczną kolekcję treści bez pól prywatnych i relacji, aby sparować asystenta.") }}
      </p>
      <button v-else-if="error" class="btn self-start" @click="run(refresh)">{{ t("Spróbuj ponownie") }}</button>
    </div>
  </section>
</template>
