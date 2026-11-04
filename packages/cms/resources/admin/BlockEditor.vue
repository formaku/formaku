<script setup lang="ts">
import RichText from "./RichText.vue";
const props = defineProps<{ block: any }>();
const emit = defineEmits(["change"]);
function child() {
  props.block.children ??= [];
  props.block.children.push({
    uid: crypto.randomUUID(),
    type: "text",
    props: { document: { type: "doc", content: [{ type: "paragraph" }] } },
  });
  emit("change");
}
</script>
<template>
  <div class="space-y-4" @input="emit('change')">
    <RichText
      v-if="block.type === 'text'"
      v-model="block.props.document"
      @update:modelValue="emit('change')"
    />
    <template v-else-if="['container', 'columns'].includes(block.type)"
      ><BlockEditor
        v-for="b in block.children ?? []"
        :key="b.uid"
        :block="b"
        @change="emit('change')"
      /><button class="btn btn-sm" type="button" @click="child">
        Dodaj tekst do układu
      </button></template
    >
    <template v-else-if="block.type === 'faq'"
      ><div
        v-for="(item, i) in block.props.items ?? []"
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
        @click="
          block.props.items ??= [];
          block.props.items.push({ question: '', answer: '' });
          emit('change');
        "
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
          v-model="block.props[key]" /></label
    ></template>
  </div>
</template>
