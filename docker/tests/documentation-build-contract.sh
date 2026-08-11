#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)

if [[ "${DOCUMENTATION_BUILD_CANDIDATE:-0}" != 1 ]]; then
    candidate=$(mktemp -d)
    cleanup_candidate() {
        git -C "$root_dir" worktree remove --force "$candidate" >/dev/null 2>&1 || true
    }
    trap cleanup_candidate EXIT
    git -C "$root_dir" worktree add --detach "$candidate" HEAD >/dev/null
    DOCUMENTATION_BUILD_CANDIDATE=1 bash "$candidate/docker/tests/documentation-build-contract.sh"
    exit 0
fi

temporary_dir=$(mktemp -d)
first_build="$temporary_dir/first"
trap 'rm -rf "$temporary_dir"' EXIT

cd "$root_dir"
bash "$root_dir/docker/scripts/vendor-sync.sh" --composer-check
pnpm install --frozen-lockfile
pnpm documentation:build
cp -a var/private-documentation "$first_build"
pnpm documentation:build
diff -r "$first_build" var/private-documentation

node --input-type=module <<'NODE'
import { mkdtemp, mkdir, readFile, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { verifyDistribution } from './scripts/documentation/verify-dist.mjs';

const directory = await mkdtemp(join(tmpdir(), 'agelto-documentation-fixture-'));
const manifest = join(directory, 'manifest.json');
const failure = async (name, prepare) => {
    const site = join(directory, name);
    await mkdir(site);
    await prepare(site);
    await verifyDistribution({ siteDirectory: site, manifestPath: manifest })
        .then(() => { throw new Error(`${name} unexpectedly passed`); })
        .catch((error) => {
            if (error.message === `${name} unexpectedly passed`) throw error;
        });
};

await failure('symlink', async (site) => symlink('/etc/passwd', join(site, 'escape.html')));
await failure('extension', async (site) => writeFile(join(site, 'payload.exe'), 'binary'));
await failure('percent-path', async (site) => writeFile(join(site, 'asset%2f.html'), 'content'));
await failure('remote-script', async (site) => writeFile(join(site, 'index.html'), '<script src="https://example.test/payload.js"></script>'));
await failure('active-svg', async (site) => writeFile(join(site, 'active.svg'), '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>'));

const byteOrderSite = join(directory, 'byte-order');
await mkdir(byteOrderSite);
await writeFile(join(byteOrderSite, 'ai_training-loop.js'), 'training');
await writeFile(join(byteOrderSite, 'ai-context.js'), 'context');
await verifyDistribution({ siteDirectory: byteOrderSite, manifestPath: manifest });
const byteOrderManifest = JSON.parse(await readFile(manifest, 'utf8'));

if (byteOrderManifest.map(({ path }) => path).join('\n') !== 'ai-context.js\nai_training-loop.js') {
    throw new Error('Manifest paths must use PHP SORT_STRING-compatible byte ordering');
}
NODE

image="agelto-documentation-contract:$RANDOM"
docker build --file "$root_dir/docker/php/Dockerfile" --target application -t "$image" "$root_dir"
container=$(docker create "$image")
trap 'docker rm -f "$container" >/dev/null 2>&1 || true; rm -rf "$temporary_dir"' EXIT
docker export "$container" > "$temporary_dir/image.tar"
tar -tf "$temporary_dir/image.tar" | grep -Fx 'var/private-documentation/site/index.html' >/dev/null
tar -tf "$temporary_dir/image.tar" | grep -Fx 'var/private-documentation/manifest.json' >/dev/null
! tar -tf "$temporary_dir/image.tar" | grep -Eq '^var/www/html/public/(documentation|.*\.html)$'
docker run --rm --entrypoint sh "$image" -c 'test ! -e /usr/local/bin/node && test ! -e /usr/local/bin/pnpm && test "$(stat -c %a /var/private-documentation/site)" = 555 && test "$(stat -c %a /var/private-documentation/manifest.json)" = 444'
docker rm -f "$container" >/dev/null

echo 'Documentation build contract passed'
