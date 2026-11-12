import { test, expect } from "@playwright/test";

test("custom field and nested block survive saving and publication", async ({ page }, info) => {
  test.slow();
  const suffix = info.project.name + "-" + Date.now();
  const collectionName = "Test rozszerzeń " + suffix;
  const collectionKey = "extensions_" + suffix.replace("-", "_");
  const slug = "extensions-" + suffix;

  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill("admin@formaku.test");
  await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();

  await page.getByRole("button", { name: "Buduj", exact: true }).click();
  await page.getByRole("button", { name: "Nowa kolekcja" }).click();
  await page.getByLabel("Nazwa", { exact: true }).fill(collectionName);
  await page.getByLabel("Identyfikator").fill(collectionKey);
  await page.getByLabel("Rodzaj").selectOption("content");
  await page.getByRole("button", { name: "Dodaj pole", exact: true }).click();
  await page.getByLabel("Etykieta", { exact: true }).last().fill("Cena testowa");
  await page.getByLabel("Klucz", { exact: true }).last().fill("price");
  const fieldType = page.getByRole("combobox", { name: "Typ", exact: true }).last();
  await expect(
    fieldType.getByRole("option", { name: "Testowa cena w groszach" }),
    "Use the Playwright fixture server with APP_ENV=testing and a dedicated formaku-e2e.sqlite database",
  ).toBeAttached();
  await fieldType.selectOption({ label: "Testowa cena w groszach" });
  await page.getByRole("button", { name: "Aktywuj konfigurację" }).click();

  const card = page.getByRole("article").filter({ hasText: collectionName });
  await expect(card).toBeVisible();
  const collectionsResponse = await page.request.get("/formaku/api/v1/collections");
  await expect(collectionsResponse).toBeOK();
  const collections = await collectionsResponse.json();
  const collection = collections.find(({ key }: { key: string }) => key === collectionKey);
  expect(collection?.definition.fields).toEqual(expect.arrayContaining([
    expect.objectContaining({ key: "price", type: "qa_price_cents" }),
  ]));

  await card.getByRole("button", { name: "Otwórz dane" }).click();
  await page.getByRole("button", { name: "Dodaj rekord" }).click();
  await page.getByLabel("Tytuł", { exact: true }).fill("Strona testowa rozszerzeń");
  await page.getByLabel("Adres URL", { exact: true }).fill(slug);
  const price = page.getByLabel("Cena testowa", { exact: true });
  await expect(price).toHaveAttribute("type", "number");
  await price.fill("123");

  await page.getByRole("button", { name: "Testowy układ rozszerzenia", exact: true }).click();
  const count = page.getByLabel("Testowy licznik", { exact: true });
  const enabled = page.getByRole("combobox", { name: "Testowe włączenie" });
  await count.fill("0");
  await enabled.selectOption("false");
  await page.getByLabel("Typ bloku w układzie").selectOption("hero");
  await page.getByRole("button", { name: "Dodaj blok do układu" }).click();
  const childTitle = page.getByRole("article", { name: "Sekcja główna" })
    .getByLabel("title", { exact: true });
  await childTitle.fill("Potomek w układzie rozszerzenia");

  await page.getByRole("button", { name: "Zapisz szkic" }).click();
  await expect(page.getByRole("status")).toContainText("Szkic zapisany");
  await expect(page).toHaveURL(/record=\d+/);
  const recordId = new URL(page.url()).searchParams.get("record");

  await page.reload();
  await expect(price).toHaveValue("123");
  await expect(count).toHaveValue("0");
  await expect(enabled).toHaveValue("false");
  await expect(childTitle).toHaveValue("Potomek w układzie rozszerzenia");

  const recordResponse = await page.request.get("/formaku/api/v1/records/" + recordId + "?history=0");
  await expect(recordResponse).toBeOK();
  const { data } = await recordResponse.json();
  expect(data.price).toBe(123);
  expect(data.blocks).toMatchObject([{
    type: "qa_container",
    props: { count: 0, enabled: false },
    children: [{ type: "hero", props: { title: "Potomek w układzie rozszerzenia" } }],
  }]);

  await page.getByRole("button", { name: "Opublikuj treść" }).click();
  await expect(page.getByRole("status")).toContainText("Treść opublikowana");
  const publicResponse = await page.goto("/p/" + slug);
  expect(publicResponse?.ok()).toBe(true);
  const layout = page.locator('section[data-qa-count="0"][data-qa-enabled="false"]');
  await expect(layout.getByRole("heading", { name: "Potomek w układzie rozszerzenia" })).toBeVisible();
});
