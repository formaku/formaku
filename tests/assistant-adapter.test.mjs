import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { once } from "node:events";
import { mkdtemp, rm, writeFile } from "node:fs/promises";
import { createServer } from "node:http";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { createInterface } from "node:readline";
import { test } from "node:test";
import { fileURLToPath } from "node:url";

test("the MCP adapter validates drafts and reads Commerce without forwarding its CMS token", { timeout: 10_000 }, async (t) => {
  const token = "fma_" + "a".repeat(64);
  const requests = [];
  let catalogPath;
  const server = createServer(async (request, response) => {
    const url = new URL(request.url, "http://localhost");
    const body = [];
    for await (const chunk of request) body.push(chunk);
    requests.push({ url, method: request.method, authorization: request.headers.authorization, body: Buffer.concat(body).toString() });
    response.setHeader("Content-Type", "application/json");
    switch (url.pathname) {
      case "/cms/assistant/v1/connection":
        response.end(JSON.stringify({ public_catalog_path: catalogPath }));
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
});
