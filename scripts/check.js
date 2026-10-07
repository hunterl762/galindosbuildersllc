import fs from "node:fs/promises";
import path from "node:path";
import { spawnSync } from "node:child_process";
import ejs from "ejs";
import { root } from "../src/config.js";
async function walk(dir) {
  const files = [];
  for (const item of await fs.readdir(dir, { withFileTypes: true })) {
    const file = path.join(dir, item.name);
    if (item.isDirectory()) files.push(...(await walk(file)));
    else files.push(file);
  }
  return files;
}
for (const dir of ["src", "scripts", "test", "assets/js", "views"])
  for (const file of await walk(path.join(root, dir))) {
    if (file.endsWith(".js")) {
      const result = spawnSync(process.execPath, ["--check", file], {
        encoding: "utf8",
      });
      if (result.status !== 0) throw new Error(result.stderr);
    }
    if (file.endsWith(".ejs"))
      ejs.compile(await fs.readFile(file, "utf8"), { filename: file });
  }
console.log("JavaScript syntax and EJS template compilation passed.");
