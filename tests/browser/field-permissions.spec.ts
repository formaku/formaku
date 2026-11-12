import { test, expect, type Page } from "@playwright/test";

async function signIn(page: Page, email: string) {
  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill(email);
  await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
  await expect(page.getByRole("heading", { name: "Twój warsztat." })).toBeVisible();
}

test("field permissions persist across draft saves and restoration", async ({ page: admin, browser }, info) => {
  const key = "fields_" + info.project.name + "_" + Date.now();
  const name = "Field permissions " + key;
  await signIn(admin, "admin@formaku.test");
  const headers = {
    Accept: "application/json",
    "X-CSRF-TOKEN": (await admin.locator('meta[name="csrf-token"]').getAttribute("content"))!,
  };
  const api = "/formaku/api/v1";
  const schema = await admin.request.post(api + "/collections", {
    headers,
    data: {
      key, name, kind: "data",
      definition: {
        fields: [
          { key: "title", label: "Title", type: "text", required: true },
          { key: "status_note", label: "Status note", type: "text", required: true, access: { write: [] } },
          { key: "internal_note", label: "Internal note", type: "text", private: true, access: { read: [] } },
        ],
      },
    },
  });
  expect(schema.ok()).toBeTruthy();
  const { id: collectionId } = await schema.json();
  const records = api + "/collections/" + collectionId + "/records";
  const initial = await admin.request.post(records, {
    headers,
    data: { version: 0, data: { title: "Previous title", status_note: "Previous status", internal_note: "Previous internal value" } },
  });
  expect(initial.ok()).toBeTruthy();
  const { id, version } = await initial.json();
  const current = await admin.request.post(records, {
    headers,
    data: { id, version, data: { title: "Current title", status_note: "Current status", internal_note: "Current internal value" } },
  });
  expect(current.ok()).toBeTruthy();

  await admin.reload();
  await admin.getByRole("button", { name: "Buduj", exact: true }).click();
  await admin.getByRole("article").filter({ hasText: name }).getByRole("button", { name: "Edytuj model" }).click();
  await admin.getByText("Uprawnienia pól", { exact: true }).click();
  const internal = admin.locator("details section").filter({ has: admin.getByRole("heading", { name: "Internal note", exact: true }) });
  await expect(internal.getByRole("checkbox", { name: "Ogranicz odczyt" })).toBeChecked();
  await internal.getByLabel("Role z odczytem").fill("publisher");
  await admin.getByRole("button", { name: "Aktywuj konfigurację" }).click();
  await expect(admin.getByRole("status")).toContainText("Konfiguracja aktywowana");

  const editorContext = await browser.newContext({ baseURL: info.project.use.baseURL });
  try {
    const editor = await editorContext.newPage();
    await signIn(editor, "field-editor@formaku.test");
    const addRecord = editor.getByRole("button", { name: "Dodaj rekord", exact: true });
    await editor.getByRole("button", { name: "Strony i oferta", exact: true }).click();
    await expect(addRecord).toBeVisible();
    await editor.getByRole("button", { name, exact: true }).click();
    const editableRow = editor.getByRole("row").filter({ hasText: "Current title" });
    await expect(editableRow).toBeVisible();
    await expect(addRecord).toHaveCount(0);
    await editableRow.getByRole("button", { name: "Edytuj" }).click();
    await expect(editor.getByText("Status note · Tylko odczyt", { exact: true })).toBeVisible();
    await expect(editor.getByText("Current status", { exact: true })).toBeVisible();
    await expect(editor.getByText("Internal note", { exact: true })).toHaveCount(0);

    await editor.getByLabel("Title", { exact: true }).fill("Editor title");
    const saved = editor.waitForResponse((response) =>
      response.url().endsWith(records) && response.request().method() === "POST",
    );
    await editor.getByRole("button", { name: "Zapisz szkic", exact: true }).click();
    const saveResponse = await saved;
    expect(saveResponse.ok()).toBeTruthy();
    expect(saveResponse.request().postDataJSON().data).toEqual({ title: "Editor title" });
    await expect(editor.getByRole("status")).toContainText("Szkic zapisany");

    await editor.getByRole("button", { name: "Historia wersji" }).click();
    const restore = editor.getByRole("button", { name: "Przywróć do szkicu" });
    await expect(restore).toHaveCount(3);
    await restore.last().click();
    await expect(editor.getByLabel("Title", { exact: true })).toHaveValue("Previous title");
    await expect(editor.getByText("Current status", { exact: true })).toBeVisible();

    const final = await admin.request.get(api + "/records/" + id, { headers });
    expect(final.ok()).toBeTruthy();
    expect((await final.json()).data).toEqual({
      title: "Previous title",
      status_note: "Current status",
      internal_note: "Current internal value",
    });
  } finally {
    await editorContext.close();
  }
});
