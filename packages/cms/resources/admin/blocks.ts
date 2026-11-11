import { t } from "./locale";
export const blockLabels = {
  hero: "Sekcja główna",
  text: "Tekst",
  cta: "Wezwanie do działania",
  image: "Obraz",
  cards: "Karty",
  faq: "Pytania i odpowiedzi",
  container: "Kontener",
  columns: "Kolumny",
} as const;

export type Block = {
  uid?: string;
  type: string;
  props?: Record<string, any>;
  children?: Block[];
};

export const maxBlocksPerLevel = 100;
export const maxLayoutDepth = 9;

const editorKeys = new WeakMap<Block, symbol>();

export function blockEditorKey(block: Block): string | symbol {
  if (block.uid) return block.uid;
  let key = editorKeys.get(block);
  if (!key) {
    key = Symbol();
    editorKeys.set(block, key);
  }
  return key;
}

function assignBlockIds(block: Block): void {
  block.uid = crypto.randomUUID();
  if (isLayoutBlock(block.type) && Array.isArray(block.children)) {
    block.children.forEach(assignBlockIds);
  }
}

export function duplicateBlock(blocks: Block[], index: number): boolean {
  const original = blocks[index];
  if (!original || blocks.length >= maxBlocksPerLevel) return false;

  const copy: Block = JSON.parse(JSON.stringify(original));
  assignBlockIds(copy);
  blocks.splice(index + 1, 0, copy);
  return true;
}

export function isLayoutBlock(type: string): boolean {
  return type === "container" || type === "columns";
}

export function createBlock(type: string): Block {
  const block = { uid: crypto.randomUUID(), type };
  switch (type) {
    case "text":
      return {
        ...block,
        props: { document: { type: "doc", content: [{ type: "paragraph" }] } },
      };
    case "columns":
      return { ...block, props: { columns: 2 }, children: [] };
    case "container":
      return { ...block, props: { layout: "stack" }, children: [] };
    default:
      return {
        ...block,
        props: {
          title: t("Nowa sekcja"),
          text: "",
          url: "/portal",
          label: t("Zobacz więcej"),
        },
      };
  }
}
