import { expect, test } from "@playwright/test";

test("move blocks between layouts and the page through publication", async ({
  page,
  request: guest,
}, info) => {
  test.setTimeout(90_000);
  const slug = "move-blocks-" + info.project.name + "-" + Date.now();
  const title = "Moving blocks " + slug;
  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill("admin@formaku.test");
  await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();

  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute("content");
  const collections = await (await page.request.get("/formaku/api/v1/collections")).json();
  const pages = collections.find((collection: { key: string }) => collection.key === "pages");
  const hero = (title: string) => ({ uid: crypto.randomUUID(), type: "hero", props: { title } });
  await expect(await page.request.post(`/formaku/api/v1/collections/${pages.id}/records`, {
    headers: { "X-CSRF-TOKEN": csrf ?? "" },
    data: {
      version: 0,
      data: {
        title,
        slug,
        blocks: [
          { uid: crypto.randomUUID(), type: "columns", props: { columns: 2 }, children: [hero("Moved between layouts")] },
          { uid: crypto.randomUUID(), type: "container", props: { layout: "stack" }, children: [hero("Moved to page level")] },
          hero("Stayed on page"),
        ],
      },
    },
  })).toBeOK();

  await page.getByRole("button", { name: "Strony i oferta", exact: true }).click();
  await page.getByRole("row").filter({ hasText: title }).getByRole("button", { name: "Edytuj" }).click();
  const roots = page.locator('article[draggable="true"]');
  const rootColumns = roots.first();
  const rootContainer = roots.nth(1);
  const sections = { name: "Sekcja główna", exact: true };
  const firstBlock = rootColumns.getByRole("article", sections);
  const trigger = { name: "Przenieś blok", exact: true };
  const dialog = page.getByRole("dialog", trigger);
  const destination = dialog.getByLabel("Miejsce docelowe");
  const move = dialog.getByRole("button", { name: "Przenieś", exact: true });

  await firstBlock.getByRole("button", trigger).click();
  await expect(dialog).toBeVisible();
  await expect(destination.getByRole("option", { name: "1 · Kolumny", exact: true })).toHaveCount(0);
  await page.keyboard.press("Escape");
  await expect(dialog).toBeHidden();
  await expect(firstBlock.getByLabel("title", { exact: true })).toHaveValue("Moved between layouts");

  await firstBlock.getByRole("button", trigger).click();
  await destination.selectOption({ label: "2 · Kontener" });
  await move.click();
  await expect(firstBlock).toHaveCount(0);
  await expect(rootContainer.getByRole("article", sections)).toHaveCount(2);
  await expect(rootContainer.getByRole("article", sections).last().getByLabel("title", { exact: true }))
    .toHaveValue("Moved between layouts");

  await rootContainer.locator(":scope > div:first-child").getByRole("button", trigger).click();
  await expect(destination.getByRole("option", { name: "2 · Kontener", exact: true })).toHaveCount(0);
  await destination.selectOption({ label: "1 · Kolumny" });
  await move.click();
  await expect(roots).toHaveCount(2);
  const nestedContainer = rootColumns.getByRole("article", { name: "Kontener", exact: true });
  const nestedSections = nestedContainer.getByRole("article", sections);
  await expect(nestedSections).toHaveCount(2);

  await nestedSections.first().getByRole("button", trigger).click();
  await destination.selectOption({ label: "Poziom strony" });
  await move.click();
  await expect(roots).toHaveCount(3);
  await expect(nestedSections).toHaveCount(1);
  await expect(roots.last().getByLabel("title", { exact: true })).toHaveValue("Moved to page level");

  const status = page.getByRole("status");
  await page.getByRole("button", { name: "Zapisz szkic" }).click();
  await expect(status).toContainText("Szkic zapisany");
  expect((await guest.get("/p/" + slug)).status()).toBe(404);
  await page.getByRole("button", { name: "Opublikuj treść" }).click();
  await expect(status).toContainText("Treść opublikowana");

  await page.context().clearCookies();
  await page.goto("/p/" + slug);
  await expect(page.locator(".block-layout-columns-2 .block-layout-stack h1"))
    .toHaveText("Moved between layouts");
  await expect(page.locator(".prose h1")).toHaveText([
    "Moved between layouts", "Stayed on page", "Moved to page level",
  ]);
});
