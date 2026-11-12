import { expect, test } from "@playwright/test";

test("reviews an AI proposal before applying only page content to a new draft", async ({ page }, info) => {
  test.slow();
  const taskId = Date.now() * 10 + ["chromium", "firefox", "webkit"].indexOf(info.project.name);
  const title = "Testowa propozycja AI " + taskId;
  const recordPosts: unknown[] = [];
  const aiMethods: string[] = [];
  page.on("request", (request) => {
    if (request.method() === "POST" && /\/formaku\/api\/v1\/collections\/\d+\/records$/.test(new URL(request.url()).pathname)) {
      recordPosts.push(request.postDataJSON());
    }
  });
  await page.route("**/formaku/api/v1/ai", async (route) => {
    const method = route.request().method();
    aiMethods.push(method);
    if (method !== "GET") return route.abort();
    return route.fulfill({ json: [{
      id: taskId,
      state: "ready",
      prompt: "Syntetyczne zadanie bez dostawcy AI",
      result: {
        title,
        slug: "do-not-copy-slug",
        state: "do-not-copy-state",
        blocks: [
          { type: "cta", props: { title: "Działanie", label: "Przejdź", url: "/portal", text: "do-not-copy-cta-text" } },
          { type: "hero", props: { title: "Nagłówek", text: "Treść główna", url: "do-not-copy-hero-url" } },
        ],
      },
    }] });
  });
  await page.route("**/formaku/api/v1/bootstrap", async (route) => {
    const response = await route.fetch();
    const bootstrap = await response.json();
    const pages = bootstrap.collections.find(({ key }: { key: string }) => key === "pages");
    return route.fulfill({
      response,
      json: {
        ...bootstrap,
        collections: [
          ...bootstrap.collections,
          { ...pages, id: 1000001, name: "Bez prawa tworzenia", can_create: false },
          {
            ...pages, id: 1000002, name: "Model bez adresu", can_create: true,
            definition: {
              ...pages.definition,
              fields: [
                ...pages.definition.fields.filter(({ key }: { key: string }) => key !== "slug"),
                { key: "reference", label: "Wymagany numer referencyjny", type: "text", required: true },
                { key: "summary", label: "Opcjonalne streszczenie", type: "text", required: false },
                { key: "status_note", label: "Wymagana notatka tylko do odczytu", type: "text", required: true, writable: false },
                { key: "edition", label: "Wymagane wydanie", type: "number", required: "1" },
                { key: "caption", label: "Opcjonalny podpis", type: "text", required: "0" },
              ],
            },
          },
          {
            ...pages, id: 1000003, name: "Model z liczbowym adresem", can_create: true,
            definition: {
              ...pages.definition,
              fields: pages.definition.fields.map((field: { key: string }) => field.key === "slug"
                ? { ...field, label: "Liczbowy adres strony", type: "number", required: true }
                : field),
            },
          },
        ],
      },
    });
  }, { times: 1 });

  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill("admin@formaku.test");
  await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
  await page.getByRole("button", { name: "Buduj", exact: true }).click();
  await page.getByRole("article").filter({ hasText: "Strony i oferta" })
    .getByRole("button", { name: "Otwórz dane" }).click();
  await page.getByRole("button", { name: "Dodaj rekord" }).click();
  const recordTitle = page.getByLabel("Tytuł", { exact: true });
  await recordTitle.fill("Zachowaj ten niezapisany szkic");

  const aiSection = page.getByRole("button", { name: "Asystent AI", exact: true });
  const refusal = page.waitForEvent("dialog");
  const cancelledNavigation = aiSection.click();
  const firstDialog = await refusal;
  await firstDialog.dismiss();
  await cancelledNavigation;
  expect(firstDialog.message()).toContain("Odrzucić niezapisane zmiany?");
  await expect(recordTitle).toHaveValue("Zachowaj ten niezapisany szkic");
  expect(aiMethods).toEqual([]);
  expect(recordPosts).toEqual([]);

  page.once("dialog", (dialog) => dialog.accept());
  await aiSection.click();
  await page.getByRole("button", { name: "Sprawdź propozycję" }).click();
  const review = page.getByRole("region", { name: "Podgląd nowego szkicu" });
  const destination = review.getByRole("combobox", { name: "Kolekcja docelowa" });
  await expect(destination.getByRole("option", { name: "Bez prawa tworzenia" })).toHaveCount(0);
  const apply = review.getByRole("button", { name: "Zastosuj jako nowy szkic" });
  const emptyRequiredFields = review.getByRole("status");
  await expect(apply).toBeDisabled();
  await expect(emptyRequiredFields).toHaveCount(0);
  await destination.selectOption({ label: "Model bez adresu" });
  await expect(review).toContainText("Model kolekcji nie ma zapisywalnego pola adresu URL.");
  await expect(emptyRequiredFields.getByRole("listitem")).toHaveText(["Wymagany numer referencyjny", "Wymagane wydanie"]);
  await destination.selectOption({ label: "Model z liczbowym adresem" });
  await expect(emptyRequiredFields.getByRole("listitem")).toHaveText(["Liczbowy adres strony"]);
  await page.getByRole("combobox", { name: "Język panelu" }).selectOption("en");
  const englishReview = page.getByRole("region", { name: "New draft preview" });
  await expect(englishReview.getByRole("combobox", { name: "Target collection" })).toBeVisible();
  await expect(englishReview.getByRole("button", { name: "Apply as new draft" })).toBeEnabled();
  await expect(englishReview.getByRole("status")).toContainText("These required fields will be empty after applying the proposal.");
  await page.getByRole("combobox", { name: "Panel language" }).selectOption("pl");

  await destination.selectOption({ label: "Strony i oferta" });
  await expect(emptyRequiredFields).toHaveCount(0);
  await expect(review).toContainText(title);
  await expect(review).toContainText("proposal-" + taskId);
  await expect(review.getByRole("listitem").nth(0)).toContainText("Przejdź");
  await expect(review.getByRole("listitem").nth(0)).toContainText("/portal");
  await expect(review.getByRole("listitem").nth(1)).toContainText("Treść główna");
  await expect(review).not.toContainText("do-not-copy");

  const recordsUrl = /\/formaku\/api\/v1\/collections\/\d+\/records(?:\?.*)?$/;
  const loadingStarted = Promise.withResolvers<void>();
  const allowLoading = Promise.withResolvers<void>();
  await page.route(recordsUrl, async (route) => {
    loadingStarted.resolve();
    await allowLoading.promise;
    await route.continue();
  }, { times: 1 });
  const recordsResponse = page.waitForResponse((response) => recordsUrl.test(response.url()));
  try {
    await apply.click();
    await loadingStarted.promise;
    await page.getByRole("button", { name: "Przegląd", exact: true }).click();
    await expect(review).toHaveCount(0);
  } finally {
    allowLoading.resolve();
  }
  await (await recordsResponse).finished();

  await aiSection.click();
  await expect(review).toBeVisible();
  await apply.click();
  await expect(recordTitle).toHaveValue(title);
  await expect(page.getByLabel("Adres URL", { exact: true })).toHaveValue("proposal-" + taskId);
  await expect(page.getByText("Niezapisane zmiany", { exact: true })).toBeVisible();
  expect(new URL(page.url()).searchParams.has("record")).toBe(false);
  expect(aiMethods).toEqual(["GET", "GET"]);
  expect(recordPosts).toEqual([]);

  await page.getByRole("button", { name: "Zapisz szkic" }).click();
  await expect(page.getByRole("status")).toContainText("Szkic zapisany");
  expect(recordPosts).toEqual([{
    version: 0,
    data: {
      title,
      slug: "proposal-" + taskId,
      blocks: [
        { type: "cta", props: { title: "Działanie", label: "Przejdź", url: "/portal" } },
        { type: "hero", props: { title: "Nagłówek", text: "Treść główna" } },
      ],
    },
  }]);
});
