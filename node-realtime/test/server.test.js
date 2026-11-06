import test from "node:test";
import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { createHmac } from "node:crypto";
import { fileURLToPath } from "node:url";

test("realtime health and protected publishing endpoints", async (t) => {
  const port = 35000 + (process.pid % 20000);
  const serviceToken = "integration-test-service-token-with-32-chars";
  const child = spawn(process.execPath, [fileURLToPath(new URL("../src/server.js", import.meta.url))], {
    env: {
      ...process.env,
      REALTIME_PORT: String(port),
      APP_KEY: "integration-test-app-key-with-at-least-32-characters",
      REALTIME_SERVICE_TOKEN: serviceToken,
      REALTIME_ALLOWED_ORIGINS: "http://localhost:8000",
    },
    stdio: "ignore",
  });
  t.after(() => child.kill());

  let health;
  for (let attempt = 0; attempt < 30; attempt += 1) {
    try {
      health = await fetch(`http://127.0.0.1:${port}/health`);
      break;
    } catch {
      await new Promise((resolve) => setTimeout(resolve, 100));
    }
  }
  assert.ok(health, "server should start and expose health endpoint");
  assert.equal(health.status, 200);
  assert.deepEqual(await health.json(), { data: { status: "ok", connectedClients: 0 } });

  const payload = JSON.stringify({ userId: 7, type: "new_chapter", payload: { book: "sample" } });
  const denied = await fetch(`http://127.0.0.1:${port}/publish`, {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: payload,
  });
  assert.equal(denied.status, 401);

  const accepted = await fetch(`http://127.0.0.1:${port}/publish`, {
    method: "POST",
    headers: { authorization: `Bearer ${serviceToken}`, "content-type": "application/json" },
    body: payload,
  });
  assert.equal(accepted.status, 202);
  assert.deepEqual(await accepted.json(), { data: { accepted: true } });

  const appKey = "integration-test-app-key-with-at-least-32-characters";
  const header = Buffer.from(JSON.stringify({ alg: "HS256", typ: "JWT" })).toString("base64url");
  const claims = Buffer.from(JSON.stringify({ sub: 7, role: "reader", exp: Math.floor(Date.now() / 1000) + 60 })).toString("base64url");
  const message = `${header}.${claims}`;
  const signature = createHmac("sha256", appKey).update(message).digest("base64url");
  const blockedOrigin = await fetch(`http://127.0.0.1:${port}/events?token=${message}.${signature}`, {
    headers: { origin: "https://not-allowed.example" },
  });
  assert.equal(blockedOrigin.status, 403);
  const stream = await fetch(`http://127.0.0.1:${port}/events?token=${message}.${signature}`, {
    headers: { origin: "http://localhost:8000" },
  });
  assert.equal(stream.status, 200);
  assert.equal(stream.headers.get("access-control-allow-origin"), "http://localhost:8000");
  const reader = stream.body.getReader();
  await reader.read();
  const notification = await fetch(`http://127.0.0.1:${port}/publish`, {
    method: "POST",
    headers: { authorization: `Bearer ${serviceToken}`, "content-type": "application/json" },
    body: payload,
  });
  assert.equal(notification.status, 202);
  const delivered = await reader.read();
  assert.match(new TextDecoder().decode(delivered.value), /event: notification/);
  assert.match(new TextDecoder().decode(delivered.value), /"type":"new_chapter"/);
  await reader.cancel();
});
