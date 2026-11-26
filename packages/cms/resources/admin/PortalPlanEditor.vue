<script setup lang="ts">
import { t } from "./locale";
import type { PortalPlan, PortalField } from "./agency";

const plan = defineModel<PortalPlan>({ required: true });
const fieldTypes: Record<PortalField["type"], string> = {
  text: "Tekst",
  number: "Liczba",
  date: "Data",
  boolean: "Tak / nie",
  select: "Lista wyboru",
};
</script>

<template>
  <div class="space-y-5">
    <label class="block"
      ><span class="field-label">{{ t("Nazwa projektu") }}</span>
      <input class="input w-full" required maxlength="100" v-model="plan.name" />
    </label>
    <fieldset class="fieldset rounded-box border border-base-300 p-4">
      <legend class="fieldset-legend">{{ t("Strony usługowe") }}</legend>
      <div
        v-for="(page, index) in plan.pages"
        :key="index"
        class="space-y-3 border-b border-base-300 pb-4 mb-2"
      >
        <div class="grid gap-3 sm:grid-cols-2">
          <label
            ><span class="field-label">{{ t("Tytuł strony") }}</span
            ><input class="input w-full" required maxlength="200" v-model="page.title"
          /></label>
          <label
            ><span class="field-label">{{ t("Adres strony (slug)") }}</span
            ><input
              class="input w-full"
              required
              pattern="[a-z0-9]+(-[a-z0-9]+)*"
              maxlength="100"
              v-model="page.slug"
          /></label>
        </div>
        <label class="block"
          ><span class="field-label">{{ t("Treść strony") }}</span
          ><textarea class="textarea w-full" rows="3" required maxlength="4000" v-model="page.body" />
        </label>
        <label class="block"
          ><span class="field-label">{{ t("Tekst przycisku do portalu") }}</span
          ><input class="input w-full" required maxlength="100" v-model="page.cta"
        /></label>
        <button
          type="button"
          v-if="plan.pages.length > 1"
          class="btn btn-sm btn-ghost"
          @click="plan.pages.splice(index, 1)"
        >
          {{ t("Usuń stronę") }}
        </button>
      </div>
      <button
        type="button"
        class="btn btn-sm justify-self-start"
        :disabled="plan.pages.length >= 8"
        @click="plan.pages.push({ title: '', slug: '', body: '', cta: t('Złóż zapytanie') })"
      >
        {{ t("Dodaj stronę") }}
      </button>
    </fieldset>
    <fieldset class="fieldset rounded-box border border-base-300 p-4">
      <legend class="fieldset-legend">{{ t("Pola zapytania ofertowego") }}</legend>
      <div
        v-for="(field, index) in plan.fields"
        :key="index"
        class="grid gap-3 border-b border-base-300 pb-4 mb-2 sm:grid-cols-3"
      >
        <label
          ><span class="field-label">{{ t("Etykieta") }}</span
          ><input required class="input w-full" maxlength="100" v-model="field.label"
        /></label>
        <label
          ><span class="field-label">{{ t("Klucz pola") }}</span
          ><input
            required
            class="input w-full"
            maxlength="49"
            pattern="[a-z][a-z0-9_]{0,48}"
            v-model="field.key"
        /></label>
        <label
          ><span class="field-label">{{ t("Typ pola") }}</span>
          <select class="select w-full" v-model="field.type" @change="field.options = []">
            <option v-for="(label, key) in fieldTypes" :key="key" :value="key">{{ t(label) }}</option>
          </select>
        </label>
        <label v-if="field.type === 'select'" class="sm:col-span-2"
          ><span class="field-label">{{ t("Opcje, po jednej w wierszu") }}</span>
          <textarea
            class="textarea w-full"
            required
            :value="field.options.join('\n')"
            @change="
              field.options = ($event.target as HTMLTextAreaElement).value
                .split('\n')
                .map((line) => line.trim())
                .filter(Boolean)
            "
          />
        </label>
        <button
          type="button"
          class="btn btn-sm btn-ghost justify-self-start"
          @click="plan.fields.splice(index, 1)"
        >
          {{ t("Usuń pole") }}
        </button>
      </div>
      <button
        type="button"
        class="btn btn-sm justify-self-start"
        :disabled="plan.fields.length >= 12"
        @click="plan.fields.push({ key: '', label: '', type: 'text', options: [] })"
      >
        {{ t("Dodaj pole") }}
      </button>
    </fieldset>
    <label class="block"
      ><span class="field-label">{{ t("Etapy obsługi w kolejności, po jednym kluczu w wierszu") }}</span>
      <textarea
        class="textarea w-full"
        required
        :value="plan.states.join('\n')"
        @change="
          plan.states = ($event.target as HTMLTextAreaElement).value
            .split('\n')
            .map((line) => line.trim())
            .filter(Boolean)
        "
      />
    </label>
    <label v-if="plan.questions.length" class="block"
      ><span class="field-label">{{
        t("Pytania do klienta. Po uzgodnieniu uzupełnij projekt i usuń rozstrzygnięte pytania.")
      }}</span>
      <textarea
        class="textarea w-full"
        :value="plan.questions.join('\n')"
        @change="
          plan.questions = ($event.target as HTMLTextAreaElement).value
            .split('\n')
            .map((line) => line.trim())
            .filter(Boolean)
        "
      />
    </label>
  </div>
</template>
