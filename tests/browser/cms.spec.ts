import { test, expect, type Page } from "@playwright/test";
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
  await page.getByRole("combobox", { name: "Język panelu" }).selectOption("en");
  await expect(page.getByRole("heading", { name: "Your workspace." })).toBeVisible();
  await expect(page.locator("html")).toHaveAttribute("lang", "en");
  await page.reload();
  await expect(page.getByRole("combobox", { name: "Panel language" })).toHaveValue("en");
  await page.getByRole("button", { name: "Media", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Media library" })).toBeVisible();
  await expect(page.getByLabel("Upload file")).toBeVisible();
  await page.getByRole("combobox", { name: "Panel language" }).selectOption("pl");
  await expect(page.getByRole("heading", { name: "Biblioteka mediów" })).toBeVisible();
  await page.reload();
  await expect(page.getByRole("heading", { name: "Twój warsztat." })).toBeVisible();
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
      "iVBORw0KGgoAAAANSUhEUgAABAAAAABACAIAAAALaJKrAAABaElEQVR4nO3XQQ0AIBDAsBOCQZRgGxfwWJMK2HezzgYAACLm" +
      "ewEAAPCMAQAAgBADAAAAIQYAAABCDAAAAIQYAAAACDEAAAAQYgAAACDEAAAAQIgBAACAEAMAAAAhBgAAAEIMAAAAhBgAAAAI" +
      "MQAAABBiAAAAIMQAAABAiAEAAIAQAwAAACEGAAAAQgwAAACEGAAAAAgxAAAAEGIAAAAgxAAAAECIAQAAgBADAAAAIQYAAABC" +
      "DAAAAIQYAAAACDEAAAAQYgAAACDEAAAAQIgBAACAEAMAAAAhBgAAAEIMAAAAhBgAAAAIMQAAABBiAAAAIMQAAABAiAEAAIAQ" +
      "AwAAACEGAAAAQgwAAACEGAAAAAgxAAAAEGIAAAAgxAAAAECIAQAAgBADAAAAIQYAAABCDAAAAIQYAAAACDEAAAAQYgAAACDE" +
      "AAAAQIgBAACAEAMAAAAhBgAAAEIMAAAAhBgAAAAIMQAAABByAVPpD7UJl2IgAAAAAElFTkSuQmCC",
      "base64",
    ),
  });
  await expect(page.getByRole("status")).toContainText("Plik zapisany");
  const card = page.getByRole("article").filter({ hasText: originalName });
  const thumbnail = card.locator("img");
  await expect(thumbnail).toHaveAttribute("src", /\/media\/\d+$/);
  const fileUrl = (await thumbnail.getAttribute("src"))!;

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
  await page.getByRole("button", { name: "container", exact: true }).click();
  await page.getByLabel("Typ bloku w układzie").selectOption("image");
  await page.getByRole("button", { name: "Dodaj blok do układu" }).click();
  const chooseImage = page.getByRole("button", { name: /^(Wybierz|Zmień) obraz$/ });
  await chooseImage.click();
  const picker = page.getByRole("dialog", { name: "Wybierz obraz" });
  await picker.getByRole("button", { name: originalName, exact: true }).click();
  await expect(picker).toBeHidden();
  await page.getByLabel("Podpis obrazu").fill("Browser image caption");
  await page.getByRole("button", { name: "Zapisz szkic" }).click();
  await expect(page.getByRole("status")).toContainText("Szkic zapisany");
  await page.getByRole("button", { name: "Pokaż podgląd" }).click();
  const preview = page.frameLocator('iframe[title="Podgląd zapisanego szkicu"]');
  await expect(preview.getByRole("heading", { name: "Saved draft" })).toBeVisible();
  const previewImage = preview.locator("figure img");
  await expect(previewImage).toHaveAttribute("src", fileUrl);
  await expect(previewImage).toHaveAttribute(
    "srcset",
    /\/variants\/480 480w,\s+.+\/variants\/960 960w/,
  );
  await expect.poll(() => previewImage.evaluate((image: HTMLImageElement) => image.naturalWidth)).toBeGreaterThan(0);
  for (const width of [480, 960]) {
    const variant = await page.request.get(`${fileUrl}/variants/${width}`);
    expect(variant.status()).toBe(200);
    expect(variant.headers()["content-type"]).toMatch(/^image\/webp\b/);
  }
  await expect(preview.locator("figcaption")).toHaveText("Browser image caption");
  await chooseImage.click();
  await expect(picker).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(picker).toBeHidden();
  await expect(page.getByRole("button", { name: "Opublikuj treść", exact: true })).toBeEnabled();
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
  await card.getByRole("button", { name: "Użycie pliku" }).click();
  const usage = page.getByRole("dialog", { name: "Użycie pliku" });
  const usedBy = usage.getByRole("listitem").filter({ hasText: "Browser test " + info.project.name });
  await expect(usedBy.getByText("Szkic", { exact: true })).toBeVisible();
  await expect(usedBy.getByText("Publikacja", { exact: true })).toBeVisible();
  await usage.getByRole("button", { name: "Zamknij" }).click();
  await expect(usage).toBeHidden();
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

  if (info.project.name === "chromium") {
    const publicMenu = page.getByRole("navigation", { name: "Menu główne" });
    await expect(publicMenu.getByRole("link")).toHaveText(["Portal klienta", "Panel"]);
    await page.goto("/formaku");
    await page.getByRole("button", { name: "Ustawienia", exact: true }).click();
    const header = page.getByRole("group", { name: "Menu nagłówka" });
    await header.getByRole("button", { name: "Dodaj link", exact: true }).click();
    await header.getByLabel("Etykieta linku 3").fill("Oferta Formaku");
    await header.getByLabel("Adres linku 3").fill("/p/" + slug);
    await header.getByRole("button", { name: "Przesuń link 3 w górę" }).click();
    const footer = page.getByRole("group", { name: "Linki stopki" });
    await footer.getByRole("button", { name: "Dodaj link", exact: true }).click();
    await footer.getByLabel("Etykieta linku 1").fill("Dokumentacja Formaku");
    await footer.getByLabel("Adres linku 1").fill("https://formaku.eu/pl/docs/");
    await page.getByLabel("Tekst stopki").fill("Formaku <alpha>");
    await page.getByRole("button", { name: "Zapisz menu i stopkę" }).click();
    await expect(page.getByText("Zapisano menu i stopkę.", { exact: true })).toBeVisible();
    await page.goto("/p/" + slug);
    await expect(publicMenu.getByRole("link")).toHaveText(["Portal klienta", "Oferta Formaku", "Panel"]);
    await expect(publicMenu.getByRole("link", { name: "Oferta Formaku" }))
      .toHaveAttribute("href", "/p/" + slug);
    const publicFooter = page.getByRole("contentinfo");
    await expect(publicFooter.getByText("Formaku <alpha>", { exact: true })).toBeVisible();
    await expect(publicFooter.getByRole("link", { name: "Dokumentacja Formaku" }))
      .toHaveAttribute("href", "https://formaku.eu/pl/docs/");
  }
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

  const backToList = page.getByRole("button", { name: "Wróć do listy" });
  await expect(backToList).toBeEnabled();
  const reload = Promise.withResolvers<void>();
  await page.route(`**/formaku/api/v1/collections/${pages.id}/records`, async (route) => {
    await reload.promise;
    await route.continue();
  }, { times: 1 });
  const savedRow = page.getByRole("row").filter({ hasText: title });
  await backToList.click();
  try {
    await expect(savedRow).toHaveCount(0);
  } finally {
    reload.resolve();
  }
  await savedRow.getByRole("button", { name: "Edytuj" }).click();
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

  await test.step("copy a page template into a new draft", () =>
    verifyPageTemplates(page, slug),
  );
});

test("collection forms save SEO metadata, related records and a private file", async ({
  page, request: guest,
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

  await page.goto("/formaku");
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute("content");
  const post = async (path: string, data: object) => {
    const response = await page.request.post("/formaku/api/v1" + path, {
      data, headers: { "X-CSRF-TOKEN": csrf ?? "" },
    });
    await expect(response).toBeOK();
    return response.json();
  };
  const nameField = { key: "name", label: "Nazwa", type: "text" };
  const collections = await (await page.request.get("/formaku/api/v1/collections")).json();
  const target = collections.find((entry: { key: string }) => entry.key === "relation_targets");
  const source = await post("/collections", {
    key: "source_" + suffix,
    name: "Relation source " + suffix,
    kind: "data",
    definition: { fields: [
      nameField,
      { key: "links", label: "Powiązane rekordy", type: "relation", target: target.id },
      { key: "attachment", label: "Załącznik", type: "media", private: true },
    ] },
  });
  const filename = "internal-" + suffix + ".pdf";
  const upload = await page.request.post("/formaku/api/v1/media", {
    headers: { "X-CSRF-TOKEN": csrf ?? "", Accept: "application/json" },
    multipart: {
      visibility: "private",
      file: { name: filename, mimeType: "application/pdf", buffer: Buffer.from("%PDF-1.4\n%%EOF\n") },
    },
  });
  await expect(upload).toBeOK();
  const attachment = await upload.json();
  const optionsUrl = `/formaku/api/v1/collections/${target.id}/relation-options`;
  const newest = (await (await page.request.get(optionsUrl)).json()).data[0];
  const oldest = (await (await page.request.get(optionsUrl + "?page=2")).json()).data[0];
  await page.reload();
  await page.getByRole("button", { name: source.name, exact: true }).click();
  await page.getByRole("button", { name: "Dodaj rekord" }).click();
  await page.getByLabel("Nazwa", { exact: true }).fill("Source record");
  const pickerTrigger = page.getByRole("button", { name: "Wybierz rekordy", exact: true });
  await pickerTrigger.click();
  const picker = page.getByRole("dialog", { name: "Powiązane rekordy" });
  await picker.getByRole("checkbox", { name: "Target 26", exact: true }).check();
  await picker.getByRole("button", { name: "Następna" }).click();
  await picker.getByRole("checkbox", { name: "Target 1", exact: true }).check();
  await picker.getByRole("button", { name: "Zastosuj wybór" }).click();
  await pickerTrigger.click();
  await expect(picker.getByRole("checkbox", { name: "Target 26", exact: true })).toBeChecked();
  await picker.getByRole("checkbox", { name: "Target 26", exact: true }).uncheck();
  await page.keyboard.press("Escape");
  await expect(picker).toBeHidden();

  const chooseFile = page.getByRole("button", { name: "Wybierz plik", exact: true });
  await chooseFile.click();
  const filePicker = page.getByRole("dialog", { name: "Wybierz plik" });
  const fileOption = filePicker.getByRole("button", { name: filename, exact: true });
  await expect(fileOption.getByText("Prywatny", { exact: true })).toBeVisible();
  await fileOption.click();
  await expect(filePicker).toBeHidden();
  await chooseFile.click();
  await expect(fileOption).toHaveAttribute("aria-current", "true");
  await page.keyboard.press("Escape");
  await expect(filePicker).toBeHidden();

  const saveResponse = page.waitForResponse((response) =>
    response.request().method() === "POST" &&
    response.url().endsWith(`/collections/${source.id}/records`),
  );
  await page.getByRole("button", { name: "Zapisz szkic" }).click();
  const saved = await saveResponse;
  expect(saved.ok()).toBe(true);
  const savedData = (await saved.json()).data;
  const savedIds = savedData.links;
  expect(savedIds).toEqual(expect.arrayContaining([oldest.id, newest.id]));
  expect(savedIds).toHaveLength(2);
  expect(savedData.attachment).toBe(attachment.id);
  const fileUrl = `/formaku/media/${attachment.id}`;
  await expect(await page.request.get(fileUrl)).toBeOK();
  expect((await guest.get(fileUrl)).status()).toBe(404);
});

async function verifyPageTemplates(page: Page, suffix: string) {
  const templateName = "Template " + suffix;
  const originalSlug = "template-source-" + suffix;
  const copySlug = "template-copy-" + suffix;
  await page.goto("/formaku");
  await page.getByRole("button", { name: "Strony i oferta", exact: true }).click();
  const addRecord = page.getByRole("button", { name: "Dodaj rekord" });
  const title = page.getByLabel("Tytuł", { exact: true });
  const slug = page.getByLabel("Adres URL", { exact: true });
  const heroTitle = page.getByLabel("title", { exact: true });
  const status = page.getByRole("status");

  await addRecord.click();
  await title.fill("Template source " + suffix);
  await slug.fill(originalSlug);
  await page.getByRole("button", { name: "hero", exact: true }).click();
  await heroTitle.fill("Original template section");
  await page.getByRole("button", { name: "Zapisz szkic" }).click();
  await expect(status).toContainText("Szkic zapisany");
  await page.getByLabel("Nazwa szablonu").fill(templateName);
  await page.getByRole("button", { name: "Zapisz jako szablon" }).click();
  await expect(status).toContainText("Zapisano szablon strony");

  await page.getByRole("button", { name: "Wróć do listy" }).click();
  await addRecord.click();
  await page.getByRole("button", { name: "Pokaż szablony kolekcji" }).click();
  await page.getByLabel("Szablon kolekcji").selectOption({ label: templateName });
  await page.getByRole("button", { name: "Wstaw sekcje szablonu" }).click();
  await expect(heroTitle).toHaveValue("Original template section");
  await expect(title).toHaveValue("");
  await expect(slug).toHaveValue("");
  await title.fill("Template copy " + suffix);
  await slug.fill(copySlug);
  await heroTitle.fill("Edited copy");
  await page.getByRole("button", { name: "Zapisz szkic" }).click();
  await expect(status).toContainText("Szkic zapisany");
  expect((await page.request.get("/p/" + copySlug)).status()).toBe(404);
  await page.getByRole("button", { name: "Opublikuj treść" }).click();
  await expect(status).toContainText("Treść opublikowana");
  await expect(await page.request.get("/p/" + copySlug)).toBeOK();

  await page.getByRole("button", { name: "Wróć do listy" }).click();
  await addRecord.click();
  await page.getByRole("button", { name: "Pokaż szablony kolekcji" }).click();
  const chooser = page.getByLabel("Szablon kolekcji");
  await chooser.selectOption({ label: templateName });
  await page.getByRole("button", { name: "Wstaw sekcje szablonu" }).click();
  await expect(heroTitle).toHaveValue("Original template section");
  await page.getByRole("button", { name: "Usuń szablon" }).click();
  await page.getByRole("button", { name: "Potwierdź usunięcie" }).click();
  await expect(status).toContainText("Usunięto szablon strony");
  await expect(chooser.getByRole("option", { name: templateName })).toHaveCount(0);
  await expect(await page.request.get("/p/" + copySlug)).toBeOK();
}

test("system theme follows visitor preferences while manual themes take precedence", async ({
  page,
  browser,
  baseURL,
}, info) => {
  test.skip(info.project.name !== "chromium", "Only one project writes shared site settings.");
  test.setTimeout(60_000);
  await page.emulateMedia({ colorScheme: "dark" });
  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill("admin@formaku.test");
  await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
  await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
  await page.getByRole("button", { name: "Ustawienia", exact: true }).click();

  const theme = page.getByLabel("Motyw witryny");
  const saveTheme = async (value: string) => {
    await theme.selectOption(value);
    await page.getByRole("button", { name: "Zapisz menu i stopkę" }).click();
    await expect(page.getByText("Zapisano menu i stopkę.", { exact: true })).toBeVisible();
  };
  const visitor = await browser.newContext({ baseURL, javaScriptEnabled: false });
  try {
    const publicPage = await visitor.newPage();
    const palette = () => publicPage.evaluate(() => {
      const style = getComputedStyle(document.documentElement);
      return ["--color-base-100", "--formaku-hero-background"].map(
        (name) => style.getPropertyValue(name).trim(),
      );
    });

    await saveTheme("light");
    await publicPage.emulateMedia({ colorScheme: "dark" });
    await publicPage.goto("/p/start");
    await expect(publicPage.locator("html")).toHaveAttribute("data-theme", "light");
    const light = await palette();
    expect(light.every(Boolean)).toBe(true);

    await saveTheme("dark");
    await publicPage.emulateMedia({ colorScheme: "light" });
    await publicPage.reload();
    await expect(publicPage.locator("html")).toHaveAttribute("data-theme", "dark");
    const dark = await palette();
    expect(dark.every(Boolean)).toBe(true);
    expect(dark).not.toEqual(light);

    await expect(theme.getByRole("option", { name: "Zgodny z systemem" })).toHaveAttribute("value", "system");
    await saveTheme("system");
    await publicPage.reload();
    await expect(publicPage.locator("html")).not.toHaveAttribute("data-theme");
    await expect.poll(palette).toEqual(light);
    await publicPage.emulateMedia({ colorScheme: "dark" });
    await expect.poll(palette).toEqual(dark);
    await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
  } finally {
    await visitor.close();
  }
});
