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
  await page
    .getByRole("button", { name: "Strony i oferta", exact: true })
    .click();
  await page.getByRole("button", { name: "Dodaj rekord" }).click();
  await page
    .getByLabel("Tytuł", { exact: true })
    .fill("Browser test " + info.project.name);
  await page.getByLabel("Adres URL", { exact: true }).fill(slug);
  await page.getByRole("button", { name: "hero", exact: true }).click();
  await page.getByLabel("title", { exact: true }).fill("Browser publication");
  await page.getByRole("button", { name: "Zapisz szkic" }).click();
  await expect(page.getByRole("status")).toContainText("Szkic zapisany");
  await page
    .getByRole("button", { name: "Opublikuj treść", exact: true })
    .click();
  await expect(page.getByRole("status")).toContainText("Treść opublikowana");
  await page.goto("/p/" + slug);
  await expect(
    page.getByRole("heading", { name: "Browser publication" }),
  ).toBeVisible();
});
test("mobile login stays within viewport", async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/login");
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth),
  ).toBeLessThanOrEqual(390);
});
