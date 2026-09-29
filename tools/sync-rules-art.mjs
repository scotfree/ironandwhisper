/**
 * Copy the real silhouettes from img/*.svg into rules.html.
 *
 * The manual inlines the town, city and pawn drawings as <symbol> definitions,
 * so it stands alone with no requests and prints without the game. Those were
 * hand-copied, and they drifted: the city's flag had been redrawn in img/ and
 * the manual still had the old one. This rewrites everything between the
 * `art:begin` and `art:end` markers from the files, so a drawing edited in a
 * drawing app reaches the manual on the next build.
 *
 * The colours are handled the way BoardView.frameMarkup handles them on the
 * board: **white fill and black stroke are the game's colours** and become
 * `var(--art-fill)` and `var(--art-stroke)`, which inherit into a <use> from
 * whatever uses it. Any other colour is left alone.
 *
 * Town and city get `vector-effect="non-scaling-stroke"` so the outline stays
 * a line whether the drawing is a 120px box or a letter-sized icon in the text
 * — the same thing the game's stylesheet does to them. The pawn does not: it is
 * small everywhere, and its stroke scaling with it is what keeps it from
 * turning into a blot.
 *
 * Ids inside a drawing (the city's gradient) are prefixed with the drawing's
 * name, because every symbol shares one document.
 *
 * Run by `npm run build`, before the client build.
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const rulesFile = join(root, 'rules.html');

const BEGIN = '<!-- art:begin — generated from img/*.svg by tools/sync-rules-art.mjs; edit the files, not this -->';
const END = '<!-- art:end -->';

/** name => whether its outline should stay a fixed width at any size. */
const drawings = { town: true, city: true, pawn: false };

function symbol(name, keepStroke) {
    const file = readFileSync(join(root, 'img', `${name}.svg`), 'utf8');
    const open = file.match(/<svg\b[^>]*>/);
    const close = file.lastIndexOf('</svg>');
    if (!open || close === -1) {
        throw new Error(`img/${name}.svg has no <svg> element`);
    }
    const viewBox = open[0].match(/viewBox="([^"]*)"/)?.[1];
    if (!viewBox) {
        throw new Error(`img/${name}.svg has no viewBox`);
    }

    let body = file.slice(open.index + open[0].length, close)
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/(fill\s*[:=]\s*"?)(#fff(?:fff)?|white)\b/gi, '$1var(--art-fill)')
        .replace(/(stroke\s*[:=]\s*"?)(#000(?:000)?|black)\b/gi, '$1var(--art-stroke)')
        .replace(/\bid="([^"]+)"/g, `id="${name}-$1"`)
        .replace(/url\(#([^)]+)\)/g, `url(#${name}-$1)`);

    if (keepStroke) {
        body = body.replace(/<(path|circle|rect|ellipse|polygon|polyline|line)\b/g,
            '<$1 vector-effect="non-scaling-stroke"');
    }

    const lines = body.split('\n').map(line => line.trim()).filter(Boolean);
    return `  <symbol id="art-${name}" viewBox="${viewBox}">\n`
        + lines.map(line => `    ${line}`).join('\n')
        + '\n  </symbol>';
}

const html = readFileSync(rulesFile, 'utf8');
const start = html.indexOf(BEGIN);
const end = html.indexOf(END);
if (start === -1 || end === -1 || end < start) {
    throw new Error('rules.html is missing its art:begin / art:end markers');
}

const art = Object.entries(drawings).map(([name, keep]) => symbol(name, keep)).join('\n');
const updated = html.slice(0, start + BEGIN.length) + '\n' + art + '\n' + html.slice(end);

if (updated !== html) {
    writeFileSync(rulesFile, updated);
    console.log('rules.html <- img/town.svg, img/city.svg, img/pawn.svg');
} else {
    console.log('rules.html art already matches img/');
}
