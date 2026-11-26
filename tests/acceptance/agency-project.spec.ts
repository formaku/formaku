import { expect, test, type Browser, type Page } from "@playwright/test";
import { execFileSync, spawn } from "node:child_process";
import { once } from "node:events";
import { mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { randomBytes } from "node:crypto";
import { createServer } from "node:net";

async function localPort() {
  const server = createServer();
  server.listen(0, "127.0.0.1");
  await once(server, "listening");
  const address = server.address();
  if (!address || typeof address === "string") throw new Error("Loopback port unavailable");
  await new Promise<void>((resolve, reject) => server.close((error) => (error ? reject(error) : resolve())));
  return address.port;
}

async function installation(browser: Browser, temporary: string, name: string) {
  const port = await localPort();
  const baseURL = "http://127.0.0.1:" + port;
  const database = join(temporary, name + "-formaku-e2e.sqlite");
  await writeFile(database, "");
  const env = {
    ...process.env,
    APP_ENV: "testing",
    APP_URL: baseURL,
    APP_KEY: "base64:" + randomBytes(32).toString("base64"),
    DB_CONNECTION: "sqlite",
    DB_DATABASE: database,
    DB_URL: "",
    SESSION_DRIVER: "database",
    CACHE_STORE: "array",
    MAIL_MAILER: "array",
    QUEUE_CONNECTION: "database",
    OPENAI_API_KEY: "",
    GEMINI_API_KEY: "",
  };
  execFileSync("php", ["apps/starter/artisan", "migrate", "--force", "--no-interaction"], { env });
  execFileSync("php", ["scripts/e2e-fixtures.php"], { env });
  const child = spawn(
    "php",
    ["-S", "127.0.0.1:" + port, "-t", "apps/starter/public", "tests/browser/router.php"],
    { env, stdio: "ignore" },
  );
  const context = await browser.newContext({ baseURL, acceptDownloads: true });
  async function close() {
    if (child.exitCode === null && child.signalCode === null) {
      const closed = once(child, "exit");
      child.kill();
      await closed;
    }
    await context.close();
  }
  try {
    const page = await context.newPage();
    await expect
      .poll(async () => {
        try {
          return (await context.request.get("/up")).status();
        } catch {
          return 0;
        }
      })
      .toBe(200);
    await page.goto("/login");
    await page.getByLabel("E-mail", { exact: true }).fill("admin@formaku.test");
    await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
    await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
    await page.getByRole("button", { name: "Portale B2B" }).click();
    return { page, context, close, env };
  } catch (error) {
    await close();
    throw error;
  }
}

test("an agency reviews a draft, deploys a reusable package to a separate installation and rolls back an update", async ({
  browser,
}) => {
  const temporary = await mkdtemp(join(tmpdir(), "formaku-agency-"));
  const cleanup: (() => Promise<void>)[] = [];
  try {
    const source = await installation(browser, temporary, "source");
    cleanup.push(source.close);
    const { page } = source;
    const fixture = "Firma testowa oferuje montaż urządzeń w halach przemysłowych.";
    await page.getByLabel("Brief klienta").fill(fixture);
    await page.getByLabel("Zatwierdzone materiały firmy").fill("Odpowiedź udostępnia handlowiec.");
    await page.getByRole("button", { name: "Przygotuj propozycję" }).click();
    await page.getByLabel("Tytuł strony").fill("Montaż w halach");
    await page.getByLabel("Treść strony").fill(fixture);
    await page.getByRole("button", { name: "Zapisz poprawki" }).click();
    await expect(page.getByText("Zapisano propozycję.", { exact: true })).toBeVisible();
    await page.getByRole("button", { name: "Podgląd propozycji" }).click();
    const previewLink = page.getByRole("link", { name: "Podgląd w nowej karcie" });
    const preview = await source.context.request.get((await previewLink.getAttribute("href"))!);
    expect(preview.status()).toBe(200);
    expect(await preview.text()).toContain(fixture);
    await page.getByRole("button", { name: "Aktywuj formularz i zapisz szkice stron" }).click();
    await expect(page.getByRole("link", { name: "Otwórz w edytorze" })).toBeVisible();
    expect((await source.context.request.get("/p/uslugi-b2b")).status()).toBe(404);
    await page.getByRole("button", { name: "Sprawdź portal" }).click();
    await expect(page.getByRole("heading", { name: "Odbiór konfiguracji" })).toBeVisible();
    await page.getByRole("button", { name: "Uruchom test przeglądarkowy" }).click();
    await expect(page.getByText("W kolejce", { exact: true })).toBeVisible();
    execFileSync("php", ["apps/starter/artisan", "queue:work", "--once", "--timeout=145"], {
      env: source.env,
      timeout: 155000,
    });
    await page.getByRole("button", { name: "Sprawdź portal" }).click();
    await expect(page.getByText("Test przeglądarkowy zaliczony", { exact: true })).toBeVisible();
    await page.getByRole("button", { name: "Pakiety wdrożeniowe" }).click();
    async function download(revision: number) {
      await page.getByLabel("Wersja pakietu", { exact: true }).fill(String(revision));
      const pending = page.waitForEvent("download");
      await page.getByRole("button", { name: "Eksportuj pakiet" }).click();
      const path = join(temporary, "package-" + revision + ".json");
      await (await pending).saveAs(path);
      expect(await readFile(path, "utf8")).not.toContain(fixture);
      return path;
    }
    const firstPackage = await download(1);
    const secondPackage = await download(2);
    const target = await installation(browser, temporary, "target");
    cleanup.push(target.close);
    const targetPage = target.page;
    await targetPage.getByRole("button", { name: "Pakiety wdrożeniowe" }).click();
    async function deploy(targetPage: Page, file: string, brand: string) {
      await expect(targetPage.getByLabel("Pakiet JSON")).toBeEnabled();
      await targetPage.getByLabel("Pakiet JSON").setInputFiles(file);
      await targetPage.getByLabel("Nazwa firmy docelowej").fill(brand);
      await targetPage.getByLabel("Opis usług firmy docelowej").fill("Serwis przemysłowy");
      await targetPage.getByRole("button", { name: "Porównaj z tą instalacją" }).click();
      await targetPage.getByRole("button", { name: "Zastosuj pakiet" }).click();
      await expect(targetPage.getByText("Wdrożono konfigurację pakietu.", { exact: true })).toBeVisible();
    }
    await deploy(targetPage, firstPackage, "Druga firma");
    execFileSync("php", ["apps/starter/artisan", "formaku:check-portal", "--installed"], {
      env: target.env,
      timeout: 120000,
    });
    await targetPage.getByRole("button", { name: "Sprawdź portal" }).click();
    await expect(targetPage.getByText("Test przeglądarkowy zaliczony", { exact: true })).toBeVisible();
    await deploy(targetPage, secondPackage, "Nowa oferta drugiej firmy");
    targetPage.once("dialog", (dialog) => dialog.accept());
    await targetPage.getByRole("button", { name: "Cofnij tę aktualizację" }).click();
    await expect(targetPage.getByText("Przywrócono konfigurację.", { exact: true })).toBeVisible();
    const records = await target.context.request.get("/formaku/api/v1/bootstrap");
    const collections = (await records.json()).collections;
    const pages = collections.find((c: { key: string }) => c.key === "agency_pages");
    const list = await target.context.request.get("/formaku/api/v1/collections/" + pages.id + "/records");
    const id = (await list.json()).data[0].id;
    const restored = await target.context.request.get("/formaku/api/v1/records/" + id + "/preview");
    expect(await restored.text()).toContain("Druga firma");
    expect((await target.context.request.get("/p/oferta-b2b")).status()).toBe(404);
  } finally {
    try {
      for (const release of cleanup.reverse()) await release();
    } finally {
      await rm(temporary, { recursive: true, force: true });
    }
  }
});
