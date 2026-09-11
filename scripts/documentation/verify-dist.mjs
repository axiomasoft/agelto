import { createHash } from 'node:crypto';
import { lstat, mkdir, readFile, readdir, rename, rm, writeFile } from 'node:fs/promises';
import { basename, extname, join, posix, relative, resolve, sep } from 'node:path';

const MIME_TYPES = Object.freeze({
    '.avif': 'image/avif',
    '.css': 'text/css; charset=utf-8',
    '.gif': 'image/gif',
    '.html': 'text/html; charset=utf-8',
    '.ico': 'image/x-icon',
    '.jpeg': 'image/jpeg',
    '.jpg': 'image/jpeg',
    '.js': 'text/javascript; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.mjs': 'text/javascript; charset=utf-8',
    '.png': 'image/png',
    '.txt': 'text/plain; charset=utf-8',
    '.webmanifest': 'application/manifest+json; charset=utf-8',
    '.webp': 'image/webp',
    '.woff': 'font/woff',
    '.woff2': 'font/woff2',
});

// VitePress local search emits generated chunks such as
// `assets/chunks/@localSearchIndexroot.<hash>.js`. `@` is a literal safe URL-path
// character; all traversal, encoding and separator checks remain below.
const SAFE_PATH = /^[A-Za-z0-9][A-Za-z0-9.@_/-]*$/;
const REMOTE_EXECUTABLE_RESOURCE = /<(?:script|link)\b[^>]+(?:src|href)\s*=\s*["']https?:\/\//i;
const REMOTE_CSS_RESOURCE = /(?:@import\s+(?:url\()?|url\()\s*["']?https?:\/\//i;
const REMOTE_MODULE = /\bimport\s*(?:\([^)]*|[^;]*?\bfrom\s*)["']https?:\/\//i;

export async function verifyDistribution({ siteDirectory, manifestPath }) {
    const rootDirectory = resolve(siteDirectory);
    const entries = await collectEntries(rootDirectory, rootDirectory);

    entries.sort((left, right) => Buffer.compare(Buffer.from(left.path), Buffer.from(right.path)));
    await writeFile(manifestPath, `${JSON.stringify(entries, null, 2)}\n`);

    return entries;
}

async function collectEntries(rootDirectory, directory) {
    const entries = [];

    for (const directoryEntry of await readdir(directory, { withFileTypes: true })) {
        const absolutePath = join(directory, directoryEntry.name);
        const stats = await lstat(absolutePath);

        if (stats.isSymbolicLink()) {
            throw new Error(`Symlink is forbidden: ${absolutePath}`);
        }

        if (stats.isDirectory()) {
            entries.push(...await collectEntries(rootDirectory, absolutePath));
            continue;
        }

        if (!stats.isFile()) {
            throw new Error(`Only regular files are allowed: ${absolutePath}`);
        }

        const path = toManifestPath(rootDirectory, absolutePath);
        const mime = MIME_TYPES[extname(path).toLowerCase()];

        if (mime === undefined) {
            throw new Error(`Unsupported documentation extension: ${path}`);
        }

        const contents = await readFile(absolutePath);
        assertNoRemoteExecutableResource(path, contents);

        entries.push({
            path,
            mime,
            sha256: createHash('sha256').update(contents).digest('hex'),
        });
    }

    return entries;
}

function toManifestPath(rootDirectory, absolutePath) {
    const path = relative(rootDirectory, absolutePath).split(sep).join(posix.sep);

    if (
        path.length === 0
        || !SAFE_PATH.test(path)
        || path.includes('%')
        || path.includes('\\')
        || path.split('/').some((segment) => segment === '' || segment === '.' || segment === '..')
    ) {
        throw new Error(`Unsafe documentation path: ${absolutePath}`);
    }

    return path;
}

function assertNoRemoteExecutableResource(path, contents) {
    const extension = extname(path).toLowerCase();
    const source = contents.toString('utf8');

    if (
        (extension === '.html' && REMOTE_EXECUTABLE_RESOURCE.test(source))
        || (extension === '.css' && REMOTE_CSS_RESOURCE.test(source))
        || (['.js', '.mjs'].includes(extension) && REMOTE_MODULE.test(source))
    ) {
        throw new Error(`Remote executable resource is forbidden: ${path}`);
    }
}

export async function publishAtomically({ artifactDirectory, finalDirectory }) {
    const parentDirectory = resolve(finalDirectory, '..');
    const backupDirectory = join(parentDirectory, `.${basename(finalDirectory)}-previous-${process.pid}`);

    await mkdir(parentDirectory, { recursive: true });

    try {
        await rename(finalDirectory, backupDirectory);
    } catch (error) {
        if (error.code !== 'ENOENT') {
            throw error;
        }
    }

    try {
        await rename(artifactDirectory, finalDirectory);
    } catch (error) {
        await rename(backupDirectory, finalDirectory).catch(() => undefined);
        throw error;
    }

    await rm(backupDirectory, { force: true, recursive: true });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    const [siteDirectory, manifestPath] = process.argv.slice(2);

    if (siteDirectory === undefined || manifestPath === undefined) {
        console.error('Usage: node scripts/documentation/verify-dist.mjs <site-directory> <manifest-path>');
        process.exit(2);
    }

    verifyDistribution({ siteDirectory, manifestPath })
        .then((entries) => console.log(`Documentation distribution verified (${entries.length} files)`))
        .catch((error) => {
            console.error(error.message);
            process.exitCode = 1;
        });
}
