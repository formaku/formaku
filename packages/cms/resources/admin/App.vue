<script setup lang="ts">
import { ref, onMounted, computed, onBeforeUnmount, watch } from "vue";
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
  Eye,
  Save,
  LogOut,
} from "lucide-vue-next";
import RichText from "./RichText.vue";
import BlockEditor from "./BlockEditor.vue";
import RelationPicker from "./RelationPicker.vue";
import AssistantConnection from "./AssistantConnection.vue";
import { blockEditorKey, createBlock, maxBlocksPerLevel } from "./blocks";
type Field = {
  key: string;
  label: string;
  type: string;
  required?: boolean;
  private?: boolean;
  options?: string[];
  target?: number;
};
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
type Collection = {
  id: number;
  key: string;
  name: string;
  kind: string;
  version: number;
  definition: {
    fields: Field[];
    states?: string[];
    transitions?: { from: string; to: string }[];
    public?: boolean;
    seo?: CollectionSeo;
  };
};
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
  records = ref<any[]>([]),
  record = ref<any>(null),
  schema = ref<any>(null),
  tasks = ref<any[]>([]),
  releases = ref<any[]>([]),
  error = ref(""),
  message = ref(""),
  busy = ref(false),
  dirty = ref(false),
  prompt = ref(""),
  provider = ref("openai"),
  commit = ref(""),
  search = ref("");
const publicContentSchema = computed(
  () => schema.value?.kind === "content" && schema.value.definition.public,
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
const previewUrl = computed(() =>
  record.value?.id && selected.value?.kind === "content"
    ? `${api}/records/${record.value.id}/preview?version=${record.value.version}`
    : null,
);
watch(
  () => record.value?.id,
  () => {
    previewOpen.value = false;
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
  if (!res.ok) throw new Error(body.message ?? "Operacja nie powiodła się");
  return body;
}
async function run(fn: () => Promise<void>) {
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
}
async function choose(c: Collection) {
  if (dirty.value && !confirm("Odrzucić niezapisane zmiany?")) return;
  selected.value = c;
  section.value = "records";
  record.value = null;
  records.value = [];
  dirty.value = false;
  const d = await request("/collections/" + c.id + "/records");
  records.value = d.data;
}
function contentTemplate() {
  if (schema.value.kind === "content") {
    schema.value.definition.public = true;
    schema.value.definition.fields = [
      { key: "title", label: "Tytuł", type: "text", required: true },
      { key: "slug", label: "Adres URL", type: "text", required: true },
      { key: "blocks", label: "Sekcje strony", type: "richtext" },
    ];
  }
}
async function openHistory() {
  await run(async () => {
    record.value = await request("/records/" + record.value.id);
  });
}
async function importProject(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0];
  if (file)
    await run(async () => {
      await request("/project/import", "POST", JSON.parse(await file.text()));
      await load();
      message.value = "Projekt zaimportowany.";
    });
}
async function applyProposal(task: any) {
  const c = collections.value.find(
    (c) =>
      c.kind === "content" &&
      c.definition.fields.some((f) => f.key === "blocks"),
  );
  if (!c) throw Error("Utwórz kolekcję stron z polem blocks.");
  await choose(c);
  fresh();
  record.value.data = { ...task.result, slug: "proposal-" + task.id };
  dirty.value = true;
}
function fresh() {
  record.value = { version: 0, data: {} };
  dirty.value = false;
}
function edit(r: any) {
  record.value = clone(r);
  dirty.value = false;
}
async function save() {
  clearTimeout(autosaveTimer);
  await run(async () => {
    const snapshot = clone(record.value);
    const result = await request(
      "/collections/" + selected.value!.id + "/records",
      "POST",
      snapshot,
    );
    if (JSON.stringify(record.value.data) !== JSON.stringify(snapshot.data)) {
      record.value.id = result.id;
      record.value.version = result.version;
      dirty.value = true;
      message.value =
        "Zapisano wcześniejszy stan. Nowsze zmiany czekają na zapis.";
    } else {
      record.value = result;
      dirty.value = false;
      message.value = "Szkic zapisany.";
    }
    const d = await request("/collections/" + selected.value!.id + "/records");
    records.value = d.data;
    await load();
  });
}
async function publish() {
  await run(async () => {
    await request("/records/" + record.value.id + "/publish", "POST", {
      version: record.value.version,
    });
    record.value = await request("/records/" + record.value.id);
    message.value = "Treść opublikowana.";
    await load();
  });
}
function newSchema() {
  schema.value = {
    key: "",
    name: "",
    kind: "data",
    definition: {
      fields: [{ key: "title", label: "Tytuł", type: "text", required: true }],
      states: ["new"],
      transitions: [],
    },
  };
  section.value = "schema";
}
async function saveSchema() {
  await run(async () => {
    await request("/collections", "POST", schema.value);
    await load();
    schema.value = null;
    section.value = "builder";
    message.value = "Konfiguracja aktywowana. Panel jest gotowy.";
  });
}
function addBlock(type: string) {
  const blocks = (record.value.data.blocks ??= []);
  if (blocks.length >= maxBlocksPerLevel) return;
  blocks.push(createBlock(type));
  dirty.value = true;
}
function moveBlock(i: number, delta: number) {
  const a = record.value.data.blocks,
    j = i + delta;
  if (j < 0 || j >= a.length) return;
  [a[i], a[j]] = [a[j], a[i]];
  dirty.value = true;
}
async function navigate(s: string) {
  if (dirty.value && !confirm("Odrzucić niezapisane zmiany?")) return;
  dirty.value = false;
  section.value = s;
  record.value = null;
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
    message.value = "Metadane pliku zapisane.";
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
    if (!res.ok) throw Error("Nie udało się przesłać pliku");
    input.value = "";
    await loadMedia();
    message.value = "Plik zapisany.";
  });
}
async function ai() {
  await run(async () => {
    await request("/ai", "POST", {
      provider: provider.value,
      prompt: prompt.value,
    });
    tasks.value = await request("/ai");
    message.value = "Zadanie dodane do kolejki.";
  });
}
async function release() {
  await run(async () => {
    await request("/releases", "POST", { commit: commit.value });
    releases.value = await request("/releases");
    message.value = "Żądanie wysłane. Stan sprawdzisz w GitHub Actions.";
  });
}
async function exportProject() {
  await run(async () => {
    const d = await request("/project");
    const a = document.createElement("a");
    a.href = URL.createObjectURL(
      new Blob([JSON.stringify(d, null, 2)], { type: "application/json" }),
    );
    a.download = "formaku-project.json";
    a.click();
    URL.revokeObjectURL(a.href);
  });
}
watch(
  () => record.value?.data,
  () => {
    clearTimeout(autosaveTimer);
    if (record.value?.id && dirty.value && !busy.value)
      autosaveTimer = setTimeout(() => {
        if (dirty.value && !busy.value) void save();
      }, 1800);
  },
  { deep: true },
);
function dropBlock(i: number) {
  if (draggedBlock.value === null) return;
  const [block] = record.value.data.blocks.splice(draggedBlock.value, 1);
  record.value.data.blocks.splice(i, 0, block);
  draggedBlock.value = null;
  dirty.value = true;
}
function warn(e: BeforeUnloadEvent) {
  if (dirty.value) e.preventDefault();
}
onMounted(() => {
  run(load);
  window.addEventListener("beforeunload", warn);
});
onBeforeUnmount(() => {
  clearTimeout(autosaveTimer);
  window.removeEventListener("beforeunload", warn);
});
const title = computed(
  () =>
    ({
      dashboard: "Twój warsztat",
      builder: "Buduj po swojemu",
      records: selected.value?.name,
      schema: "Model kolekcji",
      media: "Biblioteka mediów",
      ai: "Asystent AI",
      assistant: "Asystent witryny",
      releases: "Wydania aplikacji",
      settings: "Ustawienia",
    })[section.value],
);
</script>
<template>
  <TooltipProvider
    ><div class="min-h-screen lg:grid lg:grid-cols-[244px_1fr]">
      <aside
        class="bg-base-100 border-r border-base-300 p-6 lg:fixed lg:inset-y-0 lg:w-[244px] flex flex-col gap-8"
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
            >TWOJA APLIKACJA</span
          ><span class="flex justify-between"
            >Mój projekt <span class="badge badge-sm">Local</span></span
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
            Praca</button
          ><button
            v-if="boot?.abilities?.build"
            class="btn btn-sm"
            :class="mode === 'build' ? 'bg-base-100' : 'btn-ghost'"
            @click="
              mode = 'build';
              navigate('builder');
            "
          >
            Buduj
          </button>
        </div>
        <nav class="flex flex-col gap-1 text-sm">
          <button
            class="btn btn-ghost justify-start"
            @click="navigate('dashboard')"
          >
            <LayoutDashboard :size="17" />Przegląd</button
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
              <Shapes :size="17" />Modele i ekrany</button
            ><button
              class="btn btn-ghost justify-start"
              @click="navigate('ai')"
            >
              <Sparkles :size="17" />Asystent AI
            </button></template
          >
          <div class="border-t border-base-300 my-4" />
          <button
            v-if="boot?.abilities?.build"
            class="btn btn-ghost justify-start"
            @click="navigate('assistant')"
          >
            <Plug :size="17" />Asystent witryny
          </button>
          <button
            class="btn btn-ghost justify-start"
            @click="navigate('releases')"
          >
            <Box :size="17" />Wydania</button
          ><button
            class="btn btn-ghost justify-start"
            @click="navigate('settings')"
          >
            <Settings :size="17" />Ustawienia
          </button>
        </nav>
        <div class="mt-auto">
          <a href="/" target="_blank" class="btn btn-ghost justify-start w-full"
            ><Globe :size="16" />Otwórz witrynę<ArrowUpRight :size="15"
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
          class="h-20 border-b border-base-300 px-6 lg:px-12 flex items-center justify-between"
        >
          <span class="text-xs text-base-content/55 flex items-center gap-3"
            >Mój projekt <ChevronRight :size="12" />{{
              mode === "work" ? "Praca" : "Buduj"
            }}</span
          ><a href="/portal" class="text-sm flex items-center gap-2"
            >Portal klienta<ArrowUpRight :size="15"
          /></a>
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
                    ? "PRZESTRZEŃ DO TWORZENIA"
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
                Dobre pomysły potrzebują miejsca. Tu nabierają kształtu.
              </p>
            </div>
            <button
              v-if="section === 'builder'"
              class="btn btn-primary"
              @click="newSchema"
            >
              <Plus :size="16" />Nowa kolekcja
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
                      records: "Wszystkie rekordy",
                      published: "Opublikowane treści",
                      media: "Pliki w bibliotece",
                    }[key as string]
                  }}
                </p>
                <div class="text-4xl tracking-tight mt-5">
                  {{ value
                  }}<span class="text-xs text-base-content/40 ml-3"
                    >w Twojej aplikacji</span
                  >
                </div>
              </div>
            </div>
            <div class="grid xl:grid-cols-[1.5fr_1fr] gap-6">
              <section
                class="bg-primary text-primary-content rounded-xl p-8 lg:p-10 relative overflow-hidden"
              >
                <span class="mono text-xs opacity-60"
                  >01 — OD CZEGO ZACZNIEMY?</span
                >
                <h2 class="text-3xl leading-tight tracking-tight mt-6 max-w-sm">
                  Twoja aplikacja.<br />Twój sposób tworzenia.
                </h2>
                <p class="text-sm opacity-75 mt-4 max-w-sm leading-6">
                  Zdefiniuj dane, ułóż stronę i udostępnij ją światu. Formaku
                  zadba o panel administracyjny.
                </p>
                <button
                  class="btn mt-8 bg-base-100 text-base-content"
                  @click="
                    mode = 'build';
                    navigate('builder');
                  "
                >
                  Otwórz builder<ArrowRight :size="16" /></button
                ><Shapes
                  class="absolute -right-8 -bottom-8 opacity-10"
                  :size="220"
                />
              </section>
              <section
                class="bg-base-100 border border-base-300 rounded-xl p-8"
              >
                <h2 class="text-lg tracking-tight">
                  Wszystko na swoim miejscu
                </h2>
                <div
                  v-for="(label, i) in [
                    'Przygotuj strukturę danych',
                    'Dodaj treści i strony',
                    'Opublikuj własną aplikację',
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
                          "Kolekcje, relacje i gotowe formularze.",
                          "Edytor blokowy i szkice.",
                          "Jeden obraz. Twój serwer.",
                        ][i]
                      }}
                    </p>
                  </div>
                </div>
              </section>
            </div>
            <section class="mt-10">
              <div class="flex justify-between items-center mb-5">
                <h2 class="text-xl tracking-tight">Twoje kolekcje</h2>
                <button
                  class="btn btn-sm btn-ghost"
                  @click="
                    mode = 'build';
                    navigate('builder');
                  "
                >
                  Zarządzaj<ArrowUpRight :size="14" />
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
                    c.kind === "content" ? "Treści" : "Dane aplikacji"
                  }}</span
                  ><ChevronRight :size="16" />
                </button>
                <p v-if="!collections.length" class="p-8 text-base-content/50">
                  Dodaj pierwszą kolekcję w builderze.
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
                {{ c.definition.fields.length }} pól
              </p>
              <div class="flex gap-2 mt-6">
                <button
                  class="btn btn-sm"
                  @click="
                    schema = clone(c);
                    section = 'schema';
                  "
                >
                  Edytuj model</button
                ><button
                  class="btn btn-sm btn-ghost"
                  @click="run(() => choose(c))"
                >
                  Otwórz dane<ArrowUpRight :size="14" />
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
                ><span class="field-label">Nazwa</span
                ><input
                  class="input w-full"
                  v-model="schema.name"
                  required /></label
              ><label
                ><span class="field-label">Identyfikator</span
                ><input
                  class="input w-full"
                  v-model="schema.key"
                  pattern="[a-z][a-z0-9_]+"
                  required
                  :disabled="!!schema.id" /></label
              ><label
                ><span class="field-label">Rodzaj</span
                ><select
                  class="select w-full"
                  @change="contentTemplate"
                  v-model="schema.kind"
                  :disabled="!!schema.id"
                >
                  <option value="data">Dane aplikacji</option>
                  <option value="content">Treść publikowana</option>
                </select></label
              >
            </div>
            <div class="bg-base-100 border border-base-300 rounded-xl p-6">
              <h2 class="text-lg mb-5">Pola formularza</h2>
              <div
                v-for="(f, i) in schema.definition.fields"
                :key="i"
                class="grid md:grid-cols-[1fr_1fr_1fr_auto] gap-3 mb-4"
              >
                <label
                  ><span class="field-label">Etykieta</span
                  ><input
                    class="input w-full"
                    v-model="f.label"
                    required /></label
                ><label
                  ><span class="field-label">Klucz</span
                  ><input
                    class="input w-full"
                    v-model="f.key"
                    required /></label
                ><label
                  ><span class="field-label">Typ</span
                  ><select class="select w-full" v-model="f.type">
                    <option
                      v-for="t in [
                        'text',
                        'richtext',
                        'number',
                        'date',
                        'boolean',
                        'select',
                        'media',
                        'relation',
                      ]"
                      :key="t"
                    >
                      {{ t }}
                    </option>
                  </select></label
                >
                <div class="flex items-end gap-2 pb-2">
                  <label class="text-xs"
                    ><input type="checkbox" v-model="f.required" />
                    Wymagane</label
                  ><label class="text-xs"
                    ><input type="checkbox" v-model="f.private" />
                    Prywatne</label
                  ><button
                    class="btn btn-sm btn-ghost"
                    type="button"
                    @click="schema.definition.fields.splice(i, 1)"
                    aria-label="Usuń pole"
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
                <label v-if="f.type === 'select'"
                  ><span class="field-label"
                    >Opcje: {{ f.label }} (rozdzielone przecinkami)</span
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
                    >Kolekcja docelowa: {{ f.label }}</span
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
                    label: 'Nowe pole',
                    type: 'text',
                  })
                "
              >
                <Plus :size="15" />Dodaj pole
              </button>
            </div>
            <fieldset
              v-if="publicContentSchema"
              class="fieldset bg-base-100 border border-base-300 rounded-xl p-6"
            >
              <legend class="fieldset-legend text-lg">Metadane SEO</legend>
              <p class="text-sm text-base-content/70">
                Przypisz pola tekstowe używane w metadanych opublikowanych stron.
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
                    <option value="">Bez przypisania</option>
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
                  >Stany procesu (rozdzielone przecinkami)</span
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
                Dodaj przejście
              </button>
            </div>
            <section class="bg-base-100 border border-base-300 rounded-xl p-6">
              <h2 class="text-lg mb-4">Dostęp do kolekcji</h2>
              <button v-if="!schema.definition.access" type="button" class="btn btn-sm" @click="schema.definition.access={editor:{scope:'all',write:true},publisher:{scope:'all',write:false}}">Skonfiguruj dostęp ról</button>
              <div v-for="(rule,role) in schema.definition.access" :key="role" class="flex flex-wrap items-center gap-4 mb-3">
                <strong class="text-sm w-24">{{role}}</strong>
                <label><span class="field-label">Widoczne rekordy</span><select class="select" v-model="rule.scope"><option value="all">Wszystkie</option><option value="own">Własne</option><option value="assigned">Przypisane</option><option value="none">Brak dostępu</option></select></label>
                <label class="text-sm"><input type="checkbox" v-model="rule.write"/> Edycja rekordów</label>
              </div>
              <p class="text-xs text-base-content/50 mt-3">Administrator buildera zachowuje pełny dostęp. Po skonfigurowaniu reguł pozostałe role wymagają jawnego wpisu.</p>
            </section>
            <button class="btn btn-primary" :disabled="busy">
              Aktywuj konfigurację<Check :size="16" />
            </button>
          </form>
          <section v-if="section === 'records' && selected">
            <div v-if="!record">
              <div class="flex justify-end mb-4">
                <button class="btn btn-primary" @click="fresh">
                  <Plus :size="16" />Dodaj rekord
                </button>
              </div>
              <div
                class="overflow-x-auto bg-base-100 border border-base-300 rounded-xl"
              >
                <table class="table">
                  <thead>
                    <tr>
                      <th>Tytuł</th>
                      <th>Stan</th>
                      <th>Wersja</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="r in records" :key="r.id">
                      <td>{{ r.data.title ?? r.data.name ?? "#" + r.id }}</td>
                      <td>
                        <span class="badge badge-outline">{{
                          r.published_id ? "Opublikowano" : r.state
                        }}</span>
                      </td>
                      <td class="mono">{{ r.version }}</td>
                      <td>
                        <button class="btn btn-sm" @click="edit(r)">
                          Edytuj
                        </button>
                      </td>
                    </tr>
                    <tr v-if="!records.length">
                      <td
                        colspan="4"
                        class="p-10 text-center text-base-content/50"
                      >
                        Tu pojawią się pierwsze rekordy.
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
            <div v-else>
              <div class="flex flex-wrap justify-between gap-3 mb-6">
                <button class="btn" :disabled="busy" @click="run(() => choose(selected!))">
                  Wróć do listy
                </button>
                <div class="flex flex-wrap gap-2 items-center">
                  <span class="text-xs text-base-content/50">{{
                    dirty ? "Niezapisane zmiany" : "Zapisano"
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
                    {{ previewOpen ? "Ukryj podgląd" : "Pokaż podgląd" }}
                  </button><a
                    v-if="previewUrl"
                    class="btn"
                    :href="previewUrl"
                    target="_blank"
                    rel="noopener"
                    >Podgląd w nowej karcie<ArrowUpRight :size="16" /></a
                  ><button class="btn" @click="save" :disabled="busy">
                    <Save :size="16" />Zapisz szkic</button
                  ><button
                    v-if="
                      record.id &&
                      selected.kind === 'content' &&
                      boot?.abilities?.publish
                    "
                    class="btn btn-primary"
                    :disabled="dirty || busy"
                    @click="publish"
                  >
                    Opublikuj treść
                  </button>
                </div>
              </div>
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
                        Podgląd strony
                      </h2>
                      <p class="text-sm text-base-content/70">
                        Podgląd pokazuje ostatni zapisany szkic. Zapisz zmiany, aby go odświeżyć.
                      </p>
                    </div>
                    <label>
                      <span class="field-label">Szerokość podglądu</span>
                      <select class="select select-sm w-auto" v-model="previewViewport">
                        <option value="desktop">Komputer · 1280 px</option>
                        <option value="tablet">Tablet · 768 px</option>
                        <option value="mobile">Telefon · 390 px</option>
                      </select>
                    </label>
                  </div>
                  <div
                    class="overflow-x-auto rounded-box border border-base-300 bg-base-200"
                    role="region"
                    aria-label="Obszar podglądu strony"
                    tabindex="0"
                  >
                    <iframe
                      :src="previewUrl"
                      title="Podgląd zapisanego szkicu"
                      sandbox="allow-same-origin"
                      referrerpolicy="no-referrer"
                      :class="previewWidths[previewViewport]"
                      class="mx-auto block h-[70vh] max-w-none border-0 bg-base-100"
                    ></iframe>
                  </div>
                </div>
              </section>
              <div
                class="space-y-5 bg-base-100 border border-base-300 rounded-xl p-6"
                @input="dirty = true"
              >
                <template v-for="f in selected.definition.fields" :key="f.key"
                  ><RelationPicker
                    v-if="f.type === 'relation' && f.key !== 'blocks'"
                    v-model="record.data[f.key]"
                    :target="f.target"
                    :label="f.label"
                    :private-field="f.private"
                    @update:model-value="dirty = true"
                  /><label v-else-if="f.key !== 'blocks'"
                    ><span class="field-label"
                      >{{ f.label
                      }}<span v-if="f.private" class="text-secondary">
                        · wewnętrzne</span
                      ></span
                    ><RichText
                      v-if="f.type === 'richtext'"
                      v-model="record.data[f.key]"
                      @update:modelValue="dirty = true" /><input
                      v-else-if="f.type === 'boolean'"
                      type="checkbox"
                      v-model="record.data[f.key]" /><select
                      v-else-if="f.type === 'select'"
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
                        ['number', 'media'].includes(f.type)
                          ? 'number'
                          : f.type === 'date'
                            ? 'date'
                            : 'text'
                      "
                      v-model="record.data[f.key]"
                      :required="f.required" /></label
                ></template>
                <template
                  v-if="
                    selected.definition.fields.some((f) => f.key === 'blocks')
                  "
                  ><h2 class="text-lg">Sekcje strony</h2>
                  <div class="space-y-4">
                    <article
                      v-for="(b, i) in record.data.blocks ?? []"
                      :key="blockEditorKey(b)"
                      draggable="true"
                      @dragstart="draggedBlock = Number(i)"
                      @dragover.prevent
                      @drop.prevent="dropBlock(Number(i))"
                      class="border border-base-300 rounded-xl p-5"
                    >
                      <div class="flex justify-between items-center mb-4">
                        <span class="mono text-xs text-primary">{{
                          b.type
                        }}</span>
                        <div class="flex gap-1">
                          <button
                            class="btn btn-xs"
                            @click="moveBlock(Number(i), -1)"
                            :disabled="i === 0"
                            aria-label="Przesuń w górę"
                          >
                            ↑</button
                          ><button
                            class="btn btn-xs"
                            @click="moveBlock(Number(i), 1)"
                            aria-label="Przesuń w dół"
                          >
                            ↓</button
                          ><button
                            class="btn btn-xs"
                            @click="
                              record.data.blocks.splice(i, 1);
                              dirty = true;
                            "
                            aria-label="Usuń sekcję"
                          >
                            ×
                          </button>
                        </div>
                      </div>
                      <BlockEditor :block="b" @change="dirty = true" />
                    </article>
                  </div>
                  <div class="flex flex-wrap gap-2">
                    <button
                      v-for="b in boot?.blocks"
                      :key="b"
                      class="btn btn-sm"
                      :disabled="
                        (record.data.blocks?.length ?? 0) >= maxBlocksPerLevel
                      "
                      @click="addBlock(b)"
                    >
                      <Plus :size="14" />{{ b }}
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
                class="flex gap-3 mt-5 items-end"
              >
                <label
                  ><span class="field-label">Data publikacji</span
                  ><input
                    type="datetime-local"
                    class="input"
                    v-model="scheduledAt" /></label
                ><button
                  class="btn"
                  :disabled="dirty || busy || !scheduledAt"
                  @click="
                    run(async () => {
                      await request(
                        '/records/' + record.id + '/schedule',
                        'POST',
                        {
                          version: record.version,
                          publish_at: new Date(scheduledAt).toISOString(),
                        },
                      );
                      record = await request('/records/' + record.id);
                      message = 'Publikacja zaplanowana.';
                    })
                  "
                >
                  Zaplanuj publikację
                </button>
              </div>
              <div v-if="record.id" class="mt-5">
                <button class="btn btn-sm" @click="openHistory">
                  Historia wersji
                </button>
                <div v-if="record.history" class="mt-4 space-y-2">
                  <div
                    v-for="h in record.history"
                    :key="h.id"
                    class="flex justify-between bg-base-100 border border-base-300 rounded-lg p-3 text-sm"
                  >
                    <span>Rewizja #{{ h.id }} · {{ h.created_at }}</span
                    ><button
                      class="btn btn-xs"
                      @click="
                        run(async () => {
                          record = await request(
                            '/records/' + record.id + '/restore/' + h.id,
                            'POST',
                            { version: record.version },
                          );
                          message = 'Wersja przywrócona do szkicu.';
                        })
                      "
                    >
                      Przywróć do szkicu
                    </button>
                  </div>
                </div>
              </div>
              <div
                v-if="record.id && selected.kind === 'data'"
                class="mt-5 flex gap-3"
              >
                <label
                  ><span class="field-label">Przypisany pracownik</span
                  ><select class="select" v-model="record.assignee_id">
                    <option :value="null">Bez przypisania</option>
                    <option v-for="u in boot?.staff" :key="u.id" :value="u.id">
                      {{ u.name }}
                    </option>
                  </select></label
                ><button
                  v-for="t in selected.definition.transitions?.filter(
                    (t) => t.from === record.state,
                  )"
                  :key="t.to"
                  class="btn"
                  @click="
                    run(async () => {
                      record = await request(
                        '/records/' + record.id + '/transition',
                        'POST',
                        {
                          version: record.version,
                          state: t.to,
                          assignee_id: record.assignee_id,
                        },
                      );
                    })
                  "
                >
                  Zmień stan: {{ t.to }}
                </button>
              </div>
            </div>
          </section>
          <section v-if="section === 'media'">
            <label class="field-label" for="media-upload">Prześlij plik</label>
            <input
              id="media-upload"
              type="file"
              class="file-input w-full max-w-sm mb-6"
              accept="image/png,image/jpeg,image/webp,application/pdf"
              :disabled="busy || !!mediaEdit"
              @change="upload"
            />
            <p v-if="!boot?.abilities?.publish" class="mb-4 text-sm text-base-content/70">
              Metadane plików publicznych może zmieniać osoba z prawem do publikacji.
            </p>
            <p v-if="media && media.data.length === 0">Biblioteka jest pusta.</p>
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
                    <span class="field-label">Nazwa pliku</span>
                    <input
                      v-model="mediaEdit.name"
                      class="input w-full"
                      required
                      maxlength="255"
                      :disabled="busy"
                    />
                  </label>
                  <label class="block">
                    <span class="field-label">Tekst alternatywny</span>
                    <input
                      v-model="mediaEdit.alt"
                      class="input w-full"
                      maxlength="250"
                      :disabled="busy"
                    />
                  </label>
                  <p v-if="m.visibility === 'public' && m.mime.startsWith('image/')" class="text-xs text-base-content/70">
                    Tekst alternatywny zmieni się też na opublikowanych stronach używających tego obrazu.
                  </p>
                  <div class="flex flex-wrap gap-2">
                    <button class="btn btn-sm" :disabled="busy || !mediaEdit.name.trim()">
                      Zapisz metadane
                    </button>
                    <button type="button" class="btn btn-sm btn-ghost" :disabled="busy" @click="mediaEdit = undefined">
                      Anuluj
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
                  Edytuj metadane
                </button>
              </article>
            </div>
            <nav
              v-if="media && media.last_page > 1"
              aria-label="Strony biblioteki mediów"
              class="mt-6 flex flex-wrap items-center gap-3"
            >
              <div class="flex gap-2">
                <button
                  type="button"
                  class="btn btn-sm"
                  :disabled="busy || !!mediaEdit || media.current_page === 1"
                  @click="changeMediaPage(-1)"
                >
                  Poprzednia
                </button>
                <button
                  type="button"
                  class="btn btn-sm"
                  :disabled="busy || !!mediaEdit || media.current_page >= media.last_page"
                  @click="changeMediaPage(1)"
                >
                  Następna
                </button>
              </div>
              <span aria-live="polite">Strona {{ media.current_page }} z {{ media.last_page }}</span>
            </nav>
          </section>
          <section v-if="section === 'ai'" class="space-y-6">
            <div class="bg-base-100 border border-base-300 p-6 rounded-xl">
              <label
                ><span class="field-label">Dostawca</span
                ><select class="select mb-4" v-model="provider">
                  <option value="openai">OpenAI</option>
                  <option value="gemini">Gemini</option>
                </select></label
              ><label
                ><span class="field-label">Co chcesz przygotować?</span
                ><textarea
                  class="textarea w-full h-32"
                  v-model="prompt"
                  placeholder="Przygotuj stronę oferty dla studia projektowego…"
                /></label
              ><button
                class="btn btn-primary mt-4"
                @click="ai"
                :disabled="busy || !prompt"
              >
                Przygotuj propozycję<Sparkles :size="16" />
              </button>
              <p class="text-xs text-base-content/50 mt-3">
                Wynik jest propozycją. Sprawdź go przed zastosowaniem i
                publikacją.
              </p>
            </div>
            <article
              v-for="t in tasks"
              :key="t.id"
              class="bg-base-100 border border-base-300 p-5 rounded-xl"
            >
              <span class="badge">{{ t.state }}</span>
              <p class="my-3">{{ t.prompt }}</p>
              <p v-if="t.error" class="text-error">{{ t.error }}</p>
              <button
                v-if="t.state === 'ready'"
                class="btn btn-sm my-3"
                @click="run(() => applyProposal(t))"
              >
                Zastosuj jako nowy szkic
              </button>
              <pre v-if="t.result" class="whitespace-pre-wrap text-xs">{{
                JSON.stringify(t.result, null, 2)
              }}</pre>
            </article>
            <button class="btn" @click="navigate('ai')">Odśwież zadania</button>
          </section>
          <section v-if="section === 'releases'" class="space-y-6">
            <div class="bg-base-100 border border-base-300 rounded-xl p-8">
              <h2 class="text-xl">Od repozytorium do Twojego serwera</h2>
              <p class="text-base-content/55 text-sm my-4">
                GitHub Actions → GHCR → Docker Compose. Wskaż zatwierdzony
                commit aplikacji.
              </p>
              <label
                ><span class="field-label">Pełny SHA commita</span
                ><input
                  class="input w-full mono"
                  v-model="commit"
                  pattern="[a-f0-9]{40}" /></label
              ><button
                class="btn btn-primary mt-5"
                @click="release"
                :disabled="busy || commit.length !== 40"
              >
                Zbuduj wydanie<ArrowUpRight :size="16" />
              </button>
            </div>
            <article
              v-for="r in releases"
              :key="r.id"
              class="bg-base-100 p-5 rounded-xl border border-base-300"
            >
              <span class="badge">{{ r.state }}</span
              ><span class="mono text-xs ml-3">{{ r.commit.slice(0, 12) }}</span
              ><a
                v-if="r.run_url"
                :href="r.run_url"
                target="_blank"
                class="btn btn-sm ml-3"
                >GitHub Actions</a
              >
            </article>
          </section>
          <AssistantConnection
            v-if="section === 'assistant' && boot?.abilities?.build"
            :base="base"
            :request="request"
          />
          <section v-if="section === 'settings'" class="space-y-6">
            <div class="bg-base-100 border border-base-300 rounded-xl p-6">
              <h2 class="text-xl mb-5">Połączenia AI</h2>
              <div
                v-for="(p, k) in boot?.providers"
                :key="k"
                class="flex justify-between py-4 border-b border-base-300"
              >
                <span>{{ k }}</span
                ><span class="badge badge-outline">{{
                  p.configured ? "Skonfigurowano" : "Wymaga klucza i modelu"
                }}</span>
              </div>
              <p class="text-sm text-base-content/55 mt-5">
                Klucze i modele ustaw w konfiguracji środowiska serwera.
              </p>
            </div>
            <button class="btn" @click="exportProject">
              Eksportuj definicje projektu</button
            ><label class="btn ml-3"
              >Importuj definicje<input
                type="file"
                accept="application/json"
                class="hidden"
                @change="importProject"
            /></label>
          </section>
          <footer
            class="mt-16 pt-6 border-t border-base-300 flex justify-between text-xs text-base-content/40"
          >
            <span>Formaku · Od pomysłu do własnej aplikacji.</span
            ><span class="mono">OPEN SOURCE / MIT</span>
          </footer>
        </div>
      </main>
    </div></TooltipProvider
  >
</template>
