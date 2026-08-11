import { spawnSync } from 'node:child_process';
import { mkdtemp, mkdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { publishAtomically, verifyDistribution } from './verify-dist.mjs';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const finalDirectory = join(repositoryRoot, 'var/private-documentation');
const temporaryDirectory = await mkdtemp(join(tmpdir(), 'agelto-documentation-'));
const artifactDirectory = join(temporaryDirectory, 'private-documentation');
const siteDirectory = join(artifactDirectory, 'site');
const manifestPath = join(artifactDirectory, 'manifest.json');

try {
    await mkdir(artifactDirectory, { recursive: true });

    const result = spawnSync(
        process.execPath,
        ['node_modules/vitepress/bin/vitepress.js', 'build', 'docs'],
        {
            cwd: repositoryRoot,
            env: { ...process.env, DOCUMENTATION_OUTPUT_DIR: siteDirectory },
            stdio: 'inherit',
        },
    );

    if (result.status !== 0) {
        throw new Error(`VitePress build failed with exit code ${result.status ?? 'unknown'}`);
    }

    await verifyDistribution({ siteDirectory, manifestPath });
    await publishAtomically({ artifactDirectory, finalDirectory });

    console.log(`Private documentation published at ${finalDirectory}`);
} finally {
    await rm(temporaryDirectory, { force: true, recursive: true });
}
