import { expect, test } from "@playwright/test";

test("undo block removal restores the current draft across autosave", async ({
  page,
  request: guest,
}, info) => {
  test.setTimeout(90_000);
  const slug = "undo-block-removal-" + info.project.name + "-" + Date.now();
  const title = "Undo removal " + slug;
  const hero = (title: string) => ({ type: "hero", props: { title } });
  const container = {
    type: "container",
    props: { layout: "stack" },
    children: [hero("Restored nested content")],
  };
  const blocks = [
    hero("Before layout"),
    {
      type: "columns",
      props: { columns: 2 },
      children: [hero("Before nested block"), container, hero("After nested block")],
    },
    hero("After layout"),
  ];

  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill("admin@formaku.test");
  await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute("content");
  const headers = { "X-CSRF-TOKEN": csrf ?? "" };
  const collections = await (await page.request.get("/formaku/api/v1/collections")).json();
  const pages = collections.find((collection: { key: string }) => collection.key === "pages");
  const recordsPath = `/formaku/api/v1/collections/${pages.id}/records`;

  const created = await page.request.post(recordsPath, {
    headers,
    data: { version: 0, data: { title, slug, blocks } },
  });
  await expect(created).toBeOK();
  const record = await created.json();
  await expect(await page.request.post(`/formaku/api/v1/records/${record.id}/publish`, {
    headers,
    data: { version: record.version },
  })).toBeOK();
  await expect(await page.request.post(recordsPath, {
    headers,
    data: {
      version: 0,
      data: { title: title + " other", slug: slug + "-other", blocks: [hero("Other draft")] },
    },
  })).toBeOK();

  const selectPages = page.getByRole("button", { name: "Strony i oferta", exact: true });
  await selectPages.click();
  await page.getByRole("row").filter({ has: page.getByText(title, { exact: true }) })
    .getByRole("button", { name: "Edytuj" }).click();

  const roots = page.locator('article[draggable="true"]');
  const layout = roots.nth(1);
  const nested = layout.getByRole("article", { name: "Kontener", exact: true });
  const removeNested = { name: "Usuń blok z układu", exact: true };
  const removeRoot = { name: "Usuń sekcję", exact: true };
  const undo = page.getByRole("button", { name: "Cofnij usunięcie bloku", exact: true });
  const publish = page.getByRole("button", { name: "Opublikuj treść" });
  const headingField = { exact: true };
  const nextSave = () => page.waitForResponse((response) =>
    new URL(response.url()).pathname === recordsPath &&
    response.request().method() === "POST" &&
    response.request().postDataJSON().id === record.id
  );

  const autosave = nextSave();
  await nested.locator(":scope > div:first-child").getByRole("button", removeNested).click();
  await expect(nested).toHaveCount(0);
  const saved = await autosave;
  expect(saved.ok()).toBe(true);
  expect((await saved.json()).data.blocks[1].children.map((block: { type: string }) => block.type))
    .toEqual(["hero", "hero"]);
  await expect(publish).toBeEnabled();
  await expect(undo).toBeEnabled();

  const publicBeforeUndo = await guest.get("/p/" + slug);
  await expect(publicBeforeUndo).toBeOK();
  expect(await publicBeforeUndo.text()).toContain("Restored nested content");

  await undo.click();
  await expect(layout.getByRole("article").nth(1)).toHaveAccessibleName("Kontener");
  await expect(nested.getByLabel("title", headingField)).toHaveValue("Restored nested content");
  await layout.getByRole("button", removeRoot).click();
  await expect(roots).toHaveCount(2);
  await undo.click();
  await expect(roots).toHaveCount(3);
  await expect(nested.getByLabel("title", headingField)).toHaveValue("Restored nested content");
  await expect(undo).toBeHidden();
  await nested.getByLabel("title", headingField).fill("Edited after restore");

  const manualSave = nextSave();
  await page.getByRole("button", { name: "Zapisz szkic" }).click();
  expect((await manualSave).ok()).toBe(true);
  await expect(publish).toBeEnabled();
  await publish.click();
  await expect(page.getByRole("status")).toContainText("Treść opublikowana");

  const otherAutosave = nextSave();
  await roots.last().getByRole("button", removeRoot).click();
  expect((await otherAutosave).ok()).toBe(true);
  await expect(publish).toBeEnabled();
  await expect(undo).toBeEnabled();
  await selectPages.click();
  await page.getByRole("row").filter({ hasText: title + " other" })
    .getByRole("button", { name: "Edytuj" }).click();
  await expect(undo).toBeHidden();
  await expect(roots.first().getByLabel("title", headingField)).toHaveValue("Other draft");

  await page.context().clearCookies();
  await page.goto("/p/" + slug);
  await expect(page.locator(".block-layout-columns-2 .block-layout-stack h1"))
    .toHaveText("Edited after restore");
  await expect(page.locator(".prose h1")).toHaveText([
    "Before layout", "Before nested block", "Edited after restore", "After nested block", "After layout",
  ]);
});
