<script setup lang="ts">
import { watch } from "vue";
import { useEditor, EditorContent } from "@tiptap/vue-3";
import StarterKit from "@tiptap/starter-kit";
const props = defineProps<{ modelValue: any }>();
const emit = defineEmits(["update:modelValue"]);
const editor = useEditor({
  extensions: [
    StarterKit.configure({
      codeBlock: false,
      horizontalRule: false,
      link: false,
      underline: false,
    }),
  ],
  content: props.modelValue ?? {
    type: "doc",
    content: [{ type: "paragraph" }],
  },
  onUpdate: ({ editor }) => emit("update:modelValue", editor.getJSON()),
});
watch(
  () => props.modelValue,
  (v) => {
    if (
      editor.value &&
      v &&
      JSON.stringify(editor.value.getJSON()) !== JSON.stringify(v)
    )
      editor.value.commands.setContent(v, { emitUpdate: false });
  },
  { deep: true },
);
</script>
<template>
  <div class="border border-base-300 rounded-field bg-base-100">
    <div class="flex gap-1 p-2 border-b border-base-300">
      <button
        class="btn btn-sm"
        @click="editor?.chain().focus().toggleBold().run()"
        type="button"
      >
        B</button
      ><button
        class="btn btn-sm"
        @click="editor?.chain().focus().toggleItalic().run()"
        type="button"
      >
        I</button
      ><button
        class="btn btn-sm"
        @click="editor?.chain().focus().toggleHeading({ level: 2 }).run()"
        type="button"
      >
        H2
      </button>
    </div>
    <EditorContent :editor="editor" />
  </div>
</template>
