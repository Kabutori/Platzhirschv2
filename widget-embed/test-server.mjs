import http from 'node:http';
import { readFile } from 'node:fs/promises';
import { basename } from 'node:path';
const token = 'a'.repeat(64);
const widget = await readFile(new URL('../app/public/widget.js', import.meta.url));
const host = `<!doctype html><html lang="de"><meta charset="utf-8"><title>Widget test host</title>
<style>input,button,select {display:none !important} body {color:red}</style>
<platzhirsch-booking token="${token}"></platzhirsch-booking>
<script src="http://127.0.0.1:4175/widget.js" defer></script></html>`;
for (const port of [4174, 4175]) {
  http
    .createServer(async (request, response) => {
      if (
        ['/admin/', '/administration/login', '/restaurant/login'].includes(request.url) ||
        /^\/admin\/assets\/[^/]+$/.test(request.url || '')
      ) {
        try {
          const path = ['/admin/', '/administration/login', '/restaurant/login'].includes(request.url)
            ? 'index.html'
            : 'assets/' + basename(request.url);
          const content = await readFile(new URL('../app/public/admin/' + path, import.meta.url));
          const type = path.endsWith('.js')
            ? 'text/javascript'
            : path.endsWith('.css')
              ? 'text/css'
              : path.endsWith('.html')
                ? 'text/html'
                : 'font/woff2';
          response.writeHead(200, { 'Content-Type': type });
          response.end(content);
        } catch {
          response.writeHead(404);
          response.end();
        }
        return;
      }
      if (request.url === '/widget.js') {
        response.writeHead(200, { 'Content-Type': 'application/javascript' });
        response.end(widget);
      } else {
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        response.end(host);
      }
    })
    .listen(port, '127.0.0.1');
}
