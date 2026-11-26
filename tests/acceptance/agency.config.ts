import { defineConfig } from "@playwright/test";

export default defineConfig({
  testDir: ".",
  testMatch: "agency-project.spec.ts",
  outputDir: "../../test-results/agency",
  timeout: 180_000,
  use: { browserName: "chromium", actionTimeout: 12_000, trace: "retain-on-failure" },
});
