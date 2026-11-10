/**
 * @file
 * Build script for compiling and minifying theme assets using esbuild.
 */

const esbuild = require("esbuild");
const fs = require("fs");
const path = require("path");

/**
 * Recursively find all .css and .js files in a directory.
 *
 * @param {string} dir
 *   The directory to search.
 * @param {Array} files
 *   The accumulated file list.
 *
 * @return {Array}
 *   List of files to process.
 */
function getFiles(dir, files = []) {
  if (!fs.existsSync(dir)) return files;

  const list = fs.readdirSync(dir);
  for (const file of list) {
    const fullPath = path.join(dir, file);
    if (fs.statSync(fullPath).isDirectory()) {
      getFiles(fullPath, files);
    } else if (fullPath.endsWith(".css") || fullPath.endsWith(".js")) {
      // Exclude files that are already minified to prevent loops
      if (!fullPath.endsWith(".min.css") && !fullPath.endsWith(".min.js")) {
        files.push(fullPath);
      }
    }
  }
  return files;
}

// Gather all source files
const cssFiles = getFiles("css");
const jsFiles = getFiles("js");
const componentFiles = getFiles("components");

const allFiles = [...cssFiles, ...jsFiles, ...componentFiles];

if (allFiles.length === 0) {
  console.log("No CSS or JS source files found.");
  process.exit(0);
}

console.log(`Found ${allFiles.length} files to compile. Starting esbuild...`);

// Run esbuild
esbuild
  .build({
    entryPoints: allFiles,
    bundle: false,
    minify: true,
    outdir: "dist",
    outbase: ".", // Preserves directory structure (e.g. components/colors/colors.css -> dist/components/colors/colors.min.css)
    outExtension: { ".css": ".min.css", ".js": ".min.js" },
  })
  .then(() => {
    console.log(
      `✅ Successfully compiled and minified all files into the dist/ directory!`,
    );
  })
  .catch((err) => {
    console.error("❌ Build failed:", err);
    process.exit(1);
  });
