import { expect, test } from "@playwright/test";

test("reads deployment receipts independently of application builds", async ({ page }) => {
  const release = "12345678-1234-1234-1234-123456789abc";
  const image = "ghcr.io/formaku/formaku@sha256:" + "a".repeat(64);
  const run = {
    run_id: 820001,
    run_attempt: 2,
    run_url: "https://github.com/formaku/formaku/actions/runs/820001/attempts/2",
  };
  const receipt = { format: 1, release, image, ssh_exit_code: 0 };
  const responses = [
    { state: "pending", receipt: null },
    { state: "unavailable", receipt: null },
    { state: "verified", receipt: { ...receipt, host_status: null } },
    { state: "verified", receipt: {
      ...receipt,
      ssh_exit_code: 255,
      host_status: { state: "failed", rollback: "restored", backup: release },
    } },
  ];
  let received = 0;
  let resumePendingResponse!: () => void;
  const pendingResponse = new Promise<void>((resolve) => { resumePendingResponse = resolve; });
  await page.addInitScript(() => localStorage.setItem("formaku.admin.locale", "en"));
  await page.route("**/formaku/api/v1/deployments/receipt?*", async (route) => {
    expect(route.request().method()).toBe("GET");
    expect(Object.fromEntries(new URL(route.request().url()).searchParams)).toEqual({
      run_id: String(run.run_id), release_id: release, image,
    });
    const response = responses[received++];
    if (response.state === "pending") await pendingResponse;
    await route.fulfill({ json: { ...run, ...response } });
  });

  await page.goto("/login");
  await page.getByLabel("E-mail", { exact: true }).fill("admin@formaku.test");
  await page.getByLabel("Hasło", { exact: true }).fill("Local-Test-Formaku-2026!");
  await page.getByRole("button", { name: "Otwórz swoją przestrzeń" }).click();
  await page.getByRole("button", { name: "Releases", exact: true }).click();
  await expect(page.getByRole("button", { name: "Build release" })).toBeVisible();

  const panel = page.getByRole("region", { name: "Deployment receipt" });
  const runId = panel.getByLabel("GitHub Actions run ID");
  const releaseId = panel.getByLabel("Deployment release ID");
  const digest = panel.getByLabel("Image digest");
  const check = panel.getByRole("button", { name: "Check receipt" });
  const result = panel.getByRole("status");
  await runId.fill(String(run.run_id));
  await releaseId.fill(release);
  await digest.fill(image);
  await check.click();
  try {
    await expect(digest).toBeDisabled();
    await expect(check).toBeDisabled();
  } finally {
    resumePendingResponse();
  }
  await expect(result).toContainText("The deployment workflow is still in progress.");
  await expect(result).toContainText("Run 820001, attempt 2");
  await expect(result.getByRole("link", { name: "Open run in GitHub Actions" })).toHaveAttribute("href", run.run_url);
  await check.click();
  await expect(result).toContainText("The deployment receipt is unavailable for this attempt.");
  await check.click();
  await expect(result).toContainText("Historical result recorded by the workflow for this attempt.");
  await expect(result).toContainText("SSH exit code");
  await expect(result.getByText("0", { exact: true })).toBeVisible();
  await expect(result).toContainText("Host status is unavailable.");
  await expect(result.getByText("Deployed", { exact: true })).toHaveCount(0);
  await check.click();
  await expect(result).toContainText("255");
  await expect(result).toContainText("Deployment failed");
  await expect(result).toContainText("Previous version restored");
  await expect(result).toContainText(release);

  await runId.fill("820002");
  await expect(result).toBeEmpty();
  expect(received).toBe(responses.length);
});
