/**
 * Bier Creative — Local Development Server
 * Zero-dependency HTTP server with live-reloading, MIME resolution,
 * audio streaming range support, and admin route handling.
 */
const http = require('http');
const fs = require('fs');
const path = require('path');
const url = require('url');

const ROOT_DIR = path.resolve(__dirname);
let PORT = parseInt(process.env.PORT, 10) || 3000;

const MIME_TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.mjs': 'application/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.webmanifest': 'application/manifest+json; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.webp': 'image/webp',
  '.gif': 'image/gif',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
  '.mp3': 'audio/mpeg',
  '.ogg': 'audio/ogg',
  '.wav': 'audio/wav',
  '.xml': 'application/xml; charset=utf-8',
  '.txt': 'text/plain; charset=utf-8'
};

// Live reload clients
const sseClients = new Set();

function broadcastReload() {
  for (const client of sseClients) {
    try {
      client.write('data: reload\n\n');
    } catch {
      sseClients.delete(client);
    }
  }
}

// Watch project files for live reload
let debounceTimer = null;
try {
  fs.watch(ROOT_DIR, { recursive: true }, (eventType, filename) => {
    if (!filename) return;
    const ext = path.extname(filename).toLowerCase();
    if (['.html', '.css', '.js', '.json', '.webmanifest'].includes(ext)) {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => {
        console.log(`  \x1b[36m⚡ File changed: ${filename} — Reloading connected browsers...\x1b[0m`);
        broadcastReload();
      }, 100);
    }
  });
} catch (e) {
  // fs.watch recursive fallback
}

const LIVE_RELOAD_SCRIPT = `
<!-- BIER DEV LIVE RELOAD -->
<script>
(function() {
  if (window.location.protocol === 'file:') return;
  var es = new EventSource('/__livereload');
  es.onmessage = function(e) {
    if (e.data === 'reload') {
      console.log('[Bier Dev] Reloading page...');
      window.location.reload();
    }
  };
  es.onerror = function() {
    setTimeout(function() {
      // Reconnect handled automatically by EventSource
    }, 2000);
  };
})();
</script>
`;

function handleRequest(req, res) {
  const reqUrl = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  let pathname = decodeURIComponent(reqUrl.pathname);

  // SSE Live Reload Endpoint
  if (pathname === '/__livereload') {
    res.writeHead(200, {
      'Content-Type': 'text/event-stream',
      'Cache-Control': 'no-cache',
      'Connection': 'keep-alive',
      'Access-Control-Allow-Origin': '*'
    });
    res.write(':\n\n');
    sseClients.add(res);
    req.on('close', () => sseClients.delete(res));
    return;
  }

  // Redirect /admin to /admin/
  if (pathname === '/admin') {
    res.writeHead(301, { Location: '/admin/' + (reqUrl.search || '') });
    res.end();
    return;
  }

  // Resolve file path
  let relativePath = pathname.replace(/^\/+/, '');
  if (!relativePath || relativePath === '') {
    relativePath = 'index.html';
  } else if (relativePath === 'admin/' || relativePath === 'admin') {
    relativePath = 'admin/index.html';
  }

  const filePath = path.resolve(ROOT_DIR, relativePath);

  // Security: check path traversal
  if (!filePath.startsWith(ROOT_DIR)) {
    res.writeHead(403, { 'Content-Type': 'text/plain' });
    res.end('403 Forbidden');
    return;
  }

  fs.stat(filePath, (err, stats) => {
    if (err) {
      res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end(`404 Not Found: ${pathname}`);
      return;
    }

    // If it's a directory, check for index.html
    if (stats.isDirectory()) {
      const dirIndex = path.join(filePath, 'index.html');
      if (fs.existsSync(dirIndex)) {
        serveFile(req, res, dirIndex, fs.statSync(dirIndex));
      } else {
        res.writeHead(403, { 'Content-Type': 'text/plain' });
        res.end('403 Forbidden: Directory Listing Not Allowed');
      }
      return;
    }

    serveFile(req, res, filePath, stats);
  });
}

function serveFile(req, res, filePath, stats) {
  const ext = path.extname(filePath).toLowerCase();
  const contentType = MIME_TYPES[ext] || 'application/octet-stream';

  // Support Audio / Video HTTP Range requests (crucial for .mp3 playback and scrubbing)
  const range = req.headers.range;
  if (range && (ext === '.mp3' || ext === '.ogg' || ext === '.wav')) {
    const parts = range.replace(/bytes=/, '').split('-');
    const start = parseInt(parts[0], 10);
    const end = parts[1] ? parseInt(parts[1], 10) : stats.size - 1;
    const chunksize = (end - start) + 1;
    const fileStream = fs.createReadStream(filePath, { start, end });

    res.writeHead(206, {
      'Content-Range': `bytes ${start}-${end}/${stats.size}`,
      'Accept-Ranges': 'bytes',
      'Content-Length': chunksize,
      'Content-Type': contentType
    });
    fileStream.pipe(res);
    return;
  }

  // Inject live-reload script into HTML
  if (ext === '.html') {
    fs.readFile(filePath, 'utf8', (err, html) => {
      if (err) {
        res.writeHead(500, { 'Content-Type': 'text/plain' });
        res.end('500 Internal Server Error');
        return;
      }
      const injectedHtml = html.includes('</body>')
        ? html.replace('</body>', `${LIVE_RELOAD_SCRIPT}</body>`)
        : html + LIVE_RELOAD_SCRIPT;

      const buffer = Buffer.from(injectedHtml, 'utf8');
      res.writeHead(200, {
        'Content-Type': contentType,
        'Content-Length': buffer.length,
        'Cache-Control': 'no-cache, no-store, must-revalidate',
        'Access-Control-Allow-Origin': '*'
      });
      res.end(buffer);
    });
    return;
  }

  // Static assets
  res.writeHead(200, {
    'Content-Type': contentType,
    'Content-Length': stats.size,
    'Accept-Ranges': 'bytes',
    'Cache-Control': 'public, max-age=3600',
    'Access-Control-Allow-Origin': '*'
  });
  fs.createReadStream(filePath).pipe(res);
}

function startServer(port) {
  const server = http.createServer(handleRequest);

  server.on('error', (err) => {
    if (err.code === 'EADDRINUSE') {
      console.warn(`  \x1b[33mPort ${port} is in use, trying port ${port + 1}...\x1b[0m`);
      startServer(port + 1);
    } else {
      console.error('Server error:', err);
    }
  });

  server.listen(port, () => {
    console.log('\n\x1b[1m\x1b[32m  ✔ Bier Creative Dev Server is running!\x1b[0m\n');
    console.log(`  \x1b[1m>\x1b[0m \x1b[36mMain Website:\x1b[0m  http://localhost:${port}/`);
    console.log(`  \x1b[1m>\x1b[0m \x1b[36mAdmin Portal:\x1b[0m  http://localhost:${port}/admin/`);
    console.log(`  \x1b[1m>\x1b[0m \x1b[90mLive Reload:\x1b[0m  Active (watching HTML, CSS, JS)\n`);
    console.log('  Press \x1b[1mCtrl+C\x1b[0m to stop the server.\n');
  });
}

startServer(PORT);
