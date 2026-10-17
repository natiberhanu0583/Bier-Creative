/**
 * Bier Creative — Project Linter & Code Verification
 * Verifies JavaScript syntax, HTML structure, duplicate IDs, CSS syntax, and JSON manifests.
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

let errorCount = 0;
let warningCount = 0;

function logPass(msg) {
  console.log(`  \x1b[32m✔\x1b[0m ${msg}`);
}
function logWarn(msg) {
  warningCount++;
  console.log(`  \x1b[33m⚠\x1b[0m ${msg}`);
}
function logFail(msg) {
  errorCount++;
  console.error(`  \x1b[31m✖\x1b[0m ${msg}`);
}

console.log('\n\x1b[1m\x1b[36m=== BIER CREATIVE — CODE QUALITY & LINT CHECK ===\x1b[0m\n');

// 1. JavaScript Files
console.log('\x1b[1m[1/4] Checking JavaScript Syntax...\x1b[0m');
const jsFiles = [
  'script.js',
  'chatbot.js',
  'chatbrain.js',
  'api-config.js',
  'server.js',
  'admin/admin.js',
  'admin/config.js',
  'admin/i18n.js',
  'admin/i18n-en.js',
  'admin/sw.js'
];

for (const relPath of jsFiles) {
  const filePath = path.resolve(__dirname, relPath);
  if (!fs.existsSync(filePath)) {
    logWarn(`File skipped (not found): ${relPath}`);
    continue;
  }
  try {
    const code = fs.readFileSync(filePath, 'utf8');
    new vm.Script(code, { filename: relPath });
    logPass(`${relPath} (valid syntax)`);
  } catch (err) {
    logFail(`${relPath}: ${err.message}`);
  }
}

// 2. HTML Files
console.log('\n\x1b[1m[2/4] Checking HTML Structure & Duplicate IDs...\x1b[0m');
const htmlFiles = ['index.html', 'admin/index.html'];

for (const relPath of htmlFiles) {
  const filePath = path.resolve(__dirname, relPath);
  if (!fs.existsSync(filePath)) {
    logWarn(`File skipped (not found): ${relPath}`);
    continue;
  }
  const content = fs.readFileSync(filePath, 'utf8');

  // Check doctype
  if (!/<!DOCTYPE\s+html>/i.test(content)) {
    logWarn(`${relPath}: Missing <!DOCTYPE html>`);
  }

  // Check duplicate IDs
  const idMatches = [...content.matchAll(/\bid=["']([^"']+)["']/g)];
  const seenIds = new Set();
  const duplicateIds = new Set();
  for (const match of idMatches) {
    const id = match[1];
    if (seenIds.has(id)) {
      duplicateIds.add(id);
    } else {
      seenIds.add(id);
    }
  }

  if (duplicateIds.size > 0) {
    logFail(`${relPath}: Duplicate IDs found: ${[...duplicateIds].join(', ')}`);
  } else {
    logPass(`${relPath}: ${idMatches.length} unique element IDs verified (0 duplicates)`);
  }

  // Tag balancing check for key structural tags
  const tagsToTrack = new Set(['div', 'section', 'article', 'aside', 'nav', 'main', 'header', 'footer', 'form', 'table']);
  const tagRegex = /<\/?([a-z0-9\-]+)([^>]*)>/gi;
  let m;
  const stack = [];
  let line = 1;
  let lastIdx = 0;
  let htmlErrors = 0;

  while ((m = tagRegex.exec(content)) !== null) {
    const rawTag = m[0];
    const tagName = m[1].toLowerCase();
    const isClosing = rawTag.startsWith('</');
    const isSelfClosing = rawTag.endsWith('/>');

    line += (content.substring(lastIdx, m.index).match(/\n/g) || []).length;
    lastIdx = m.index;

    // Skip script/style content
    if (tagName === 'script' || tagName === 'style') {
      if (!isClosing) {
        const closeRegex = new RegExp(`<\/${tagName}>`, 'gi');
        closeRegex.lastIndex = tagRegex.lastIndex;
        const closeMatch = closeRegex.exec(content);
        if (closeMatch) {
          tagRegex.lastIndex = closeRegex.lastIndex;
          lastIdx = tagRegex.lastIndex;
          continue;
        }
      }
    }

    if (tagsToTrack.has(tagName)) {
      if (isClosing) {
        if (stack.length === 0 || stack[stack.length - 1].tagName !== tagName) {
          logFail(`${relPath}:${line}: Mismatched closing tag </${tagName}>`);
          htmlErrors++;
        } else {
          stack.pop();
        }
      } else if (!isSelfClosing) {
        stack.push({ tagName, line });
      }
    }
  }

  if (stack.length > 0) {
    for (const openTag of stack) {
      logFail(`${relPath}:${openTag.line}: Unclosed tag <${openTag.tagName}>`);
      htmlErrors++;
    }
  }

  if (htmlErrors === 0) {
    logPass(`${relPath}: HTML structural tags balanced correctly`);
  }
}

// 3. CSS Files
console.log('\n\x1b[1m[3/4] Checking CSS Files...\x1b[0m');
const cssFiles = ['style.css', 'admin/admin.css'];

for (const relPath of cssFiles) {
  const filePath = path.resolve(__dirname, relPath);
  if (!fs.existsSync(filePath)) {
    logWarn(`File skipped (not found): ${relPath}`);
    continue;
  }
  const css = fs.readFileSync(filePath, 'utf8');
  let braceCount = 0;
  let parenCount = 0;
  let line = 1;
  let cssErrors = 0;

  for (let i = 0; i < css.length; i++) {
    const ch = css[i];
    if (ch === '\n') line++;
    else if (ch === '{') braceCount++;
    else if (ch === '}') {
      braceCount--;
      if (braceCount < 0) {
        logFail(`${relPath}:${line}: Unexpected closing brace '}'`);
        cssErrors++;
        braceCount = 0;
      }
    } else if (ch === '(') parenCount++;
    else if (ch === ')') {
      parenCount--;
      if (parenCount < 0) {
        logFail(`${relPath}:${line}: Unexpected closing parenthesis ')'`);
        cssErrors++;
        parenCount = 0;
      }
    }
  }

  if (braceCount > 0) {
    logFail(`${relPath}: Unclosed brace '{' (${braceCount} open)`);
    cssErrors++;
  }
  if (parenCount > 0) {
    logFail(`${relPath}: Unclosed parenthesis '(' (${parenCount} open)`);
    cssErrors++;
  }

  if (cssErrors === 0) {
    logPass(`${relPath}: Balanced braces and parentheses verified`);
  }
}

// 4. JSON Files
console.log('\n\x1b[1m[4/4] Checking Manifest & JSON Files...\x1b[0m');
const jsonFiles = ['package.json', 'admin/manifest.webmanifest'];

for (const relPath of jsonFiles) {
  const filePath = path.resolve(__dirname, relPath);
  if (!fs.existsSync(filePath)) {
    logWarn(`File skipped (not found): ${relPath}`);
    continue;
  }
  try {
    JSON.parse(fs.readFileSync(filePath, 'utf8'));
    logPass(`${relPath} (valid JSON syntax)`);
  } catch (err) {
    logFail(`${relPath}: ${err.message}`);
  }
}

// Summary
console.log('\n' + '='.repeat(50));
if (errorCount === 0) {
  console.log(`\x1b[32m\x1b[1m✨ LINT PASSED: 0 errors (${warningCount} warnings)\x1b[0m`);
  console.log('='.repeat(50) + '\n');
  process.exit(0);
} else {
  console.error(`\x1b[31m\x1b[1m❌ LINT FAILED: ${errorCount} error(s) found (${warningCount} warnings)\x1b[0m`);
  console.log('='.repeat(50) + '\n');
  process.exit(1);
}
