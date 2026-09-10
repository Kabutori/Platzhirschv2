import {copyFile,mkdir} from 'node:fs/promises';
const destination=new URL('../app/public/landing/',import.meta.url);
await mkdir(destination,{recursive:true});
for(const [from,to] of [
 ['../admin-ui/packages/design-tokens/tokens.css','tokens.css'],
 ['../admin-ui/node_modules/@fontsource/work-sans/files/work-sans-latin-400-normal.woff2','work-sans.woff2'],
 ['../admin-ui/node_modules/@fontsource/newsreader/files/newsreader-latin-400-normal.woff2','newsreader.woff2'],
]) await copyFile(new URL(from,import.meta.url),new URL(to,destination));
console.log('Landingpage: gemeinsame Designtokens und lokale Schriften bereitgestellt.');
