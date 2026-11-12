<script setup lang="ts">
import { locale, t } from "./locale";
import { ref, onMounted, computed, nextTick, onBeforeUnmount, watch } from "vue";
import { TooltipProvider } from "reka-ui";
import {
  LayoutDashboard,
  Shapes,
  Files,
  Image,
  ArrowUpRight,
  ArrowRight,
  Plus,
  Settings,
  Box,
  Layers,
  Search,
  ChevronRight,
  Check,
  Globe,
  Sparkles,
  Plug,
  GripVertical,
  Trash2,
  Copy,
  Move,
  Eye,
  Save,
  LogOut,
} from "lucide-vue-next";
import RichText from "./RichText.vue";
import BlockEditor from "./BlockEditor.vue";
import MediaPicker from "./MediaPicker.vue";
import MediaUsage from "./MediaUsage.vue";
import RelationPicker from "./RelationPicker.vue";
import AssistantConnection from "./AssistantConnection.vue";
import SiteNavigation from "./SiteNavigation.vue";
import PageTranslations from "./PageTranslations.vue";
import PanelExtensions from "./PanelExtensions.vue";
import RolePicker, { isRoleKey, roleDisplayName, type RoleOption } from "./RolePicker.vue";
import {
  blockEditorKey,
  blockLabel,
  buildBlockCatalog,
  blockMoveDestinations,
  createBlock,
  duplicateBlock,
  isBuiltInBlock,
  maxBlocksPerLevel,
  moveBlockTo,
  removeBlock,
  restoreBlock,
  type Block,
  type BlockPath,
  type RemovedBlock,
} from "./blocks";
type FieldAbility = "read" | "write";
type CollectionRoleRule = { scope: "all" | "own" | "assigned" | "none"; write: boolean };
type CollectionRoleRules = Record<string, CollectionRoleRule>;
type Field = {
  key: string;
  label: string;
  type: string;
  required?: boolean;
  private?: boolean;
  access?: Partial<Record<FieldAbility, string[]>>;
  writable?: boolean;
  options?: string[];
  target?: number;
};
const builtInFieldTypes = [
  "text", "richtext", "number", "date", "boolean", "select", "media", "relation",
] as const;
type FieldInput = (typeof builtInFieldTypes)[number];
type FieldTypeMetadata = { key: string; label: string; input: FieldInput };
const reusableFieldInputs = new Set<FieldInput>(["text", "richtext", "number", "date", "boolean", "select"]);
const seoLabels = {
  title: "Tytuł SEO",
  description: "Opis SEO",
} as const;
type SeoTarget = keyof typeof seoLabels;
type CollectionSeo = Partial<Record<SeoTarget, string>>;
type MediaItem = {
  id: number;
  name: string;
  alt: string | null;
  mime: string;
  visibility: "public" | "private";
  size: number;
};
type MediaPage = {
  data: MediaItem[];
  current_page: number;
  last_page: number;
};
const releaseStateLabels = {
  dispatching: "Wysyłanie żądania",
  requested: "Żądanie przyjęte",
  unknown: "Stan nieznany",
  queued: "W kolejce",
  running: "Budowa w toku",
  succeeded: "Budowa udana",
  failed: "Budowa nieudana",
  cancelled: "Budowa anulowana",
};
type Release = {
  id: string;
  commit: string;
  state: keyof typeof releaseStateLabels;
  run_url: string | null;
};
const deploymentHostStateLabels = {
  deploying: "Wdrażanie w toku",
  deployed: "Wdrożono",
  failed: "Wdrożenie nieudane",
};
const deploymentRollbackLabels = {
  not_attempted: "Nie rozpoczęto",
  restored: "Przywrócono poprzednią wersję",
  failed: "Przywrócenie nie powiodło się",
  unavailable: "Przywrócenie niedostępne",
};
type DeploymentReceiptResult = {
  state: "pending" | "unavailable" | "verified";
  run_id: number;
  run_attempt: number;
  run_url: string;
  receipt: {
    format: 1;
    release: string;
    image: string;
    ssh_exit_code: number;
    host_status: {
      state: keyof typeof deploymentHostStateLabels;
      rollback: keyof typeof deploymentRollbackLabels | null;
      backup: string | null;
    } | null;
  } | null;
};
type PortalAttachment = { id: number; name: string; size: number; url: string };
const supportedPortalAttachmentTypes = ["pdf", "png", "jpeg"] as const;
type PortalAttachmentType = (typeof supportedPortalAttachmentTypes)[number];
type PortalAttachmentLimits = {
  max_files?: number;
  max_mib?: number;
  types?: PortalAttachmentType[];
};
type PageTemplate = { id: number; name: string };
type PageTemplatePage = {
  data: PageTemplate[];
  current_page: number;
  last_page: number;
};
type Collection = {
  id: number;
  key: string;
  name: string;
  kind: string;
  version: number;
  can_create?: boolean;
  definition: {
    fields: Field[];
    states?: string[];
    transitions?: { from: string; to: string }[];
    public?: boolean;
    seo?: CollectionSeo;
    access?: CollectionRoleRules | CollectionRoleRule[];
    portal_fields?: string[];
    portal_attachments?: boolean;
    portal_attachment_limits?: PortalAttachmentLimits | [];
  };
};
type AiTask = { id: number; state: string; prompt: string; error?: string | null; result?: unknown };
type ProposalBlock = {
  type: "hero" | "cta";
  props: { title: string; text?: string; label?: string; url?: string };
};
type PageProposal = { title: string; slug: string; blocks: ProposalBlock[] };

const clone = (v: any) => JSON.parse(JSON.stringify(v));
const previewWidths = {
  desktop: "w-[1280px]",
  tablet: "w-[768px]",
  mobile: "w-[390px]",
} as const;
const previewViewport = ref<keyof typeof previewWidths>("desktop");
const previewOpen = ref(false);
const scheduledAt = ref("");
const draggedBlock = ref<number | null>(null);
let autosaveTimer: ReturnType<typeof setTimeout> | undefined;
const media = ref<MediaPage | null>(null);
const mediaEdit = ref<Pick<MediaItem, "id" | "name"> & { alt: string }>();
const boot = ref<any>(null),
  section = ref("dashboard"),
  mode = ref("work"),
  collections = ref<Collection[]>([]),
  selected = ref<Collection | null>(null),
  recordsPage = ref<{ data: any[]; current_page: number; last_page: number } | null>(null),
  record = ref<any>(null),
  schema = ref<any>(null),
  tasks = ref<AiTask[]>([]),
  releases = ref<Release[]>([]),
  error = ref(""),
  message = ref(""),
  busy = ref(false),
  dirty = ref(false),
  prompt = ref(""),
  provider = ref("openai"),
  commit = ref(""),
  search = ref("");
const reviewTaskId = ref<number | null>(null);
const proposalCollectionId = ref<number | null>(null);
function proposalField(collection: Collection, key: string, type: string): boolean {
  return collection.definition.fields.some((field) =>
    field.key === key && field.type === type && field.writable !== false,
  );
}
const proposalCollections = computed(() => collections.value.filter((collection) =>
  collection.can_create === true && collection.kind === "content"
    && proposalField(collection, "title", "text") && proposalField(collection, "blocks", "richtext"),
));
const proposalCollection = computed(() =>
  proposalCollections.value.find((collection) => collection.id === proposalCollectionId.value),
);
const reviewedProposal = computed(() => {
  const task = tasks.value.find((item) => item.id === reviewTaskId.value);
  return task ? pageProposal(task) : null;
});
const proposedSlug = computed(() =>
  proposalCollection.value && proposalField(proposalCollection.value, "slug", "text")
    ? reviewedProposal.value?.slug
    : undefined,
);
const emptyRequiredProposalFields = computed(() => {
  const collection = proposalCollection.value;
  if (!collection || !reviewedProposal.value) return [];

  const filled = new Set(["title", "blocks"]);
  if (proposedSlug.value !== undefined) filled.add("slug");

  return collection.definition.fields.filter((field) =>
    [true, 1, "1"].includes(field.required ?? false) && field.writable !== false && !filled.has(field.key),
  );
});

const fieldTypes = computed(() => {
  const types = new Map<string, FieldTypeMetadata>(
    builtInFieldTypes.map((key) => [key, { key, label: key, input: key }]),
  );
  for (const type of (boot.value?.fieldTypes ?? []) as FieldTypeMetadata[]) {
    if (!types.has(type.key) && reusableFieldInputs.has(type.input)) {
      types.set(type.key, type);
    }
  }
  return types;
});
type RevisionPage = { data: { id: number; created_at: string }[]; current_page: number; last_page: number };
const historyPage = ref<RevisionPage | null>(null);
type RevisionChange = { key: string; label: string; type: string; previous?: unknown; current?: unknown };
type RevisionComparison = {
  revision: { id: number; created_at: string };
  draft: { id: number; created_at: string };
  changes: RevisionChange[];
};
const comparisonDialog = ref<HTMLDialogElement | null>(null);
const comparison = ref<RevisionComparison | null>(null);
const comparisonError = ref(false);
let comparisonRequest = 0;
const emptyRecordsFilter = { publication: "", state: "", label: "" };
const recordsFilter = ref(emptyRecordsFilter);
const recordsLabelInput = ref("");
const recordsStates = computed(() => {
  const states = (selected.value?.definition.states ?? []).filter(Boolean);
  return states.length ? [...new Set(states)] : ["new"];
});
let recordsRequest = 0;
const portalAttachments = ref<PortalAttachment[]>([]);
const portalAttachmentsFailed = ref(false);
const portalRecordId = computed<number | undefined>(() =>
  section.value === "records" && selected.value?.key === "requests" && selected.value.kind === "data"
    ? record.value?.id
    : undefined,
);
const translationDirty = ref(false);
const draftSaveConflict = ref(false);
const translationsRecordId = computed<number | undefined>(() =>
  selected.value?.kind === "content" && selected.value.definition.public && boot.value?.abilities?.publish
    ? record.value?.id
    : undefined,
);
const hasUnsavedChanges = computed(() => dirty.value || translationDirty.value);
watch(translationsRecordId, () => { translationDirty.value = false; });
let pendingAutosave: { id?: number } | null = null;
const blockCatalog = computed(() => buildBlockCatalog(boot.value?.blockTypes, boot.value?.blocks));
const pageBlocks = computed<Block[]>(() => record.value?.data.blocks ?? []);
const pageBlocksHeading = ref<HTMLElement | null>(null);
const removedBlock = ref<RemovedBlock | null>(null);
const blockMoveDialog = ref<HTMLDialogElement | null>(null);
const blockMoveSource = ref<BlockPath | null>(null);
const blockMoveDestination = ref<BlockPath | null>(null);
const availableBlockDestinations = computed(() =>
  blockMoveSource.value
    ? blockMoveDestinations(pageBlocks.value, blockMoveSource.value, blockCatalog.value)
    : [],
);
const refreshingReleases = ref(new Set<string>());
const releaseRefreshErrors = ref(new Set<string>());
const deploymentReceiptQuery = ref({ run_id: "", release_id: "", image: "" });
const deploymentReceiptResult = ref<DeploymentReceiptResult | null>(null);
const deploymentReceiptLoading = ref(false);
const deploymentReceiptFailed = ref(false);
const templateName = ref("");
const templatePage = ref<PageTemplatePage | null>(null);
const templateId = ref<number | null>(null);
const templateToDelete = ref<number | null>(null);
const supportsPageTemplates = computed(
  () =>
    selected.value?.kind === "content" &&
    selected.value.definition.fields.some(
      (field) => field.key === "blocks" && field.type === "richtext" && !field.private && field.writable !== false,
    ),
);
watch(record, () => {
  closeBlockMove();
  templateName.value = "";
  templatePage.value = null;
  templateId.value = null;
  templateToDelete.value = null;
});
const configuredRoles = computed<RoleOption[]>(() => boot.value?.roles ?? []);
const configuredRoleLabels = computed(() => new Map(configuredRoles.value.map(({ key, label }) => [key, label])));
const collectionRoleRules = computed(() =>
  Object.entries<CollectionRoleRule>(schema.value?.definition.access ?? {}),
);
const collectionRoleKeys = computed(() => collectionRoleRules.value.map(([key]) => key));
function addCollectionRole(key: string) {
  const definition = schema.value.definition;
  if (!definition.access || !isRoleKey(key) || collectionRoleKeys.value.includes(key)) return;
  definition.access = { ...definition.access, [key]: { scope: "none", write: false } };
}
function removeCollectionRole(key: string) {
  schema.value.definition.access = Object.fromEntries(
    collectionRoleRules.value.filter(([ruleKey]) => ruleKey !== key),
  );
}
function toggleFieldAccess(field: Field, ability: FieldAbility, enabled: boolean) {
  field.access ??= {};
  if (enabled) field.access[ability] = [];
  else delete field.access[ability];
}
function updateFieldRoles(field: Field, ability: FieldAbility, value: string) {
  field.access ??= {};
  field.access[ability] = Array.from(new Set(value.split(",").map((role) => role.trim()).filter(Boolean)));
}
function addFieldRole(field: Field, ability: FieldAbility, key: string) {
  const roles = field.access?.[ability];
  if (roles && isRoleKey(key) && !roles.includes(key)) roles.push(key);
}
function canRestrictFieldRead(field: Field) {
  return field.private === true && field.key !== "slug";
}
const invalidFieldRead = computed(() => schema.value?.definition.fields.some(
  (field: Field) => field.access?.read !== undefined && !canRestrictFieldRead(field),
));
const portalSchema = computed(() => schema.value?.key === "requests" && schema.value.kind === "data");
const portalFieldTypes = new Set(["text", "number", "date", "boolean", "select"]);
const portalModelFields = computed<Field[]>(() => {
  if (!portalSchema.value) return [];
  return schema.value.definition.fields.filter((field: Field) => {
    if (field.private || !portalFieldTypes.has(field.type)) return false;
    if (!/^[a-z][a-z0-9_]{0,48}$/.test(field.key) || field.key === "title" || field.key === "description") return false;
    if (field.type !== "select") return true;
    const options = field.options;
    return Array.isArray(options) && options.length > 0 && new Set(options).size === options.length
      && options.every((option) => typeof option === "string" && option !== "" && option.trim() === option
        && !option.includes(",") && !option.startsWith('"'));
  });
});
const portalFieldOrderAnnouncement = ref("");
watch(schema, () => { portalFieldOrderAnnouncement.value = ""; });
const portalFieldSelection = computed<string[]>({
  get() {
    const keys = schema.value?.definition.portal_fields;
    return Array.isArray(keys) ? keys : [];
  },
  set(keys) {
    schema.value.definition.portal_fields = keys;
    portalFieldOrderAnnouncement.value = "";
  },
});
const portalOrderedFields = computed(() => {
  const eligible = new Map(portalModelFields.value.map((field) => [field.key, field]));
  return [...new Set(portalFieldSelection.value)].flatMap((key) => {
    const field = eligible.get(key);
    return field ? [field] : [];
  });
});
async function movePortalField(index: number, offset: -1 | 1, event: MouseEvent) {
  const fields = portalOrderedFields.value;
  const field = fields[index];
  const nextIndex = index + offset;
  if (!field || nextIndex < 0 || nextIndex >= fields.length) return;

  const button = event.currentTarget as HTMLButtonElement;
  const row = button.closest("li");
  const keys = fields.map(({ key }) => key);
  keys.splice(index, 1);
  keys.splice(nextIndex, 0, field.key);
  portalFieldSelection.value = keys;
  portalFieldOrderAnnouncement.value = t("Pole {0} zajmuje pozycję {1} z {2}.", field.label, nextIndex + 3, fields.length + 2);
  await nextTick();
  const focusTarget = button.disabled ? row?.querySelector<HTMLButtonElement>("button:not(:disabled)") : button;
  focusTarget?.focus();
}
const portalAttachmentLimits = computed<PortalAttachmentLimits>(() => {
  const limits: Collection["definition"]["portal_attachment_limits"] =
    schema.value?.definition.portal_attachment_limits;
  return limits && !Array.isArray(limits) ? limits : {};
});
function setPortalAttachmentLimit<K extends keyof PortalAttachmentLimits>(
  key: K, value: NonNullable<PortalAttachmentLimits[K]>,
) {
  schema.value.definition.portal_attachment_limits = { ...portalAttachmentLimits.value, [key]: value };
}
const portalAttachmentTypes = computed<PortalAttachmentType[]>({
  get: () => portalAttachmentLimits.value.types ?? [...supportedPortalAttachmentTypes],
  set: (types) => setPortalAttachmentLimit("types", types),
});
function readonlyFieldValue(value: unknown): string {
  if (value == null || value === "") return "—";
  if (typeof value === "boolean") return value ? t("Tak") : t("Nie");
  if (typeof value !== "object") return String(value);
  return JSON.stringify(value, null, 2);
}
const publicContentSchema = computed(
  () => schema.value?.kind === "content" && schema.value.definition.public,
);
const privatePublicSlug = computed(() =>
  publicContentSchema.value && schema.value.definition.fields.some(
    (field: Field) => field.key === "slug" && field.private,
  ),
);
const seoFields = computed<Field[]>(() =>
  publicContentSchema.value
    ? schema.value.definition.fields.filter(
        (field: Field) => field.type === "text" && !field.private,
      )
    : [],
);
function updateSeoField(target: SeoTarget, field: string) {
  const definition = schema.value.definition;
  if (field) {
    definition.seo ??= {};
    definition.seo[target] = field;
  } else if (definition.seo) {
    delete definition.seo[target];
    if (!Object.keys(definition.seo).length) delete definition.seo;
  }
}
watch(
  () => seoFields.value.map((field) => field.key),
  (eligibleKeys) => {
    const mapping: CollectionSeo | undefined = schema.value?.definition.seo;
    if (!mapping) return;
    for (const target of ["title", "description"] as const) {
      const field = mapping[target];
      if (field && !eligibleKeys.includes(field)) updateSeoField(target, "");
    }
  },
);
const base = (
  document.querySelector('meta[name="formaku-prefix"]') as HTMLMetaElement
).content;
const api = base + "/api/v1";
function recordAddress(id?: number): URL {
  const url = new URL(window.location.href);
  if (id) url.searchParams.set("record", String(id));
  else url.searchParams.delete("record");
  return url;
}
function syncRecordAddress(id?: number) {
  const url = recordAddress(id);
  if (url.href !== window.location.href) window.history.replaceState(window.history.state, "", url);
}
const recordLink = computed(() => record.value?.id ? recordAddress(record.value.id).href : null);
const previewUrl = computed(() =>
  record.value?.id && selected.value?.kind === "content"
    ? `${api}/records/${record.value.id}/preview?version=${record.value.version}`
    : null,
);
watch(
  () => record.value?.id,
  (id) => {
    syncRecordAddress(id);
    comparisonDialog.value?.close();
    previewOpen.value = false;
    scheduledAt.value = "";
  },
);
async function request(path: string, method = "GET", data?: any) {
  const res = await fetch(api + path, {
    method,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-CSRF-TOKEN": (
        document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement
      ).content,
    },
    body: data ? JSON.stringify(data) : undefined,
  });
  const body = await res.json();
  if (!res.ok) {
    throw Object.assign(new Error(body.message ?? t("Operacja nie powiodła się")), {
      status: res.status,
    });
  }
  return body;
}
watch(portalRecordId, async (id, _previousId, onCleanup) => {
  portalAttachments.value = [];
  portalAttachmentsFailed.value = false;
  if (!id) return;

  let active = true;
  onCleanup(() => { active = false; });
  try {
    const result: PortalAttachment[] = await request(`/records/${id}/attachments`);
    if (active) portalAttachments.value = result;
  } catch {
    if (active) portalAttachmentsFailed.value = true;
  }
});
async function run(fn: () => Promise<unknown>) {
  busy.value = true;
  error.value = "";
  message.value = "";
  try {
    await fn();
  } catch (e: any) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}
async function load() {
  boot.value = await request("/bootstrap");
  collections.value = boot.value.collections;
  if (selected.value) {
    const current = collections.value.find((collection) => collection.id === selected.value?.id);
    selected.value = current ?? null;
    if (!current && section.value === "records") {
      setDraft(null);
      recordsPage.value = null;
      dirty.value = false;
      section.value = "dashboard";
    }
  }
}
async function loadPanel() {
  await load();
  const id = new URL(window.location.href).searchParams.get("record");
  if (id === null || section.value !== "dashboard") return;
  const stillOpening = () =>
    section.value === "dashboard" && new URL(window.location.href).searchParams.get("record") === id;
  const unavailable = () => {
    if (!stillOpening()) return;
    syncRecordAddress();
    error.value = t("Rekord jest niedostępny.");
  };

  if (!/^[1-9][0-9]*$/.test(id) || !Number.isSafeInteger(Number(id))) {
    unavailable();
    return;
  }
  const linked = await request(`/records/${id}?history=0`).catch((failure: unknown) => {
    if (!(failure instanceof Error && "status" in failure && [403, 404].includes(Number(failure.status)))) throw failure;
    unavailable();
    return null;
  });
  if (!linked || !stillOpening()) return;
  const collection = collections.value.find((item) => item.id === linked.collection_id);
  if (!collection) {
    unavailable();
    return;
  }
  selected.value = collection;
  section.value = "records";
  edit(linked);
}
async function loadRecords(page: number, filters = recordsFilter.value) {
  const collectionId = selected.value!.id;
  const requestId = ++recordsRequest;
  const query = new URLSearchParams();
  if (page > 1) query.set("page", String(page));
  if (filters.publication === "scheduled") {
    query.set("scheduled", "1");
  } else if (filters.publication) {
    query.set("publication", filters.publication);
  }
  if (filters.state) query.set("state", filters.state);
  if (filters.label) query.set("label", filters.label);
  const path = `/collections/${collectionId}/records`;
  let result = await request(query.size ? `${path}?${query}` : path);
  if (requestId !== recordsRequest || selected.value?.id !== collectionId) return;
  if (result.current_page > result.last_page) {
    query.set("page", String(result.last_page));
    result = await request(`${path}?${query}`);
    if (requestId !== recordsRequest || selected.value?.id !== collectionId) return;
  }
  recordsPage.value = result;
  recordsFilter.value = filters;
}
async function changeRecordsFilter(key: "state" | "publication", event: Event) {
  const control = event.target as HTMLSelectElement;
  await run(() => loadRecords(1, { ...recordsFilter.value, [key]: control.value }));
  control.value = recordsFilter.value[key];
}
async function choose(c: Collection, keepList = false): Promise<boolean> {
  if (hasUnsavedChanges.value && !confirm(t("Odrzucić niezapisane zmiany?"))) return false;
  const page = keepList ? (recordsPage.value?.current_page ?? 1) : 1;
  const filters = keepList ? recordsFilter.value : emptyRecordsFilter;
  selected.value = c;
  section.value = "records";
  setDraft(null);
  recordsPage.value = null;
  recordsFilter.value = filters;
  recordsLabelInput.value = filters.label;
  dirty.value = false;
  const loading = loadRecords(page);
  const requestId = recordsRequest;
  await loading;
  return requestId === recordsRequest && section.value === "records"
    && selected.value?.id === c.id && record.value === null;
}
function formatPublicationTime(value: string): string {
  return new Date(value).toLocaleString(locale.value, { dateStyle: "medium", timeStyle: "short" });
}
function contentTemplate() {
  if (schema.value.kind === "content") {
    schema.value.definition.public = true;
    schema.value.definition.fields = [
      { key: "title", label: t("Tytuł"), type: "text", required: true },
      { key: "slug", label: t("Adres URL"), type: "text", required: true },
      { key: "blocks", label: t("Sekcje strony"), type: "richtext" },
    ];
  }
}
async function openHistory(page = 1) {
  await run(async () => {
    const draft = record.value;
    const path = `/records/${draft.id}/revisions`;
    const result: RevisionPage = await request(page > 1 ? `${path}?page=${page}` : path);
    if (record.value === draft) historyPage.value = result;
  });
}
function resetRevisionComparison() {
  comparisonRequest++;
  comparison.value = null;
  comparisonError.value = false;
}
async function compareRevision(revisionId: number) {
  const recordId = record.value?.id;
  const dialog = comparisonDialog.value;
  if (!recordId || !dialog || dialog.open) return;
  resetRevisionComparison();
  const requestId = comparisonRequest;
  dialog.showModal();
  try {
    const result: RevisionComparison = await request(`/records/${recordId}/revisions/${revisionId}/compare`);
    if (requestId === comparisonRequest && dialog.open) comparison.value = result;
  } catch {
    if (requestId === comparisonRequest && dialog.open) comparisonError.value = true;
  }
}
function comparisonValue(change: RevisionChange, side: "previous" | "current"): string {
  if (!Object.hasOwn(change, side)) return t("Brak wartości");
  const value = change[side];
  if (value === null) return t("Wartość null");
  if (value === "") return t("Pusty tekst");
  return readonlyFieldValue(value);
}
async function restoreDraftRevision(revisionId: number) {
  await run(async () => {
    const draft = record.value;
    const result = await request(
      "/records/" + draft.id + "/restore/" + revisionId,
      "POST",
      { version: draft.version },
    );
    if (record.value !== draft) return;
    clearTimeout(autosaveTimer);
    pendingAutosave = null;
    setDraft(result);
    dirty.value = false;
    message.value = t("Wersja przywrócona do szkicu.");
  });
}
async function importProject(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0];
  if (file)
    await run(async () => {
      await request("/project/import", "POST", JSON.parse(await file.text()));
      await load();
      message.value = t("Projekt zaimportowany.");
    });
}
function proposalObject(value: unknown): Record<string, unknown> | null {
  return value !== null && typeof value === "object" && !Array.isArray(value)
    ? value as Record<string, unknown>
    : null;
}
function pageProposal(task: AiTask): PageProposal | null {
  const data = proposalObject(task.result);
  if (task.state !== "ready" || !Number.isSafeInteger(task.id) || task.id < 1
    || typeof data?.title !== "string" || !data.title.trim()
    || !Array.isArray(data.blocks) || data.blocks.length === 0 || data.blocks.length > 30) return null;

  const blocks: ProposalBlock[] = [];
  for (const item of data.blocks) {
    const block = proposalObject(item);
    const type = block?.type;
    const props = proposalObject(block?.props);
    if ((type !== "hero" && type !== "cta") || typeof props?.title !== "string" || !props.title.trim()) {
      return null;
    }

    const draftProps: ProposalBlock["props"] = { title: props.title };
    if (type === "hero" && typeof props.text === "string") draftProps.text = props.text;
    if (type === "cta") {
      if (typeof props.label === "string") draftProps.label = props.label;
      if (typeof props.url === "string") draftProps.url = props.url;
    }
    blocks.push({ type, props: draftProps });
  }

  return { title: data.title, slug: "proposal-" + task.id, blocks };
}
function toggleProposalReview(task: AiTask) {
  if (reviewTaskId.value === task.id) {
    reviewTaskId.value = null;
    return;
  }

  reviewTaskId.value = task.id;
  proposalCollectionId.value = proposalCollections.value.length === 1 ? proposalCollections.value[0].id : null;
}
async function applyProposal() {
  const proposal = reviewedProposal.value;
  const collection = proposalCollection.value;
  if (!proposal || !collection || !(await choose(collection))) return;

  const current = proposalCollections.value.find((item) => item.id === collection.id);
  if (!current) return;
  const data = {
    title: proposal.title,
    blocks: clone(proposal.blocks),
    ...(proposalField(current, "slug", "text") ? { slug: proposal.slug } : {}),
  };
  setDraft({ version: 0, data });
  dirty.value = true;
  reviewTaskId.value = null;
}
function setDraft(draft: any) {
  record.value = draft;
  historyPage.value = null;
  if (!draft?.id) syncRecordAddress();
  draftSaveConflict.value = false;
  removedBlock.value = null;
}
function fresh() {
  setDraft({ version: 0, data: {} });
  dirty.value = false;
}
function edit(r: any) {
  setDraft(clone(r));
  dirty.value = false;
}
async function savePageTemplate() {
  if (!record.value?.id || dirty.value || !templateName.value.trim()) return;
  await run(async () => {
    await request(`/records/${record.value.id}/page-templates`, "POST", {
      name: templateName.value.trim(),
      version: record.value.version,
    });
    templateName.value = "";
    message.value = t("Zapisano szablon strony.");
  });
}
function pageTemplatePath(collectionId: number) {
  return `/collections/${collectionId}/page-templates`;
}
async function loadPageTemplates(page = 1) {
  const draft = record.value;
  const collectionId = selected.value!.id;
  await run(async () => {
    const result: PageTemplatePage = await request(
      `${pageTemplatePath(collectionId)}?page=${page}`,
    );
    if (draft !== record.value || collectionId !== selected.value?.id) return;
    templatePage.value = result;
    templateId.value = null;
    templateToDelete.value = null;
  });
}
async function applyPageTemplate() {
  const draft = record.value;
  if (!draft || draft.id || !templateId.value || draft.data.blocks?.length) return;
  const collectionId = selected.value!.id;
  await run(async () => {
    const result = await request(
      `${pageTemplatePath(collectionId)}/${templateId.value}`,
    );
    if (draft !== record.value || collectionId !== selected.value?.id || draft.data.blocks?.length) return;
    draft.data.blocks = clone(result.blocks);
    removedBlock.value = null;
    dirty.value = true;
    message.value = t("Wstawiono sekcje szablonu do szkicu.");
  });
}
async function deletePageTemplate() {
  const draft = record.value;
  const collectionId = selected.value!.id;
  const page = templatePage.value;
  if (!page || !templateToDelete.value) return;
  await run(async () => {
    const path = pageTemplatePath(collectionId);
    await request(`${path}/${templateToDelete.value}`, "DELETE");
    if (draft !== record.value || collectionId !== selected.value?.id) return;
    templateId.value = null;
    templateToDelete.value = null;
    const targetPage = page.data.length === 1 ? Math.max(1, page.current_page - 1) : page.current_page;
    const result: PageTemplatePage = await request(`${path}?page=${targetPage}`);
    if (draft !== record.value || collectionId !== selected.value?.id) return;
    templatePage.value = result;
    message.value = t("Usunięto szablon strony.");
  });
}
async function save() {
  if (draftSaveConflict.value) return;
  clearTimeout(autosaveTimer);
  pendingAutosave = null;
  await run(async () => {
    const draft = record.value;
    const collectionId = selected.value!.id;
    const snapshot = clone(draft);
    const data = { ...snapshot.data };
    for (const field of selected.value!.definition.fields) {
      if (field.writable === false) delete data[field.key];
    }
    const result = await request(
      "/collections/" + collectionId + "/records",
      "POST",
      { id: snapshot.id, version: snapshot.version, data },
    ).catch((failure: unknown) => {
      if (!(failure instanceof Error && "status" in failure && failure.status === 409)) throw failure;
      if (record.value === draft && selected.value?.id === collectionId) draftSaveConflict.value = true;
      return null;
    });
    if (!result || record.value !== draft || selected.value?.id !== collectionId) return;
    if (JSON.stringify(draft.data) !== JSON.stringify(snapshot.data)) {
      record.value.id = result.id;
      record.value.version = result.version;
      record.value.publish_at = result.publish_at;
      dirty.value = true;
      message.value =
        t("Zapisano wcześniejszy stan. Nowsze zmiany czekają na zapis.");
    } else {
      record.value = result;
      dirty.value = false;
      message.value = t("Szkic zapisany.");
    }
    if (selected.value?.id === collectionId) {
      await loadRecords(recordsPage.value?.current_page ?? 1);
    }
    await load();
  });
}
async function loadCurrentDraft() {
  const draft = record.value;
  if (!draftSaveConflict.value || busy.value) return;
  if (dirty.value && !confirm(t("Odrzucić niezapisane zmiany szkicu i wczytać aktualną wersję?"))) return;
  const confirmedData = JSON.stringify(draft.data);

  await run(async () => {
    const current = await request(`/records/${draft.id}?history=0`);
    if (record.value !== draft || JSON.stringify(draft.data) !== confirmedData) return;
    setDraft(current);
    dirty.value = false;
    scheduledAt.value = "";
    message.value = t("Wczytano aktualny szkic.");
  });
}
function downloadDraftEdits() {
  const draft = record.value;
  const fields = selected.value!.definition.fields
    .filter((field) => field.writable !== false && Object.hasOwn(draft.data, field.key));
  const values = Object.fromEntries(fields.map((field) => [field.key, draft.data[field.key]]));
  downloadJson(`formaku-draft-${draft.id}.json`, values);
}
async function updateSchedule(cancel = false) {
  await run(async () => {
    const draft = record.value;
    const path = `/records/${draft.id}/schedule`;
    const result = await request(path, cancel ? "DELETE" : "POST", {
      version: draft.version,
      ...(cancel ? {} : { publish_at: new Date(scheduledAt.value).toISOString() }),
    });
    if (record.value !== draft) return;
    draft.version = result.version;
    draft.publish_at = result.publish_at;
    scheduledAt.value = "";
    message.value = cancel
      ? t("Anulowano zaplanowaną publikację.")
      : t("Publikacja zaplanowana.");
  });
}
async function publish() {
  await run(async () => {
    const draft = record.value;
    await request("/records/" + draft.id + "/publish", "POST", {
      version: draft.version,
    });
    if (record.value !== draft) return;
    removedBlock.value = null;
    const result = await request(`/records/${draft.id}?history=0`);
    if (record.value !== draft) return;
    setDraft(result);
    message.value = t("Treść opublikowana.");
    await load();
  });
}
async function unpublish() {
  const draft = record.value;
  if (!draft?.published_id || busy.value || hasUnsavedChanges.value) return;
  if (!confirm(t("Wycofać publikację? Zapisany szkic pozostanie w panelu, a zaplanowana publikacja zostanie anulowana."))) return;

  await run(async () => {
    const updated = await request(`/records/${draft.id}/publish`, "DELETE", {
      version: draft.version,
    });
    if (record.value !== draft) return;
    Object.assign(draft, updated);
    scheduledAt.value = "";
    message.value = t("Publikacja wycofana.");
    await load();
  });
}
function newSchema() {
  schema.value = {
    key: "",
    name: "",
    kind: "data",
    definition: {
      fields: [{ key: "title", label: t("Tytuł"), type: "text", required: true }],
      states: ["new"],
      transitions: [],
    },
  };
  section.value = "schema";
}
async function saveSchema() {
  await run(async () => {
    const definition = { ...schema.value.definition };
    if (portalSchema.value && Array.isArray(definition.portal_fields)) {
      definition.portal_fields = portalOrderedFields.value.map(({ key }) => key);
    } else {
      delete definition.portal_fields;
    }
    if (!portalSchema.value) {
      delete definition.portal_attachments;
      delete definition.portal_attachment_limits;
    }
    await request("/collections", "POST", { ...schema.value, definition });
    await load();
    schema.value = null;
    section.value = "builder";
    message.value = t("Konfiguracja aktywowana. Panel jest gotowy.");
  });
}
function addBlock(type: string) {
  const blocks = (record.value.data.blocks ??= []);
  if (blocks.length >= maxBlocksPerLevel) return;
  if (!blockCatalog.value.has(type)) return;
  blocks.push(createBlock(type, blockCatalog.value));
  dirty.value = true;
}
function duplicatePageBlock(index: number) {
  if (duplicateBlock(record.value.data.blocks, index, blockCatalog.value)) dirty.value = true;
}
function blockDestinationLabel(path: BlockPath): string {
  if (path.length === 0) return t("Poziom strony");

  let siblings: Block[] | undefined = pageBlocks.value;
  return path.map((index) => {
    const block: Block | undefined = siblings?.[index];
    siblings = block?.children;
    const type = block?.type ?? "";
    return `${index + 1} · ${blockLabel(type, blockCatalog.value)}`;
  }).join(" / ");
}
async function openBlockMove(path: BlockPath, trigger: EventTarget | null) {
  if (busy.value || blockMoveSource.value) return;
  if (trigger instanceof HTMLButtonElement) trigger.focus();
  blockMoveSource.value = path;
  const source = blockMoveSource.value;
  await nextTick();
  if (blockMoveSource.value === source) blockMoveDialog.value?.showModal();
}
function closeBlockMove() {
  blockMoveDialog.value?.close();
  blockMoveSource.value = null;
  blockMoveDestination.value = null;
}
async function confirmBlockMove() {
  const source = blockMoveSource.value;
  const destination = blockMoveDestination.value;
  if (!source || !destination) return;

  const moved = moveBlockTo(pageBlocks.value, source, destination, blockCatalog.value);
  closeBlockMove();
  if (!moved) {
    error.value = t("Nie można przenieść tego bloku.");
    return;
  }
  dirty.value = true;
  await nextTick();
  pageBlocksHeading.value?.focus();
}
function removePageBlock(path: BlockPath) {
  const removed = removeBlock(pageBlocks.value, path, blockCatalog.value);
  if (!removed) return;
  removedBlock.value = removed;
  dirty.value = true;
}
async function undoBlockRemoval() {
  if (!removedBlock.value || busy.value) return;
  const result = restoreBlock(pageBlocks.value, removedBlock.value, blockCatalog.value);
  if (result !== "restored") {
    const errors = {
      missing: "Nie można przywrócić bloku. Układ docelowy jest niedostępny.",
      full: "Nie można przywrócić bloku. Lista bloków jest pełna.",
      depth: "Nie można przywrócić bloku. Układ jest za głęboko zagnieżdżony.",
    } as const;
    error.value = t(errors[result]);
    return;
  }
  removedBlock.value = null;
  dirty.value = true;
  error.value = "";
  message.value = t("Przywrócono blok do szkicu.");
  await nextTick();
  pageBlocksHeading.value?.focus();
}
function moveBlock(i: number, delta: number) {
  const a = record.value.data.blocks,
    j = i + delta;
  if (j < 0 || j >= a.length) return;
  [a[i], a[j]] = [a[j], a[i]];
  dirty.value = true;
}
async function navigate(s: string) {
  if (s === "settings" && section.value === s) return;
  if (hasUnsavedChanges.value && !confirm(t("Odrzucić niezapisane zmiany?"))) return;
  dirty.value = false;
  section.value = s;
  setDraft(null);
  if (s === "media") {
    mediaEdit.value = undefined;
    media.value = null;
    await run(() => loadMedia());
  }
  if (s === "ai") tasks.value = await request("/ai");
  if (s === "releases") releases.value = await request("/releases");
}
async function loadMedia(page = 1) {
  media.value = await request("/media?page=" + page);
}
function changeMediaPage(offset: number) {
  const currentPage = media.value?.current_page;
  if (currentPage !== undefined) return run(() => loadMedia(currentPage + offset));
}
function editMedia(item: MediaItem) {
  mediaEdit.value = { id: item.id, name: item.name, alt: item.alt ?? "" };
}
async function saveMedia() {
  const draft = mediaEdit.value;
  const current = media.value;
  if (!draft || !current) return;

  await run(async () => {
    const saved: MediaItem = await request("/media/" + draft.id, "PATCH", {
      name: draft.name.trim(),
      alt: draft.alt.trim(),
    });
    current.data = current.data.map((item) => (item.id === saved.id ? saved : item));
    mediaEdit.value = undefined;
    message.value = t("Metadane pliku zapisane.");
  });
}
async function upload(e: Event) {
  const input = e.target as HTMLInputElement;
  const f = input.files?.[0];
  if (!f) return;
  await run(async () => {
    const d = new FormData();
    d.append("file", f);
    d.append("visibility", "public");
    const res = await fetch(api + "/media", {
      method: "POST",
      headers: {
        Accept: "application/json",
        "X-CSRF-TOKEN": (
          document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement
        ).content,
      },
      body: d,
    });
    if (!res.ok) throw Error(t("Nie udało się przesłać pliku"));
    input.value = "";
    await loadMedia();
    message.value = t("Plik zapisany.");
  });
}
async function ai() {
  await run(async () => {
    await request("/ai", "POST", {
      provider: provider.value,
      prompt: prompt.value,
    });
    tasks.value = await request("/ai");
    message.value = t("Zadanie dodane do kolejki.");
  });
}
async function release() {
  await run(async () => {
    const created: Release = await request("/releases", "POST", { commit: commit.value });
    releases.value = [created, ...releases.value].slice(0, 30);
  });
}
async function refreshRelease(id: string) {
  if (refreshingReleases.value.has(id)) return;
  refreshingReleases.value.add(id);
  releaseRefreshErrors.value.delete(id);
  try {
    const updated: Release = await request("/releases/" + id + "/refresh", "POST");
    releases.value = releases.value.map((item) => (item.id === id ? updated : item));
  } catch {
    releaseRefreshErrors.value.add(id);
  } finally {
    refreshingReleases.value.delete(id);
  }
}
function clearDeploymentReceipt() {
  deploymentReceiptResult.value = null;
  deploymentReceiptFailed.value = false;
}
async function checkDeploymentReceipt() {
  if (deploymentReceiptLoading.value) return;
  clearDeploymentReceipt();
  deploymentReceiptLoading.value = true;
  const query = new URLSearchParams(deploymentReceiptQuery.value);
  try {
    deploymentReceiptResult.value = await request(`/deployments/receipt?${query}`);
  } catch {
    deploymentReceiptFailed.value = true;
  } finally {
    deploymentReceiptLoading.value = false;
  }
}
function downloadJson(filename: string, data: unknown) {
  const link = document.createElement("a");
  const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: "application/json" }));
  link.href = url;
  link.download = filename;
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 0);
}
async function exportProject() {
  await run(async () => {
    downloadJson("formaku-project.json", await request("/project"));
  });
}
function scheduleAutosave() {
  clearTimeout(autosaveTimer);
  const draft = pendingAutosave;
  if (!draft || record.value !== draft || !dirty.value) {
    pendingAutosave = null;
    return;
  }
  if (!draft.id || busy.value || blockMoveSource.value || draftSaveConflict.value) return;

  autosaveTimer = setTimeout(() => {
    if (record.value === draft && dirty.value && !busy.value && !blockMoveSource.value && !draftSaveConflict.value) {
      void save();
    }
  }, 1800);
}

watch([() => record.value?.data, dirty], () => {
  pendingAutosave = dirty.value ? record.value : null;
  scheduleAutosave();
}, { deep: true });
watch([busy, blockMoveSource, draftSaveConflict], scheduleAutosave);
function dropBlock(i: number) {
  if (draggedBlock.value === null) return;
  const [block] = record.value.data.blocks.splice(draggedBlock.value, 1);
  record.value.data.blocks.splice(i, 0, block);
  draggedBlock.value = null;
  dirty.value = true;
}
function warn(e: BeforeUnloadEvent) {
  if (hasUnsavedChanges.value) e.preventDefault();
}
onMounted(() => {
  run(loadPanel);
  window.addEventListener("beforeunload", warn);
});
onBeforeUnmount(() => {
  clearTimeout(autosaveTimer);
  window.removeEventListener("beforeunload", warn);
});
const panelExtensions = computed(() => {
  const extensions = boot.value?.extensions;
  return extensions?.resources?.length || extensions?.actions?.length ? extensions : null;
});
const title = computed(
  () =>
    ({
      dashboard: t("Twój warsztat"),
      builder: t("Buduj po swojemu"),
      records: selected.value?.name,
      schema: t("Model kolekcji"),
      media: t("Biblioteka mediów"),
      ai: t("Asystent AI"),
      assistant: t("Asystent witryny"),
      extensions: t("Rozszerzenia"),
      releases: t("Wydania aplikacji"),
      settings: t("Ustawienia"),
    })[section.value],
);
</script>
<template>
  <TooltipProvider
    ><div class="min-h-screen lg:grid lg:grid-cols-[244px_1fr]">
      <aside
        class="bg-base-100 border-r border-base-300 p-6 lg:fixed lg:inset-y-0 lg:w-[244px] lg:overflow-y-auto lg:overscroll-contain flex flex-col gap-8"
      >
        <a
          :href="base"
          class="flex items-center gap-3 text-primary"
          aria-label="Formaku"
          ><span class="brand-mark"><i /><i /><i /></span
          ><strong class="text-3xl tracking-[-.06em] text-base-content"
            >formaku<span class="text-secondary">.</span></strong
          ></a
        >
        <div class="border border-base-300 rounded-lg p-3 text-sm">
          <span class="text-xs text-base-content/50 block mb-1"
            >{{ t("TWOJA APLIKACJA") }}</span
          ><span class="flex justify-between"
            >{{ t("Mój projekt") }} <span class="badge badge-sm">Local</span></span
          >
        </div>
        <div class="grid grid-cols-2 bg-base-200 p-1 rounded-lg">
          <button
            class="btn btn-sm"
            :class="mode === 'work' ? 'bg-base-100' : 'btn-ghost'"
            @click="
              mode = 'work';
              navigate('dashboard');
            "
          >
            {{ t("Praca") }}</button
          ><button
            v-if="boot?.abilities?.build"
            class="btn btn-sm"
            :class="mode === 'build' ? 'bg-base-100' : 'btn-ghost'"
            @click="
              mode = 'build';
              navigate('builder');
            "
          >
            {{ t("Buduj") }}
          </button>
        </div>
        <nav class="flex flex-col gap-1 text-sm">
          <button
            class="btn btn-ghost justify-start"
            @click="navigate('dashboard')"
          >
            <LayoutDashboard :size="17" />{{ t("Przegląd") }}</button
          ><template v-if="mode === 'work'"
            ><button
              v-for="c in collections"
              :key="c.id"
              class="btn btn-ghost justify-start"
              @click="run(() => choose(c))"
            >
              <Files :size="17" />{{ c.name }}</button
            ><button
              class="btn btn-ghost justify-start"
              @click="navigate('media')"
            >
              <Image :size="17" />Media
            </button></template
          ><template v-else
            ><button
              class="btn btn-ghost justify-start"
              @click="navigate('builder')"
            >
              <Shapes :size="17" />{{ t("Modele i ekrany") }}</button
            ><button
              class="btn btn-ghost justify-start"
              @click="navigate('ai')"
            >
              <Sparkles :size="17" />{{ t("Asystent AI") }}
            </button></template
          >
          <button
            v-if="panelExtensions"
            class="btn btn-ghost justify-start"
            @click="navigate('extensions')"
          >
            <Layers :size="17" />{{ t("Rozszerzenia") }}
          </button>
          <div class="border-t border-base-300 my-4" />
          <button
            v-if="boot?.abilities?.build"
            class="btn btn-ghost justify-start"
            @click="navigate('assistant')"
          >
            <Plug :size="17" />{{ t("Asystent witryny") }}
          </button>
          <button
            class="btn btn-ghost justify-start"
            @click="navigate('releases')"
          >
            <Box :size="17" />{{ t("Wydania") }}</button
          ><button
            class="btn btn-ghost justify-start"
            @click="navigate('settings')"
          >
            <Settings :size="17" />{{ t("Ustawienia") }}
          </button>
        </nav>
        <div class="mt-auto">
          <a href="/" target="_blank" class="btn btn-ghost justify-start w-full"
            ><Globe :size="16" />{{ t("Otwórz witrynę") }}<ArrowUpRight :size="15"
          /></a>
          <div
            class="mt-4 border-t border-base-300 pt-4 flex gap-3 items-center"
          >
            <span
              class="rounded-full bg-base-200 w-9 h-9 grid place-items-center"
              >{{ boot?.user?.name?.[0] }}</span
            >
            <div class="text-xs">
              <strong class="block">{{ boot?.user?.name }}</strong
              ><span class="text-base-content/50">Formaku · 0.1.0 alpha</span>
            </div>
          </div>
        </div>
      </aside>
      <main class="min-w-0 lg:col-start-2">
        <header
          class="min-h-20 border-b border-base-300 px-6 py-3 lg:px-12 flex flex-wrap items-center justify-between gap-3"
        >
          <span class="text-xs text-base-content/55 flex items-center gap-3"
            >{{ t("Mój projekt") }} <ChevronRight :size="12" />{{
              mode === "work" ? t("Praca") : t("Buduj")
            }}</span
          >
          <div class="flex items-center gap-4">
            <select v-model="locale" class="select select-sm w-auto" :aria-label='t("Język panelu")'>
              <option value="pl" lang="pl">Polski</option>
              <option value="en" lang="en">English</option>
            </select>
            <a href="/portal" class="text-sm flex items-center gap-2">
              {{ t("Portal klienta") }}<ArrowUpRight :size="15" />
            </a>
          </div>
        </header>
        <div class="p-6 lg:p-12 max-w-[1600px] mx-auto">
          <div
            v-if="error"
            role="alert"
            class="p-4 mb-6 border border-error text-error rounded-lg"
          >
            {{ error }}
          </div>
          <div
            v-if="message"
            role="status"
            class="p-4 mb-6 border border-success text-success rounded-lg"
          >
            {{ message }}
          </div>
          <div class="flex items-end justify-between mb-10 gap-4">
            <div>
              <p
                class="mono text-xs tracking-widest text-secondary uppercase mb-3"
              >
                {{
                  section === "dashboard"
                    ? t("PRZESTRZEŃ DO TWORZENIA")
                    : "FORMAKU / " + section
                }}
              </p>
              <h1 class="text-3xl lg:text-4xl tracking-[-.05em] font-medium">
                {{ title
                }}<span v-if="section === 'dashboard'" class="text-primary"
                  >.</span
                >
              </h1>
              <p
                v-if="section === 'dashboard'"
                class="text-base-content/55 mt-3"
              >
                {{ t("Dobre pomysły potrzebują miejsca. Tu nabierają kształtu.") }}
              </p>
            </div>
            <button
              v-if="section === 'builder'"
              class="btn btn-primary"
              @click="newSchema"
            >
              <Plus :size="16" />{{ t("Nowa kolekcja") }}
            </button>
          </div>
          <template v-if="section === 'dashboard'"
            ><div class="grid md:grid-cols-3 gap-5 mb-9">
              <div
                v-for="(value, key) in boot?.counts"
                :key="key"
                class="bg-base-100 p-6 border border-base-300 rounded-xl"
              >
                <p class="text-sm text-base-content/55">
                  {{
                    {
                      records: t("Wszystkie rekordy"),
                      published: t("Opublikowane treści"),
                      media: t("Pliki w bibliotece"),
                    }[key as string]
                  }}
                </p>
                <div class="text-4xl tracking-tight mt-5">
                  {{ value
                  }}<span class="text-xs text-base-content/40 ml-3"
                    >{{ t("w Twojej aplikacji") }}</span
                  >
                </div>
              </div>
            </div>
            <div class="grid xl:grid-cols-[1.5fr_1fr] gap-6">
              <section
                class="bg-primary text-primary-content rounded-xl p-8 lg:p-10 relative overflow-hidden"
              >
                <span class="mono text-xs opacity-60"
                  >{{ t("01 — OD CZEGO ZACZNIEMY?") }}</span
                >
                <h2 class="text-3xl leading-tight tracking-tight mt-6 max-w-sm">
                  {{ t("Twoja aplikacja.") }}<br />{{ t("Twój sposób tworzenia.") }}
                </h2>
                <p class="text-sm opacity-75 mt-4 max-w-sm leading-6">
                  {{ t("Zdefiniuj dane, ułóż stronę i udostępnij ją światu. Formaku\n                  zadba o panel administracyjny.") }}
                </p>
                <button
                  class="btn mt-8 bg-base-100 text-base-content"
                  @click="
                    mode = 'build';
                    navigate('builder');
                  "
                >
                  {{ t("Otwórz builder") }}<ArrowRight :size="16" /></button
                ><Shapes
                  class="absolute -right-8 -bottom-8 opacity-10"
                  :size="220"
                />
              </section>
              <section
                class="bg-base-100 border border-base-300 rounded-xl p-8"
              >
                <h2 class="text-lg tracking-tight">
                  {{ t("Wszystko na swoim miejscu") }}
                </h2>
                <div
                  v-for="(label, i) in [
                    t('Przygotuj strukturę danych'),
                    t('Dodaj treści i strony'),
                    t('Opublikuj własną aplikację'),
                  ]"
                  :key="label"
                  class="flex gap-4 mt-7"
                >
                  <span class="mono text-sm text-secondary">0{{ i + 1 }}</span>
                  <div>
                    <strong class="text-sm font-medium">{{ label }}</strong>
                    <p class="text-xs text-base-content/50 mt-1">
                      {{
                        [
                          t("Kolekcje, relacje i gotowe formularze."),
                          t("Edytor blokowy i szkice."),
                          t("Jeden obraz. Twój serwer."),
                        ][i]
                      }}
                    </p>
                  </div>
                </div>
              </section>
            </div>
            <section class="mt-10">
              <div class="flex justify-between items-center mb-5">
                <h2 class="text-xl tracking-tight">{{ t("Twoje kolekcje") }}</h2>
                <button
                  class="btn btn-sm btn-ghost"
                  @click="
                    mode = 'build';
                    navigate('builder');
                  "
                >
                  {{ t("Zarządzaj") }}<ArrowUpRight :size="14" />
                </button>
              </div>
              <div
                class="bg-base-100 border border-base-300 rounded-xl divide-y divide-base-300"
              >
                <button
                  v-for="c in collections"
                  :key="c.id"
                  class="w-full p-5 flex items-center gap-5 text-left hover:bg-base-200"
                  @click="run(() => choose(c))"
                >
                  <span
                    class="w-10 h-10 bg-base-200 grid place-items-center rounded-lg"
                    ><Layers :size="18" /></span
                  ><span class="flex-1"
                    ><strong class="block text-sm font-medium">{{
                      c.name
                    }}</strong
                    ><span class="mono text-xs text-base-content/45">{{
                      c.key
                    }}</span></span
                  ><span class="badge badge-outline">{{
                    c.kind === "content" ? t("Treści") : t("Dane aplikacji")
                  }}</span
                  ><ChevronRight :size="16" />
                </button>
                <p v-if="!collections.length" class="p-8 text-base-content/50">
                  {{ t("Dodaj pierwszą kolekcję w builderze.") }}
                </p>
              </div>
            </section></template
          >
          <section
            v-if="section === 'builder'"
            class="grid md:grid-cols-2 xl:grid-cols-3 gap-5"
          >
            <article
              v-for="c in collections"
              :key="c.id"
              class="bg-base-100 border border-base-300 rounded-xl p-6"
            >
              <Layers :size="24" class="text-primary" />
              <h2 class="text-xl mt-6">{{ c.name }}</h2>
              <p class="mono text-xs text-base-content/50 mt-2">
                {{ c.key }} · v{{ c.version }} ·
                {{ c.definition.fields.length }} {{ t("pól") }}
              </p>
              <div class="flex gap-2 mt-6">
                <button
                  class="btn btn-sm"
                  @click="
                    schema = clone(c);
                    section = 'schema';
                  "
                >
                  {{ t("Edytuj model") }}</button
                ><button
                  class="btn btn-sm btn-ghost"
                  @click="run(() => choose(c))"
                >
                  {{ t("Otwórz dane") }}<ArrowUpRight :size="14" />
                </button>
              </div>
            </article>
          </section>
          <form
            v-if="section === 'schema' && schema"
            @submit.prevent="saveSchema"
            class="space-y-6"
          >
            <div class="grid md:grid-cols-3 gap-4">
              <label
                ><span class="field-label">{{ t("Nazwa") }}</span
                ><input
                  class="input w-full"
                  v-model="schema.name"
                  required /></label
              ><label
                ><span class="field-label">{{ t("Identyfikator") }}</span
                ><input
                  class="input w-full"
                  v-model="schema.key"
                  pattern="[a-z][a-z0-9_]+"
                  required
                  :disabled="!!schema.id" /></label
              ><label
                ><span class="field-label">{{ t("Rodzaj") }}</span
                ><select
                  class="select w-full"
                  @change="contentTemplate"
                  v-model="schema.kind"
                  :disabled="!!schema.id"
                >
                  <option value="data">{{ t("Dane aplikacji") }}</option>
                  <option value="content">{{ t("Treść publikowana") }}</option>
                </select></label
              >
            </div>
            <label v-if="schema.kind === 'content'" class="flex items-center gap-3">
              <input type="checkbox" class="checkbox" v-model="schema.definition.public" />
              {{ t("Publiczna kolekcja treści") }}
            </label>
            <p v-if="privatePublicSlug" class="alert alert-warning" role="alert">
              {{ t("Pole slug jest częścią publicznego adresu. Wyłącz jego prywatność albo ustaw kolekcję jako niepubliczną.") }}
            </p>
            <div class="bg-base-100 border border-base-300 rounded-xl p-6">
              <h2 class="text-lg mb-5">{{ t("Pola formularza") }}</h2>
              <div
                v-for="(f, i) in schema.definition.fields"
                :key="i"
                class="grid md:grid-cols-[1fr_1fr_1fr_auto] gap-3 mb-4"
              >
                <label
                  ><span class="field-label">{{ t("Etykieta") }}</span
                  ><input
                    class="input w-full"
                    v-model="f.label"
                    required /></label
                ><label
                  ><span class="field-label">{{ t("Klucz") }}</span
                  ><input
                    class="input w-full"
                    v-model="f.key"
                    required /></label
                ><label
                  ><span class="field-label">{{ t("Typ") }}</span
                  ><select class="select w-full" v-model="f.type">
                    <option v-if="!fieldTypes.has(f.type)" :value="f.type" disabled>
                      {{ f.type }}
                    </option>
                    <option v-for="type in fieldTypes.values()" :key="type.key" :value="type.key">
                      {{ type.label }}
                    </option>
                  </select></label
                >
                <div class="flex items-end gap-2 pb-2">
                  <label class="text-xs"
                    ><input type="checkbox" v-model="f.required" />
                    {{ t("Wymagane") }}</label
                  ><label class="text-xs"
                    ><input type="checkbox" v-model="f.private" />
                    {{ t("Prywatne") }}</label
                  ><button
                    class="btn btn-sm btn-ghost"
                    type="button"
                    @click="schema.definition.fields.splice(i, 1)"
                    :aria-label='t("Usuń pole")'
                  >
                    <Trash2 :size="16" />
                  </button>
                </div>
              </div>
              <div
                v-for="f in schema.definition.fields"
                :key="'extra-' + f.key"
                class="mb-4"
              >
                <label v-if="fieldTypes.get(f.type)?.input === 'select'"
                  ><span class="field-label"
                    >{{ t("Opcje:") }} {{ f.label }} {{ t("(rozdzielone przecinkami)") }}</span
                  ><input
                    class="input w-full"
                    :value="f.options?.join(', ')"
                    @change="
                      f.options = ($event.target as HTMLInputElement).value
                        .split(',')
                        .map((x: string) => x.trim())
                        .filter(Boolean)
                    " /></label
                ><label v-if="f.type === 'relation'"
                  ><span class="field-label"
                    >{{ t("Kolekcja docelowa:") }} {{ f.label }}</span
                  ><select class="select w-full" v-model="f.target">
                    <option v-for="c in collections" :key="c.id" :value="c.id">
                      {{ c.name }}
                    </option>
                  </select></label
                >
              </div>
              <button
                class="btn btn-sm"
                type="button"
                @click="
                  schema.definition.fields.push({
                    key: 'field_' + schema.definition.fields.length,
                    label: t('Nowe pole'),
                    type: 'text',
                  })
                "
              >
                <Plus :size="15" />{{ t("Dodaj pole") }}
              </button>
            </div>
            <fieldset v-if="portalSchema" class="fieldset bg-base-100 border border-base-300 rounded-xl p-6">
              <legend class="fieldset-legend text-lg">{{ t("Pola portalu klienta") }}</legend>
              <p class="text-sm text-base-content/70">
                {{ t("Wybierz dodatkowe pola formularza zgłoszenia. Formularz zawsze zawiera temat i opis.") }}
              </p>
              <p v-if="!portalModelFields.length" class="text-sm">
                {{ t("Dodaj pole tekstowe, liczbowe, datę, pole Tak/Nie lub listę z opcjami, aby je tu wybrać.") }}
              </p>
              <label v-for="field in portalModelFields" :key="field.key" class="flex items-center gap-2 text-sm py-1">
                <input v-model="portalFieldSelection" type="checkbox" class="checkbox checkbox-sm" :value="field.key" />
                <span class="min-w-0 wrap-anywhere">{{ field.label }} ({{ field.key }})</span>
              </label>
              <div class="mt-3">
                <h3 id="portal-field-order-heading" class="text-sm font-medium">{{ t("Kolejność pól w formularzu") }}</h3>
                <ol class="list mt-2 border border-base-300 rounded-box" aria-labelledby="portal-field-order-heading">
                  <li class="list-row text-sm">{{ t("Temat") }}</li>
                  <li class="list-row text-sm">{{ t("Opis") }}</li>
                  <li v-for="(field, index) in portalOrderedFields" :key="field.key" class="list-row items-center text-sm">
                    <span class="list-col-grow min-w-0 wrap-anywhere">{{ field.label }}</span>
                    <div class="flex flex-wrap gap-2">
                      <button
                        type="button"
                        class="btn btn-sm"
                        :aria-label="t('Przesuń w górę pole {0}', field.label)"
                        :disabled="index === 0"
                        @click="movePortalField(index, -1, $event)"
                      >{{ t("Przesuń w górę") }}</button>
                      <button
                        type="button"
                        class="btn btn-sm"
                        :aria-label="t('Przesuń w dół pole {0}', field.label)"
                        :disabled="index === portalOrderedFields.length - 1"
                        @click="movePortalField(index, 1, $event)"
                      >{{ t("Przesuń w dół") }}</button>
                    </div>
                  </li>
                </ol>
                <p aria-live="polite" aria-atomic="true" class="sr-only">{{ portalFieldOrderAnnouncement }}</p>
              </div>
              <label class="flex items-center gap-2 border-t border-base-300 mt-2 pt-3 text-sm">
                <input v-model="schema.definition.portal_attachments" type="checkbox" class="checkbox checkbox-sm" />
                <span>{{ t("Załączniki do zgłoszeń") }}</span>
              </label>
              <div v-if="schema.definition.portal_attachments" class="mt-3 grid gap-4 sm:grid-cols-2">
                <label>
                  <span class="field-label">{{ t("Maksymalna liczba plików") }}</span>
                  <select
                    class="select w-full"
                    :value="portalAttachmentLimits.max_files ?? 3"
                    @change="setPortalAttachmentLimit('max_files', Number(($event.target as HTMLSelectElement).value))"
                  >
                    <option v-for="limit in 3" :key="limit" :value="limit">{{ limit }}</option>
                  </select>
                </label>
                <label>
                  <span class="field-label">{{ t("Maksymalny rozmiar pliku (MiB)") }}</span>
                  <select
                    class="select w-full"
                    :value="portalAttachmentLimits.max_mib ?? 5"
                    @change="setPortalAttachmentLimit('max_mib', Number(($event.target as HTMLSelectElement).value))"
                  >
                    <option v-for="limit in 5" :key="limit" :value="limit">{{ limit }}</option>
                  </select>
                </label>
                <fieldset class="fieldset sm:col-span-2" aria-describedby="portal-attachment-types-hint">
                  <legend class="fieldset-legend">{{ t("Dozwolone typy plików") }}</legend>
                  <div class="flex flex-wrap gap-4">
                    <label v-for="type in supportedPortalAttachmentTypes" :key="type" class="flex items-center gap-2 text-sm">
                      <input
                        v-model="portalAttachmentTypes"
                        :value="type"
                        :disabled="portalAttachmentTypes.length === 1 && portalAttachmentTypes.includes(type)"
                        type="checkbox"
                        class="checkbox checkbox-sm"
                      />
                      {{ type.toUpperCase() }}
                    </label>
                  </div>
                  <p id="portal-attachment-types-hint" class="text-sm text-base-content/70">
                    {{ t("Wybierz co najmniej jeden typ pliku.") }}
                  </p>
                </fieldset>
              </div>
            </fieldset>
            <fieldset
              v-if="publicContentSchema"
              class="fieldset bg-base-100 border border-base-300 rounded-xl p-6"
            >
              <legend class="fieldset-legend text-lg">{{ t("Metadane SEO") }}</legend>
              <p class="text-sm text-base-content/70">
                {{ t("Przypisz pola tekstowe używane w metadanych opublikowanych stron.") }}
              </p>
              <div class="grid md:grid-cols-2 gap-4">
                <div v-for="(label, target) in seoLabels" :key="target">
                  <label class="field-label" :for="'seo-' + target">{{
                    label
                  }}</label>
                  <select
                    :id="'seo-' + target"
                    class="select w-full"
                    :value="schema.definition.seo?.[target] ?? ''"
                    @change="
                      updateSeoField(
                        target,
                        ($event.target as HTMLSelectElement).value,
                      )
                    "
                  >
                    <option value="">{{ t("Bez przypisania") }}</option>
                    <option
                      v-for="field in seoFields"
                      :key="field.key"
                      :value="field.key"
                    >
                      {{ field.label }} ({{ field.key }})
                    </option>
                  </select>
                </div>
              </div>
            </fieldset>
            <div
              v-if="schema.kind === 'data'"
              class="bg-base-100 border border-base-300 rounded-xl p-6"
            >
              <label
                ><span class="field-label"
                  >{{ t("Stany procesu (rozdzielone przecinkami)") }}</span
                ><input
                  class="input w-full"
                  :value="schema.definition.states?.join(', ')"
                  @change="
                    schema.definition.states = (
                      $event.target as HTMLInputElement
                    ).value
                      .split(',')
                      .map((v: string) => v.trim())
                      .filter(Boolean)
                  "
              /></label>
              <div
                v-for="(t, i) in schema.definition.transitions"
                :key="i"
                class="flex gap-3 mt-4"
              >
                <select class="select" v-model="t.from">
                  <option v-for="v in schema.definition.states" :key="v">
                    {{ v }}
                  </option></select
                ><span>→</span
                ><select class="select" v-model="t.to">
                  <option v-for="v in schema.definition.states" :key="v">
                    {{ v }}
                  </option>
                </select>
              </div>
              <button
                type="button"
                class="btn btn-sm mt-4"
                @click="
                  schema.definition.transitions ??= [];
                  schema.definition.transitions.push({
                    from: schema.definition.states?.[0],
                    to: schema.definition.states?.[1],
                  });
                "
              >
                {{ t("Dodaj przejście") }}
              </button>
            </div>
            <section class="bg-base-100 border border-base-300 rounded-xl p-6">
              <h2 class="text-lg mb-4">{{ t("Dostęp do kolekcji") }}</h2>
              <template v-if="schema.definition.access === undefined">
                <p class="text-sm mb-3">{{ t("Domyślny dostęp pozwala użytkownikom panelu wyświetlać i edytować wszystkie rekordy tej kolekcji.") }}</p>
                <button type="button" class="btn btn-sm" @click="schema.definition.access = {}">
                  {{ t("Skonfiguruj dostęp ról") }}
                </button>
              </template>
              <template v-else>
                <p v-if="collectionRoleRules.length" class="text-sm mb-3">
                  {{ t("Użytkownicy z uprawnieniem Buduj zachowują pełny dostęp. Pozostali potrzebują reguły zezwalającej na dostęp.") }}
                </p>
                <p v-else class="text-sm mb-3">
                  {{ t("Tylko użytkownicy z uprawnieniem Buduj mają dostęp do tej kolekcji.") }}
                </p>
                <fieldset
                  v-for="[key, rule] in collectionRoleRules"
                  :key="key"
                  class="fieldset border border-base-300 rounded-box p-3 mb-3"
                >
                  <legend class="fieldset-legend">{{ roleDisplayName(key, configuredRoleLabels.get(key)) }}</legend>
                  <div class="flex flex-wrap items-end gap-4">
                    <label class="block">
                      <span class="field-label">{{ t("Widoczne rekordy") }}</span>
                      <select v-model="rule.scope" class="select select-sm">
                        <option value="all">{{ t("Wszystkie") }}</option>
                        <option value="own">{{ t("Własne") }}</option>
                        <option value="assigned">{{ t("Przypisane") }}</option>
                        <option value="none">{{ t("Brak dostępu") }}</option>
                      </select>
                    </label>
                    <label class="flex items-center gap-2 min-h-8 text-sm">
                      <input type="checkbox" class="checkbox checkbox-sm" v-model="rule.write" />
                      {{ t("Edycja rekordów") }}
                    </label>
                    <button
                      type="button" class="btn btn-ghost btn-sm"
                      :aria-label="t('Usuń regułę roli {0}', key)"
                      @click="removeCollectionRole(key)"
                    >
                      <Trash2 :size="16" aria-hidden="true" />{{ t("Usuń regułę") }}
                    </button>
                  </div>
                </fieldset>
                <RolePicker
                  :roles="configuredRoles"
                  :selected="collectionRoleKeys"
                  allow-custom
                  @add="addCollectionRole"
                />
                <button type="button" class="btn btn-outline btn-sm mt-4" @click="delete schema.definition.access">
                  {{ t("Przywróć domyślny dostęp") }}
                </button>
                <p class="text-sm mt-2">
                  {{ t("Po przywróceniu i zapisaniu domyślnego dostępu użytkownicy panelu będą mogli wyświetlać i edytować wszystkie rekordy tej kolekcji.") }}
                </p>
              </template>
            </section>
            <details class="collapse collapse-arrow border border-base-300 bg-base-100">
              <summary class="collapse-title">{{ t("Uprawnienia pól") }}</summary>
              <div class="collapse-content space-y-4">
                <p class="text-sm">{{ t("Zaznacz uprawnienie, aby ograniczyć je do podanych ról. Pusta lista daje dostęp tylko osobom z uprawnieniem Buduj.") }}</p>
                <p v-if="invalidFieldRead" class="alert alert-warning" role="alert">
                  {{ t("Odczyt można ograniczyć tylko dla pól prywatnych innych niż slug.") }}
                </p>
                <section v-for="field in schema.definition.fields" :key="field.key" class="space-y-2 border-t border-base-300 pt-3">
                  <h3 class="font-medium">{{ field.label }}</h3>
                  <div v-for="ability in (['read', 'write'] as const)" :key="ability" class="space-y-2">
                    <label class="flex items-center gap-2">
                      <input
                        type="checkbox" class="checkbox checkbox-sm"
                        :checked="field.access?.[ability] !== undefined"
                        :disabled="ability === 'read' && !canRestrictFieldRead(field) && field.access?.read === undefined"
                        @change="toggleFieldAccess(field, ability, ($event.target as HTMLInputElement).checked)"
                      />
                      {{ t(ability === 'read' ? 'Ogranicz odczyt' : 'Ogranicz zapis') }}
                    </label>
                    <template v-if="field.access?.[ability] !== undefined">
                      <label class="block">
                        <span class="field-label">{{ t(ability === 'read' ? 'Role z odczytem' : 'Role z zapisem') }}</span>
                        <input
                          class="input w-full"
                          :value="field.access[ability].join(', ')"
                          @change="updateFieldRoles(field, ability, ($event.target as HTMLInputElement).value)"
                        />
                      </label>
                      <RolePicker
                        v-if="configuredRoles.length"
                        :roles="configuredRoles"
                        :selected="field.access[ability]"
                        @add="addFieldRole(field, ability, $event)"
                      />
                    </template>
                  </div>
                </section>
              </div>
            </details>
            <button class="btn btn-primary" :disabled="busy || privatePublicSlug || invalidFieldRead">
              {{ t("Aktywuj konfigurację") }}<Check :size="16" />
            </button>
          </form>
          <section v-if="section === 'records' && selected">
            <div v-if="!record">
              <form
                class="mb-3 grid grid-cols-[minmax(0,1fr)_auto] gap-2 sm:max-w-md"
                role="search"
                @submit.prevent="run(() => loadRecords(1, { ...recordsFilter, label: recordsLabelInput.trim() }))"
              >
                <input
                  v-model="recordsLabelInput"
                  type="search"
                  class="input w-full"
                  maxlength="200"
                  :aria-label='t("Szukaj po tytule lub nazwie")'
                  :placeholder='t("Szukaj po tytule lub nazwie")'
                  :disabled="busy || !recordsPage"
                />
                <button type="submit" class="btn" :disabled="busy || !recordsPage">{{ t("Szukaj") }}</button>
              </form>
              <div class="mb-4 flex flex-wrap justify-between gap-2">
                <select
                  v-if="selected.kind === 'content'"
                  class="select w-full sm:w-auto"
                  :aria-label='t("Filtr publikacji")'
                  :value="recordsFilter.publication"
                  :disabled="busy || !recordsPage"
                  @change="changeRecordsFilter('publication', $event)"
                >
                  <option value="">{{ t("Wszystkie stany publikacji") }}</option>
                  <option value="unpublished">{{ t("Nieopublikowane") }}</option>
                  <option value="published">{{ t("Opublikowane") }}</option>
                  <option value="changed">{{ t("Z nowszym szkicem") }}</option>
                  <option value="scheduled">{{ t("Zaplanowane") }}</option>
                </select>
                <select
                  class="select w-full sm:w-auto"
                  :aria-label='t("Filtr stanu")'
                  :value="recordsFilter.state"
                  :disabled="busy || !recordsPage"
                  @change="changeRecordsFilter('state', $event)"
                >
                  <option value="">{{ t("Wszystkie stany") }}</option>
                  <option v-for="state in recordsStates" :key="state" :value="state">{{ state }}</option>
                </select>
                <button class="btn btn-primary ml-auto" :disabled="busy" @click="fresh">
                  <Plus :size="16" />{{ t("Dodaj rekord") }}
                </button>
              </div>
              <div
                class="overflow-x-auto bg-base-100 border border-base-300 rounded-xl"
              >
                <table class="table">
                  <thead>
                    <tr>
                      <th>{{ t("Tytuł") }}</th>
                      <th>{{ t("Stan") }}</th>
                      <th v-if="selected.kind === 'content'">{{ t("Termin publikacji") }}</th>
                      <th>{{ t("Wersja") }}</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="r in recordsPage?.data" :key="r.id">
                      <td>{{ r.data.title ?? r.data.name ?? "#" + r.id }}</td>
                      <td>
                        <span class="badge badge-outline">{{ r.state }}</span>
                        <template v-if="selected.kind === 'content'">
                          <span class="badge badge-outline ms-1">{{ r.published_id ? t("Opublikowano") : t("Szkic") }}</span>
                          <span
                            v-if="r.published_id && r.draft_id && r.draft_id !== r.published_id"
                            class="badge badge-outline ms-1"
                          >{{ t("Nowszy szkic") }}</span>
                        </template>
                      </td>
                      <td v-if="selected.kind === 'content'">
                        <time v-if="r.publish_at" :datetime="r.publish_at">{{ formatPublicationTime(r.publish_at) }}</time>
                      </td>
                      <td class="mono">{{ r.version }}</td>
                      <td>
                        <button class="btn btn-sm" @click="edit(r)">
                          {{ t("Edytuj") }}
                        </button>
                      </td>
                    </tr>
                    <tr v-if="recordsPage && !recordsPage.data.length">
                      <td
                        :colspan="selected.kind === 'content' ? 5 : 4"
                        class="p-10 text-center text-base-content/50"
                      >
                        <template v-if="recordsFilter.publication === 'scheduled' && !recordsFilter.state && !recordsFilter.label">{{ t("Brak zaplanowanych publikacji.") }}</template>
                        <template v-else-if="recordsFilter.publication || recordsFilter.state || recordsFilter.label">{{ t("Brak rekordów pasujących do filtrów.") }}</template>
                        <template v-else>{{ t("Tu pojawią się pierwsze rekordy.") }}</template>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div v-if="recordsPage && recordsPage.last_page > 1" class="mt-3 flex flex-wrap items-center gap-2">
                <button
                  type="button"
                  class="btn btn-sm"
                  :disabled="busy || recordsPage.current_page === 1"
                  @click="run(() => loadRecords(recordsPage!.current_page - 1))"
                >{{ t("Poprzednia strona") }}</button>
                <span class="text-sm" aria-live="polite">{{ recordsPage.current_page }} / {{ recordsPage.last_page }}</span>
                <button
                  type="button"
                  class="btn btn-sm"
                  :disabled="busy || recordsPage.current_page >= recordsPage.last_page"
                  @click="run(() => loadRecords(recordsPage!.current_page + 1))"
                >{{ t("Następna strona") }}</button>
              </div>
            </div>
            <div v-else>
              <div class="flex flex-wrap justify-between gap-3 mb-6">
                <button class="btn" :disabled="busy" @click="run(() => choose(selected!, true))">
                  {{ t("Wróć do listy") }}
                </button>
                <div class="flex flex-wrap gap-2 items-center">
                  <span class="text-xs text-base-content/50">{{
                    hasUnsavedChanges ? t("Niezapisane zmiany") : t("Zapisano")
                  }}</span
                  ><button
                    v-if="previewUrl"
                    type="button"
                    class="btn"
                    :aria-expanded="previewOpen"
                    aria-controls="record-preview"
                    @click="previewOpen = !previewOpen"
                  >
                    <Eye :size="16" />
                    {{ previewOpen ? t("Ukryj podgląd") : t("Pokaż podgląd") }}
                  </button><a
                    v-if="previewUrl"
                    class="btn"
                    :href="previewUrl"
                    target="_blank"
                    rel="noopener"
                    >{{ t("Podgląd w nowej karcie") }}<ArrowUpRight :size="16" /></a
                  ><a
                    v-if="recordLink"
                    class="btn"
                    :href="recordLink"
                    target="_blank"
                    rel="noopener"
                  >{{ t("Otwórz rekord w nowej karcie") }}<ArrowUpRight :size="16" /></a
                  ><button class="btn" @click="save" :disabled="busy || draftSaveConflict">
                    <Save :size="16" />{{ t("Zapisz szkic") }}</button
                  ><button
                    v-if="
                      record.id &&
                      selected.kind === 'content' &&
                      boot?.abilities?.publish
                    "
                    class="btn btn-primary"
                    :disabled="dirty || busy || draftSaveConflict"
                    @click="publish"
                  >
                    {{ t("Opublikuj treść") }}
                  </button>
                  <button
                    v-if="record.published_id && selected.kind === 'content' && boot?.abilities?.publish"
                    type="button"
                    class="btn btn-outline btn-error"
                    :disabled="hasUnsavedChanges || busy || draftSaveConflict"
                    @click="unpublish"
                  >
                    {{ t("Wycofaj publikację") }}
                  </button>
                </div>
              </div>
              <section v-if="draftSaveConflict" class="alert alert-warning alert-vertical sm:alert-horizontal mb-6">
                <div role="alert">
                  <p class="font-semibold">{{ t("Szkic zmienił się na serwerze.") }}</p>
                  <p class="text-sm">{{ t("Twoje zmiany są w edytorze. Aby wznowić zapisywanie, wczytaj aktualny szkic.") }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                  <button type="button" class="btn btn-sm" @click="downloadDraftEdits">
                    {{ t("Pobierz moje zmiany") }}
                  </button>
                  <button type="button" class="btn btn-sm" :disabled="busy" @click="loadCurrentDraft">
                    {{ t("Wczytaj aktualny szkic") }}
                  </button>
                </div>
              </section>
              <section
                v-if="previewOpen && previewUrl"
                id="record-preview"
                aria-labelledby="record-preview-heading"
                class="card card-border mb-6 bg-base-100"
              >
                <div class="card-body min-w-0">
                  <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                      <h2 id="record-preview-heading" class="card-title">
                        {{ t("Podgląd strony") }}
                      </h2>
                      <p class="text-sm text-base-content/70">
                        {{ t("Podgląd pokazuje ostatni zapisany szkic. Zapisz zmiany, aby go odświeżyć.") }}
                      </p>
                    </div>
                    <label>
                      <span class="field-label">{{ t("Szerokość podglądu") }}</span>
                      <select class="select select-sm w-auto" v-model="previewViewport">
                        <option value="desktop">{{ t("Komputer · 1280 px") }}</option>
                        <option value="tablet">Tablet · 768 px</option>
                        <option value="mobile">{{ t("Telefon · 390 px") }}</option>
                      </select>
                    </label>
                  </div>
                  <div
                    class="overflow-x-auto rounded-box border border-base-300 bg-base-200"
                    role="region"
                    :aria-label='t("Obszar podglądu strony")'
                    tabindex="0"
                  >
                    <iframe
                      :src="previewUrl"
                      :title='t("Podgląd zapisanego szkicu")'
                      sandbox="allow-same-origin"
                      referrerpolicy="no-referrer"
                      :class="previewWidths[previewViewport]"
                      class="mx-auto block h-[70vh] max-w-none border-0 bg-base-100"
                    ></iframe>
                  </div>
                </div>
              </section>
              <PageTranslations
                v-if="translationsRecordId"
                :key="translationsRecordId"
                :record-id="translationsRecordId"
                :request="request"
                @dirty="translationDirty = $event"
              />
              <section
                v-if="supportsPageTemplates && (!record.id || boot?.abilities?.build)"
                aria-labelledby="page-templates-heading"
                class="mb-6 rounded-box border border-base-300 bg-base-100 p-4"
              >
                <h2 id="page-templates-heading" class="mb-3 text-lg">{{ t("Szablony stron") }}</h2>
                <form
                  v-if="record.id"
                  class="flex flex-col gap-2 sm:flex-row sm:items-end"
                  @submit.prevent="savePageTemplate"
                >
                  <label>
                    <span class="field-label">{{ t("Nazwa szablonu") }}</span>
                    <input v-model="templateName" class="input w-full" maxlength="100" required />
                  </label>
                  <button
                    type="submit"
                    class="btn"
                    :disabled="busy || dirty || !record.data.blocks?.length || !templateName.trim()"
                  >{{ t("Zapisz jako szablon") }}</button>
                  <p v-if="dirty" class="text-sm">{{ t("Zapisz szkic, aby skopiować jego sekcje.") }}</p>
                </form>
                <button
                  v-else-if="!templatePage"
                  type="button"
                  class="btn"
                  :disabled="busy"
                  @click="loadPageTemplates()"
                >{{ t("Pokaż szablony kolekcji") }}</button>
                <template v-else>
                  <p v-if="!templatePage.data.length">{{ t("W tej kolekcji nie ma szablonów.") }}</p>
                  <div v-else class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end">
                    <label>
                      <span class="field-label">{{ t("Szablon kolekcji") }}</span>
                      <select
                        v-model="templateId"
                        class="select w-full"
                        :disabled="busy"
                        @change="templateToDelete = null"
                      >
                        <option :value="null">{{ t("Wybierz szablon") }}</option>
                        <option v-for="template in templatePage.data" :key="template.id" :value="template.id">
                          {{ template.name }}
                        </option>
                      </select>
                    </label>
                    <button
                      type="button"
                      class="btn"
                      :disabled="busy || !templateId || !!record.data.blocks?.length"
                      @click="applyPageTemplate"
                    >{{ t("Wstaw sekcje szablonu") }}</button>
                    <button
                      v-if="boot?.abilities?.build && !templateToDelete"
                      type="button"
                      class="btn"
                      :disabled="busy || !templateId"
                      @click="templateToDelete = templateId"
                    >{{ t("Usuń szablon") }}</button>
                    <template v-if="templateToDelete">
                      <button type="button" class="btn" :disabled="busy" @click="deletePageTemplate">
                        {{ t("Potwierdź usunięcie") }}
                      </button>
                      <button type="button" class="btn" :disabled="busy" @click="templateToDelete = null">
                        {{ t("Anuluj") }}
                      </button>
                    </template>
                  </div>
                  <p v-if="record.data.blocks?.length" class="mt-2 text-sm">
                    {{ t("Szablon można wstawić do szkicu bez sekcji.") }}
                  </p>
                  <div v-if="templatePage.last_page > 1" class="mt-3 flex flex-wrap items-center gap-2">
                    <button type="button" class="btn btn-sm" :disabled="busy || templatePage.current_page === 1"
                      @click="loadPageTemplates(templatePage.current_page - 1)">{{ t("Poprzednia strona") }}</button>
                    <span class="text-sm">{{ templatePage.current_page }} / {{ templatePage.last_page }}</span>
                    <button type="button" class="btn btn-sm" :disabled="busy || templatePage.current_page === templatePage.last_page"
                      @click="loadPageTemplates(templatePage.current_page + 1)">{{ t("Następna strona") }}</button>
                  </div>
                </template>
              </section>
              <div
                class="space-y-5 bg-base-100 border border-base-300 rounded-xl p-6"
                @input="dirty = true"
              >
                <template v-for="f in selected.definition.fields" :key="f.key"
                  ><div v-if="f.key !== 'blocks' && (f.writable === false || !fieldTypes.has(f.type))">
                    <span class="field-label">{{ f.label }} · {{ t("Tylko odczyt") }}</span>
                    <p v-if="f.writable !== false" class="text-sm">{{ t("Typ pola {0} nie ma kontrolki w panelu.", f.type) }}</p>
                    <p class="whitespace-pre-wrap wrap-anywhere">{{ readonlyFieldValue(record.data[f.key]) }}</p>
                  </div><RelationPicker
                    v-else-if="f.type === 'relation' && f.key !== 'blocks'"
                    v-model="record.data[f.key]"
                    :target="f.target"
                    :label="f.label"
                    :private-field="f.private"
                    @update:model-value="dirty = true"
                  /><div v-else-if="f.type === 'media' && f.key !== 'blocks'">
                    <span :id="'media-field-' + f.key" class="field-label">
                      {{ f.label }}<span v-if="f.private" class="text-secondary"> {{ t("· wewnętrzne") }}</span>
                    </span>
                    <MediaPicker
                      mode="file"
                      :description-id="'media-field-' + f.key"
                      :selected-id="record.data[f.key]"
                      @select="record.data[f.key] = $event ?? null; dirty = true"
                    />
                  </div><label v-else-if="f.key !== 'blocks'"
                    ><span class="field-label"
                      >{{ f.label
                      }}<span v-if="f.private" class="text-secondary">
                        {{ t("· wewnętrzne") }}</span
                      ></span
                    ><RichText
                      v-if="fieldTypes.get(f.type)?.input === 'richtext'"
                      v-model="record.data[f.key]"
                      @update:modelValue="dirty = true" /><input
                      v-else-if="fieldTypes.get(f.type)?.input === 'boolean'"
                      type="checkbox"
                      v-model="record.data[f.key]" /><select
                      v-else-if="fieldTypes.get(f.type)?.input === 'select'"
                      class="select w-full"
                      v-model="record.data[f.key]"
                    >
                      <option v-for="o in f.options" :key="o">
                        {{ o }}
                      </option></select
                    ><input
                      v-else
                      class="input w-full"
                      :type="
                        fieldTypes.get(f.type)?.input === 'number'
                          ? 'number'
                          : fieldTypes.get(f.type)?.input === 'date'
                            ? 'date'
                            : 'text'
                      "
                      v-model="record.data[f.key]"
                      :required="f.required" /></label
                ></template>
                <p v-if="selected.definition.fields.some((f) => f.key === 'blocks' && f.writable === false)">
                  {{ t("Sekcje strony są tylko do odczytu. Otwórz podgląd zapisanego szkicu.") }}
                </p>
                <template v-else-if="selected.definition.fields.some((f) => f.key === 'blocks')">
                  <div class="flex flex-wrap items-center gap-2">
                    <h2 ref="pageBlocksHeading" tabindex="-1" class="text-lg">{{ t("Sekcje strony") }}</h2>
                    <button
                      v-if="removedBlock"
                      class="btn btn-sm"
                      type="button"
                      :disabled="busy"
                      @click="undoBlockRemoval"
                    >
                      {{ t("Cofnij usunięcie bloku") }}
                    </button>
                  </div>
                  <div class="space-y-4">
                    <article
                      v-for="(b, i) in pageBlocks"
                      :key="blockEditorKey(b)"
                      draggable="true"
                      @dragstart="draggedBlock = Number(i)"
                      @dragover.prevent
                      @drop.prevent="dropBlock(Number(i))"
                      class="border border-base-300 rounded-xl p-5"
                    >
                      <div class="flex flex-wrap justify-between items-center gap-2 mb-4">
                        <span class="mono text-xs text-primary">{{
                          isBuiltInBlock(b.type) ? b.type : blockLabel(b.type, blockCatalog)
                        }}</span>
                        <div class="flex flex-wrap gap-1">
                          <button
                            class="btn btn-xs"
                            @click="moveBlock(Number(i), -1)"
                            :disabled="i === 0"
                            :aria-label='t("Przesuń w górę")'
                          >
                            ↑</button
                          ><button
                            class="btn btn-xs"
                            @click="moveBlock(Number(i), 1)"
                            :aria-label='t("Przesuń w dół")'
                          >
                            ↓</button
                          ><button
                            class="btn btn-xs"
                            type="button"
                            :aria-label='t("Powiel blok")'
                            :title='t("Powiel blok")'
                            :disabled="record.data.blocks.length >= maxBlocksPerLevel"
                            @click="duplicatePageBlock(Number(i))"
                          >
                            <Copy :size="12" aria-hidden="true" />
                          </button
                          ><button
                            class="btn btn-xs"
                            type="button"
                            :aria-label='t("Przenieś blok")'
                            :title='t("Przenieś blok")'
                            aria-haspopup="dialog"
                            :disabled="busy"
                            @click="openBlockMove([i], $event.currentTarget)"
                          >
                            <Move :size="12" aria-hidden="true" />
                          </button
                          ><button
                            class="btn btn-xs"
                            type="button"
                            @click="removePageBlock([i])"
                            :aria-label='t("Usuń sekcję")'
                          >
                            ×
                          </button>
                        </div>
                      </div>
                      <BlockEditor
                        :block="b"
                        :catalog="blockCatalog"
                        :move-disabled="busy"
                        @change="dirty = true"
                        @move="(path, trigger) => openBlockMove([i, ...path], trigger)"
                        @remove="(path) => removePageBlock([i, ...path])"
                      />
                    </article>
                  </div>
                  <div class="flex flex-wrap gap-2">
                    <button
                      v-for="b in blockCatalog.keys()"
                      :key="b"
                      class="btn btn-sm"
                      :disabled="
                        (record.data.blocks?.length ?? 0) >= maxBlocksPerLevel
                      "
                      @click="addBlock(b)"
                    >
                      <Plus :size="14" />{{ isBuiltInBlock(b) ? b : blockLabel(b, blockCatalog) }}
                    </button>
                  </div></template
                >
              </div>
              <div
                v-if="
                  record.id &&
                  selected.kind === 'content' &&
                  boot?.abilities?.publish
                "
                class="mt-5"
              >
                <p v-if="record.publish_at" class="mb-3 text-sm">
                  {{ t("Zaplanowany termin publikacji") }}
                  <time :datetime="record.publish_at" class="font-medium">
                    {{ formatPublicationTime(record.publish_at) }}
                  </time>
                </p>
                <div class="flex flex-wrap gap-3 items-end">
                  <label>
                    <span class="field-label">{{ t("Data publikacji") }}</span>
                    <input type="datetime-local" class="input" v-model="scheduledAt" :disabled="busy" />
                  </label>
                  <button type="button" class="btn" :disabled="dirty || busy || draftSaveConflict || !scheduledAt" @click="updateSchedule()">
                    {{ t("Zaplanuj publikację") }}
                  </button>
                  <button v-if="record.publish_at" type="button" class="btn" :disabled="busy || draftSaveConflict" @click="updateSchedule(true)">
                    {{ t("Anuluj zaplanowaną publikację") }}
                  </button>
                </div>
              </div>
              <section
                v-if="portalAttachments.length || portalAttachmentsFailed"
                aria-labelledby="record-attachments-heading"
                class="mt-5 bg-base-100 border border-base-300 rounded-xl p-6"
              >
                <h2 id="record-attachments-heading" class="text-lg">{{ t("Załączniki do zgłoszenia") }}</h2>
                <p v-if="portalAttachmentsFailed" role="alert" class="mt-2 text-sm text-error">
                  {{ t("Nie udało się wczytać załączników.") }}
                </p>
                <ul v-else class="mt-2 space-y-2">
                  <li v-for="attachment in portalAttachments" :key="attachment.id" class="text-sm">
                    <a :href="attachment.url" class="link wrap-anywhere" download>{{ attachment.name }}</a>
                    <span class="ml-2 text-xs text-base-content/70">{{ Math.ceil(attachment.size / 1024) }} KiB</span>
                  </li>
                </ul>
              </section>
              <div v-if="record.id" class="mt-5">
                <button class="btn btn-sm" :disabled="busy" @click="openHistory()">
                  {{ t("Historia wersji") }}
                </button>
                <div v-if="historyPage" class="mt-4 space-y-2">
                  <div
                    v-for="h in historyPage.data"
                    :key="h.id"
                    class="flex flex-wrap items-center justify-between gap-2 bg-base-100 border border-base-300 rounded-lg p-3 text-sm"
                  >
                    <span>{{ t("Rewizja #") }}{{ h.id }} · {{ h.created_at }}</span>
                    <div class="flex flex-wrap gap-2">
                      <a
                        v-if="selected.kind === 'content'"
                        class="btn btn-xs"
                        :href="`${api}/records/${record.id}/revisions/${h.id}/preview`"
                        target="_blank"
                        rel="noopener"
                      >
                        {{ t("Podgląd rewizji") }}<ArrowUpRight :size="12" />
                      </a>
                      <button type="button" class="btn btn-xs" :disabled="busy" @click="compareRevision(h.id)">
                        {{ t("Porównaj ze szkicem") }}
                      </button>
                      <button
                        type="button"
                        class="btn btn-xs"
                        :disabled="busy || hasUnsavedChanges || draftSaveConflict"
                        @click="restoreDraftRevision(h.id)"
                      >
                        {{ t("Przywróć do szkicu") }}
                      </button>
                    </div>
                  </div>
                  <div v-if="historyPage.last_page > 1" class="flex flex-wrap items-center gap-2">
                    <button
                      type="button"
                      class="btn btn-sm"
                      :disabled="busy || historyPage.current_page === 1"
                      @click="openHistory(historyPage!.current_page - 1)"
                    >{{ t("Poprzednia strona") }}</button>
                    <span class="text-sm" aria-live="polite">{{ historyPage.current_page }} / {{ historyPage.last_page }}</span>
                    <button
                      type="button"
                      class="btn btn-sm"
                      :disabled="busy || historyPage.current_page >= historyPage.last_page"
                      @click="openHistory(historyPage!.current_page + 1)"
                    >{{ t("Następna strona") }}</button>
                  </div>
                </div>
              </div>
              <div
                v-if="record.id && selected.kind === 'data'"
                class="mt-5 flex gap-3"
              >
                <label
                  ><span class="field-label">{{ t("Przypisany pracownik") }}</span
                  ><select class="select" v-model="record.assignee_id">
                    <option :value="null">{{ t("Bez przypisania") }}</option>
                    <option v-for="u in boot?.staff" :key="u.id" :value="u.id">
                      {{ u.name }}
                    </option>
                  </select></label
                ><button
                  v-for="transition in selected.definition.transitions?.filter(
                    (t) => t.from === record.state,
                  )"
                  :key="transition.to"
                  class="btn"
                  :disabled="busy || draftSaveConflict"
                  @click="
                    run(async () => {
                      record = await request(
                        '/records/' + record.id + '/transition',
                        'POST',
                        {
                          version: record.version,
                          state: transition.to,
                          assignee_id: record.assignee_id,
                        },
                      );
                    })
                  "
                >
                  {{ t("Zmień stan:") }} {{ transition.to }}
                </button>
              </div>
            </div>
          </section>
          <section v-if="section === 'media'">
            <label class="field-label" for="media-upload">{{ t("Prześlij plik") }}</label>
            <input
              id="media-upload"
              type="file"
              class="file-input w-full max-w-sm mb-6"
              accept="image/png,image/jpeg,image/webp,application/pdf"
              :disabled="busy || !!mediaEdit"
              @change="upload"
            />
            <p v-if="!boot?.abilities?.publish" class="mb-4 text-sm text-base-content/70">
              {{ t("Metadane plików publicznych może zmieniać osoba z prawem do publikacji.") }}
            </p>
            <p v-if="media && media.data.length === 0">{{ t("Biblioteka jest pusta.") }}</p>
            <div v-if="media" class="grid md:grid-cols-3 gap-4">
              <article
                v-for="m in media.data"
                :key="m.id"
                class="bg-base-100 border border-base-300 rounded-xl p-4"
              >
                <img
                  v-if="m.mime.startsWith('image/')"
                  :src="base + '/media/' + m.id"
                  :alt="m.alt ?? ''"
                  class="h-40 object-cover w-full rounded-lg mb-4"
                />
                <p class="text-sm wrap-anywhere">{{ m.name }}</p>
                <span class="mono text-xs text-base-content/45"
                  >{{ m.visibility }} · {{ Math.ceil(m.size / 1024) }} KB</span
                >
                <form
                  v-if="mediaEdit && mediaEdit.id === m.id"
                  class="mt-4 space-y-3"
                  @submit.prevent="saveMedia"
                >
                  <label class="block">
                    <span class="field-label">{{ t("Nazwa pliku") }}</span>
                    <input
                      v-model="mediaEdit.name"
                      class="input w-full"
                      required
                      maxlength="255"
                      :disabled="busy"
                    />
                  </label>
                  <label class="block">
                    <span class="field-label">{{ t("Tekst alternatywny") }}</span>
                    <input
                      v-model="mediaEdit.alt"
                      class="input w-full"
                      maxlength="250"
                      :disabled="busy"
                    />
                  </label>
                  <p v-if="m.visibility === 'public' && m.mime.startsWith('image/')" class="text-xs text-base-content/70">
                    {{ t("Tekst alternatywny zmieni się też na opublikowanych stronach używających tego obrazu.") }}
                  </p>
                  <div class="flex flex-wrap gap-2">
                    <button class="btn btn-sm" :disabled="busy || !mediaEdit.name.trim()">
                      {{ t("Zapisz metadane") }}
                    </button>
                    <button type="button" class="btn btn-sm btn-ghost" :disabled="busy" @click="mediaEdit = undefined">
                      {{ t("Anuluj") }}
                    </button>
                  </div>
                </form>
                <button
                  v-else-if="m.visibility === 'private' || boot?.abilities?.publish"
                  type="button"
                  class="btn btn-sm btn-ghost mt-3 block"
                  :disabled="busy || !!mediaEdit"
                  @click="editMedia(m)"
                >
                  {{ t("Edytuj metadane") }}
                </button>
                <MediaUsage :api="api" :id="m.id" :name="m.name" />
              </article>
            </div>
            <nav
              v-if="media && media.last_page > 1"
              :aria-label='t("Strony biblioteki mediów")'
              class="mt-6 flex flex-wrap items-center gap-3"
            >
              <div class="flex gap-2">
                <button
                  type="button"
                  class="btn btn-sm"
                  :disabled="busy || !!mediaEdit || media.current_page === 1"
                  @click="changeMediaPage(-1)"
                >
                  {{ t("Poprzednia") }}
                </button>
                <button
                  type="button"
                  class="btn btn-sm"
                  :disabled="busy || !!mediaEdit || media.current_page >= media.last_page"
                  @click="changeMediaPage(1)"
                >
                  {{ t("Następna") }}
                </button>
              </div>
              <span aria-live="polite">{{ t("Strona") }} {{ media.current_page }} z {{ media.last_page }}</span>
            </nav>
          </section>
          <section v-if="section === 'ai'" class="space-y-6">
            <div class="bg-base-100 border border-base-300 p-6 rounded-xl">
              <label
                ><span class="field-label">{{ t("Dostawca") }}</span
                ><select class="select mb-4" v-model="provider">
                  <option value="openai">OpenAI</option>
                  <option value="gemini">Gemini</option>
                </select></label
              ><label
                ><span class="field-label">{{ t("Co chcesz przygotować?") }}</span
                ><textarea
                  class="textarea w-full h-32"
                  v-model="prompt"
                  :placeholder='t("Przygotuj stronę oferty dla studia projektowego…")'
                /></label
              ><button
                class="btn btn-primary mt-4"
                @click="ai"
                :disabled="busy || !prompt"
              >
                {{ t("Przygotuj propozycję") }}<Sparkles :size="16" />
              </button>
              <p class="text-xs text-base-content/50 mt-3">
                {{ t("Wynik jest propozycją. Sprawdź go przed zastosowaniem i\n                publikacją.") }}
              </p>
            </div>
            <article
              v-for="task in tasks"
              :key="task.id"
              class="bg-base-100 border border-base-300 p-5 rounded-xl"
            >
              <span class="badge">{{ task.state }}</span>
              <p class="my-3">{{ task.prompt }}</p>
              <p v-if="task.error" class="text-error">{{ task.error }}</p>
              <button
                v-if="task.state === 'ready'"
                type="button"
                class="btn btn-sm my-3"
                :disabled="busy"
                :aria-expanded="reviewTaskId === task.id"
                :aria-controls="'proposal-review-' + task.id"
                @click="toggleProposalReview(task)"
              >
                {{ reviewTaskId === task.id ? t("Zamknij podgląd") : t("Sprawdź propozycję") }}
              </button>
              <section
                v-if="reviewTaskId === task.id && task.state === 'ready'"
                :id="'proposal-review-' + task.id"
                class="mt-4 space-y-4 border-t border-base-300 pt-4"
                :aria-label='t("Podgląd nowego szkicu")'
              >
                <h2 class="text-lg font-semibold">{{ t("Podgląd nowego szkicu") }}</h2>
                <template v-if="reviewedProposal">
                  <label v-if="proposalCollections.length">
                    <span class="field-label">{{ t("Kolekcja docelowa") }}</span>
                    <select v-model="proposalCollectionId" class="select w-full max-w-md" :disabled="busy">
                      <option :value="null" disabled>{{ t("Wybierz kolekcję") }}</option>
                      <option v-for="collection in proposalCollections" :key="collection.id" :value="collection.id">
                        {{ collection.name }}
                      </option>
                    </select>
                  </label>
                  <p v-else class="alert alert-warning">{{ t("Brak kolekcji stron, w której możesz zapisać tytuł i sekcje.") }}</p>
                  <dl class="space-y-2">
                    <div>
                      <dt class="text-sm font-semibold">{{ t("Tytuł") }}</dt>
                      <dd class="whitespace-pre-wrap wrap-anywhere">{{ reviewedProposal.title }}</dd>
                    </div>
                    <div v-if="proposalCollection">
                      <dt class="text-sm font-semibold">{{ t("Proponowany adres URL") }}</dt>
                      <dd v-if="proposedSlug"><code>{{ proposedSlug }}</code></dd>
                      <dd v-else>{{ t("Model kolekcji nie ma zapisywalnego pola adresu URL.") }}</dd>
                    </div>
                  </dl>
                  <h3 class="text-sm font-semibold">{{ t("Sekcje strony") }}</h3>
                  <ol class="list-decimal list-inside space-y-3">
                    <li v-for="(block, index) in reviewedProposal.blocks" :key="index" class="border border-base-300 rounded-lg p-3">
                      <strong>{{ blockLabel(block.type, blockCatalog) }}</strong>
                      <p class="whitespace-pre-wrap wrap-anywhere">{{ block.props.title }}</p>
                      <p v-if="block.type === 'hero' && block.props.text" class="whitespace-pre-wrap wrap-anywhere">{{ block.props.text }}</p>
                      <dl v-if="block.type === 'cta'" class="mt-2 text-sm">
                        <dt class="font-semibold">{{ t("Etykieta przycisku") }}</dt>
                        <dd class="whitespace-pre-wrap wrap-anywhere">{{ block.props.label ?? "Dalej" }}</dd>
                        <dt class="font-semibold">{{ t("Adres odnośnika") }}</dt>
                        <dd class="whitespace-pre-wrap wrap-anywhere">{{ block.props.url ?? "/" }}</dd>
                      </dl>
                    </li>
                  </ol>
                  <div v-if="emptyRequiredProposalFields.length" class="alert alert-warning" role="status">
                    <div>
                      <h3 class="font-semibold">{{ t("Te wymagane pola pozostaną puste po zastosowaniu propozycji.") }}</h3>
                      <ul class="mt-1 list-disc list-inside">
                        <li v-for="field in emptyRequiredProposalFields" :key="field.key" class="wrap-anywhere">{{ field.label }}</li>
                      </ul>
                    </div>
                  </div>
                  <p class="text-sm">{{ t("Nowy szkic pojawi się w edytorze. Zapisz go, aby dodać rekord do kolekcji.") }}</p>
                  <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="busy || !proposalCollection"
                    @click="run(applyProposal)"
                  >
                    {{ t("Zastosuj jako nowy szkic") }}
                  </button>
                </template>
                <p v-else class="alert alert-warning">{{ t("Ta propozycja zawiera nieobsługiwane dane.") }}</p>
              </section>
            </article>
            <button class="btn" @click="navigate('ai')">{{ t("Odśwież zadania") }}</button>
          </section>
          <section v-if="section === 'releases'" class="space-y-6">
            <div class="bg-base-100 border border-base-300 rounded-xl p-8">
              <h2 class="text-xl">{{ t("Od repozytorium do Twojego serwera") }}</h2>
              <p class="text-base-content/55 text-sm my-4">
                {{ t("GitHub Actions → GHCR → Docker Compose. Wskaż zatwierdzony\n                commit aplikacji.") }}
              </p>
              <label
                ><span class="field-label">{{ t("Pełny SHA commita") }}</span
                ><input
                  class="input w-full mono"
                  v-model="commit"
                  pattern="[a-f0-9]{40}" /></label
              ><button
                class="btn btn-primary mt-5"
                @click="release"
                :disabled="busy || commit.length !== 40"
              >
                {{ t("Zbuduj wydanie") }}<ArrowUpRight :size="16" />
              </button>
            </div>
            <section
              v-if="boot?.abilities?.build"
              class="card card-border bg-base-100"
              aria-labelledby="deployment-receipt-heading"
            >
              <div class="card-body gap-4">
                <h2 id="deployment-receipt-heading" class="card-title">{{ t("Potwierdzenie wdrożenia") }}</h2>
                <form @submit.prevent="checkDeploymentReceipt" @input="clearDeploymentReceipt">
                  <fieldset :disabled="deploymentReceiptLoading" class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                      <label>
                        <span class="field-label">{{ t("ID uruchomienia GitHub Actions") }}</span>
                        <input
                          v-model.trim="deploymentReceiptQuery.run_id"
                          class="input w-full mono"
                          inputmode="numeric"
                          pattern="[1-9][0-9]*"
                          required
                        />
                      </label>
                      <label>
                        <span class="field-label">{{ t("ID wydania wdrożenia") }}</span>
                        <input
                          v-model.trim="deploymentReceiptQuery.release_id"
                          class="input w-full mono"
                          placeholder="00000000-0000-0000-0000-000000000000"
                          pattern="[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}"
                          spellcheck="false"
                          required
                        />
                      </label>
                    </div>
                    <label class="block">
                      <span class="field-label">{{ t("Digest obrazu") }}</span>
                      <input
                        v-model.trim="deploymentReceiptQuery.image"
                        class="input w-full mono"
                        placeholder="ghcr.io/owner/image@sha256:…"
                        pattern="ghcr\.io/[a-z0-9_.\-]+/[a-z0-9_.\-]+@sha256:[a-f0-9]{64}"
                        maxlength="300"
                        spellcheck="false"
                        required
                      />
                    </label>
                    <button type="submit" class="btn">
                      <span v-if="deploymentReceiptLoading" class="loading loading-spinner loading-xs" aria-hidden="true"></span>
                      {{ t("Sprawdź potwierdzenie") }}
                    </button>
                  </fieldset>
                </form>
                <p v-if="deploymentReceiptFailed" class="alert alert-error" role="alert">
                  {{ t("Nie udało się odczytać potwierdzenia. Sprawdź podane dane i spróbuj ponownie.") }}
                </p>
                <div role="status">
                  <div v-if="deploymentReceiptResult" class="space-y-3 rounded-box bg-base-200 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                      <p class="font-medium">
                        {{ t("Uruchomienie {0}, próba {1}", deploymentReceiptResult.run_id, deploymentReceiptResult.run_attempt) }}
                      </p>
                      <a
                        :href="deploymentReceiptResult.run_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="link"
                      >{{ t("Otwórz uruchomienie w GitHub Actions") }}</a>
                    </div>
                    <p v-if="deploymentReceiptResult.state === 'pending'">
                      {{ t("Workflow wdrożenia nadal trwa.") }}
                    </p>
                    <p v-else-if="deploymentReceiptResult.state === 'unavailable'">
                      {{ t("Potwierdzenie wdrożenia jest niedostępne dla tej próby.") }}
                    </p>
                    <template v-else-if="deploymentReceiptResult.receipt">
                      <p class="text-sm text-base-content/70">{{ t("Wynik historyczny zapisany przez workflow dla tej próby.") }}</p>
                      <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                          <dt class="font-medium">{{ t("Kod wyjścia SSH") }}</dt>
                          <dd class="mono">{{ deploymentReceiptResult.receipt.ssh_exit_code }}</dd>
                        </div>
                        <template v-if="deploymentReceiptResult.receipt.host_status">
                          <div>
                            <dt class="font-medium">{{ t("Status hosta z potwierdzenia") }}</dt>
                            <dd>{{ t(deploymentHostStateLabels[deploymentReceiptResult.receipt.host_status.state]) }}</dd>
                          </div>
                          <div v-if="deploymentReceiptResult.receipt.host_status.rollback">
                            <dt class="font-medium">{{ t("Przywrócenie wersji") }}</dt>
                            <dd>{{ t(deploymentRollbackLabels[deploymentReceiptResult.receipt.host_status.rollback]) }}</dd>
                          </div>
                          <div v-if="deploymentReceiptResult.receipt.host_status.backup">
                            <dt class="font-medium">{{ t("Kopia zapasowa") }}</dt>
                            <dd class="mono break-all">{{ deploymentReceiptResult.receipt.host_status.backup }}</dd>
                          </div>
                        </template>
                        <div v-else>
                          <dt class="font-medium">{{ t("Status hosta z potwierdzenia") }}</dt>
                          <dd>{{ t("Status hosta jest niedostępny.") }}</dd>
                        </div>
                      </dl>
                    </template>
                  </div>
                </div>
              </div>
            </section>
            <article
              v-for="r in releases"
              :key="r.id"
              class="bg-base-100 p-5 rounded-xl border border-base-300"
            >
              <div class="flex flex-wrap items-center gap-3">
                <span class="badge" role="status">{{ t(releaseStateLabels[r.state] ?? r.state) }}</span>
                <span class="mono text-xs">{{ r.commit.slice(0, 12) }}</span>
                <button
                  type="button"
                  class="btn btn-sm"
                  :disabled="refreshingReleases.has(r.id)"
                  @click="refreshRelease(r.id)"
                >
                  <span
                    v-if="refreshingReleases.has(r.id)"
                    class="loading loading-spinner loading-xs"
                    aria-hidden="true"
                  ></span>
                  {{ refreshingReleases.has(r.id) ? t("Odświeżanie stanu…") : t("Odśwież stan") }}
                </button>
                <a
                  v-if="r.run_url"
                  :href="r.run_url"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="btn btn-sm"
                >GitHub Actions</a>
              </div>
              <p v-if="releaseRefreshErrors.has(r.id)" role="alert" class="mt-3 text-sm text-error">
                {{ t("Nie udało się odświeżyć stanu budowy. Spróbuj ponownie.") }}
              </p>
            </article>
          </section>
          <PanelExtensions
            v-if="panelExtensions"
            v-show="section === 'extensions'"
            :active="section === 'extensions'"
            :extensions="panelExtensions"
            :request="request"
          />
          <AssistantConnection
            v-if="section === 'assistant' && boot?.abilities?.build"
            :base="base"
            :request="request"
          />
          <section v-if="section === 'settings'" class="space-y-6">
            <SiteNavigation v-if="boot?.abilities?.build" :request="request" @dirty="dirty = $event" />
            <div class="bg-base-100 border border-base-300 rounded-xl p-6">
              <h2 class="text-xl mb-5">{{ t("Połączenia AI") }}</h2>
              <div
                v-for="(p, k) in boot?.providers"
                :key="k"
                class="flex justify-between py-4 border-b border-base-300"
              >
                <span>{{ k }}</span
                ><span class="badge badge-outline">{{
                  p.configured ? t("Skonfigurowano") : t("Wymaga klucza i modelu")
                }}</span>
              </div>
              <p class="text-sm text-base-content/55 mt-5">
                {{ t("Klucze i modele ustaw w konfiguracji środowiska serwera.") }}
              </p>
            </div>
            <button class="btn" @click="exportProject">
              {{ t("Eksportuj definicje projektu") }}</button
            ><label class="btn ml-3"
              >{{ t("Importuj definicje") }}<input
                type="file"
                accept="application/json"
                class="hidden"
                @change="importProject"
            /></label>
          </section>
          <footer
            class="mt-16 pt-6 border-t border-base-300 flex justify-between text-xs text-base-content/40"
          >
            <span>{{ t("Formaku · Od pomysłu do własnej aplikacji.") }}</span
            ><span class="mono">OPEN SOURCE / MIT</span>
          </footer>
        </div>
      </main>
    </div>
    <Teleport to="body">
      <dialog
        ref="comparisonDialog"
        class="modal"
        aria-labelledby="revision-comparison-title"
        @close="resetRevisionComparison"
      >
        <div class="modal-box max-w-3xl">
          <h2 id="revision-comparison-title" class="text-lg font-semibold">{{ t("Porównanie rewizji") }}</h2>
          <p v-if="comparisonError" role="alert" class="mt-4">{{ t("Nie udało się pobrać porównania.") }}</p>
          <p v-else-if="!comparison" role="status" class="mt-4">{{ t("Pobieram porównanie…") }}</p>
          <template v-else>
            <p class="mt-2 text-sm">
              {{ t("Rewizja #{0} i zapisany szkic #{1}", comparison.revision.id, comparison.draft.id) }}
            </p>
            <p v-if="!comparison.changes.length" class="mt-4">{{ t("Brak różnic w dostępnych polach.") }}</p>
            <ul v-else class="mt-4 space-y-4">
              <li v-for="change in comparison.changes" :key="change.key" class="rounded-lg border border-base-300 p-3">
                <h3 class="font-medium">{{ change.label }}</h3>
                <dl class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div class="min-w-0">
                    <dt class="text-sm font-medium">{{ t("Wybrana rewizja") }}</dt>
                    <dd class="mt-1 whitespace-pre-wrap wrap-anywhere rounded-md bg-base-200 p-2 text-sm">{{ comparisonValue(change, "previous") }}</dd>
                  </div>
                  <div class="min-w-0">
                    <dt class="text-sm font-medium">{{ t("Zapisany szkic") }}</dt>
                    <dd class="mt-1 whitespace-pre-wrap wrap-anywhere rounded-md bg-base-200 p-2 text-sm">{{ comparisonValue(change, "current") }}</dd>
                  </div>
                </dl>
              </li>
            </ul>
          </template>
          <form method="dialog" class="modal-action">
            <button class="btn" autofocus>{{ t("Zamknij") }}</button>
          </form>
        </div>
      </dialog>
      <dialog
        ref="blockMoveDialog"
        class="modal"
        aria-labelledby="block-move-title"
        @cancel.prevent="closeBlockMove"
      >
        <form class="modal-box space-y-4" @submit.prevent="confirmBlockMove">
          <h2 id="block-move-title" class="text-lg font-semibold">{{ t("Przenieś blok") }}</h2>
          <label v-if="availableBlockDestinations.length">
            <span class="field-label">{{ t("Miejsce docelowe") }}</span>
            <select v-model="blockMoveDestination" class="select w-full" autofocus>
              <option :value="null" disabled>{{ t("Wybierz miejsce docelowe") }}</option>
              <option
                v-for="destination in availableBlockDestinations"
                :key="destination.path.join('.')"
                :value="destination.path"
              >
                {{ blockDestinationLabel(destination.path) }}
              </option>
            </select>
          </label>
          <p v-else>{{ t("Brak dostępnych miejsc docelowych.") }}</p>
          <div class="modal-action">
            <button type="button" class="btn" :autofocus="!availableBlockDestinations.length" @click="closeBlockMove">
              {{ t("Anuluj") }}
            </button>
            <button v-if="availableBlockDestinations.length" type="submit" class="btn" :disabled="!blockMoveDestination">
              {{ t("Przenieś") }}
            </button>
          </div>
        </form>
      </dialog>
    </Teleport>
  </TooltipProvider>
</template>
