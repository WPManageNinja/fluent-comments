/**
 * Scrapes every $t() and $_n() call in the admin source and writes them out
 * as a PHP array of __() calls, so WordPress's own string extractor - which
 * cannot read .vue files - has something to find.
 *
 *     npm run i18n
 *
 * The English string is the key on both sides, so nothing has to be kept in
 * sync by hand: add a $t('...') and re-run this. The output file is
 * generated in full every time and should never be edited.
 *
 * A translator comment is picked up from a single-line comment sitting
 * directly above the call:
 *
 *     <!-- translators: %s is the post type name -->
 *     {{ $t('%s has no template', row.label) }}
 *
 * Comments are only carried across for strings that actually contain a
 * placeholder - a comment on "Save changes" tells a translator nothing.
 */

// ESM, because package.json is "type": "module".
import fs from 'node:fs';
import path from 'node:path';

const targetDir = 'resources/admin/src';
const namespace = 'fluent-comments';
const phpNamespace = 'FluentComments\\App\\Services';
const finalFile = 'app/Services/TransStrings.php';
const extensions = ['.vue', '.js'];

// The runtime itself. Its $t( occurrences are the definition and its
// examples, not call sites, so scanning it only produces noise.
const skipFiles = [path.join(targetDir, 'i18n.js')];

function readDirRecursively(dir, allFiles = []) {
    fs.readdirSync(dir).forEach(entry => {
        const filepath = path.join(dir, entry);

        if (fs.statSync(filepath).isDirectory()) {
            readDirRecursively(filepath, allFiles);
        } else if (extensions.includes(path.extname(entry)) && !skipFiles.includes(filepath)) {
            allFiles.push(filepath);
        }
    });

    return allFiles;
}

/**
 * Reads a quoted literal starting at `index`, honouring backslash escapes.
 *
 * Parsed rather than matched with one regex so that an apostrophe inside a
 * string ("a post\'s own content") and a call broken over several lines
 * both survive. A regex that handled either tended to swallow the next
 * string on the line.
 *
 * @returns {{value: string, end: number}|null}
 */
function readLiteral(content, index) {
    const quote = content[index];

    if (quote !== "'" && quote !== '"') {
        return null;
    }

    let value = '';

    for (let i = index + 1; i < content.length; i++) {
        const char = content[i];

        if (char === '\\') {
            // Kept escaped: it goes straight back out into a PHP
            // single-quoted literal, and escapePhpSingleQuoted() normalises
            // it there.
            value += char + content[i + 1];
            i++;
            continue;
        }

        if (char === quote) {
            return {value, end: i};
        }

        // A literal cannot span lines, so an unterminated one is a
        // template literal or something else this cannot read.
        if (char === '\n') {
            return null;
        }

        value += char;
    }

    return null;
}

function skipSpace(content, index) {
    while (index < content.length && /\s/.test(content[index])) {
        index++;
    }

    return index;
}

/**
 * The comment line directly above the call, if there is one. Only asked for
 * when the string has a placeholder in it.
 */
function commentAbove(content, callIndex) {
    const lines = content.slice(0, callIndex).split('\n');
    const previous = lines[lines.length - 2];

    if (!previous) {
        return null;
    }

    const match = previous.match(/<!--\s*(.*?)\s*-->/) || previous.match(/\/\*\s*(.*?)\s*\*\//);

    return match ? match[1].trim() : null;
}

function extractStrings(files) {
    const strings = {};
    const comments = {};

    const record = (value, content, callIndex) => {
        strings[value] = true;

        if (!comments[value] && value.includes('%')) {
            const comment = commentAbove(content, callIndex);

            if (comment) {
                comments[value] = comment;
            }
        }
    };

    files.forEach(file => {
        const content = fs.readFileSync(file, 'utf8');
        const callRegex = /\$(_n|t)\(/g;

        let match;

        while ((match = callRegex.exec(content)) !== null) {
            const isPlural = match[1] === '_n';
            const first = readLiteral(content, skipSpace(content, match.index + match[0].length));

            if (!first) {
                // A variable, a template literal, or a concatenation. There
                // is nothing to hand a translator, so say so rather than
                // dropping it silently.
                const line = content.slice(0, match.index).split('\n').length;
                console.warn(`  skipped a non-literal $${match[1]}() call at ${file}:${line}`);
                continue;
            }

            record(first.value, content, match.index);

            if (!isPlural) {
                continue;
            }

            let cursor = skipSpace(content, first.end + 1);

            if (content[cursor] !== ',') {
                continue;
            }

            const second = readLiteral(content, skipSpace(content, cursor + 1));

            if (second) {
                record(second.value, content, match.index);
            }
        }
    });

    return {strings: Object.keys(strings), comments};
}

/**
 * Escape an apostrophe for a PHP single-quoted literal. Strings scraped
 * from source already arrive escaped, so unescaping first keeps this
 * idempotent rather than doubling the backslash on every run.
 */
function escapePhpSingleQuoted(str) {
    return String(str).replace(/\\'/g, "'").replace(/'/g, "\\'");
}

function writeResults(strings, comments) {
    const body = strings.sort().map(str => {
        const key = escapePhpSingleQuoted(str);
        const lines = [];

        if (comments[str]) {
            lines.push(`            /* translators: ${comments[str].replace(/^translators:\s*/i, '')} */`);
        }

        lines.push(`            '${key}' => __('${key}', '${namespace}')`);

        return lines.join('\n');
    }).join(',\n');

    const contents = `<?php

namespace ${phpNamespace};

/**
 * Every string the admin app asks for by name.
 *
 * Auto-generated by i18n.node.js from the $t() and $_n() calls in
 * ${targetDir}. Do not edit it by hand - run \`npm run i18n\` instead.
 *
 * It exists so that WordPress's string extractor, which only reads PHP, can
 * see strings that only ever appear in a .vue file.
 */
class TransStrings
{
    public static function getStrings()
    {
        return [
${body}
        ];
    }
}
`;

    fs.writeFileSync(finalFile, contents);

    console.log(`Wrote ${strings.length} strings to ${finalFile}`);
}

const files = readDirRecursively(targetDir);
const extracted = extractStrings(files);

writeResults(extracted.strings, extracted.comments);
