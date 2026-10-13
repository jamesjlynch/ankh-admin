// Keep the source stylesheet editable. Generated assets are committed so PHP
// hosting does not need Node. Preserve rule order and do not restructure rules.
const fs = require('node:fs');
const path = require('node:path');
const postcss = require('postcss');
const CleanCSS = require('clean-css');
const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'style.css'), 'utf8');
const core = postcss.parse(source);
// Supplier Centre is a separate screen. Remove only rules whose EVERY selector
// is scoped to that screen; mixed selectors and shared styles stay in core.
core.walkRules(rule => {
  if (rule.selectors.every(selector => /[.#]sc-[\w-]+/.test(selector))) rule.remove();
});
core.walkAtRules(rule => { if (rule.nodes && !rule.nodes.length) rule.remove(); });
for (const [file, css] of [['style.min.css', source], ['style.core.min.css', core.toString()]]) {
  const result = new CleanCSS({ level: 1, inline: ['none'], rebase: false }).minify(css);
  if (result.errors.length) throw new Error(result.errors.join('\n'));
  fs.writeFileSync(path.join(root, file), result.styles + '\n');
  console.log(`${file}: ${Buffer.byteLength(result.styles)} bytes`);
}
