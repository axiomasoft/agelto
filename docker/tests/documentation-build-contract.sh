#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
temporary_dir=$(mktemp -d)
first_build="$temporary_dir/first"
trap 'rm -rf "$temporary_dir"' EXIT

cd "$root_dir"
pnpm install --frozen-lockfile
pnpm documentation:build
cp -a var/private-documentation "$first_build"
pnpm documentation:build
diff -r "$first_build" var/private-documentation

node --input-type=module <<'NODE'
import { mkdtemp, mkdir, symlink, writeFile } from 'node:fs/promises';
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
