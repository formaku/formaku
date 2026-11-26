<script lang="ts">
export type ActionField = { key: string; label: string; type: "text" | "integer" | "boolean"; required?: boolean };
export type ActionInput = Record<string, string | number | boolean>;

export function emptyActionValues(fields: ActionField[]): ActionInput {
  return Object.fromEntries(fields.map((field) => [field.key, ""]));
}

// Leaves out empty values. Returns null when a required value is missing or an integer field holds another number.
export function actionInput(fields: ActionField[], values: ActionInput): ActionInput | null {
  const input: ActionInput = Object.fromEntries(
    fields.flatMap((field) => {
      const value = values[field.key];
      return value === undefined || (typeof value === "string" && value.trim() === "") ? [] : [[field.key, value]];
    }),
  );
  const invalid = fields.some((field) => {
    const value = Object.hasOwn(input, field.key) ? input[field.key] : undefined;
    return (field.required && value === undefined) ||
      (field.type === "integer" && value !== undefined && !Number.isSafeInteger(value));
  });
  return invalid ? null : input;
}
</script>

<script setup lang="ts">
import { t } from "./locale";

defineProps<{ fields: ActionField[]; disabled: boolean; autofocus?: boolean }>();
// The inputs write into this object. The parent reads it when the form is submitted.
const values = defineModel<ActionInput>({ required: true });
</script>

<template>
  <label v-for="(field, index) in fields" :key="field.key" class="block">
    <span class="field-label">{{ field.label }}</span>
    <select
      v-if="field.type === 'boolean'"
      v-model="values[field.key]"
      class="select w-full max-w-md"
      :required="field.required"
      :disabled="disabled"
      :autofocus="autofocus && index === 0"
    >
      <option value="">{{ t("Wybierz wartość") }}</option>
      <option :value="true">{{ t("Tak") }}</option>
      <option :value="false">{{ t("Nie") }}</option>
    </select>
    <input
      v-else
      v-model="values[field.key]"
      class="input w-full max-w-md"
      :type="field.type === 'integer' ? 'number' : 'text'"
      :step="field.type === 'integer' ? 1 : undefined"
      :maxlength="field.type === 'text' ? 2000 : undefined"
      :required="field.required"
      :disabled="disabled"
      :autofocus="autofocus && index === 0"
    />
  </label>
</template>
