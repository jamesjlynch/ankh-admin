// Keep the source stylesheet editable. Generated assets are committed so PHP
// hosting does not need Node. Preserve rule order and do not restructure rules.
const fs = require('node:fs');
const path = require('node:path');
const postcss = require('postcss');
const CleanCSS = require('clean-css');
const zlib = require('node:zlib');
const crypto = require('node:crypto');
const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'style.css'), 'utf8');
const core = postcss.parse(source);
// Supplier Centre is a separate screen. Remove only rules whose EVERY selector
// is scoped to that screen; mixed selectors and shared styles stay in core.
core.walkRules(rule => {
  if (rule.selectors.every(selector => /[.#]sc-[\w-]+/.test(selector))) rule.remove();
});
core.walkAtRules(rule => { if (rule.nodes && !rule.nodes.length) rule.remove(); });
const embedded = {};
for (const [file, css] of [['style.min.css', source], ['style.core.min.css', core.toString()]]) {
  const result = new CleanCSS({ level: 1, inline: ['none'], rebase: false }).minify(css);
  if (result.errors.length) throw new Error(result.errors.join('\n'));
  fs.writeFileSync(path.join(root, file), result.styles + '\n');
  embedded[file === 'style.min.css' ? 'full-css' : 'core-css'] = zlib.gzipSync(result.styles + '\n', { level: 9 }).toString('base64');
  console.log(`${file}: ${Buffer.byteLength(result.styles)} bytes`);
}
// Existing Krystal hooks sometimes copy index.php and style.css only. Keep a
// public, compiled CSS fallback in the entrypoint, without a config/session/DB
// dependency. Only these fixed, non-sensitive stylesheet payloads are exposed.
const version = crypto.createHash('sha256').update(source).digest('hex').slice(0, 16);
const begin = '// BEGIN GENERATED CSS FALLBACK\n';
const end = '// END GENERATED CSS FALLBACK';
const payload = `$compiledCssVersion='${version}';\n` +
  `$cssAsset=$_GET['asset']??'';\n` +
  `if(in_array($cssAsset,['core-css','full-css'],true)){\n` +
  ` $compiledCss=['core-css'=>'${embedded['core-css']}','full-css'=>'${embedded['full-css']}'];\n` +
  ` header('Content-Type: text/css; charset=utf-8');\n` +
  ` header('X-Content-Type-Options: nosniff');\n` +
  ` header('Cache-Control: public, max-age='.(($_GET['v']??'')===$compiledCssVersion?'31536000, immutable':'300'));\n` +
  ` echo gzdecode(base64_decode($compiledCss[$cssAsset],true));exit;\n}\n`;
let app = fs.readFileSync(path.join(root, 'index.php'), 'utf8');
const start = app.indexOf(begin), stop = app.indexOf(end);
if (start < 0 || stop < start) throw new Error('Generated CSS fallback markers missing');
app = app.slice(0, start + begin.length) + payload + app.slice(stop);
fs.writeFileSync(path.join(root, 'index.php'), app);
