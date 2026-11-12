import { expect, test, type Page } from "@playwright/test";

const api = "/formaku/api/v1";

async function signIn(page: Page, email: string) {
  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill(email);
  await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
  await expect(page.getByRole("heading", { name: "Twój warsztat." })).toBeVisible();
}

async function postFixture(page: Page, path: string, data: object) {
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute("content");
  const response = await page.request.post(api + path, {
    headers: { Accept: "application/json", "X-CSRF-TOKEN": csrf ?? "" },
    data,
  });
  await expect(response).toBeOK();
  return await response.json() as { id: number };
}

test("saved content has a shareable record URL that survives refresh", async ({ page }, info) => {
  test.slow();
  const unique = info.project.name + "_" + Date.now();
  const name = "Linked content " + unique;
  const originalTitle = "Linked draft " + unique;
  const editedTitle = "Edited from the link " + unique;

  await signIn(page, "admin@formaku.test");
  const collection = await postFixture(page, "/collections", {
    key: "record_link_content_" + unique,
    name,
    kind: "content",
    definition: {
      fields: [
        { key: "title", label: "Linked title", type: "text", required: true },
        { key: "slug", label: "Linked slug", type: "text", required: true },
      ],
    },
  });
  const record = await postFixture(page, `/collections/${collection.id}/records`, {
    version: 0,
    data: { title: originalTitle, slug: "linked-" + unique.replaceAll("_", "-") },
  });

  await page.goto("/formaku?source=qa");
  await page.getByRole("button", { name, exact: true }).click();
  await page.getByRole("row").filter({
    has: page.getByRole("cell", { name: originalTitle, exact: true }),
  }).getByRole("button", { name: "Edytuj", exact: true }).click();

  const matchesRecord = (url: URL) =>
    url.pathname === "/formaku" &&
    url.searchParams.get("record") === String(record.id) &&
    url.searchParams.get("source") === "qa";
  await expect(page).toHaveURL(matchesRecord);
  const openLink = page.getByRole("link", { name: "Otwórz rekord w nowej karcie", exact: true });
  const popupOpened = page.waitForEvent("popup");
  await openLink.click();
  const linked = await popupOpened;
  try {
    await expect(linked).toHaveURL(matchesRecord);
    const title = linked.getByLabel("Linked title", { exact: true });
    await expect(title).toHaveValue(originalTitle);
    const currentDraftResponse = linked.waitForResponse((response) => {
      const url = new URL(response.url());
      return url.pathname === `${api}/records/${record.id}`
        && url.searchParams.get("history") === "0" && response.ok();
    });
    await linked.reload();
    expect(await (await currentDraftResponse).json()).not.toHaveProperty("history");
    await expect(title).toHaveValue(originalTitle);
    await expect(linked).toHaveURL(matchesRecord);

    await title.fill(editedTitle);
    await linked.getByRole("button", { name: "Zapisz szkic", exact: true }).click();
    await expect(linked.getByRole("status")).toContainText("Szkic zapisany.");
    await linked.reload();
    await expect(title).toHaveValue(editedTitle);
    await linked.getByRole("button", { name: "Wróć do listy", exact: true }).click();
    await expect(linked).toHaveURL((url) => !url.searchParams.has("record") && url.searchParams.get("source") === "qa");
    await expect(linked.getByRole("cell", { name: editedTitle, exact: true })).toBeVisible();
  } finally {
    await linked.close();
  }
});

test("linked data records respect the editor's own-record scope", async ({ page: admin, browser }, info) => {
  test.slow();
  const unique = info.project.name + "_" + Date.now();
  const name = "Linked data " + unique;
  const foreignTitle = "Other owner's record " + unique;
  const ownTitle = "My record " + unique;

  await signIn(admin, "admin@formaku.test");
  const collection = await postFixture(admin, "/collections", {
    key: "record_link_data_" + unique,
    name,
    kind: "data",
    definition: {
      access: { editor: { scope: "own", write: true } },
      fields: [{ key: "title", label: "Linked data title", type: "text", required: true }],
    },
  });
  const records = `/collections/${collection.id}/records`;
  const foreign = await postFixture(admin, records, { version: 0, data: { title: foreignTitle } });

  const context = await browser.newContext({ baseURL: info.project.use.baseURL });
  try {
    const editor = await context.newPage();
    await signIn(editor, "field-editor@formaku.test");
    const own = await postFixture(editor, records, { version: 0, data: { title: ownTitle } });
    const title = editor.getByLabel("Linked data title", { exact: true });
    const unavailable = editor.getByRole("alert").filter({ hasText: "Rekord jest niedostępny." });

    await editor.goto(`/formaku?record=${own.id}`);
    await expect(title).toHaveValue(ownTitle);

    await editor.goto(`/formaku?record=${foreign.id}`);
    await expect(unavailable).toBeVisible();
    await expect(title).toHaveCount(0);
    await expect(editor.getByText(foreignTitle, { exact: true })).toHaveCount(0);

    await editor.goto(`/formaku?record=${Number.MAX_SAFE_INTEGER}`);
    await expect(unavailable).toBeVisible();
    await expect(title).toHaveCount(0);

    await editor.getByRole("button", { name, exact: true }).click();
    await expect(editor.getByRole("cell", { name: ownTitle, exact: true })).toBeVisible();
    await expect(editor.getByRole("cell", { name: foreignTitle, exact: true })).toHaveCount(0);
  } finally {
    await context.close();
  }
});
