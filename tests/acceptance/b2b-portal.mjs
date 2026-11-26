import { chromium, expect } from "@playwright/test";
import AxeBuilder from "@axe-core/playwright";

const chunks = [];
for await (const chunk of process.stdin) chunks.push(chunk);
const setup = JSON.parse(Buffer.concat(chunks).toString("utf8"));
const origin = new URL(setup.base);
if (origin.protocol !== "http:" || origin.hostname !== "127.0.0.1")
  throw Error("Expected the private test server.");

function step(label) {
  process.stdout.write(`STEP ${label}\n`);
}

async function accessibility(page) {
  const { violations } = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"])
    .analyze();
  const blocking = violations.filter((v) => ["serious", "critical"].includes(v.impact));
  if (blocking.length)
    throw Error(
      blocking.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(" ")).join(", ")}`).join("\n"),
    );
}

async function login(page, account) {
  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill(account.email);
  await page.getByLabel("Hasło", { exact: true }).fill(setup.password);
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
  await page.waitForURL((url) => url.pathname !== "/login");
}

async function testPage(browser, options = {}) {
  const context = await browser.newContext({ baseURL: setup.base, ...options });
  await context.route("**/*", (route) =>
    new URL(route.request().url()).origin === origin.origin ? route.continue() : route.abort(),
  );
  const page = await context.newPage();
  page.setDefaultTimeout(10000);
  return page;
}

let browser;
let staff;
try {
  step("Otwarcie instalacji testowej");
  await expect
    .poll(
      async () => {
        try {
          return (await fetch(new URL("/login", origin))).status;
        } catch {
          return 0;
        }
      },
      { timeout: 10000 },
    )
    .toBe(200);
  browser = await chromium.launch();
  const owner = await testPage(browser, { viewport: { width: 390, height: 844 } });
  await login(owner, setup.accounts.owner);
  step("Przejście ze strony usługi do formularza");
  for (const path of setup.pages) {
    await owner.goto(path);
    await owner.locator("main a[href='/portal']").first().click();
    await expect(owner.getByRole("heading", { name: "Moje zgłoszenia" })).toBeVisible();
  }
  step("Dostępność formularza na telefonie i obsługa klawiaturą");
  await accessibility(owner);
  const fits = await owner.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
  expect(fits).toBe(true);
  const subject = owner.locator("#portal-field-title");
  await subject.focus();
  await subject.fill("Syntetyczne zapytanie B2B");
  await owner.keyboard.press("Tab");
  const description = owner.locator("#portal-field-description");
  await expect(description).toBeFocused();
  await description.fill("Scenariusz odbiorowy agencji");
  const service = owner.locator("#portal-field-service");
  if (await service.evaluate((el) => el.tagName === "SELECT")) await service.selectOption({ index: 1 });
  else await service.fill("Konsultacja");
  await owner.locator("#portal-field-desired_date").fill("2030-05-20");
  const form = owner.locator("form[action='/portal/requests']");
  for (const field of await form.locator("[required]").all()) {
    if (await field.inputValue()) continue;
    const type = await field.evaluate((el) => [el.tagName, el.type]);
    if (type[0] === "SELECT") await field.selectOption({ index: 1 });
    else await field.fill(type[1] === "date" ? "2030-05-20" : type[1] === "number" ? "1" : "QA");
  }
  const submit = form.getByRole("button", { name: "Wyślij zgłoszenie" });
  await submit.focus();
  await owner.keyboard.press("Enter");
  await owner.getByRole("link", { name: /Syntetyczne zapytanie B2B/ }).click();
  const detail = new URL(owner.url()).pathname;
  const id = detail.split("/").at(-1);
  await expect(owner.locator("main")).toContainText("2030-05-20");

  step("Weryfikacja dostępu drugiego klienta");
  const other = await testPage(browser);
  await login(other, setup.accounts.other);
  await other.goto("/portal");
  await expect(other.locator("main")).not.toContainText("Syntetyczne zapytanie B2B");
  expect((await other.request.get(detail)).status()).toBe(404);

  step("Przypisanie handlowca i zmiana stanu");
  staff = await testPage(browser);
  await login(staff, setup.accounts.staff);
  await staff.goto(`${setup.prefix}?record=${id}`);
  await staff.getByLabel("Przypisany pracownik").selectOption(String(setup.accounts.staff.id));
  await staff.getByRole("button", { name: `Zmień stan: ${setup.nextState}`, exact: true }).click();
  await expect
    .poll(
      async () =>
        (await (await owner.request.get(detail, { headers: { Accept: "application/json" } })).json()).state,
    )
    .toBe(setup.nextState);

  step("Ochrona notatki wewnętrznej i roboczej odpowiedzi");
  await staff
    .getByLabel(setup.labels.internal_note + "· wewnętrzne", { exact: true })
    .fill("Tylko dla handlowca QA");
  await staff.getByLabel(setup.labels.customer_reply, { exact: true }).fill("Gotowa odpowiedź QA");
  await staff.getByRole("button", { name: "Zapisz szkic" }).click();
  await expect(staff.getByRole("status").filter({ hasText: "Szkic zapisany" })).toBeVisible();
  await owner.reload();
  await expect(owner.locator("main")).not.toContainText("Gotowa odpowiedź QA");
  await expect(owner.locator("main")).not.toContainText("Tylko dla handlowca QA");

  step("Jawne udostępnienie odpowiedzi właścicielowi");
  await staff.getByRole("button", { name: "Udostępnij zapisaną odpowiedź klientowi" }).click();
  await expect(
    staff.getByRole("status").filter({ hasText: "Klient widzi zapisaną odpowiedź" }),
  ).toBeVisible();
  await owner.reload();
  await expect(owner.locator("main")).toContainText("Gotowa odpowiedź QA");
  await expect(owner.locator("main")).not.toContainText("Tylko dla handlowca QA");
  expect((await other.request.get(detail)).status()).toBe(404);
  await accessibility(owner);
} catch (error) {
  // Do not log test-account credentials or request/response payloads.
  console.error(String(error.message).replaceAll(setup.password, "[redacted]"));
  process.exitCode = 1;
} finally {
  await browser?.close();
}
