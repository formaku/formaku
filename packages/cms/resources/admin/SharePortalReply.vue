<script setup lang="ts">
import { ref, watch } from "vue";
import { locale } from "./locale";

const props = defineProps<{
  recordId: number;
  version: number;
  disabled: boolean;
  request: (path: string, method: string, data: { version: number }) => Promise<{ version: number }>;
}>();
const emit = defineEmits<{ shared: [version: number] }>();
const state = ref<"idle" | "busy" | "done" | "failed">("idle");
watch(
  () => [props.recordId, props.version],
  () => {
    state.value = "idle";
  },
);

async function share() {
  if (props.disabled || state.value === "busy") return;
  const id = props.recordId;
  const version = props.version;
  state.value = "busy";
  try {
    const result = await props.request(`/records/${id}/share-reply`, "POST", { version });
    if (id === props.recordId && props.version === version) {
      emit("shared", result.version);
      state.value = "done";
    }
  } catch {
    if (id === props.recordId) state.value = "failed";
  }
}
</script>

<template>
  <div class="mt-4">
    <button type="button" class="btn" :disabled="disabled || state === 'busy'" @click="share">
      {{ locale === "pl" ? "Udostępnij zapisaną odpowiedź klientowi" : "Share saved reply with customer" }}
    </button>
    <p v-if="state === 'done'" role="status">
      {{
        locale === "pl"
          ? "Klient widzi zapisaną odpowiedź w portalu."
          : "The customer can see the saved reply in their portal."
      }}
    </p>
    <p v-if="state === 'failed'" role="alert">
      {{
        locale === "pl"
          ? "Nie udało się udostępnić odpowiedzi. Sprawdź aktualną wersję zgłoszenia."
          : "The reply could not be shared. Check the current request version."
      }}
    </p>
  </div>
</template>
