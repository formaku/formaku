import { readFile } from "node:fs/promises";
import { expect, test } from "@playwright/test";

test("a stale autosave preserves local edits until the current draft is loaded", async ({ page }, info) => {
  test.slow();
  const slug = "draft-conflict-" + info.project.name + "-" + Date.now();
  const initialTitle = "Initial " + slug;
  const serverTitle = "Saved elsewhere " + slug;
  const firstLocalTitle = "Unsaved " + slug;
  const revisedLocalTitle = "More edits " + slug;
  const recoveredTitle = "Saved after conflict " + slug;

  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill("admin@formaku.test");
  await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute("content");
  const headers = { "X-CSRF-TOKEN": csrf ?? "" };
  const api = "/formaku/api/v1";
  const collectionsResponse = await page.request.get(api + "/collections");
  await expect(collectionsResponse).toBeOK();
  const collections = await collectionsResponse.json();
  const pages = collections.find((collection: { key: string }) => collection.key === "pages");
  const recordsPath = `${api}/collections/${pages.id}/records`;
  const createdResponse = await page.request.post(recordsPath, {
    headers,
    data: { version: 0, data: { title: initialTitle, slug, blocks: [] } },
  });
  await expect(createdResponse).toBeOK();
  const created = await createdResponse.json();
  const recordPath = `${api}/records/${created.id}`;

  await page.getByRole("button", { name: "Strony i oferta", exact: true }).click();
  const row = page.getByRole("row").filter({
    has: page.getByRole("cell", { name: initialTitle, exact: true }),
  });
  await row.getByRole("button", { name: "Edytuj", exact: true }).click();
  const title = page.getByLabel("Tytuł", { exact: true });
  await expect(title).toHaveValue(initialTitle);

  const newerResponse = await page.request.post(recordsPath, {
    headers,
    data: { id: created.id, version: created.version, data: { title: serverTitle, slug, blocks: [] } },
  });
  await expect(newerResponse).toBeOK();
  const newer = await newerResponse.json();
  expect(newer.version).toBeGreaterThan(created.version);

  const saveVersions: number[] = [];
  page.on("request", (request) => {
    if (request.method() !== "POST" || new URL(request.url()).pathname !== recordsPath) return;
    const payload = request.postDataJSON();
    if (payload.id === created.id) saveVersions.push(payload.version);
  });
  const nextSave = () => page.waitForResponse((response) =>
    response.request().method() === "POST" && new URL(response.url()).pathname === recordsPath,
    { timeout: 12000 },
  );
  const staleSave = nextSave();
  await title.fill(firstLocalTitle);
  expect((await staleSave).status()).toBe(409);

  const conflict = page.getByRole("alert").filter({ hasText: "Szkic zmienił się na serwerze." });
  const reload = page.getByRole("button", { name: "Wczytaj aktualny szkic", exact: true });
  const save = page.getByRole("button", { name: "Zapisz szkic", exact: true });
  await expect(conflict).toBeVisible();
  await expect(title).toHaveValue(firstLocalTitle);
  await expect(save).toBeDisabled();
  expect(saveVersions).toEqual([created.version]);

  await title.fill(revisedLocalTitle);
  // Wait beyond the 1.8 s debounce so a repeat save would be observed.
  await page.waitForTimeout(2300);
  await expect(title).toHaveValue(revisedLocalTitle);
  await expect(conflict).toBeVisible();
  await expect(save).toBeDisabled();

  const downloaded = page.waitForEvent("download");
  await page.getByRole("button", { name: "Pobierz moje zmiany", exact: true }).click();
  const copy = await downloaded;
  expect(copy.suggestedFilename()).toBe(`formaku-draft-${created.id}.json`);
  expect(JSON.parse(await readFile(await copy.path(), "utf8")))
    .toEqual({ title: revisedLocalTitle, slug, blocks: [] });

  const dismissal = page.waitForEvent("dialog").then((dialog) => dialog.dismiss());
  await reload.click();
  await dismissal;
  await expect(conflict).toBeVisible();
  await expect(title).toHaveValue(revisedLocalTitle);
  const duringConflict = await page.request.get(recordPath);
  await expect(duringConflict).toBeOK();
  const savedElsewhere = await duringConflict.json();
  expect(savedElsewhere.version).toBe(newer.version);
  expect(savedElsewhere.data.title).toBe(serverTitle);
  expect(saveVersions).toEqual([created.version]);

  const confirmation = page.waitForEvent("dialog").then((dialog) => dialog.accept());
  await reload.click();
  await confirmation;
  await expect(conflict).toHaveCount(0);
  await expect(title).toHaveValue(serverTitle);

  const resumedSave = nextSave();
  await title.fill(recoveredTitle);
  const resumed = await resumedSave;
  expect(resumed.ok()).toBe(true);
  const recovered = await resumed.json();
  expect(recovered.data.title).toBe(recoveredTitle);
  expect(recovered.version).toBe(newer.version + 1);
  await expect(page.getByRole("status")).toContainText("Szkic zapisany.");
  await expect(title).toHaveValue(recoveredTitle);
  expect(saveVersions).toEqual([created.version, newer.version]);
});
