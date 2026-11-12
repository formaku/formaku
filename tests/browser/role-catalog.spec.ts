import { test, expect, type Page } from "@playwright/test";

async function signIn(page: Page, email: string) {
  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill(email);
  await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
  await expect(page.getByRole("heading", { name: "Twój warsztat." })).toBeVisible();
}

test("the model editor adds configured and custom roles without implicit grants", async ({ page: admin, browser }, info) => {
  const key = "role_catalog_" + info.project.name + "_" + Date.now();
  const name = "Roles " + key;
  const customRole = "reviewer.external";
  await signIn(admin, "admin@formaku.test");
  await admin.getByRole("button", { name: "Buduj", exact: true }).click();
  await admin.getByRole("button", { name: "Nowa kolekcja" }).click();
  await admin.getByLabel("Nazwa", { exact: true }).fill(name);
  await admin.getByLabel("Identyfikator").fill(key);

  async function saveModel() {
    const saved = admin.waitForResponse((response) =>
      response.url().endsWith("/api/v1/collections") && response.request().method() === "POST",
    );
    await admin.getByRole("button", { name: "Aktywuj konfigurację" }).click();
    const response = await saved;
    expect(response.ok()).toBeTruthy();
    const model = await response.json();
    await expect(admin.getByRole("status")).toContainText("Konfiguracja aktywowana");
    return model.definition;
  }

  async function reopenModel() {
    await admin.getByRole("article").filter({ hasText: name }).getByRole("button", { name: "Edytuj model" }).click();
  }

  await admin.getByRole("button", { name: "Skonfiguruj dostęp ról" }).click();
  await admin.getByLabel("Rola z konfiguracji").selectOption("editor");
  await admin.getByRole("button", { name: "Dodaj rolę z konfiguracji" }).click();
  const editorRule = admin.getByRole("group", { name: /\(editor\)/ });
  await expect(editorRule.getByLabel("Widoczne rekordy")).toHaveValue("none");
  await expect(editorRule.getByRole("checkbox", { name: "Edycja rekordów" })).not.toBeChecked();
  await editorRule.getByLabel("Widoczne rekordy").selectOption("all");

  await admin.getByLabel("Własny klucz roli").fill(customRole);
  await admin.getByRole("button", { name: "Dodaj własną rolę" }).click();
  const customRule = admin.getByRole("group", { name: customRole, exact: true });
  await customRule.getByLabel("Widoczne rekordy").selectOption("own");
  await customRule.getByRole("checkbox", { name: "Edycja rekordów" }).check();

  await admin.getByText("Uprawnienia pól", { exact: true }).click();
  const titleField = admin.locator("details section").filter({
    has: admin.getByRole("heading", { name: "Tytuł", exact: true }),
  });
  await titleField.getByRole("checkbox", { name: "Ogranicz zapis" }).check();
  await titleField.getByLabel("Rola z konfiguracji").selectOption("publisher");
  await titleField.getByRole("button", { name: "Dodaj rolę z konfiguracji" }).click();
  await expect(titleField.getByLabel("Role z zapisem")).toHaveValue("publisher");

  const definition = await saveModel();
  expect(definition.access).toEqual({
    editor: { scope: "all", write: false },
    [customRole]: { scope: "own", write: true },
  });
  expect(definition.fields[0].access.write).toEqual(["publisher"]);

  const editorContext = await browser.newContext({ baseURL: info.project.use.baseURL });
  try {
    const editor = await editorContext.newPage();
    await signIn(editor, "field-editor@formaku.test");
    const collection = editor.getByRole("button", { name, exact: true });
    await expect(collection).toBeVisible();

    await reopenModel();
    await admin.getByRole("button", { name: "Usuń regułę roli " + customRole }).click();
    await admin.getByRole("button", { name: "Usuń regułę roli editor" }).click();
    expect(Object.keys((await saveModel()).access)).toHaveLength(0);
    await editor.reload();
    await expect(editor.getByRole("heading", { name: "Twój warsztat." })).toBeVisible();
    await expect(collection).toHaveCount(0);

    await reopenModel();
    await admin.getByRole("button", { name: "Przywróć domyślny dostęp" }).click();
    expect((await saveModel()).access).toBeUndefined();
    await editor.reload();
    await expect(collection).toBeVisible();
  } finally {
    await editorContext.close();
  }
});
