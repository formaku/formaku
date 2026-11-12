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

type BlockOption = { label: string; value: string | number | boolean };
export type BlockSetting = {
  key: string;
  label: string;
  input: string;
  options?: BlockOption[];
};
type BlockType = { label: string; children: boolean; schema: BlockSetting[] };
export type BlockCatalog = ReadonlyMap<string, BlockType>;

export function isBuiltInBlock(type: string): type is keyof typeof blockLabels {
  return Object.hasOwn(blockLabels, type);
}

function isBlockOption(option: { label?: unknown; value?: unknown } | null): option is BlockOption {
  if (typeof option?.label !== "string") return false;
  const value = option.value;
  // Laravel trims request strings and turns empty strings into null.
  if (typeof value === "string") return value !== "" && value.trim() === value;
  return typeof value === "boolean" || Number.isFinite(value);
}

export function buildBlockCatalog(metadata: unknown, legacy: unknown): BlockCatalog {
  const types = new Map<string, BlockType>();
  const builtIns = Array.isArray(legacy) ? legacy : Object.keys(blockLabels);
  for (const key of builtIns) {
    if (typeof key === "string" && isBuiltInBlock(key)) {
      types.set(key, { label: blockLabels[key], children: isLayoutBlock(key), schema: [] });
    }
  }
  if (!Array.isArray(metadata)) return types;
  for (const item of metadata) {
    const key: unknown = item?.key;
    const label: unknown = item?.label;
    if (typeof key !== "string" || !key || typeof label !== "string") continue;
    if (isBuiltInBlock(key)) {
      if (!types.has(key)) {
        types.set(key, {
          label: blockLabels[key], children: isLayoutBlock(key), schema: [],
        });
      }
      continue;
    }

    const schema = new Map<string, BlockSetting>();
    for (const field of Array.isArray(item.schema) ? item.schema : []) {
      if (typeof field?.key !== "string" || !field.key || typeof field.label !== "string"
        || typeof field.input !== "string") continue;
      const options = Array.isArray(field.options) ? field.options.filter(isBlockOption) : undefined;
      schema.set(field.key, { key: field.key, label: field.label, input: field.input, options });
    }
    types.set(key, { label, children: item.children === true, schema: [...schema.values()] });
  }
  return types;
}

export function blockLabel(type: string, catalog: BlockCatalog): string {
  return isBuiltInBlock(type) ? t(blockLabels[type]) : catalog.get(type)?.label ?? type;
}

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

function assignBlockIds(block: Block, catalog: BlockCatalog): void {
  block.uid = crypto.randomUUID();
  if (isLayoutBlock(block.type, catalog) && Array.isArray(block.children)) {
    block.children.forEach((child) => assignBlockIds(child, catalog));
  }
}

export function duplicateBlock(blocks: Block[], index: number, catalog: BlockCatalog): boolean {
  const original = blocks[index];
  if (!original || blocks.length >= maxBlocksPerLevel) return false;

  const copy: Block = JSON.parse(JSON.stringify(original));
  assignBlockIds(copy, catalog);
  blocks.splice(index + 1, 0, copy);
  return true;
}

export function isLayoutBlock(type: string, catalog?: BlockCatalog): boolean {
  return type === "container" || type === "columns" || catalog?.get(type)?.children === true;
}

function blockAt(blocks: Block[], path: BlockPath, catalog: BlockCatalog) {
  let siblings = blocks;
  for (const [depth, index] of path.entries()) {
    if (!Number.isInteger(index) || index < 0 || index >= siblings.length) return;
    const block = siblings[index];
    if (!block) return;
    if (depth === path.length - 1) return { block, index, siblings };
    if (!isLayoutBlock(block.type, catalog) || !Array.isArray(block.children)) return;
    siblings = block.children;
  }
}

function blockSubtreeDepth(block: Block, catalog: BlockCatalog, depth = 0): number {
  if (depth > maxBlockDepth) return depth;
  const children = block.children;
  if (children != null && !Array.isArray(children)) return Infinity;
  if (children && children.length > maxBlocksPerLevel) return Infinity;
  if (!children && !isLayoutBlock(block.type, catalog)) return depth;
  // A layout renders its children list even if the list is empty.
  return (children ?? []).reduce(
    (deepest, child) => Math.max(deepest, blockSubtreeDepth(child, catalog, depth + 1)),
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
  catalog: BlockCatalog,
): BlockMoveDestination[] {
  const original = blockAt(blocks, source, catalog);
  if (!original) return [];

  const { block: sourceBlock, siblings: sourceList } = original;
  const subtreeDepth = blockSubtreeDepth(sourceBlock, catalog);
  const destinations: BlockMoveDestination[] = [];
  if (canAppendBlock(sourceList, blocks, subtreeDepth)) {
    destinations.push({ path: [], type: null });
  }

  function visitLayouts(siblings: Block[], parentPath: BlockPath) {
    if (parentPath.length + subtreeDepth >= maxBlockDepth) return;

    siblings.forEach((block, index) => {
      if (block === sourceBlock || !isLayoutBlock(block.type, catalog)) return;
      const path = [...parentPath, index];
      if (canAppendBlock(sourceList, block.children, path.length + subtreeDepth)) {
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
  catalog: BlockCatalog,
): boolean {
  const original = blockAt(blocks, source, catalog);
  if (!original || source.every((index, depth) => destination[depth] === index)) return false;

  const layout = destination.length ? blockAt(blocks, destination, catalog)?.block : undefined;
  if (destination.length && (!layout || !isLayoutBlock(layout.type, catalog))) return false;

  const deepestDepth = destination.length + blockSubtreeDepth(original.block, catalog);
  if (!canAppendBlock(original.siblings, layout ? layout.children : blocks, deepestDepth)) {
    return false;
  }

  const target = layout ? (layout.children ??= []) : blocks;
  original.siblings.splice(original.index, 1);
  target.push(original.block);
  return true;
}

export type RemovedBlock = {
  block: Block;
  index: number;
  parentUid: string | null;
};

function uniqueLayoutPath(blocks: Block[], uid: string, catalog: BlockCatalog): BlockPath | undefined {
  let match: BlockPath | undefined;
  function visit(siblings: Block[], parentPath: BlockPath): boolean {
    for (const [index, block] of siblings.entries()) {
      if (!isLayoutBlock(block.type, catalog)) continue;
      const path = [...parentPath, index];
      if (block.uid === uid) {
        if (match) return false;
        match = path;
      }
      if (Array.isArray(block.children) && !visit(block.children, path)) return false;
    }
    return true;
  }
  return visit(blocks, []) ? match : undefined;
}

export function removeBlock(blocks: Block[], path: BlockPath, catalog: BlockCatalog): RemovedBlock | undefined {
  const source = blockAt(blocks, path, catalog);
  if (!source) return;

  let parentUid: string | null = null;
  if (path.length > 1) {
    const parent = blockAt(blocks, path.slice(0, -1), catalog)?.block;
    if (!parent) return;
    if (!parent.uid || !uniqueLayoutPath(blocks, parent.uid, catalog)) parent.uid = crypto.randomUUID();
    parentUid = parent.uid;
  }

  source.siblings.splice(source.index, 1);
  return { block: source.block, index: source.index, parentUid };
}

export function restoreBlock(
  blocks: Block[],
  removed: RemovedBlock,
  catalog: BlockCatalog,
): "restored" | "missing" | "full" | "depth" {
  let parentPath: BlockPath = [];
  let siblings = blocks;
  if (removed.parentUid !== null) {
    const path = uniqueLayoutPath(blocks, removed.parentUid, catalog);
    const layout = path && blockAt(blocks, path, catalog)?.block;
    if (!path || !layout || !Array.isArray(layout.children)) return "missing";
    parentPath = path;
    siblings = layout.children;
  }

  if (siblings.length >= maxBlocksPerLevel) return "full";
  if (parentPath.length + blockSubtreeDepth(removed.block, catalog) > maxBlockDepth) return "depth";
  siblings.splice(Math.min(removed.index, siblings.length), 0, removed.block);
  return "restored";
}

export function createBlock(type: string, catalog: BlockCatalog): Block {
  const block = { uid: crypto.randomUUID(), type };
  if (!isBuiltInBlock(type)) {
    return isLayoutBlock(type, catalog) ? { ...block, props: {}, children: [] } : { ...block, props: {} };
  }
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
