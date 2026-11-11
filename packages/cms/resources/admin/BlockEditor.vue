<script setup lang="ts">
import { computed, ref } from "vue";
import RichText from "./RichText.vue";
import {
  blockEditorKey,
  blockLabels,
  createBlock,
  isLayoutBlock,
  maxBlocksPerLevel,
  maxLayoutDepth,
  type Block,
} from "./blocks";

const props = withDefaults(defineProps<{ block: Block; depth?: number }>(), {
  depth: 0,
});
const emit = defineEmits<{ change: [] }>();
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
        <span class="field-label">Liczba kolumn</span>
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
          <option value="">Automatycznie</option>
          <option value="2">Dwie kolumny</option>
          <option value="3">Trzy kolumny</option>
        </select>
      </label>
      <label v-else>
        <span class="field-label">Układ kontenera</span>
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
          <option value="">Automatycznie</option>
          <option value="stack">Pionowo</option>
        </select>
      </label>
      <article
        v-for="(item, index) in block.children ?? []"
        :key="blockEditorKey(item)"
        :aria-label="blockLabels[item.type as keyof typeof blockLabels] ?? item.type"
        class="space-y-3 rounded-box border border-base-300 p-2 sm:p-3"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <span class="text-sm font-semibold">
            {{ blockLabels[item.type as keyof typeof blockLabels] ?? item.type }}
          </span>
          <div class="flex gap-1">
            <button
              class="btn btn-xs"
              type="button"
              aria-label="Przesuń blok w górę"
              :disabled="index === 0"
              @click="moveChild(index, -1)"
            >
              ↑
            </button>
            <button
              class="btn btn-xs"
              type="button"
              aria-label="Przesuń blok w dół"
              :disabled="index === (block.children?.length ?? 0) - 1"
              @click="moveChild(index, 1)"
            >
              ↓
            </button>
            <button
              class="btn btn-xs"
              type="button"
              aria-label="Usuń blok z układu"
              @click="removeChild(index)"
            >
              ×
            </button>
          </div>
        </div>
        <BlockEditor :block="item" :depth="depth + 1" @change="emit('change')" />
      </article>
      <div v-if="canAddChild" class="flex flex-wrap items-end gap-2">
        <label>
          <span class="field-label">Typ bloku w układzie</span>
          <select class="select select-sm max-w-full" v-model="childType">
            <option v-for="[type, label] in childTypes" :key="type" :value="type">
              {{ label }}
            </option>
          </select>
        </label>
        <button class="btn btn-sm" type="button" @click="addChild">
          Dodaj blok do układu
        </button>
      </div>
    </template>
    <template v-else-if="block.type === 'faq'"
      ><div
        v-for="(item, i) in block.props?.items ?? []"
        :key="i"
        class="space-y-2"
      >
        <label
          ><span class="field-label">Pytanie</span
          ><input class="input w-full" v-model="item.question" /></label
        ><label
          ><span class="field-label">Odpowiedź</span
          ><textarea class="textarea w-full" v-model="item.answer" />
        </label>
      </div>
      <button
        class="btn btn-sm"
        type="button"
        @click="addQuestion"
      >
        Dodaj pytanie
      </button></template
    >
    <template v-else
      ><label
        v-for="key in block.type === 'hero'
          ? ['eyebrow', 'title', 'text']
          : block.type === 'image'
            ? ['media_id', 'caption']
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
