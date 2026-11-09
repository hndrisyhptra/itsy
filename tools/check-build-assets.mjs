// Run after npm run build: verify the actual generated preload helper and files.
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import ts from 'typescript';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const build = resolve(root, 'public/build');
const manifest = JSON.parse(readFileSync(resolve(build, 'manifest.json'), 'utf8'));
let files = 0;
for (const entry of Object.values(manifest)) {
  for (const file of [entry.file, ...(entry.css ?? []), ...(entry.assets ?? [])]) {
    assert.ok(resolve(build, file).startsWith(build + '/'), 'Asset must stay in build directory');
    assert.ok(existsSync(resolve(build, file)), 'Missing build asset: ' + file); files++;
  }
  for (const key of [...(entry.imports ?? []), ...(entry.dynamicImports ?? [])]) assert.ok(manifest[key], 'Missing manifest import: ' + key);
}
const main = manifest['resources/js/area3/main.tsx'];
const source = readFileSync(resolve(build, main.file), 'utf8');
const tree = ts.createSourceFile('main.js', source, ts.ScriptTarget.Latest, true, ts.ScriptKind.JS);
let preload, map;
function find(node) {
  if (ts.isVariableDeclaration(node)) {
    if (node.initializer?.getText(tree).includes('Unable to preload CSS')) preload = node;
    if (node.name.getText(tree) === '__vite__mapDeps') map = node;
  }
  ts.forEachChild(node, find);
}
find(tree);
assert.ok(preload && map, 'Generated Vite dependency mapper/preloader not found');
const declarations = preload.parent.declarations;
const wanted = new Set([preload.name.getText(tree)]);
// Include only variables referenced by the generated helper, excluding unrelated React code.
let changed = true;
while (changed) {
  changed = false;
  for (const declaration of declarations) {
    if (!wanted.has(declaration.name.getText(tree))) continue;
    const names = new Set();
    function identifiers(node) { if (ts.isIdentifier(node)) names.add(node.text); ts.forEachChild(node, identifiers); }
    identifiers(declaration.initializer);
    for (const sibling of declarations) {
      const name = sibling.name.getText(tree);
      if (names.has(name) && !wanted.has(name)) { wanted.add(name); changed = true; }
    }
  }
}
const helpers = [...declarations].filter(d => wanted.has(d.name.getText(tree))).map(d => 'const ' + d.getText(tree) + ';').join('\n');
const dependencies = [];
function strings(node) { if (ts.isStringLiteral(node)) dependencies.push(node.text); ts.forEachChild(node, strings); }
strings(map.initializer);
assert.ok(dependencies.some(d => d.endsWith('.css')), 'Fixture must exercise generated CSS preloading');

const profiles = [
  'http://127.0.0.1:8013/build/',
  'http://localhost/its/area3-prototype/public/build/',
  'https://assets.example.test/area3/build/',
];
for (const base of profiles) {
  const links = [], window = new EventTarget();
  class Link extends EventTarget { href = ''; rel = ''; setAttribute() {} }
  const document = {
    getElementsByTagName: () => links, querySelector: () => null, createElement: () => new Link(),
    head: { appendChild(link) {
      links.push(link);
      queueMicrotask(() => {
        const url = new URL(link.href), prefix = new URL(base);
        const scoped = url.origin === prefix.origin && url.pathname.startsWith(prefix.pathname);
        const local = scoped ? resolve(build, url.pathname.slice(prefix.pathname.length)) : '';
        const exists = local.startsWith(build + '/') && existsSync(local);
        link.dispatchEvent(new Event(exists ? 'load' : 'error'));
      });
    } },
  };
  const context = vm.createContext({ URL, Event, document, window });
  const helper = vm.runInContext(helpers + '\n' + preload.name.getText(tree), context);
  let imports = 0;
  await helper(async () => { imports++; }, dependencies, new URL(main.file, base).href);
  assert.equal(imports, 1);
  assert.equal(links.length, dependencies.length);
  for (const link of links) assert.ok(link.href.startsWith(base + 'assets/'), 'Asset escaped deployment base: ' + link.href);
}
console.log(`Build assets verified: ${files} manifest references; ${dependencies.length} actual preload dependencies across ${profiles.length} root/subfolder/CDN profiles.`);
