<script lang="ts">
import { t } from "./locale";
import { messages } from "./messages.en";

export type RoleOption = { key: string; label: string };

const reservedRoleKeys = new Set(["__proto__", "constructor", "prototype", "__isVue"]);

export function isRoleKey(key: string): boolean {
  return key.length > 0 && key.trim() === key && !reservedRoleKeys.has(key) && !key.startsWith("__v_");
}

export function roleDisplayName(key: string, label?: string): string {
  if (!label || label === key) return key;
  const displayLabel = Object.hasOwn(messages, label) ? t(label) : label;
  return `${displayLabel} (${key})`;
}
</script>

<script setup lang="ts">
import { computed, ref } from "vue";

const props = defineProps<{
  roles: RoleOption[];
  selected: string[];
  allowCustom?: boolean;
}>();
const emit = defineEmits<{ add: [key: string] }>();
const configured = ref("");
const custom = ref("");
const validOptions = computed(() => props.roles.filter(({ key }) => isRoleKey(key)));
const available = computed(() => validOptions.value.filter(({ key }) => !props.selected.includes(key)));
const canAddConfigured = computed(() => available.value.some(({ key }) => key === configured.value));
const customKey = computed(() => custom.value.trim());
const customError = computed(() => {
  if (!customKey.value) return "";
  if (!isRoleKey(customKey.value)) return t("Podaj prawidłowy klucz roli.");
  if (props.selected.includes(customKey.value)) return t("Ta rola została już dodana.");
  return "";
});

function addConfigured() {
  if (!canAddConfigured.value) return;
  emit("add", configured.value);
  configured.value = "";
}

function addCustom() {
  if (!customKey.value || customError.value) return;
  emit("add", customKey.value);
  custom.value = "";
}
</script>

<template>
  <div class="flex flex-wrap gap-4">
    <div v-if="validOptions.length">
      <div class="flex flex-wrap items-end gap-2">
        <label class="block">
          <span class="field-label">{{ t("Rola z konfiguracji") }}</span>
          <select v-model="configured" class="select select-sm">
            <option value="">{{ t("Wybierz rolę") }}</option>
            <option v-for="role in available" :key="role.key" :value="role.key">
              {{ roleDisplayName(role.key, role.label) }}
            </option>
          </select>
        </label>
        <button type="button" class="btn btn-sm" :disabled="!canAddConfigured" @click="addConfigured">
          {{ t("Dodaj rolę z konfiguracji") }}
        </button>
      </div>
    </div>
    <div v-if="allowCustom">
      <div class="flex flex-wrap items-end gap-2">
        <label class="block">
          <span class="field-label">{{ t("Własny klucz roli") }}</span>
          <input
            v-model="custom"
            class="input input-sm"
            :aria-invalid="!!customError"
            @keydown.enter.prevent="addCustom"
          />
        </label>
        <button type="button" class="btn btn-sm" :disabled="!customKey || !!customError" @click="addCustom">
          {{ t("Dodaj własną rolę") }}
        </button>
      </div>
      <p v-if="customError" class="mt-1 text-sm text-error" role="status">{{ customError }}</p>
    </div>
  </div>
</template>
