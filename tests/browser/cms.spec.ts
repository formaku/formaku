import { test, expect } from "@playwright/test";
test("browser authoring and publication", async ({ page }, info) => {
  const slug = "browser-" + info.project.name + "-" + Date.now();
  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill("admin@formaku.test");
  await page
    .getByLabel("Hasło", { exact: true })
    .fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
  await expect(
    page.getByRole("heading", { name: "Twój warsztat." }),
  ).toBeVisible();
  await page.screenshot({
    path: `test-results/dashboard-${info.project.name}.png`,
    fullPage: true,
  });
  const originalName = slug + ".png";
  const updatedName = slug + "-renamed.png";
  await page.getByRole("button", { name: "Media", exact: true }).click();
  await page.getByLabel("Prześlij plik").setInputFiles({
    name: originalName,
    mimeType: "image/png",
    buffer: Buffer.from(
      "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jP1sAAAAASUVORK5CYII=",
      "base64",
    ),
  });
  await expect(page.getByRole("status")).toContainText("Plik zapisany");
  const card = page.getByRole("article").filter({ hasText: originalName });
  const thumbnail = card.locator("img");
  await expect(thumbnail).toHaveAttribute("src", /\/media\/\d+$/);
  const fileUrl = (await thumbnail.getAttribute("src"))!;
  const mediaId = fileUrl.split("/").at(-1)!;

  await page
    .getByRole("button", { name: "Strony i oferta", exact: true })
    .click();
  await page.getByRole("button", { name: "Dodaj rekord" }).click();
  await page
    .getByLabel("Tytuł", { exact: true })
    .fill("Browser test " + info.project.name);
  await page.getByLabel("Adres URL", { exact: true }).fill(slug);
  await page.getByRole("button", { name: "hero", exact: true }).click();
  await page.getByLabel("title", { exact: true }).fill("Saved draft");
  await page.getByRole("button", { name: "image", exact: true }).click();
  await page.getByLabel("media_id", { exact: true }).fill(mediaId);
  await page.getByRole("button", { name: "Zapisz szkic" }).click();
  await expect(page.getByRole("status")).toContainText("Szkic zapisany");
  await page.getByRole("button", { name: "Pokaż podgląd" }).click();
  const preview = page.frameLocator('iframe[title="Podgląd zapisanego szkicu"]');
  await expect(preview.getByRole("heading", { name: "Saved draft" })).toBeVisible();
  const previewWidth = () =>
    preview.locator("body").evaluate((body) => body.ownerDocument.defaultView?.innerWidth);
  await expect.poll(previewWidth).toBe(1280);
  await page.getByLabel("Szerokość podglądu").selectOption("tablet");
  await expect.poll(previewWidth).toBe(768);
  await page.getByLabel("Szerokość podglądu").selectOption("mobile");
  await expect.poll(previewWidth).toBe(390);
  await expect(page.getByRole("link", { name: "Podgląd w nowej karcie" }))
    .toHaveAttribute("href", /\/preview\?version=\d+$/);
  expect((await page.request.get("/p/" + slug)).status()).toBe(404);

  await page.getByLabel("title", { exact: true }).fill("Browser publication");
  await page.getByRole("button", { name: "Zapisz szkic" }).click();
  await expect(preview.getByRole("heading", { name: "Browser publication" })).toBeVisible();
  await page
    .getByRole("button", { name: "Opublikuj treść", exact: true })
    .click();
  await expect(page.getByRole("status")).toContainText("Treść opublikowana");

  await page.getByRole("button", { name: "Media", exact: true }).click();
  await card.getByRole("button", { name: "Edytuj metadane" }).click();
  await card.getByLabel("Nazwa pliku").fill(updatedName);
  await card.getByLabel("Tekst alternatywny").fill("Przykładowy obraz testowy");
  await card.getByRole("button", { name: "Zapisz metadane" }).click();
  await expect(page.getByRole("status")).toContainText("Metadane pliku zapisane");
  const updatedCard = page.getByRole("article").filter({ hasText: updatedName });
  await expect(updatedCard.getByRole("img", { name: "Przykładowy obraz testowy" }))
    .toHaveAttribute("src", fileUrl);

  await page.goto("/p/" + slug);
  await expect(
    page.getByRole("heading", { name: "Browser publication" }),
  ).toBeVisible();
  await expect(page.getByRole("img", { name: "Przykładowy obraz testowy" }))
    .toHaveAttribute("src", fileUrl);
});
test("mobile login stays within viewport", async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/login");
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth),
  ).toBeLessThanOrEqual(390);
});

test("nested blocks keep their order and responsive columns after saving", async ({
  page,
}, info) => {
  const slug = "layout-" + info.project.name + "-" + Date.now();
  const title = "Nested layout " + slug;
  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill("admin@formaku.test");
  await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute("content");
  const collections = await (await page.request.get("/formaku/api/v1/collections")).json();
  const pages = collections.find((collection: { key: string }) => collection.key === "pages");
  await expect(await page.request.post(`/formaku/api/v1/collections/${pages.id}/records`, {
    headers: { "X-CSRF-TOKEN": csrf ?? "" },
    data: { version: 0, data: { title, slug, blocks: [{ type: "columns", props: [], children: [] }] } },
  })).toBeOK();
  await page.getByRole("button", { name: "Strony i oferta", exact: true }).click();
  await page.getByRole("row").filter({ hasText: title })
    .getByRole("button", { name: "Edytuj" }).click();
  await expect(page.getByLabel("Liczba kolumn")).toHaveValue("");
  await page.getByLabel("Liczba kolumn").selectOption("3");

  const blockType = page.getByLabel("Typ bloku w układzie");
  const add = page.getByRole("button", { name: "Dodaj blok do układu" });
  await blockType.selectOption("hero");
  await add.click();
  await page.getByRole("article", { name: "Sekcja główna" })
    .getByLabel("title", { exact: true }).fill("Second child");
  await blockType.selectOption("cta");
  await add.click();
  const callToAction = page.getByRole("article", { name: "Wezwanie do działania" });
  await callToAction.getByLabel("title", { exact: true }).fill("First child");
  await callToAction.getByRole("button", { name: "Przesuń blok w górę" }).click();
  await blockType.selectOption("text");
  await add.click();
  await page.getByRole("article", { name: "Tekst" })
    .getByRole("button", { name: "Usuń blok z układu" }).click();
  await expect(page.getByRole("article", { name: "Tekst" })).toHaveCount(0);
  await page.getByRole("button", { name: "Zapisz szkic" }).click();
  await expect(page.getByRole("status")).toContainText("Szkic zapisany");

  await page.getByRole("button", { name: "Wróć do listy" }).click();
  await page.getByRole("row").filter({ hasText: title })
    .getByRole("button", { name: "Edytuj" }).click();
  await expect(page.getByLabel("Liczba kolumn")).toHaveValue("3");
  await page.getByRole("button", { name: "Pokaż podgląd" }).click();
  const preview = page.frameLocator('iframe[title="Podgląd zapisanego szkicu"]');
  const grid = preview.locator(".block-layout-grid");
  await expect(grid.locator("h1, h2")).toHaveText(["First child", "Second child"]);
  const trackCount = () =>
    grid.evaluate((element) =>
      getComputedStyle(element).gridTemplateColumns.split(" ").length,
    );
  await expect.poll(trackCount).toBe(3);
  await page.getByLabel("Szerokość podglądu").selectOption("mobile");
  await expect.poll(trackCount).toBe(1);

  await page.getByRole("button", { name: "Opublikuj treść" }).click();
  await expect(page.getByRole("status")).toContainText("Treść opublikowana");
  await page.goto("/p/" + slug);
  await expect(page.locator(".block-layout-grid h1, .block-layout-grid h2"))
    .toHaveText(["First child", "Second child"]);
});

test("collection SEO fields are saved and used on published pages", async ({
  page,
}, info) => {
  const collectionName = "SEO collection " + info.project.name;
  const suffix = info.project.name + "_" + Date.now();
  const slug = "seo-" + suffix.replace("_", "-");
  const title = "SEO example " + info.project.name;

  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill("admin@formaku.test");
  await page
    .getByLabel("Hasło", { exact: true })
    .fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
  await page.getByRole("button", { name: "Buduj", exact: true }).click();
  await page.getByRole("button", { name: "Nowa kolekcja" }).click();
  await page.getByLabel("Nazwa", { exact: true }).fill(collectionName);
  await page.getByLabel("Identyfikator").fill("seo_" + suffix);
  await page.getByLabel("Rodzaj").selectOption("content");
  await page.getByLabel("Opis SEO").selectOption("title");
  await page.getByRole("button", { name: "Aktywuj konfigurację" }).click();

  const collection = page.getByRole("article").filter({ hasText: collectionName });
  await collection.getByRole("button", { name: "Edytuj model" }).click();
  await expect(page.getByLabel("Opis SEO")).toHaveValue("title");
  await page.getByRole("button", { name: "Buduj", exact: true }).click();
  await collection.getByRole("button", { name: "Otwórz dane" }).click();
  await page.getByRole("button", { name: "Dodaj rekord" }).click();
  await page.getByLabel("Tytuł", { exact: true }).fill(title);
  await page.getByLabel("Adres URL", { exact: true }).fill(slug);
  await page.getByRole("button", { name: "Zapisz szkic" }).click();
  await expect(page.getByRole("status")).toContainText("Szkic zapisany");
  await page.getByRole("button", { name: "Opublikuj treść" }).click();
  await expect(page.getByRole("status")).toContainText("Treść opublikowana");

  await page.goto("/p/" + slug);
  await expect(page.locator('meta[name="description"]')).toHaveAttribute(
    "content",
    title,
  );
});
