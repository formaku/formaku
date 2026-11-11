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
const maxBlockDepth = maxLayoutDepth + 1;

export type BlockPath = number[];
export type BlockMoveDestination = { path: BlockPath; type: string | null };

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

function blockAt(blocks: Block[], path: BlockPath) {
  let siblings = blocks;
  for (const [depth, index] of path.entries()) {
    if (!Number.isInteger(index) || index < 0 || index >= siblings.length) return;
    const block = siblings[index];
    if (!block) return;
    if (depth === path.length - 1) return { block, index, siblings };
    if (!isLayoutBlock(block.type) || !Array.isArray(block.children)) return;
    siblings = block.children;
  }
}

function blockSubtreeDepth(block: Block, depth = 0): number {
  if (depth > maxBlockDepth) return depth;
  const children = block.children;
  if (children != null && !Array.isArray(children)) return Infinity;
  if (children && children.length > maxBlocksPerLevel) return Infinity;
  if (!children && !isLayoutBlock(block.type)) return depth;
  // A layout renders its children list even if the list is empty.
  return (children ?? []).reduce(
    (deepest, child) => Math.max(deepest, blockSubtreeDepth(child, depth + 1)),
    depth + 1,
  );
}

function canAppendBlock(
  sourceList: Block[],
  destinationList: Block[] | undefined,
  deepestDepth: number,
): boolean {
  return (
    sourceList !== destinationList &&
    (destinationList == null ||
      (Array.isArray(destinationList) && destinationList.length < maxBlocksPerLevel)) &&
    deepestDepth <= maxBlockDepth
  );
}

export function blockMoveDestinations(
  blocks: Block[],
  source: BlockPath,
): BlockMoveDestination[] {
  const original = blockAt(blocks, source);
  if (!original) return [];

  const subtreeDepth = blockSubtreeDepth(original.block);
  const destinations: BlockMoveDestination[] = [];
  if (canAppendBlock(original.siblings, blocks, subtreeDepth)) {
    destinations.push({ path: [], type: null });
  }

  function visitLayouts(siblings: Block[], parentPath: BlockPath) {
    if (parentPath.length + subtreeDepth >= maxBlockDepth) return;

    siblings.forEach((block, index) => {
      if (block === original.block || !isLayoutBlock(block.type)) return;
      const path = [...parentPath, index];
      if (canAppendBlock(original.siblings, block.children, path.length + subtreeDepth)) {
        destinations.push({ path, type: block.type });
      }
      if (Array.isArray(block.children)) visitLayouts(block.children, path);
    });
  }

  visitLayouts(blocks, []);
  return destinations;
}

export function moveBlockTo(
  blocks: Block[],
  source: BlockPath,
  destination: BlockPath,
): boolean {
  const original = blockAt(blocks, source);
  if (!original || source.every((index, depth) => destination[depth] === index)) return false;

  const layout = destination.length ? blockAt(blocks, destination)?.block : undefined;
  if (destination.length && (!layout || !isLayoutBlock(layout.type))) return false;

  const deepestDepth = destination.length + blockSubtreeDepth(original.block);
  if (!canAppendBlock(original.siblings, layout ? layout.children : blocks, deepestDepth)) {
    return false;
  }

  const target = layout ? (layout.children ??= []) : blocks;
  original.siblings.splice(original.index, 1);
  target.push(original.block);
  return true;
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
