import { readFile, writeFile } from 'node:fs/promises';
const root = new URL('../', import.meta.url);
const tokens = (await readFile(new URL('admin-ui/src/tokens.css', root), 'utf8')).replace(':root', ':host');
const css = await readFile(new URL('src/widget.css', import.meta.url), 'utf8');
const source = await readFile(new URL('src/widget.js', import.meta.url), 'utf8');
await writeFile(
  new URL('app/public/widget.js', root),
  source.replace("'__WIDGET_CSS__'", JSON.stringify(tokens + '\n' + css)),
);
console.log('Shadow-DOM booking widget built.');
