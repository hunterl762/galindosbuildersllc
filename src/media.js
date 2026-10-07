import crypto from "node:crypto";
import fs from "node:fs/promises";
import path from "node:path";
import multer from "multer";
import { fileTypeFromBuffer } from "file-type";
import { z } from "zod";
import { httpError } from "./security.js";
export const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: 10 * 1024 * 1024, files: 1, fields: 10, fieldSize: 2048 },
}).single("image");
export async function replaceMedia(db, cfg, id, file) {
  const [media] = await db.query("SELECT * FROM media WHERE id=?", [id]);
  if (
    !media ||
    !/^\/uploads\/media\/[a-f0-9]{32}\.(jpg|png|webp|gif|ico)$/.test(media.url)
  )
    throw httpError(
      422,
      "Only images uploaded to this media library can be replaced.",
    );
  if (!file) throw httpError(422, "Choose a replacement image.");
  const type = await fileTypeFromBuffer(file.buffer);
  if (!type || type.mime !== media.mime_type)
    throw httpError(
      422,
      "Replacement must use the same image format as the original.",
    );
  const target = path.join(cfg.uploadDir, "media", path.basename(media.url)),
    temporary = `${target}.${crypto.randomBytes(8).toString("hex")}.tmp`;
  await fs.writeFile(temporary, file.buffer, { flag: "wx" });
  try {
    await fs.rename(temporary, target);
    await db.query("UPDATE media SET size_bytes=? WHERE id=?", [file.size, id]);
  } finally {
    await fs.unlink(temporary).catch((e) => {
      if (e.code !== "ENOENT") throw e;
    });
  }
}
export async function saveMedia(db, cfg, file, body, options = {}) {
  if (!file) throw httpError(422, "Choose an image to upload.");
  const type = await fileTypeFromBuffer(file.buffer);
  if (
    !type ||
    ![
      "image/jpeg",
      "image/png",
      "image/webp",
      "image/gif",
      "image/x-icon",
      "image/vnd.microsoft.icon",
    ].includes(type.mime)
  )
    throw httpError(422, "Use a valid JPG, PNG, WebP, GIF or ICO image.");
  if (options.allowedMimeTypes && !options.allowedMimeTypes.includes(type.mime))
    throw httpError(422, "Use a JPG, PNG, WebP or GIF social media image.");
  const data = z
    .object({
      title: z.string().trim().max(255),
      alt_text: z.string().trim().max(255),
      folder: z.string().trim().max(100),
    })
    .parse({
      title: body.title || file.originalname,
      alt_text: body.alt_text || "",
      folder: body.folder || "",
    });
  const id = crypto.randomBytes(16).toString("hex"),
    filename = `${id}.${type.ext}`,
    url = `/uploads/media/${filename}`;
  await fs.mkdir(path.join(cfg.uploadDir, "media"), { recursive: true });
  await fs.writeFile(path.join(cfg.uploadDir, "media", filename), file.buffer, {
    flag: "wx",
  });
  try {
    const persist = async (tx) => {
      await tx.query(
        "INSERT INTO media(id,url,title,alt_text,folder,mime_type,size_bytes) VALUES(?,?,?,?,?,?,?)",
        [id, url, data.title, data.alt_text, data.folder, type.mime, file.size],
      );
      if (options.settingKey)
        await tx.query(
          "INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",
          [options.settingKey, url],
        );
    };
    if (options.settingKey) await db.transaction(persist);
    else await persist(db);
  } catch (e) {
    await fs.unlink(path.join(cfg.uploadDir, "media", filename));
    throw e;
  }
  return id;
}
export async function deleteMedia(db, cfg, id) {
  const media = await db.query("SELECT * FROM media WHERE id=?", [id]);
  if (!media.length) throw httpError(404, "Image not found.");
  const url = media[0].url;
  const checks = [
    ["project_images", "image_url"],
    ["projects", "featured_image"],
    ["projects", "og_image"],
    ["pages", "featured_image"],
    ["pages", "og_image"],
    ["services", "featured_image"],
    ["services", "og_image"],
    ["service_areas", "featured_image"],
    ["service_areas", "og_image"],
    ["seo_settings", "og_image"],
    ["site_settings", "setting_value"],
  ];
  for (const [table, column] of checks)
    if (
      (
        await db.query(`SELECT 1 FROM ${table} WHERE ${column}=? LIMIT 1`, [
          url,
        ])
      ).length
    )
      throw httpError(
        409,
        "This image is in use. Remove its content references before deleting it.",
      );
  // Preserve imported legacy files. Only app-created media files may be physically removed.
  await db.query("DELETE FROM media WHERE id=?", [id]);
  if (/^\/uploads\/media\/[a-f0-9]{32}\.(jpg|png|webp|gif|ico)$/.test(url))
    await fs
      .unlink(path.join(cfg.uploadDir, "media", path.basename(url)))
      .catch((e) => {
        if (e.code !== "ENOENT") throw e;
      });
}
