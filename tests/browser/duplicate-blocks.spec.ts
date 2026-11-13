import { expect, test } from "@playwright/test";

test("duplicate page blocks stay independent through saving and publication", async ({
  page,
  request: guest,
}, info) => {
  test.setTimeout(90_000);
  const slug = "duplicate-blocks-" + info.project.name + "-" + crypto.randomUUID();
  const title = "Duplicating blocks " + slug;
  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill("admin@formaku.test");
  await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();

  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute("content");
  const collections = await (await page.request.get("/formaku/api/v1/collections")).json();
  const pages = collections.find((collection: { key: string }) => collection.key === "pages");
  await expect(await page.request.post(`/formaku/api/v1/collections/${pages.id}/records`, {
    headers: { "X-CSRF-TOKEN": csrf ?? "" },
    data: {
      version: 0,
      data: {
        title,
        slug,
        blocks: [
          {
            uid: crypto.randomUUID(),
            type: "columns",
            props: { columns: 3 },
            children: [
              {
                uid: crypto.randomUUID(),
                type: "container",
                props: { layout: "stack" },
                children: [
                  { uid: crypto.randomUUID(), type: "hero", props: { title: "Original nested section" } },
                ],
              },
              { uid: crypto.randomUUID(), type: "cta", props: { title: "Following child" } },
            ],
          },
          { uid: crypto.randomUUID(), type: "hero", props: { title: "Following root" } },
        ],
      },
    },
  })).toBeOK();

  await page.getByRole("button", { name: "Strony i oferta", exact: true }).click();
  const row = page.getByRole("row").filter({ hasText: title });
  await row.getByRole("button", { name: "Edytuj" }).click();

  const roots = page.locator('article[draggable="true"]');
  const source = roots.first();
  const sourceContainers = source.getByRole("article", { name: "Kontener", exact: true });
  const titleInput = { exact: true };
  await sourceContainers.first().getByRole("button", { name: "Powiel blok", exact: true })
    .and(sourceContainers.first().locator(":scope > div:first-child button")).click();

  const sourceChildren = source.getByRole("article", { name: /^(Kontener|Wezwanie do działania)$/ });
  await expect(sourceChildren).toHaveCount(3);
  await expect(sourceChildren.nth(1)).toHaveAccessibleName("Kontener");
  await expect(sourceChildren.nth(2)).toHaveAccessibleName("Wezwanie do działania");
  await expect(sourceContainers.nth(1).getByLabel("title", titleInput)).toHaveValue("Original nested section");
  await sourceContainers.nth(1).getByLabel("title", titleInput).fill("Edited nested copy");
  await expect(sourceContainers.first().getByLabel("title", titleInput)).toHaveValue("Original nested section");

  await source.getByRole("button", { name: "Powiel blok", exact: true })
    .and(source.locator(":scope > div:first-child button")).click();
  await expect(roots).toHaveCount(3);
  await expect(roots.nth(2).getByLabel("title", titleInput)).toHaveValue("Following root");
  const copy = roots.nth(1);
  const copyContainers = copy.getByRole("article", { name: "Kontener", exact: true });
  await expect(copyContainers.nth(1).getByLabel("title", titleInput)).toHaveValue("Edited nested copy");
  await copy.getByLabel("Liczba kolumn").selectOption("2");
  await expect(source.getByLabel("Liczba kolumn")).toHaveValue("3");
  await copyContainers.first().getByLabel("title", titleInput).fill("Edited root copy");
  await expect(sourceContainers.first().getByLabel("title", titleInput)).toHaveValue("Original nested section");

  const status = page.getByRole("status");
  await page.getByRole("button", { name: "Zapisz szkic" }).click();
  await expect(status).toContainText("Szkic zapisany");
  expect((await guest.get("/p/" + slug)).status()).toBe(404);
  await page.getByRole("button", { name: "Pokaż podgląd" }).click();
  const preview = page.frameLocator('iframe[title="Podgląd zapisanego szkicu"]');
  await expect(preview.locator(".block-layout-columns-3 .block-layout-grid").locator("h1, h2"))
    .toHaveText(["Original nested section", "Edited nested copy", "Following child"]);
  await expect(preview.locator(".block-layout-columns-2 .block-layout-grid").locator("h1, h2"))
    .toHaveText(["Edited root copy", "Edited nested copy", "Following child"]);

  await page.getByRole("button", { name: "Opublikuj treść" }).click();
  await expect(status).toContainText("Treść opublikowana");
  const publicPage = await guest.get("/p/" + slug);
  await expect(publicPage).toBeOK();
  await page.context().clearCookies();
  await page.goto("/p/" + slug);
  await expect(page.locator(".block-layout-columns-3 .block-layout-stack h1"))
    .toHaveText(["Original nested section", "Edited nested copy"]);
  await expect(page.locator(".block-layout-columns-2 .block-layout-stack h1"))
    .toHaveText(["Edited root copy", "Edited nested copy"]);
  await expect(page.locator(".prose h1, .prose h2"))
    .toHaveText([
      "Original nested section", "Edited nested copy", "Following child",
      "Edited root copy", "Edited nested copy", "Following child", "Following root",
    ]);
});
