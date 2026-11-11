import { ref, watchEffect } from "vue";
import { messages } from "./messages.en";

const storageKey = "formaku.admin.locale";

function initialLocale(): "pl" | "en" {
  try {
    return localStorage.getItem(storageKey) === "en" ? "en" : "pl";
  } catch {
    return "pl";
  }
}

export const locale = ref(initialLocale());

export function t(key: string, ...values: (string | number)[]): string {
  const normalized = key.replace(/\s+/g, " ").trim();
  const message = locale.value === "en" && Object.hasOwn(messages, normalized)
    ? messages[normalized]
    : normalized;

  return message.replace(/\{(\d+)\}/g, (match, index) =>
    values[Number(index)]?.toString() ?? match,
  );
}

export function syncLocale(): void {
  watchEffect(() => {
    document.documentElement.lang = locale.value;
    document.title = `Formaku — ${t("Twój warsztat")}`;
    try {
      localStorage.setItem(storageKey, locale.value);
    } catch {
      // The language switch still works when browser storage is unavailable.
    }
  });
}
