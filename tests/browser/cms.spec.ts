import { test, expect, type Page } from "@playwright/test";
test("filter data records by title or name and workflow state across pages", async ({ page }) => {
  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill("admin@formaku.test");
  await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
  await page.getByRole("button", { name: "Workflow queue", exact: true }).click();

  const state = page.getByRole("combobox", { name: "Filtr stanu" });
  await expect(state).toHaveValue("");
  await expect(page.getByRole("cell", { name: "Queue closed", exact: true })).toBeVisible();
  const search = page.getByRole("searchbox", { name: "Szukaj po tytule lub nazwie" });
  await search.fill("Queue");
  const searchResult = page.waitForResponse((response) =>
    new URL(response.url()).searchParams.get("label") === "Queue" && response.ok(),
  );
  await search.press("Enter");
  await searchResult;
  await state.selectOption("new");
  await expect(page.getByRole("cell", { name: "Queue closed", exact: true })).toHaveCount(0);
  await expect(page.getByRole("cell", { name: "Queue 26", exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Następna strona" }).click();

  const row = page.getByRole("row").filter({ has: page.getByRole("cell", { name: "Queue 1", exact: true }) });
  await expect(row).toBeVisible();
  await row.getByRole("button", { name: "Edytuj" }).click();
  await page.getByRole("button", { name: "Wróć do listy" }).click();
  await expect(state).toHaveValue("new");
  await expect(search).toHaveValue("Queue");
  await expect(row).toBeVisible();

  await state.selectOption("closed");
  await expect(row).toHaveCount(0);
  await expect(page.getByRole("cell", { name: "Queue closed", exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "Następna strona" })).toHaveCount(0);
  await state.selectOption("");
  await expect(page.getByRole("cell", { name: "Queue 26", exact: true })).toBeVisible();
  await search.fill("Queue 1");
  await page.getByRole("button", { name: "Szukaj", exact: true }).click();
  await expect(page.getByRole("cell", { name: "Queue 26", exact: true })).toHaveCount(0);
  await expect(row).toBeVisible();
  await search.fill("");
  await search.press("Enter");
  await expect(page.getByRole("cell", { name: "Queue 26", exact: true })).toBeVisible();
});

test("browser authoring and publication", async ({ page }, info) => {
  test.slow();
  const slug = "browser-" + info.project.name + "-" + Date.now();
  const draftTitle = "Browser test " + slug;
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
    .fill(draftTitle);
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
  const date = page.getByLabel("Data publikacji", { exact: true });
  const futureDate = `${new Date().getFullYear() + 1}-02-01T12:00`;
  await date.fill(futureDate);
  await page.getByRole("button", { name: "Zaplanuj publikację", exact: true }).click();
  await expect(page.getByRole("status")).toContainText("Publikacja zaplanowana.");
  await page.getByRole("button", { name: "Wróć do listy", exact: true }).click();
  const publicationFilter = page.getByRole("combobox", { name: "Filtr publikacji" });
  await publicationFilter.selectOption("scheduled");
  await expect(publicationFilter).toHaveValue("scheduled");
  const contentState = page.getByRole("combobox", { name: "Filtr stanu" });
  await contentState.selectOption("new");
  const contentSearch = page.getByRole("searchbox", { name: "Szukaj po tytule lub nazwie" });
  await contentSearch.fill(draftTitle);
  const scheduledSearchResult = page.waitForResponse((response) => {
    const query = new URL(response.url()).searchParams;
    return query.get("label") === draftTitle && query.get("state") === "new"
      && query.get("scheduled") === "1" && response.ok();
  });
  await contentSearch.press("Enter");
  await scheduledSearchResult;
  const draftRow = page.getByRole("row").filter({ hasText: draftTitle });
  await expect(draftRow.locator("time")).toBeVisible();
  await expect(draftRow.getByText("Szkic", { exact: true })).toBeVisible();
  await draftRow.getByRole("button", { name: "Edytuj", exact: true }).click();
  const schedule = page.getByText("Zaplanowany termin publikacji", { exact: false });
  const scheduledTime = await schedule.locator("time").getAttribute("datetime");
  expect(new Date(scheduledTime ?? "").toISOString()).toBe(
    await page.evaluate((value) => new Date(value).toISOString(), futureDate),
  );
  const [cancelledSchedule] = await Promise.all([
    page.waitForResponse((response) =>
      response.request().method() === "DELETE" &&
      /\/formaku\/api\/v1\/records\/\d+\/schedule$/.test(new URL(response.url()).pathname),
    ),
    page.getByRole("button", { name: "Anuluj zaplanowaną publikację", exact: true }).click(),
  ]);
  expect(cancelledSchedule.status()).toBe(200);
  expect(await cancelledSchedule.json()).toMatchObject({ publish_at: null });
  await expect(page.getByRole("status")).toContainText("Anulowano zaplanowaną publikację.");
  await expect(schedule).toHaveCount(0);
  await page.getByRole("button", { name: "Wróć do listy", exact: true }).click();
  await expect(publicationFilter).toHaveValue("scheduled");
  await expect(contentState).toHaveValue("new");
  await expect(contentSearch).toHaveValue(draftTitle);
  await expect(draftRow).toHaveCount(0);
  await publicationFilter.selectOption("unpublished");
  await draftRow.getByRole("button", { name: "Edytuj", exact: true }).click();
  await expect(page.getByLabel("Tytuł", { exact: true })).toHaveValue(draftTitle);
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
  const publicationCheck = page.getByRole("region", { name: "Kontrola przed publikacją" });
  await publicationCheck.getByRole("button", { name: "Sprawdź zapisany szkic" }).click();
  await expect(publicationCheck.getByText("Uzupełnij opis SEO.")).toBeVisible();
  await page
    .getByRole("button", { name: "Opublikuj treść", exact: true })
    .click();
  await expect(page.getByRole("status")).toContainText("Treść opublikowana");

  await page.getByRole("button", { name: "Wróć do listy", exact: true }).click();
  await expect(publicationFilter).toHaveValue("unpublished");
  await expect(draftRow).toHaveCount(0);
  await publicationFilter.selectOption("published");
  await expect(draftRow.getByText("Opublikowano", { exact: true })).toBeVisible();
  await expect(draftRow.getByText("Nowszy szkic", { exact: true })).toHaveCount(0);
  await draftRow.getByRole("button", { name: "Edytuj", exact: true }).click();
  await page.getByLabel("title", { exact: true }).fill("A newer saved draft");
  await page.getByRole("button", { name: "Zapisz szkic" }).click();
  await expect(page.getByRole("status")).toContainText("Szkic zapisany");
  await page.getByRole("button", { name: "Wróć do listy", exact: true }).click();
  await expect(draftRow.getByText("Nowszy szkic", { exact: true })).toBeVisible();
  const changedDrafts = page.waitForResponse((response) => {
    const query = new URL(response.url()).searchParams;
    return query.get("publication") === "changed" && query.get("label") === draftTitle
      && query.get("state") === "new" && response.ok();
  });
  await publicationFilter.selectOption("changed");
  await changedDrafts;
  await expect(publicationFilter).toBeEnabled();
  await expect(draftRow.getByText("Opublikowano", { exact: true })).toBeVisible();
  await expect(draftRow.getByText("Nowszy szkic", { exact: true })).toBeVisible();

  await page.getByRole("button", { name: "Media", exact: true }).click();
  await card.getByRole("button", { name: "Użycie pliku" }).click();
  const usage = page.getByRole("dialog", { name: "Użycie pliku" });
  const usedBy = usage.getByRole("listitem").filter({ hasText: draftTitle });
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
test("withdraw and republish content while keeping the latest draft", async ({ page }, info) => {
  test.slow();
  const slug = "withdraw-" + info.project.name + "-" + Date.now();
  const initialTitle = "Published " + slug;
  const revisedTitle = "Revised " + slug;

  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill("admin@formaku.test");
  await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute("content");
  const api = "/formaku/api/v1";
  const post = async (path: string, data: object) => {
    const response = await page.request.post(api + path, {
      data, headers: { "X-CSRF-TOKEN": csrf ?? "" },
    });
    await expect(response).toBeOK();
    return response.json();
  };

  const collections = await (await page.request.get(api + "/collections")).json();
  const pages = collections.find((collection: { key: string }) => collection.key === "pages");
  const recordPath = `/collections/${pages.id}/records`;
  const created = await post(recordPath, { version: 0, data: { title: initialTitle, slug, blocks: [] } });
  const published = await post(`/records/${created.id}/publish`, { version: created.version });
  const revised = await post(recordPath, {
    id: created.id, version: published.version, data: { title: revisedTitle, slug, blocks: [] },
  });
  await post(`/records/${created.id}/schedule`, {
    version: revised.version, publish_at: new Date(Date.now() + 86_400_000).toISOString(),
  });
  await expect(await page.request.get("/p/" + slug)).toBeOK();

  await page.getByRole("button", { name: "Strony i oferta", exact: true }).click();
  const search = page.getByRole("searchbox", { name: "Szukaj po tytule lub nazwie" });
  await search.fill(revisedTitle);
  await search.press("Enter");
  const row = page.getByRole("row").filter({ has: page.getByRole("cell", { name: revisedTitle, exact: true }) });
  await row.getByRole("button", { name: "Edytuj", exact: true }).click();
  const unpublish = page.getByRole("button", { name: "Wycofaj publikację", exact: true });
  const scheduled = page.getByText("Zaplanowany termin publikacji", { exact: false });
  await expect(scheduled).toBeVisible();

  const cancellation = page.waitForEvent("dialog").then((dialog) => dialog.dismiss());
  await unpublish.click();
  await cancellation;
  await expect(unpublish).toBeEnabled();
  await expect(await page.request.get("/p/" + slug)).toBeOK();

  const confirmation = page.waitForEvent("dialog").then((dialog) => dialog.accept());
  await unpublish.click();
  await confirmation;
  await expect(page.getByRole("status")).toContainText("Publikacja wycofana.");
  await expect(unpublish).toHaveCount(0);
  await expect(scheduled).toHaveCount(0);
  await expect(page.getByLabel("Tytuł", { exact: true })).toHaveValue(revisedTitle);
  expect((await page.request.get("/p/" + slug)).status()).toBe(404);

  await page.getByRole("button", { name: "Wróć do listy", exact: true }).click();
  await page.getByRole("combobox", { name: "Filtr publikacji" }).selectOption("unpublished");
  await expect(row.getByText("Szkic", { exact: true })).toBeVisible();
  await row.getByRole("button", { name: "Edytuj", exact: true }).click();
  await page.getByRole("button", { name: "Opublikuj treść", exact: true }).click();
  await expect(page.getByRole("status")).toContainText("Treść opublikowana.");
  await page.goto("/p/" + slug);
  await expect(page).toHaveTitle(revisedTitle);
});

test("preview a saved revision without restoring it", async ({ page }, info) => {
  test.slow();
  const slug = "history-" + info.project.name + "-" + Date.now();
  const historicalTitle = "Earlier " + slug;
  const publishedTitle = "Published " + slug;
  const draftTitle = "Current " + slug;

  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill("admin@formaku.test");
  await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute("content");
  const api = "/formaku/api/v1";
  const post = async (path: string, data: object) => {
    const response = await page.request.post(api + path, {
      data, headers: { "X-CSRF-TOKEN": csrf ?? "" },
    });
    await expect(response).toBeOK();
    return response.json();
  };

  const collections = await (await page.request.get(api + "/collections")).json();
  const pages = collections.find((collection: { key: string }) => collection.key === "pages");
  const recordsPath = `/collections/${pages.id}/records`;
  const earlier = await post(recordsPath, { version: 0, data: { title: historicalTitle, slug, blocks: [] } });
  let updated = earlier;
  for (let i = 0; i < 24; i++) {
    updated = await post(recordsPath, {
      id: earlier.id, version: updated.version, data: { title: publishedTitle, slug, blocks: [] },
    });
  }
  const recordPath = `/records/${earlier.id}`;
  const published = await post(recordPath + "/publish", { version: updated.version });
  const draft = await post(recordsPath, {
    id: earlier.id, version: published.version, data: { title: draftTitle, slug, blocks: [] },
  });

  await page.getByRole("button", { name: "Strony i oferta", exact: true }).click();
  const row = page.getByRole("row").filter({ has: page.getByRole("cell", { name: draftTitle, exact: true }) });
  await row.getByRole("button", { name: "Edytuj", exact: true }).click();
  const history = page.getByRole("button", { name: "Historia wersji", exact: true });
  await history.click();
  const revisionLinks = page.getByRole("link", { name: "Podgląd rewizji", exact: true });
  await expect(revisionLinks).toHaveCount(25);
  const previewPath = api + recordPath + `/revisions/${earlier.draft_id}/preview`;
  const previewLink = revisionLinks.and(page.locator(`a[href="${previewPath}"]`));
  await expect(previewLink).toHaveCount(0);
  await page.getByRole("button", { name: "Następna strona", exact: true }).click();
  await expect(previewLink).toBeVisible();
  await expect(revisionLinks).toHaveCount(1);
  const comparePath = api + recordPath + `/revisions/${earlier.draft_id}/compare`;
  const [compareResponse] = await Promise.all([
    page.waitForResponse((response) => new URL(response.url()).pathname === comparePath),
    page.getByRole("button", { name: "Porównaj ze szkicem", exact: true }).click(),
  ]);
  expect(compareResponse.ok()).toBe(true);
  const comparison = page.getByRole("dialog", { name: "Porównanie rewizji" });
  await expect(comparison.getByText(historicalTitle, { exact: true })).toBeVisible();
  await expect(comparison.getByText(draftTitle, { exact: true })).toBeVisible();
  await expect(comparison.getByText(publishedTitle, { exact: true })).toHaveCount(0);
  await comparison.getByRole("button", { name: "Zamknij", exact: true }).click();
  await expect(comparison).toBeHidden();
  await expect(page.getByLabel("Tytuł", { exact: true })).toHaveValue(draftTitle);

  const popupPromise = page.waitForEvent("popup");
  await previewLink.click();
  const popup = await popupPromise;
  await expect(popup).toHaveTitle(historicalTitle);
  await popup.close();
  expect(await (await page.request.get("/p/" + slug)).text()).toContain(`<title>${publishedTitle}</title>`);
  const afterPreview = await (await page.request.get(api + recordPath)).json();
  expect(afterPreview.version).toBe(draft.version);
  expect(afterPreview.draft_id).toBe(draft.draft_id);
  expect(afterPreview.published_id).toBe(published.published_id);

  await page.getByRole("button", { name: "Przywróć do szkicu", exact: true }).click();
  await expect(page.getByRole("status")).toContainText("Wersja przywrócona do szkicu.");
  await expect(page.getByLabel("Tytuł", { exact: true })).toHaveValue(historicalTitle);
  expect(await (await page.request.get("/p/" + slug)).text()).toContain(`<title>${publishedTitle}</title>`);

  const historyRequested = Promise.withResolvers<void>();
  const returnHistory = Promise.withResolvers<void>();
  await page.route(`**${api}${recordPath}/revisions`, async (route) => {
    historyRequested.resolve();
    await returnHistory.promise;
    await route.continue();
  }, { times: 1 });
  await history.click();
  const title = page.getByLabel("Tytuł", { exact: true });
  try {
    await historyRequested.promise;
    await title.fill("Unsaved local edit");
  } finally {
    returnHistory.resolve();
  }
  await expect(history).toBeEnabled();
  await expect(title).toHaveValue("Unsaved local edit");
  await expect(page.getByRole("button", { name: "Przywróć do szkicu", exact: true }).first()).toBeDisabled();
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
  const publicCollection = page.getByRole("checkbox", { name: "Publiczna kolekcja treści" });
  const privateSlug = page.getByRole("checkbox", { name: "Prywatne", exact: true }).nth(1);
  const activateModel = page.getByRole("button", { name: "Aktywuj konfigurację" });
  await expect(page.getByLabel("Klucz", { exact: true }).nth(1)).toHaveValue("slug");
  await expect(publicCollection).toBeChecked();
  await privateSlug.check();
  await expect(page.getByRole("alert")).toContainText("Pole slug jest częścią publicznego adresu");
  await expect(activateModel).toBeDisabled();
  await publicCollection.uncheck();
  await expect(activateModel).toBeEnabled();
  await publicCollection.check();
  await privateSlug.uncheck();
  await expect(activateModel).toBeEnabled();
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
    await expect(theme).toBeEnabled();
    if (await theme.inputValue() === value) return;
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

test("site color accents apply to the public page in both system schemes", async ({
  page,
  browser,
  baseURL,
}, info) => {
  test.skip(info.project.name !== "chromium", "Only one project writes shared site settings.");
  test.setTimeout(60_000);
  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill("admin@formaku.test");
  await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
  await page.getByRole("button", { name: "Ustawienia", exact: true }).click();

  const accent = page.getByLabel("Akcent kolorystyczny");
  const theme = page.getByLabel("Motyw witryny");
  const save = async () => {
    await page.getByRole("button", { name: "Zapisz menu i stopkę" }).click();
    await expect(page.getByText("Zapisano menu i stopkę.", { exact: true })).toBeVisible();
  };

  const visitor = await browser.newContext({ baseURL, javaScriptEnabled: false });
  try {
    const publicPage = await visitor.newPage();
    const tokens = () => publicPage.evaluate(() => {
      const css = getComputedStyle(document.documentElement);
      return {
        primary: css.getPropertyValue("--color-primary").trim(),
        foreground: css.getPropertyValue("--color-primary-content").trim(),
        accent: css.getPropertyValue("--color-accent").trim(),
        background: css.getPropertyValue("--color-base-100").trim(),
      };
    });

    await publicPage.emulateMedia({ colorScheme: "light" });
    await publicPage.goto("/p/start");
    await expect(publicPage.locator("html")).not.toHaveAttribute("data-accent");
    const initial = await tokens();

    await expect(accent.getByRole("option", { name: "Niebieski" })).toHaveAttribute("value", "blue");
    await accent.selectOption("blue");
    await theme.selectOption("system");
    await save();
    await expect(page.locator("html")).not.toHaveAttribute("data-accent");
    await publicPage.reload();
    await expect(publicPage.locator("html")).toHaveAttribute("data-accent", "blue");
    const blueLight = await tokens();
    expect(blueLight.primary).not.toBe(initial.primary);
    expect(blueLight.accent).toBe(blueLight.primary);
    expect(blueLight.background).toBe(initial.background);
    await publicPage.emulateMedia({ colorScheme: "dark" });
    await expect.poll(async () => (await tokens()).primary).not.toBe(blueLight.primary);
    const blueDark = await tokens();
    expect(blueDark.accent).toBe(blueDark.primary);
    expect(blueDark.background).not.toBe(blueLight.background);

    await expect(accent.getByRole("option", { name: "Śliwkowy" })).toHaveAttribute("value", "plum");
    await accent.selectOption("plum");
    await save();
    await publicPage.reload();
    await expect(publicPage.locator("html")).toHaveAttribute("data-accent", "plum");
    const plumDark = await tokens();
    expect(plumDark.primary).not.toBe(blueDark.primary);
    expect(plumDark.foreground).toBeTruthy();
    expect(plumDark.accent).toBe(plumDark.primary);

    await theme.selectOption("dark");
    await save();
    await publicPage.emulateMedia({ colorScheme: "light" });
    await publicPage.reload();
    await expect(publicPage.locator("html")).toHaveAttribute("data-theme", "dark");
    await expect.poll(tokens).toEqual(plumDark);

    await accent.selectOption("formaku");
    await theme.selectOption("system");
    await save();
    await publicPage.reload();
    await expect(publicPage.locator("html")).not.toHaveAttribute("data-accent");
    await expect.poll(tokens).toEqual(initial);
  } finally {
    await visitor.close();
  }
});

test("site branding uses the selected public logo and can restore the default", async ({ page, browser, baseURL }, info) => {
  test.skip(info.project.name !== "chromium", "Only one project writes shared site settings.");
  const imageName = `site-logo-${Date.now()}.png`;
  const siteName = "Studio <Alpha> " + "M".repeat(62);
  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill("admin@formaku.test");
  await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
  await page.getByRole("button", { name: "Media", exact: true }).click();
  await page.getByLabel("Prześlij plik").setInputFiles({
    name: imageName,
    mimeType: "image/png",
    buffer: Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGMQSfv/HwAEhAJ553qXcAAAAABJRU5ErkJggg==", "base64"),
  });
  await expect(page.getByRole("status")).toContainText("Plik zapisany");
  const uploadedImage = page.getByRole("article").filter({ hasText: imageName }).locator("img");
  await expect(uploadedImage).toHaveAttribute("src", /\/media\/\d+$/);
  const logoUrl = (await uploadedImage.getAttribute("src"))!;

  await page.getByRole("button", { name: "Ustawienia", exact: true }).click();
  const branding = page.getByRole("group", { name: "Marka witryny" });
  const nameInput = branding.getByLabel("Nazwa witryny");
  const save = async () => {
    await page.getByRole("button", { name: "Zapisz menu i stopkę" }).click();
    await expect(page.getByText("Zapisano menu i stopkę.", { exact: true })).toBeVisible();
  };
  await nameInput.fill(siteName);
  await branding.getByRole("button", { name: "Wybierz obraz", exact: true }).click();
  const picker = page.getByRole("dialog", { name: "Wybierz obraz" });
  await picker.getByRole("button", { name: imageName, exact: true }).click();
  await save();

  const visitor = await browser.newContext({ baseURL, javaScriptEnabled: false, viewport: { width: 390, height: 844 } });
  try {
    const publicPage = await visitor.newPage();
    await publicPage.goto("/p/start");
    const banner = publicPage.getByRole("banner");
    const brandLink = banner.getByRole("link", { name: siteName, exact: true });
    await expect(brandLink).toHaveAttribute("href", "/");
    const logo = brandLink.locator("img");
    await expect(logo).toHaveAttribute("src", logoUrl);
    await expect(logo).toHaveAttribute("alt", "");
    await expect.poll(() => logo.evaluate((image: HTMLImageElement) => image.naturalWidth)).toBeGreaterThan(0);
    expect(await publicPage.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);

    await page.reload();
    await page.getByRole("button", { name: "Ustawienia", exact: true }).click();
    await expect(nameInput).toHaveValue(siteName);
    await nameInput.fill("");
    await branding.getByRole("button", { name: "Usuń przypisanie obrazu", exact: true }).click();
    await save();
    await publicPage.reload();
    await expect(banner.getByRole("link", { name: "formaku.", exact: true })).toBeVisible();
    await expect(banner.locator("img")).toHaveCount(0);
  } finally {
    await visitor.close();
  }
});

test("authors link separate drafts and visitors switch published languages", async ({ page, browser }, info) => {
  const group = "languages-" + info.project.name + "-" + Date.now();
  const polishSlug = group + "-pl";
  const englishSlug = group + "-en";
  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill("admin@formaku.test");
  await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
  await page.getByRole("button", { name: "Strony i oferta", exact: true }).click();

  const link = page.getByRole("region", { name: "Wersje językowe" });
  const publish = page.getByRole("button", { name: "Opublikuj treść", exact: true });
  async function createVersion(slug: string, language: string) {
    await page.getByRole("button", { name: "Dodaj rekord" }).click();
    await page.getByLabel("Tytuł", { exact: true }).fill(slug);
    await page.getByLabel("Adres URL", { exact: true }).fill(slug);
    await page.getByRole("button", { name: "Zapisz szkic" }).click();
    await link.getByLabel("Język strony").fill(language);
    await link.getByLabel("Klucz grupy tłumaczeń").fill(group);
    await link.getByRole("button", { name: "Zapisz powiązanie" }).click();
    await expect(link.getByRole("status")).toHaveText("Zapisano powiązanie językowe.");
  }

  await createVersion(polishSlug, "pl");
  await publish.click();
  await expect(page.getByText("Treść opublikowana.", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Wróć do listy" }).click();
  await createVersion(englishSlug, "en-GB");

  const visitor = await browser.newContext({
    baseURL: info.project.use.baseURL,
    javaScriptEnabled: false,
    viewport: { width: 390, height: 844 },
  });
  try {
    const publicPage = await visitor.newPage();
    await publicPage.goto("/p/" + polishSlug);
    await expect(publicPage.getByRole("navigation", { name: "Wersje językowe" })).toHaveCount(0);
    expect((await publicPage.request.get("/p/" + englishSlug)).status()).toBe(404);

    await publish.click();
    await expect(page.getByText("Treść opublikowana.", { exact: true })).toBeVisible();
    await publicPage.reload();
    await expect(publicPage.locator('head link[rel="alternate"]')).toHaveCount(2);
    await publicPage.getByRole("navigation", { name: "Wersje językowe" })
      .getByRole("link", { name: "en-GB" }).click();
    await expect(publicPage).toHaveURL(new RegExp("/p/" + englishSlug + "$"));
    await expect(publicPage.locator("html")).toHaveAttribute("lang", "en-GB");
    await publicPage.getByRole("navigation", { name: "Language versions" })
      .getByRole("link", { name: "pl" }).click();
    await expect(publicPage).toHaveURL(new RegExp("/p/" + polishSlug + "$"));

    await link.getByLabel("Język strony").clear();
    await link.getByLabel("Klucz grupy tłumaczeń").clear();
    await link.getByRole("button", { name: "Zapisz powiązanie" }).click();
    await expect(link.getByRole("status")).toHaveText("Zapisano powiązanie językowe.");
    await publicPage.reload();
    await expect(publicPage.getByRole("navigation", { name: "Wersje językowe" })).toHaveCount(0);
  } finally {
    await visitor.close();
  }
});
