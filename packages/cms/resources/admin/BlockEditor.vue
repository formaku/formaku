<script setup lang="ts">
import { t } from "./locale";
import { computed, ref } from "vue";
import { Copy, Move } from "lucide-vue-next";
import MediaPicker from "./MediaPicker.vue";
import RichText from "./RichText.vue";
import {
  blockEditorKey,
  blockLabels,
  createBlock,
  duplicateBlock,
  isLayoutBlock,
  maxBlocksPerLevel,
  maxLayoutDepth,
  type Block,
  type BlockPath,
} from "./blocks";

const props = withDefaults(defineProps<{ block: Block; depth?: number; moveDisabled?: boolean }>(), {
  depth: 0,
});
const emit = defineEmits<{ change: []; move: [path: BlockPath, trigger: EventTarget | null] }>();
const childType = ref("text");
const childTypes = computed(() =>
  Object.entries(blockLabels).filter(
    ([type]) => props.depth < maxLayoutDepth || !isLayoutBlock(type),
  ),
);
const canAddChild = computed(
  () =>
    props.depth <= maxLayoutDepth &&
    (props.block.children?.length ?? 0) < maxBlocksPerLevel,
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

function addChild() {
  if (!canAddChild.value) return;
  const children = (props.block.children ??= []);
  children.push(createBlock(childType.value));
  emit("change");
}

function duplicateChild(index: number) {
  const children = props.block.children;
  if (canAddChild.value && children && duplicateBlock(children, index)) {
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

function removeChild(index: number) {
  props.block.children?.splice(index, 1);
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
    <template v-else-if="isLayoutBlock(block.type)">
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
      <article
        v-for="(item, index) in block.children ?? []"
        :key="blockEditorKey(item)"
        :aria-label="t(blockLabels[item.type as keyof typeof blockLabels] ?? item.type)"
        class="space-y-3 rounded-box border border-base-300 p-2 sm:p-3"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <span class="text-sm font-semibold">
            {{ t(blockLabels[item.type as keyof typeof blockLabels] ?? item.type) }}
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
              @click="removeChild(index)"
            >
              ×
            </button>
          </div>
        </div>
        <BlockEditor
          :block="item"
          :depth="depth + 1"
          :move-disabled="moveDisabled"
          @change="emit('change')"
          @move="(path, trigger) => emit('move', [index, ...path], trigger)"
        />
      </article>
      <div v-if="canAddChild" class="flex flex-wrap items-end gap-2">
        <label>
          <span class="field-label">{{ t("Typ bloku w układzie") }}</span>
          <select class="select select-sm max-w-full" v-model="childType">
            <option v-for="[type, label] in childTypes" :key="type" :value="type">
              {{ t(label) }}
            </option>
          </select>
        </label>
        <button class="btn btn-sm" type="button" @click="addChild">
          {{ t("Dodaj blok do układu") }}
        </button>
      </div>
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
    <template v-else
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
  </div>
</template>
