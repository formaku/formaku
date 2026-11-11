import assert from "node:assert/strict";
import { spawn, spawnSync } from "node:child_process";
import { createHash } from "node:crypto";
import { once } from "node:events";
import { mkdir, mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { createServer } from "node:http";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { createInterface } from "node:readline";
import { test } from "node:test";
import { fileURLToPath } from "node:url";

test("updating the adapter preserves the local token and refuses a mismatched installation", async (t) => {
  const profile = await mkdtemp(join(tmpdir(), "formaku-adapter-update-"));
  t.after(() => rm(profile, { force: true, recursive: true }));
  const base = "https://cms.example.test/formaku/assistant/v1";
  const token = "fma_" + "b".repeat(64);
  const name = "formaku_" + createHash("sha256").update(base).digest("hex").slice(0, 12);
  const directory = join(profile, ".config", "formaku-assistant", name);
  const preload = join(profile, "profile.cjs");
  const connection = join(directory, "connection.json");
  const installed = join(directory, "adapter.mjs");
  const adapter = fileURLToPath(new URL("../packages/cms/resources/assistant/formaku-assistant.mjs", import.meta.url));
  const settings = JSON.stringify({ base, token });
  await mkdir(directory, { recursive: true });
  await writeFile(preload, "require('node:os').homedir = () => process.env.FORMAKU_TEST_PROFILE; require('node:module').syncBuiltinESMExports();");
  await writeFile(connection, settings);
  await writeFile(installed, "// earlier adapter");

  const run = (...args) => spawnSync(process.execPath, ["--require", preload, adapter, ...args], {
    env: { ...process.env, FORMAKU_TEST_PROFILE: profile },
    encoding: "utf8",
    timeout: 5000,
  });
  const refreshed = run("update", base);
  assert.equal(refreshed.status, 0);
  assert.ok(!refreshed.stdout.includes(token) && !refreshed.stderr.includes(token));
  assert.equal(await readFile(connection, "utf8"), settings);
  assert.deepEqual(await readFile(installed), await readFile(adapter));

  await writeFile(installed, "// keep this adapter");
  await writeFile(connection, JSON.stringify({ base: base.replace("cms.", "other."), token }));
  assert.equal(run("update", base).status, 1);
  assert.equal(await readFile(installed, "utf8"), "// keep this adapter");

  await writeFile(connection, '{invalid JSON ' + token);
  const invalid = run("serve", connection);
  assert.equal(invalid.status, 1);
  assert.ok(!invalid.stderr.includes(token));
});

test("the MCP adapter validates drafts and reads Commerce without forwarding its CMS token", { timeout: 10_000 }, async (t) => {
  const token = "fma_" + "a".repeat(64);
  const requests = [];
  let catalogPath;
  let variantsPath;
  let categoriesPath;
  const server = createServer(async (request, response) => {
    const url = new URL(request.url, "http://localhost");
    const body = [];
    for await (const chunk of request) body.push(chunk);
    requests.push({ url, method: request.method, authorization: request.headers.authorization, body: Buffer.concat(body).toString() });
    response.setHeader("Content-Type", "application/json");
    switch (url.pathname) {
      case "/cms/assistant/v1/connection":
        response.end(JSON.stringify({ public_catalog_path: catalogPath, public_variants_catalog_path: variantsPath, public_categories_path: categoriesPath }));
        break;
      case "/cms/assistant/v1/pages/validate":
        response.statusCode = 422;
        response.end(JSON.stringify({ message: "Invalid", errors: { title: ["Title is required"] } }));
        break;
      case "/shop/products":
        response.end(JSON.stringify({
          data: [{ id: 12, sku: "DEMO", name: "Demo", price_minor: 500, currency: "PLN", available: 7, internal: "hidden" }],
          next_cursor: "page-2",
        }));
        break;
      case "/shop/v2/products":
        response.end(JSON.stringify({
          data: [
            { id: 15, product_id: 12, sku: "DEMO-A", name: "Demo", label: "Niebieski", price_minor: "501", currency: "PLN", available: 1, public_path: "/cms/sklep/produkt/12" },
            { id: 16, product_id: 12, sku: "DEMO-B", name: "Demo", label: null, price_minor: "805", currency: "PLN", available: 0, public_path: "//different.invalid/product/12" },
          ],
          next_cursor: "variant-page-2",
        }));
        break;
      case "/shop/v2/categories":
        response.end(JSON.stringify({ data: [{ id: 19, slug: "blue-shoes", name: "Blue shoes", internal: "hidden" }], next_cursor: "category-page-2" }));
        break;
      case "/cms/assistant/v1/templates":
        response.end(JSON.stringify({ items: [{ id: 12, name: "Offer layout" }], next_page: null }));
        break;
      case "/cms/assistant/v1/images":
        response.end(JSON.stringify({ items: [{ id: 14, alt: "Example", public_path: "/cms/media/14" }], next_page: null }));
        break;
      case "/cms/assistant/v1/templates/12":
        response.end(JSON.stringify({ id: 12, name: "Offer layout", blocks: [{ type: "hero" }] }));
        break;
      default:
        response.statusCode = 404;
        response.end("{}");
    }
  });
  await once(server.listen(0, "127.0.0.1"), "listening");
  t.after(() => server.close());

  const directory = await mkdtemp(join(tmpdir(), "formaku-assistant-test-"));
  t.after(() => rm(directory, { force: true, recursive: true }));
  const config = join(directory, "connection.json");
  await writeFile(config, JSON.stringify({ base: `http://127.0.0.1:${server.address().port}/cms/assistant/v1`, token }), { mode: 0o600 });
  const adapter = fileURLToPath(new URL("../packages/cms/resources/assistant/formaku-assistant.mjs", import.meta.url));
  const child = spawn(process.execPath, [adapter, "serve", config], { stdio: ["pipe", "pipe", "inherit"] });
  t.after(() => child.kill());
  const replies = createInterface({ input: child.stdout })[Symbol.asyncIterator]();
  let id = 0;
  async function call(name, args = {}) {
    child.stdin.write(JSON.stringify({ jsonrpc: "2.0", id: ++id, method: "tools/call", params: { name, arguments: args } }) + "\n");
    const { value } = await replies.next();
    const reply = JSON.parse(value);
    assert.equal(reply.id, id);
    return reply.result;
  }

  const validation = await call("formaku_validate_draft", { data: { slug: "demo" } });
  assert.equal(validation.isError, true);
  assert.deepEqual(JSON.parse(validation.content[0].text).errors, { title: ["Title is required"] });
  assert.equal(requests[0].authorization, `Bearer ${token}`);
  assert.equal(requests[0].method, "POST");
  assert.deepEqual(JSON.parse(requests[0].body), { data: { slug: "demo" } });

  assert.equal((await call("formaku_list_products")).isError, true);
  catalogPath = "https://other.invalid/products";
  assert.equal((await call("formaku_list_products")).isError, true);
  assert.equal(requests.length, 3);

  catalogPath = "/shop/products";
  const catalog = JSON.parse((await call("formaku_list_products", { cursor: "page+1" })).content[0].text);
  assert.deepEqual(catalog.items, [{ id: 12, sku: "DEMO", name: "Demo", price_minor: 500, currency: "PLN", in_stock: true }]);
  assert.equal(catalog.next_cursor, "page-2");
  assert.equal(requests[3].authorization, `Bearer ${token}`);
  assert.equal(requests[4].authorization, undefined);
  assert.equal(requests[4].method, "GET");
  assert.equal(requests[4].url.pathname, "/shop/products");
  assert.equal(requests[4].url.searchParams.get("cursor"), "page+1");

  const templates = JSON.parse((await call("formaku_list_templates", { page: 2 })).content[0].text);
  const template = JSON.parse((await call("formaku_get_template", { id: 12 })).content[0].text);
  assert.deepEqual(templates.items, [{ id: 12, name: "Offer layout" }]);
  assert.deepEqual(template.blocks, [{ type: "hero" }]);
  assert.equal(requests[5].url.searchParams.get("page"), "2");
  assert.equal(requests[5].authorization, `Bearer ${token}`);
  assert.equal(requests[6].authorization, `Bearer ${token}`);
  const images = JSON.parse((await call("formaku_list_images", { page: 3 })).content[0].text);
  assert.deepEqual(images.items, [{ id: 14, alt: "Example", public_path: "/cms/media/14" }]);
  assert.equal(requests[7].url.searchParams.get("page"), "3");
  assert.equal(requests[7].authorization, `Bearer ${token}`);

  variantsPath = "//different.invalid/shop/v2/products";
  assert.equal((await call("formaku_list_products")).isError, true);
  assert.equal(requests.length, 9);
  variantsPath = "/shop/v2/products";
  const variants = JSON.parse((await call("formaku_list_products", { cursor: "variant+1" })).content[0].text);
  assert.deepEqual(variants.items, [
    { id: 12, variant_id: 15, variant_label: "Niebieski", public_path: "/cms/sklep/produkt/12", sku: "DEMO-A", name: "Demo", price_minor: "501", currency: "PLN", in_stock: true },
    { id: 12, variant_id: 16, sku: "DEMO-B", name: "Demo", price_minor: "805", currency: "PLN", in_stock: false },
  ]);
  assert.equal(variants.next_cursor, "variant-page-2");
  assert.equal(requests[10].authorization, undefined);
  assert.equal(requests[10].url.pathname, "/shop/v2/products");
  assert.equal(requests[10].url.searchParams.get("cursor"), "variant+1");

  assert.equal((await call("formaku_list_products", { category: "blue-shoes" })).isError, true);
  assert.equal(requests.length, 12);
  categoriesPath = "/shop/v2/categories";
  const categories = JSON.parse((await call("formaku_list_categories", { cursor: "category+1" })).content[0].text);
  assert.deepEqual(categories.items, [{ id: 19, slug: "blue-shoes", name: "Blue shoes" }]);
  assert.equal(categories.next_cursor, "category-page-2");
  assert.equal(requests[13].authorization, undefined);
  assert.equal(requests[13].url.searchParams.get("cursor"), "category+1");

  assert.equal((await call("formaku_list_products", { category: "blue-shoes&cursor=other" })).isError, true);
  assert.equal(requests.length, 15);
  await call("formaku_list_products", { category: "blue-shoes", cursor: "variant+2" });
  assert.equal(requests[16].authorization, undefined);
  assert.equal(requests[16].url.searchParams.get("category"), "blue-shoes");
  assert.equal(requests[16].url.searchParams.get("cursor"), "variant+2");
});
