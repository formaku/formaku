<script setup lang="ts">
import { t } from "./locale";
import { computed, ref, watch } from "vue";
import { Copy, Move } from "lucide-vue-next";
import MediaPicker from "./MediaPicker.vue";
import RichText from "./RichText.vue";
import {
  blockEditorKey,
  blockLabel,
  createBlock,
  duplicateBlock,
  isBuiltInBlock,
  isLayoutBlock,
  maxBlocksPerLevel,
  maxLayoutDepth,
  type Block,
  type BlockCatalog,
  type BlockSetting,
  type BlockPath,
} from "./blocks";

const props = withDefaults(defineProps<{ block: Block; catalog: BlockCatalog; depth?: number; moveDisabled?: boolean }>(), {
  depth: 0,
});
const emit = defineEmits<{
  change: [];
  move: [path: BlockPath, trigger: EventTarget | null];
  remove: [path: BlockPath];
}>();
const childType = ref("text");
const metadata = computed(() => props.catalog.get(props.block.type));
const isLayout = computed(() => isLayoutBlock(props.block.type, props.catalog));
const childTypes = computed(() =>
  [...props.catalog.keys()].filter(
    (type) => props.depth < maxLayoutDepth || !isLayoutBlock(type, props.catalog),
  ),
);
watch(childTypes, (types) => {
  if (!types.includes(childType.value)) childType.value = types[0] ?? "";
}, { immediate: true });
const canAddChild = computed(
  () =>
    props.depth <= maxLayoutDepth &&
    (props.block.children == null ||
      (Array.isArray(props.block.children) && props.block.children.length < maxBlocksPerLevel)),
);

function blockSettings() {
  const settings = props.block.props;
  if (!settings || typeof settings !== "object" || Array.isArray(settings)) {
    return (props.block.props = {});
  }
  return settings;
}

function setBlockProp(key: string, value: unknown) {
  const settings = blockSettings();
  if (value === undefined) delete settings[key];
  else settings[key] = value;
  emit("change");
}

function updateBlockInput(key: string, event: Event) {
  const input = event.target as HTMLInputElement;
  const value =
    input.type === "number" && !Number.isNaN(input.valueAsNumber)
      ? input.valueAsNumber
      : input.value;
  setBlockProp(key, value);
}

function customSettingValue(key: string): unknown {
  const settings = props.block.props;
  return settings && Object.hasOwn(settings, key) ? settings[key] : undefined;
}

function setCustomSetting(key: string, value: unknown) {
  const settings = { ...props.block.props, [key]: value };
  if (value === undefined) delete settings[key];
  props.block.props = settings;
  emit("change");
}

function customTextValue(key: string): string | number {
  const value = customSettingValue(key);
  return typeof value === "string" || typeof value === "number" ? value : "";
}

function updateCustomNumber(key: string, event: Event) {
  const input = event.target as HTMLInputElement;
  if (input.validity.badInput) return;
  if (input.value === "") setCustomSetting(key, undefined);
  else if (Number.isFinite(input.valueAsNumber)) setCustomSetting(key, input.valueAsNumber);
}

function customChoice(field: BlockSetting): string {
  const value = customSettingValue(field.key);
  if (value === undefined) return "";
  return String((field.options ?? []).findIndex((option) => option.value === value));
}

function updateCustomChoice(field: BlockSetting, event: Event) {
  const value = (event.target as HTMLSelectElement).value;
  if (value === "") setCustomSetting(field.key, undefined);
  else {
    const option = field.options?.[Number(value)];
    if (option) setCustomSetting(field.key, option.value);
  }
}

function customBoolean(key: string): string {
  const value = customSettingValue(key);
  if (value === undefined) return "";
  return typeof value === "boolean" ? String(value) : "-1";
}

function updateCustomBoolean(key: string, event: Event) {
  const value = (event.target as HTMLSelectElement).value;
  if (value === "") setCustomSetting(key, undefined);
  else if (value === "true" || value === "false") setCustomSetting(key, value === "true");
}

function addChild() {
  if (!canAddChild.value || !childTypes.value.includes(childType.value)) return;
  const children = (props.block.children ??= []);
  children.push(createBlock(childType.value, props.catalog));
  emit("change");
}

function duplicateChild(index: number) {
  const children = props.block.children;
  if (canAddChild.value && children && duplicateBlock(children, index, props.catalog)) {
    emit("change");
  }
}

function moveChild(index: number, offset: number) {
  const children = props.block.children;
  const destination = index + offset;
  if (!children || destination < 0 || destination >= children.length) return;
  [children[index], children[destination]] = [
    children[destination],
    children[index],
  ];
  emit("change");
}

function addQuestion() {
  const settings = blockSettings();
  settings.items ??= [];
  settings.items.push({ question: "", answer: "" });
  emit("change");
}
</script>
<template>
  <div class="space-y-4" @input="emit('change')">
    <RichText
      v-if="block.type === 'text'"
      :model-value="block.props?.document"
      @update:model-value="setBlockProp('document', $event)"
    />
    <template v-else-if="block.type === 'columns' || block.type === 'container'">
      <label v-if="block.type === 'columns'">
        <span class="field-label">{{ t("Liczba kolumn") }}</span>
        <select
          class="select select-sm max-w-full"
          :value="
            block.props?.columns === 2 || block.props?.columns === 3
              ? block.props.columns
              : ''
          "
          @change="
            setBlockProp(
              'columns',
              Number(($event.target as HTMLSelectElement).value) || undefined,
            )
          "
        >
          <option value="">{{ t("Automatycznie") }}</option>
          <option value="2">{{ t("Dwie kolumny") }}</option>
          <option value="3">{{ t("Trzy kolumny") }}</option>
        </select>
      </label>
      <label v-else>
        <span class="field-label">{{ t("Układ kontenera") }}</span>
        <select
          class="select select-sm max-w-full"
          :value="block.props?.layout === 'stack' ? 'stack' : ''"
          @change="
            setBlockProp(
              'layout',
              ($event.target as HTMLSelectElement).value || undefined,
            )
          "
        >
          <option value="">{{ t("Automatycznie") }}</option>
          <option value="stack">{{ t("Pionowo") }}</option>
        </select>
      </label>
    </template>
    <template v-else-if="block.type === 'image'">
      <MediaPicker
        :selected-id="block.props?.media_id"
        @select="setBlockProp('media_id', $event)"
      />
      <label>
        <span class="field-label">{{ t("Podpis obrazu") }}</span>
        <input
          class="input w-full"
          :value="block.props?.caption"
          @input.stop="updateBlockInput('caption', $event)"
        />
      </label>
    </template>
    <template v-else-if="block.type === 'faq'"
      ><div
        v-for="(item, i) in block.props?.items ?? []"
        :key="i"
        class="space-y-2"
      >
        <label
          ><span class="field-label">{{ t("Pytanie") }}</span
          ><input class="input w-full" v-model="item.question" /></label
        ><label
          ><span class="field-label">{{ t("Odpowiedź") }}</span
          ><textarea class="textarea w-full" v-model="item.answer" />
        </label>
      </div>
      <button
        class="btn btn-sm"
        type="button"
        @click="addQuestion"
      >
        {{ t("Dodaj pytanie") }}
      </button></template
    >
    <template v-else-if="isBuiltInBlock(block.type)"
      ><label
        v-for="key in block.type === 'hero'
          ? ['eyebrow', 'title', 'text']
          : block.type === 'cards'
            ? ['collection_id']
            : ['title', 'label', 'url']"
        :key="key"
        ><span class="field-label">{{ key }}</span
        ><input
          class="input w-full"
          :type="key.endsWith('_id') ? 'number' : 'text'"
          :value="block.props?.[key]"
          @input.stop="updateBlockInput(key, $event)" /></label
    ></template>
    <template v-else-if="metadata">
      <label v-for="field in metadata.schema" :key="field.key">
        <span class="field-label">{{ field.label }}</span>
        <input
          v-if="field.input === 'text'"
          class="input w-full"
          :value="customTextValue(field.key)"
          @input.stop="setCustomSetting(field.key, ($event.target as HTMLInputElement).value)"
        />
        <textarea
          v-else-if="field.input === 'textarea'"
          class="textarea w-full"
          :value="customTextValue(field.key)"
          @input.stop="setCustomSetting(field.key, ($event.target as HTMLTextAreaElement).value)"
        />
        <input
          v-else-if="field.input === 'number'"
          class="input w-full"
          type="number"
          step="any"
          :value="customTextValue(field.key)"
          @input.stop="updateCustomNumber(field.key, $event)"
        />
        <select
          v-else-if="field.input === 'boolean'"
          class="select w-full"
          :value="customBoolean(field.key)"
          @input.stop
          @change="updateCustomBoolean(field.key, $event)"
        >
          <option value="">{{ t("Nie ustawiono") }}</option>
          <option v-if="customBoolean(field.key) === '-1'" value="-1" disabled>
            {{ t("Niedostępna wartość") }}
          </option>
          <option value="true">{{ t("Tak") }}</option>
          <option value="false">{{ t("Nie") }}</option>
        </select>
        <select
          v-else-if="field.input === 'select'"
          class="select w-full"
          :value="customChoice(field)"
          @input.stop
          @change="updateCustomChoice(field, $event)"
        >
          <option value="">{{ t("Nie ustawiono") }}</option>
          <option v-if="customChoice(field) === '-1'" value="-1" disabled>
            {{ t("Niedostępna wartość") }}
          </option>
          <option v-for="(option, index) in field.options" :key="index" :value="String(index)">
            {{ option.label }}
          </option>
        </select>
        <span v-else class="block text-sm">{{ t("To ustawienie nie ma kontrolki w panelu.") }}</span>
      </label>
    </template>
    <p v-else class="text-sm">{{ t("Ten typ bloku nie udostępnia ustawień w panelu.") }}</p>
    <template v-if="isLayout">
      <article
        v-for="(item, index) in Array.isArray(block.children) ? block.children : []"
        :key="blockEditorKey(item)"
        :aria-label="blockLabel(item.type, catalog)"
        class="space-y-3 rounded-box border border-base-300 p-2 sm:p-3"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <span class="text-sm font-semibold">
            {{ blockLabel(item.type, catalog) }}
          </span>
          <div class="flex flex-wrap gap-1">
            <button
              class="btn btn-xs"
              type="button"
              :aria-label='t("Przesuń blok w górę")'
              :disabled="index === 0"
              @click="moveChild(index, -1)"
            >
              ↑
            </button>
            <button
              class="btn btn-xs"
              type="button"
              :aria-label='t("Przesuń blok w dół")'
              :disabled="index === (block.children?.length ?? 0) - 1"
              @click="moveChild(index, 1)"
            >
              ↓
            </button>
            <button
              class="btn btn-xs"
              type="button"
              :aria-label='t("Powiel blok")'
              :title='t("Powiel blok")'
              :disabled="!canAddChild"
              @click="duplicateChild(index)"
            >
              <Copy :size="12" aria-hidden="true" />
            </button>
            <button
              class="btn btn-xs"
              type="button"
              :aria-label='t("Przenieś blok")'
              :title='t("Przenieś blok")'
              aria-haspopup="dialog"
              :disabled="moveDisabled"
              @click="emit('move', [index], $event.currentTarget)"
            >
              <Move :size="12" aria-hidden="true" />
            </button>
            <button
              class="btn btn-xs"
              type="button"
              :aria-label='t("Usuń blok z układu")'
              @click="emit('remove', [index])"
            >
              ×
            </button>
          </div>
        </div>
        <BlockEditor
          :block="item"
          :catalog="catalog"
          :depth="depth + 1"
          :move-disabled="moveDisabled"
          @change="emit('change')"
          @move="(path, trigger) => emit('move', [index, ...path], trigger)"
          @remove="(path) => emit('remove', [index, ...path])"
        />
      </article>
      <div v-if="canAddChild && childTypes.length" class="flex flex-wrap items-end gap-2">
        <label>
          <span class="field-label">{{ t("Typ bloku w układzie") }}</span>
          <select class="select select-sm max-w-full" v-model="childType">
            <option v-for="type in childTypes" :key="type" :value="type">
              {{ blockLabel(type, catalog) }}
            </option>
          </select>
        </label>
        <button class="btn btn-sm" type="button" :disabled="!childTypes.includes(childType)" @click="addChild">
          {{ t("Dodaj blok do układu") }}
        </button>
      </div>
    </template>
  </div>
</template>
