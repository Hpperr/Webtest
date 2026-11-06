import { timingSafeEqual } from "node:crypto";
import { createServer } from "node:http";
import { verifyUserToken } from "./token-verifier.js";

const port = Number(process.env.REALTIME_PORT || 3100);
const tokenSecret = process.env.APP_KEY || "";
const serviceToken = process.env.REALTIME_SERVICE_TOKEN || "";
const allowedOrigins = new Set((process.env.REALTIME_ALLOWED_ORIGINS || "")
  .split(",")
  .map((origin) => origin.trim())
  .filter(Boolean));
const clients = new Map();
let nextClientId = 1;

function json(response, status, body) {
  response.writeHead(status, { "content-type": "application/json; charset=utf-8", "cache-control": "no-store" });
  response.end(JSON.stringify(body));
}

function authorizedService(request) {
  const authorization = request.headers.authorization || "";
  const provided = Buffer.from(authorization.replace(/^Bearer\s+/i, ""));
  const expected = Buffer.from(serviceToken);
  return serviceToken.length >= 32 && provided.length === expected.length && timingSafeEqual(provided, expected);
}

function publish(userId, event) {
  const message = `event: notification\ndata: ${JSON.stringify(event)}\n\n`;
  for (const response of clients.get(userId) || []) response.write(message);
}

const server = createServer(async (request, response) => {
  const url = new URL(request.url || "/", `http://${request.headers.host || "localhost"}`);
  if (url.pathname === "/health" && request.method === "GET") {
    return json(response, 200, { data: { status: "ok", connectedClients: [...clients.values()].reduce((sum, group) => sum + group.size, 0) } });
  }

  if (url.pathname === "/publish" && request.method === "POST") {
    if (!authorizedService(request)) return json(response, 401, { error: "unauthorized" });
    let body;
    try {
      const chunks = [];
      let size = 0;
      for await (const chunk of request) {
        size += chunk.length;
        if (size > 16384) return json(response, 413, { error: "payload_too_large" });
        chunks.push(chunk);
      }
      body = JSON.parse(Buffer.concat(chunks).toString("utf8"));
    } catch {
      return json(response, 400, { error: "invalid_json" });
    }
    if (!Number.isInteger(body.userId) || body.userId < 1 || typeof body.type !== "string" || body.type.length > 48 || !body.payload || typeof body.payload !== "object") {
      return json(response, 422, { error: "invalid_event" });
    }
    publish(body.userId, { type: body.type, payload: body.payload, createdAt: new Date().toISOString() });
    return json(response, 202, { data: { accepted: true } });
  }

  if (url.pathname === "/events" && request.method === "GET") {
    const origin = request.headers.origin;
    if (origin && !allowedOrigins.has(origin)) return json(response, 403, { error: "origin_not_allowed" });
    const user = verifyUserToken(url.searchParams.get("token"), tokenSecret);
    if (!user) return json(response, 401, { error: "invalid_or_expired_token" });
    response.writeHead(200, {
      "content-type": "text/event-stream; charset=utf-8",
      "cache-control": "no-cache, no-transform",
      connection: "keep-alive",
      "x-accel-buffering": "no",
      ...(origin ? { "access-control-allow-origin": origin, vary: "Origin" } : {}),
    });
    response.write(": connected\n\n");
    const group = clients.get(user.userId) || new Set();
    group.add(response);
    clients.set(user.userId, group);
    const heartbeat = setInterval(() => response.write(": heartbeat\n\n"), 25000);
    const expires = setTimeout(() => response.end(), Math.max(0, (user.expiresAt - Math.floor(Date.now() / 1000)) * 1000));
    response.on("close", () => {
      clearInterval(heartbeat);
      clearTimeout(expires);
      group.delete(response);
      if (group.size === 0) clients.delete(user.userId);
    });
    return;
  }

  return json(response, 404, { error: "not_found" });
});

if (tokenSecret.length < 32 || serviceToken.length < 32) {
  throw new Error("[Realtime] APP_KEY and REALTIME_SERVICE_TOKEN must each contain at least 32 characters.");
}

server.listen(port, "127.0.0.1", () => console.log(`[Realtime] Listening on 127.0.0.1:${port}`));

process.on("SIGTERM", () => server.close(() => process.exit(0)));
