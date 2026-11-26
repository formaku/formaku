import { expect, test, type Page } from "@playwright/test";

async function signIn(page: Page) {
  await page.addInitScript(() => localStorage.setItem("formaku.admin.locale", "en"));
  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill("admin@formaku.test");
  await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
}

// Where each visible control that the pointer can press sits on the page.
function controls(page: Page) {
  return page.evaluate(() =>
    [...document.querySelectorAll("a[href], button, input, select, textarea")].flatMap((element) => {
      const box = element.getBoundingClientRect();
      const shown = box.width && box.height && element.checkVisibility({ opacityProperty: true, visibilityProperty: true });
      if (!shown) return [];
      const name = (element.getAttribute("aria-label") ?? element.textContent ?? "").replace(/\s+/g, " ").trim();
      return [{ name, left: Math.round(box.left + window.scrollX), top: Math.round(box.top + window.scrollY) }];
    }));
}

for (const viewport of [{ width: 1280, height: 720 }, { width: 390, height: 844 }]) {
  test(`controls stay in place while the panel loads at ${viewport.width} px`, async ({ page }) => {
    await page.setViewportSize(viewport);
    const requested = Promise.withResolvers<void>();
    const held = Promise.withResolvers<void>();
    await page.route("**/formaku/api/v1/bootstrap", async (route) => {
      requested.resolve();
      await held.promise;
      await route.continue();
    }, { times: 1 });
    await signIn(page);

    let early: Awaited<ReturnType<typeof controls>>;
    try {
      await requested.promise;
      await expect(page.getByRole("status")).toHaveText("Loading the panel…");
      early = await controls(page);
    } finally {
      held.resolve();
    }
    await expect(page.getByRole("button", { name: "Releases", exact: true })).toBeVisible();
    await expect(page.getByText("All records")).toBeVisible();
    await expect(page.getByRole("status")).toHaveCount(0);

    // The menu and the dashboard grow with the collections and permissions in the bootstrap response.
    // A control drawn before it arrives would move, and a click aimed at it would land elsewhere.
    // Text may shift by a pixel when the web font arrives, which leaves the click on its control.
    const loaded = await controls(page);
    const moved = early.flatMap((control) => {
      const now = loaded.filter(({ name }) => name === control.name);
      const stayed = now.some(({ left, top }) => Math.abs(left - control.left) <= 4 && Math.abs(top - control.top) <= 4);
      return now.length && !stayed ? [`${control.name}: ${control.top} px to ${now[0].top} px from the top`] : [];
    });
    expect(moved).toEqual([]);
  });
}

test("offers another attempt when the panel cannot load", async ({ page }) => {
  await page.route("**/formaku/api/v1/bootstrap", (route) =>
    route.fulfill({ status: 503, json: { message: "Service Unavailable" } }), { times: 1 });
  await signIn(page);

  const failure = page.getByRole("alert");
  await expect(failure).toHaveText("Could not load the panel. Service Unavailable");
  await expect(page.getByRole("navigation")).toHaveCount(0);

  await page.getByRole("button", { name: "Try again" }).click();
  await expect(page.getByRole("button", { name: "Releases", exact: true })).toBeVisible();
  await expect(failure).toHaveCount(0);
});
