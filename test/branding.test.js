import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { saveMedia } from "../src/media.js";

test("failed branding settings transaction cleans up the uploaded file", async () => {
  const uploadDir = await fs.mkdtemp(path.join(os.tmpdir(), "gb-branding-"));
  const buffer = Buffer.from(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nXsAAAAASUVORK5CYII=",
    "base64",
  );
  const db = {
    transaction: async (persist) =>
      persist({
        query: async (sql) => {
          if (sql.startsWith("INSERT INTO site_settings"))
            throw new Error("Settings write failed");
        },
      }),
  };
  try {
    await assert.rejects(
      saveMedia(
        db,
        { uploadDir },
        { buffer, size: buffer.length, originalname: "icon.png" },
        { title: "Favicon" },
        { settingKey: "favicon_url" },
      ),
      /Settings write failed/,
    );
    assert.deepEqual(await fs.readdir(path.join(uploadDir, "media")), []);
  } finally {
    await fs.rm(uploadDir, { recursive: true, force: true });
  }
});
