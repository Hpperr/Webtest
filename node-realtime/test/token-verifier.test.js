import test from "node:test";
import assert from "node:assert/strict";
import { createHmac } from "node:crypto";
import { verifyUserToken } from "../src/token-verifier.js";

function sign(payload, secret = "test-secret-with-more-than-thirty-two-characters") {
  const header = Buffer.from(JSON.stringify({ alg: "HS256", typ: "JWT" })).toString("base64url");
  const body = Buffer.from(JSON.stringify(payload)).toString("base64url");
  const content = `${header}.${body}`;
  const signature = createHmac("sha256", secret).update(content).digest("base64url");
  return `${content}.${signature}`;
}

test("accepts valid, unexpired reader token", () => {
  const token = sign({ sub: 42, role: "reader", exp: 2000 });
  assert.deepEqual(verifyUserToken(token, "test-secret-with-more-than-thirty-two-characters", 1000), {
    userId: 42,
    role: "reader",
    expiresAt: 2000,
  });
});

test("rejects expired, modified, and unsupported-role tokens", () => {
  const secret = "test-secret-with-more-than-thirty-two-characters";
  assert.equal(verifyUserToken(sign({ sub: 42, role: "reader", exp: 1000 }, secret), secret, 1000), null);
  assert.equal(verifyUserToken(sign({ sub: 42, role: "reader", exp: 2000 }, secret) + "x", secret, 1000), null);
  assert.equal(verifyUserToken(sign({ sub: 42, role: "owner", exp: 2000 }, secret), secret, 1000), null);
});

test("rejects weak verification secret and invalid user identifier", () => {
  assert.equal(verifyUserToken(sign({ sub: 0, role: "reader", exp: 2000 }), "test-secret-with-more-than-thirty-two-characters", 1000), null);
  assert.equal(verifyUserToken("x.y.z", "short", 1000), null);
});
