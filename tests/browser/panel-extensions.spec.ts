import { expect, test, type Page } from "@playwright/test";

const isRowList = (url: URL) => url.pathname.endsWith("/extensions/resources/qa_row_items");

async function openExtensions(page: Page) {
  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill("admin@formaku.test");
  await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
  await expect(
    page.getByRole("button", { name: "Rozszerzenia", exact: true }),
    "Use the Playwright fixture server with APP_ENV=testing and a dedicated formaku-e2e.sqlite database",
  ).toBeVisible();
  await page.getByRole("button", { name: "Rozszerzenia", exact: true }).click();
}

test("a row action confirms the row, asks for its fields and refreshes the list", async ({ page }, info) => {
  // The fixtures hold one row per browser project, so the projects do not change each other's row.
  const code = info.project.name;
  await openExtensions(page);

  const row = page.getByRole("table", { name: "Testowe elementy" }).getByRole("row")
    .filter({ has: page.getByRole("cell", { name: code, exact: true }) });
  const status = row.getByRole("cell").nth(1);
  const note = row.getByRole("cell").nth(2);
  await expect(row).toBeVisible();

  // An action without further fields: the dialog shows the row and waits for a confirmation.
  const reopen = row.getByRole("button", { name: `Otwórz ponownie: ${code}`, exact: true });
  const position = async () => {
    const box = await reopen.boundingBox();
    return box && { left: Math.round(box.x), top: Math.round(box.y) };
  };
  const place = await position();
  await reopen.click();
  const reopening = page.getByRole("dialog", { name: "Otwórz ponownie" });
  await expect(reopening.getByRole("button", { name: "Anuluj", exact: true })).toBeFocused();
  await expect(reopening.getByRole("definition").first()).toHaveText(code);
  const listed = Promise.withResolvers<void>();
  await page.route(isRowList, async (route) => {
    await listed.promise;
    await route.continue();
  }, { times: 1 });
  await reopening.getByRole("button", { name: "Uruchom akcję", exact: true }).click();
  await expect(reopening.getByRole("status")).toHaveText("Akcja wykonana.");
  await expect(reopening.locator("pre")).toContainText('"status": "open"');
  const close = reopening.getByRole("button", { name: "Zamknij", exact: true });
  await expect(close).toBeFocused();
  await close.click();
  await expect(reopening).toBeHidden();
  await expect(reopen).toBeFocused();
  // The panel is still reading the list again. The rows stay where they were, so the next click
  // lands on the same button.
  try {
    expect(await position()).toEqual(place);
  } finally {
    listed.resolve();
  }
  await expect(status).toHaveText("open");
  await expect(note).toHaveText("—");

  // An action with a required field: an empty value stays in the panel.
  await row.getByRole("button", { name: `Zamknij element: ${code}`, exact: true }).click();
  const closing = page.getByRole("dialog", { name: "Zamknij element" });
  const reason = closing.getByLabel("Powód zamknięcia", { exact: true });
  await expect(reason).toBeFocused();
  await reason.fill("   ");
  await reason.press("Enter");
  await expect(closing.getByRole("alert")).toHaveText("Sprawdź wartości pól akcji.");
  await expect(status).toHaveText("open");

  await reason.fill(`Zamknięte w teście ${code}`);
  await reason.press("Enter");
  await expect(closing.locator("pre")).toContainText('"status": "closed"');
  // The dialog now shows the row as the action left it.
  await expect(closing.getByRole("definition").nth(1)).toHaveText("closed");
  await closing.getByRole("button", { name: "Zamknij", exact: true }).click();
  await expect(status).toHaveText("closed");
  await expect(note).toHaveText(`Zamknięte w teście ${code}`);

  // The action refuses a closed row. The dialog shows the reason given by the API and stays open.
  await row.getByRole("button", { name: `Zamknij element: ${code}`, exact: true }).click();
  await reason.fill("Drugi raz");
  await closing.getByRole("button", { name: "Uruchom akcję", exact: true }).click();
  await expect(closing.getByRole("alert")).toHaveText("Element jest już zamknięty.");
  await page.keyboard.press("Escape");
  await expect(closing).toBeHidden();
  await expect(note).toHaveText(`Zamknięte w teście ${code}`);
});

test("a standalone action sends the entered values with their types", async ({ page }) => {
  await openExtensions(page);

  const actions = page.getByRole("region", { name: "Akcje", exact: true });
  await actions.getByLabel("Wiadomość", { exact: true }).fill("Dzień dobry");
  await actions.getByLabel("Liczba", { exact: true }).fill("7");
  await actions.getByRole("combobox", { name: "Potwierdzenie" }).selectOption({ label: "Nie" });
  await actions.getByRole("button", { name: "Uruchom akcję", exact: true }).click();

  const result = actions.locator("pre");
  await expect(result).toContainText('"message": "Dzień dobry"');
  await expect(result).toContainText('"count": 7');
  await expect(result).toContainText('"confirm": false');
});

test("the action form is drawn with the first page of the list", async ({ page }) => {
  const requested = Promise.withResolvers<void>();
  const listed = Promise.withResolvers<void>();
  await page.route(isRowList, async (route) => {
    requested.resolve();
    await listed.promise;
    await route.continue();
  }, { times: 1 });
  await openExtensions(page);

  const actions = page.getByRole("region", { name: "Akcje", exact: true });
  try {
    await requested.promise;
    await expect(page.getByText("Wczytywanie zasobu…")).toBeVisible();
    // The list grows when its rows arrive. A form drawn below it earlier would move, and a click
    // aimed at one of its controls would land elsewhere.
    await expect(actions).toHaveCount(0);
  } finally {
    listed.resolve();
  }
  await expect(page.getByRole("table", { name: "Testowe elementy" })).toBeVisible();
  await expect(actions.getByRole("button", { name: "Uruchom akcję", exact: true })).toBeVisible();
});
