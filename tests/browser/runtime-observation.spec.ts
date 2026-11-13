import { expect, test } from "@playwright/test";

test("reads historical runtime observations without starting a workflow", async ({ page }) => {
  const run = {
    run_id: 820001,
    run_attempt: 2,
    run_url: "https://github.com/formaku/formaku/actions/runs/820001/attempts/2",
  };
  const image = "ghcr.io/formaku/formaku@sha256:" + "a".repeat(64);
  const recorded_at = "2026-11-12T15:30:12Z";
  const observation = (state: string) => ({
    ...run, state: "observed",
    observation: { recorded_at, host: { state, image } },
  });
  const responses = [
    { json: { state: "none" } },
    { json: { ...run, state: "pending" } },
    { json: { ...run, state: "unavailable" } },
    { json: observation("verified") },
    { status: 502, json: { message: "upstream failure" } },
    { json: observation("verified") },
    { json: observation("transitioning") },
    { json: observation("mismatch") },
    { json: observation("unavailable") },
  ];
  const holdFailure = Promise.withResolvers<void>();
  const holdNavigation = Promise.withResolvers<void>();
  let received = 0;
  await page.addInitScript(() => localStorage.setItem("formaku.admin.locale", "en"));
  await page.route("**/formaku/api/v1/deployments/runtime-observation", async (route) => {
    expect(route.request().method()).toBe("GET");
    const index = received++;
    if (index === 4) await holdFailure.promise;
    if (index === 5) await holdNavigation.promise;
    await route.fulfill(responses[index]);
  });

  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill("admin@formaku.test");
  await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
  const releases = page.getByRole("button", { name: "Releases", exact: true });
  await releases.click();
  const panel = page.getByRole("region", { name: "Runtime observation" });
  const read = panel.getByRole("button", { name: "Read observation" });
  const result = panel.getByRole("status");
  await expect(read).toBeVisible();
  expect(received).toBe(0);

  await read.click();
  await expect(result).toContainText("There are no manual runs");
  await read.click();
  await expect(result).toContainText("The runtime observation workflow is still in progress.");
  await read.click();
  await expect(result).toContainText("The saved observation is unavailable for this attempt.");
  await read.click();
  await expect(result).toContainText("Run 820001, attempt 2");
  await expect(result.getByRole("link", { name: "Open run in GitHub Actions" })).toHaveAttribute("href", run.run_url);
  await expect(result).toContainText("Historical observation recorded by GitHub Actions.");
  await expect(result.locator("time")).toHaveAttribute("datetime", recorded_at);
  await expect(result.locator("time")).not.toBeEmpty();
  await expect(result).toContainText("Runtime verified");
  await expect(result).toContainText(image);

  await read.click();
  try {
    await expect(panel.getByRole("button", { name: "Reading observation…" })).toBeDisabled();
    await expect(result).toBeEmpty();
  } finally {
    holdFailure.resolve();
  }
  await expect(panel.getByRole("alert")).toContainText("Could not read the runtime observation.");
  await expect(result).toBeEmpty();

  const response = page.waitForResponse((response) =>
    response.url().endsWith("/deployments/runtime-observation"),
  );
  await read.click();
  try {
    await page.getByRole("button", { name: "Overview", exact: true }).click();
    await expect(panel).toHaveCount(0);
    await releases.click();
    await expect(read).toBeEnabled();
    await expect(panel.getByRole("alert")).toHaveCount(0);
  } finally {
    holdNavigation.resolve();
  }
  await (await response).finished();
  await expect(result).toBeEmpty();

  for (const hostState of [
    "A transition was detected during observation",
    "An image or service mismatch was detected",
    "Host state was unavailable",
  ]) {
    await read.click();
    await expect(result).toContainText(hostState);
    await expect(result).not.toContainText(image);
  }
  expect(received).toBe(responses.length);
});
