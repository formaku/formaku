import { defineConfig, devices } from "@playwright/test";
export default defineConfig({
  testDir: "tests/browser",
  webServer: {
    command: "php -S 127.0.0.1:8765 -t apps/starter/public tests/browser/router.php",
    url: "http://127.0.0.1:8765/login",
    reuseExistingServer: !process.env.CI,
    // Browser projects share localhost. Keep their cache and login rate counters request-local.
    env: { CACHE_STORE: "array" },
  },
  use: {
    baseURL: process.env.FORMAKU_TEST_URL ?? "http://127.0.0.1:8765",
    trace: "retain-on-failure",
  },
  projects: [
    { name: "chromium", use: { ...devices["Desktop Chrome"] } },
    { name: "firefox", use: { ...devices["Desktop Firefox"] } },
    { name: "webkit", use: { ...devices["Desktop Safari"] } },
  ],
});
