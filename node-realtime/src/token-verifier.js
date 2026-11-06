import { createHmac, timingSafeEqual } from "node:crypto";

function decodeSegment(segment) {
  return JSON.parse(Buffer.from(segment, "base64url").toString("utf8"));
}

export function verifyUserToken(token, secret, now = Math.floor(Date.now() / 1000)) {
  if (typeof token !== "string" || token.length > 4096 || typeof secret !== "string" || secret.length < 32) {
    return null;
  }

  const parts = token.split(".");
  if (parts.length !== 3) return null;

  try {
    const [encodedHeader, encodedPayload, signature] = parts;
    const header = decodeSegment(encodedHeader);
    const payload = decodeSegment(encodedPayload);
    if (header.alg !== "HS256" || header.typ !== "JWT") return null;

    const expected = createHmac("sha256", secret).update(`${encodedHeader}.${encodedPayload}`).digest();
    const actual = Buffer.from(signature, "base64url");
    if (actual.length !== expected.length || !timingSafeEqual(actual, expected)) return null;
    if (!Number.isInteger(payload.sub) || payload.sub < 1 || !Number.isInteger(payload.exp) || payload.exp <= now) return null;
    if (typeof payload.role !== "string" || !["reader", "moderator", "admin"].includes(payload.role)) return null;
    return { userId: payload.sub, role: payload.role, expiresAt: payload.exp };
  } catch {
    return null;
  }
}
