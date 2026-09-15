import { readFile, writeFile } from 'node:fs/promises';
const root = new URL('../', import.meta.url);
const arg = (name) => {const i=process.argv.indexOf(name); return i >= 0 ? process.argv[i+1] : undefined;};
const tokens = (await readFile(arg('--tokens') || new URL('admin-ui/packages/design-tokens/tokens.css', root), 'utf8')).replace(':root', ':host');
const css = await readFile(new URL('src/widget.css', import.meta.url), 'utf8');
const source = await readFile(new URL('src/widget.js', import.meta.url), 'utf8');
await writeFile(
  arg('--output') || new URL('app/public/widget.js', root),
  source.replace("'__WIDGET_CSS__'", JSON.stringify(tokens + '\n' + css)),
);
console.log('Shadow-DOM booking widget built.');
