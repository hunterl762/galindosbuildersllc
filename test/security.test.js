import test from "node:test";
import assert from "node:assert/strict";
import bcrypt from "bcryptjs";
import argon2 from "argon2";
import { safeUrl, cleanHtml, verifyPassword, can } from "../src/security.js";
import { parseFields, resources } from "../src/admin-fields.js";
import { config } from "../src/config.js";
test("URL validation blocks script, protocol-relative and backslash redirects", () => {
  for (const value of [
    "javascript:alert(1)",
    "//attacker.example",
    "/\\attacker.example",
    "data:text/html,test",
  ])
    assert.throws(() => safeUrl(value));
  assert.equal(safeUrl("/projects"), "/projects");
  assert.equal(safeUrl("https://example.com/"), "https://example.com/");
});
test("rich content removes scripts, event handlers and unsafe links", () => {
  const html = cleanHtml(
    '<h2>Title</h2><script>alert(1)</script><a href="javascript:alert(1)" onclick="alert(1)">link</a><img src=x onerror=alert(1)>',
  );
  assert.equal(html, "<h2>Title</h2><a>link</a>");
});
test("PHP bcrypt and Argon2 passwords remain valid", async () => {
  const hash = await bcrypt.hash("long-password-123", 4);
  assert.equal(
    await verifyPassword("long-password-123", hash.replace("$2b$", "$2y$")),
    true,
  );
  assert.equal(await verifyPassword("wrong", hash), false);
  const argon = await argon2.hash("long-password-123");
  assert.equal(await verifyPassword("long-password-123", argon), true);
});
test("roles are enforced independently of the navigation", () => {
  assert.equal(can({ role: "editor", active: 1 }, "users"), false);
  assert.equal(can({ role: "sales", active: 1 }, "content"), false);
  assert.equal(can({ role: "owner", active: 0 }, "settings"), false);
  assert.equal(can({ role: "owner", active: 1 }, "users"), true);
});
test("project input validates slugs, dates and dimensions", () => {
  assert.throws(() =>
    parseFields(resources.projects.fields, { name: "Test", slug: "Bad Slug" }),
  );
  assert.throws(() =>
    parseFields(resources.projects.fields, {
      name: "Test",
      slug: "test",
      completion_date: "2026-02-30",
    }),
  );
  assert.throws(() =>
    parseFields(resources.projects.fields, {
      name: "Test",
      slug: "test",
      square_footage: "-1",
    }),
  );
  const data = parseFields(resources.projects.fields, {
    name: "Test",
    slug: "test",
    visible: "1",
    completion_date: "",
  });
  assert.equal(data.completion_date, null);
  assert.equal(data.visible, 1);
});
test("production configuration requires a strong secret and HTTPS", () => {
  assert.throws(() => config({}));
  assert.throws(() =>
    config({
      SESSION_SECRET: "x".repeat(32),
      NODE_ENV: "production",
      SITE_URL: "http://example.com",
    }),
  );
});
