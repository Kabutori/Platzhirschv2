import http from 'node:http';
import { readFile } from 'node:fs/promises';
const token = 'a'.repeat(64);
const widget = await readFile(new URL('../app/public/widget.js', import.meta.url));
const host = `<!doctype html><html lang="de"><meta charset="utf-8"><title>Widget test host</title>
<style>input,button,select {display:none !important} body {color:red}</style>
<platzhirsch-booking token="${token}"></platzhirsch-booking>
<script src="http://127.0.0.1:4175/widget.js" defer></script></html>`;
for (const port of [4174, 4175]) {
  http.createServer((request, response) => {
    if (request.url === '/widget.js') {
      response.writeHead(200, { 'Content-Type': 'application/javascript' });
      response.end(widget);
    } else {
      response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      response.end(host);
    }
  }).listen(port, '127.0.0.1');
}
