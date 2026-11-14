#!/usr/bin/env python3
"""Strict manifest shared by vendor sync, deployment and the isolated rig."""
import argparse
import hashlib
import io
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys

REPOSITORIES = {"azguard": "axiomasoft/azguard-private", "proxyma": "axiomasoft/proxyma"}


def require(condition, message):
    if not condition:
        raise ValueError(message)


def load(path):
    value = json.loads(Path(path).read_text())
    require(isinstance(value, dict) and type(value.get("version")) is int, "invalid manifest/version")
    if value.get("version") == 1:
        require(set(value) == {"version", "repository", "commit", "packages"}, "invalid v1 manifest")
        require(all(set(p) == {"name", "path"} for p in value["packages"]), "invalid v1 packages")
        rows = [{"key": "azguard", **{k: value[k] for k in ("repository", "commit")},
                 "packages": [{**p, "version": "dev-main"} for p in value["packages"]]}]
    else:
        require(set(value) == {"version", "repositories"} and type(value["version"]) is int
                and value["version"] == 2, "vendor manifest must be strict v1 or v2")
        rows = value["repositories"]
    require(isinstance(rows, list) and rows, "repositories must be nonempty")
    keys, names = set(), set()
    for row in rows:
        require(isinstance(row, dict) and set(row) == {"key", "repository", "commit", "packages"}, "invalid repository fields")
        key = row["key"]
        require(isinstance(key, str) and key in REPOSITORIES and key not in keys, "invalid/duplicate checkout key")
        keys.add(key)
        require(row["repository"] == {"host": "github.com", "path": REPOSITORIES[key]}, "repository is not allowlisted")
        require(isinstance(row["commit"], str) and re.fullmatch(r"[0-9a-f]{40}", row["commit"])
                and row["commit"] != "0" * 40, "commit must be full lowercase SHA")
        packages = row["packages"]
        require(isinstance(packages, list) and packages, "packages must be nonempty")
        paths = set()
        for package in packages:
            require(isinstance(package, dict) and set(package) == {"name", "path", "version"}, "invalid package fields")
            name, subpath, version = (package[k] for k in ("name", "path", "version"))
            expected = {"packages/core": f"axiomasoft/{key}", "packages/filament": f"axiomasoft/{key}-filament"}
            require(isinstance(subpath, str) and subpath in expected and name == expected[subpath]
                    and name not in names and subpath not in paths, "invalid/duplicate package or path traversal")
            require(version == ("0.1.0" if key == "proxyma" else "dev-main"), "invalid package version")
            paths.add(subpath)
            names.add(name)
        require(paths == {"packages/core", "packages/filament"}, "both package subpaths are required")
    return rows


def git(root, *args, check=True):
    result = subprocess.run(["git", "-C", str(root), *args], capture_output=True, text=True)
    require(not check or result.returncode == 0, "vendor Git operation failed")
    return result


def origin_matches(origin, row):
    repo = row["repository"]["path"]
    return origin in {f"{prefix}{repo}{suffix}" for prefix in
                      ("https://github.com/", "git@github.com:", "ssh://git@github.com/") for suffix in ("", ".git")}


def safe_checkout(root, row):
    path = root / "local/vendors" / row["key"]
    require(all(not p.is_symlink() for p in (root / "local", path.parent, path)), "vendor path is a symlink")
    return path


def check_checkout(path, row, *, exact=True):
    require((path / ".git").is_dir() and not (path / ".git").is_symlink(), "vendor checkout is missing/unsafe")
    require(origin_matches(git(path, "config", "--get", "remote.origin.url").stdout.strip(), row), "vendor origin differs")
    require(not git(path, "status", "--porcelain", "--untracked-files=all").stdout.strip(), "vendor checkout is dirty")
    if not exact:
        return
    require(git(path, "rev-parse", "HEAD").stdout.strip() == row["commit"], "vendor SHA differs from manifest")
    for package in row["packages"]:
        folder = path / package["path"]
        require(not folder.is_symlink() and not folder.parent.is_symlink()
                and folder.resolve().is_relative_to(path.resolve()), "unsafe package path")
        file = folder / "composer.json"
        require(file.is_file() and not file.is_symlink(), "vendor package is missing")
        actual = json.loads(file.read_text())
        require(actual.get("name") == package["name"], "vendor package name differs")
        if row["key"] == "proxyma" and package["path"] == "packages/filament":
            require(actual.get("require", {}).get("axiomasoft/proxyma") in {"self.version", "0.1.0"}, "UI/core versions must match")


def serialized(value):
    # Composer hashes raw composer.json + PHP serialize(path repository options).
    if isinstance(value, bool):
        return f"b:{int(value)};".encode()
    if isinstance(value, str):
        raw = value.encode()
        return f's:{len(raw)}:"'.encode() + raw + b'";'
    if isinstance(value, dict):
        return f"a:{len(value)}:{{".encode() + b"".join(serialized(k) + serialized(v) for k, v in value.items()) + b"}"
    raise ValueError("unsupported Composer option")


def package_files(checkout, row):
    """Read pinned blobs, never checkout-local secrets, symlinks or submodules."""
    argv = ["git", "-C", str(checkout), "ls-tree", "-rz", "--full-tree", row["commit"], "--",
            *[p["path"] for p in row["packages"]]]
    result = subprocess.run(argv, capture_output=True)
    require(result.returncode == 0, "cannot read pinned package tree")
    entries = []
    for entry in filter(None, result.stdout.split(b"\0")):
        meta, name = entry.split(b"\t", 1)
        mode, kind, oid = meta.split()
        path = Path(name.decode())
        require(not path.is_absolute() and ".." not in path.parts, "unsafe package path")
        require(mode in {b"100644", b"100755"} and kind == b"blob", "package contains a symlink or special file")
        require(not any(part in {".git", "secrets", "credentials", "auth.json", "vendor", "node_modules"}
                        or part.startswith((".env", "id_rsa", "id_ed25519")) or part.endswith(".key")
                        for part in path.parts), "package contains a forbidden credential/build path")
        entries.append((name.decode(), oid))
    # git archive applies export-ignore/export-subst; snapshots must match raw pinned source.
    result = subprocess.run(["git", "-C", str(checkout), "cat-file", "--batch"],
                            input=b"".join(oid + b"\n" for _, oid in entries), capture_output=True)
    require(result.returncode == 0, "cannot read pinned package blobs")
    stream, files = io.BytesIO(result.stdout), {}
    for name, oid in entries:
        header = stream.readline().split()
        require(len(header) == 3 and header[:2] == [oid, b"blob"] and header[2].isdigit(), "invalid pinned blob header")
        size = int(header[2])
        files[name] = stream.read(size)
        require(len(files[name]) == size and stream.read(1) == b"\n", "incomplete pinned blob")
    require(all(p["path"] + "/composer.json" in files for p in row["packages"]), "pinned package manifest is missing")
    return files


def package_hashes(files, row):
    return {p["path"]: hashlib.sha256(b"".join(name.encode() + b"\0" + files[name] + b"\0"
            for name in sorted(files) if name.startswith(p["path"] + "/"))).hexdigest() for p in row["packages"]}


def composer_contract(root, rows):
    composer = json.loads((root / "composer.json").read_text())
    lock = json.loads((root / "composer.lock").read_text())
    locked = lock["packages"] + lock.get("packages-dev", [])
    for row in rows:
        pinned = package_files(root / "local/vendors" / row["key"], row)
        for package in row["packages"]:
            url = f"local/vendors/{row['key']}/{package['path']}"
            repos = [r for r in composer.get("repositories", []) if r.get("url") == url]
            require(len(repos) == 1 and repos[0].get("type") == "path", "missing/duplicate Composer path repository")
            options = repos[0].get("options", {})
            require(options.get("symlink") is False and options.get("versions", {}).get(package["name"]) == package["version"], "Composer must copy the pinned version")
            require(composer["require"].get(package["name"]) == package["version"], "Composer requirement differs")
            records = [p for p in locked if p["name"] == package["name"]]
            require(len(records) == 1, "missing/duplicate locked package")
            record = records[0]
            require(record["version"] == package["version"] and record.get("dist", {}).get("url") == url
                    and record["dist"].get("type") == "path" and record.get("transport-options", {}).get("symlink") is False, "Composer lock differs")
            raw = pinned[package["path"] + "/composer.json"]
            reference = options.get("reference", "auto")
            require(reference in {"auto", "config"}, "Composer reference must bind source")
            nested_git = root / "local/vendors" / row["key"] / package["path"] / ".git"
            expected = (row["commit"] if reference == "auto" and nested_git.is_dir()
                        else hashlib.sha1(raw + serialized({**options, "relative": True})).hexdigest())
            require(record["dist"].get("reference") == expected, "Composer reference differs from pinned source")


def composer_command():
    composer = shutil.which("composer")
    require(composer is not None, "Composer executable is required")
    requested = os.environ.get("AGELTO_PHP_BINARY")
    for candidate in ([requested] if requested else ["php8.5", "php8.4", "php"]):
        php = shutil.which(candidate)
        if php is None:
            continue
        result = subprocess.run([php, "-r", "echo PHP_VERSION_ID;"], capture_output=True, text=True)
        version = result.stdout.strip()
        # The consumer lock includes Symfony 8.1, whose minimum is PHP 8.4.1.
        if result.returncode == 0 and version.isdecimal() and 80401 <= int(version) < 90000:
            return [php, composer]
    raise ValueError("Composer checks require PHP >=8.4.1 <9; install it or set AGELTO_PHP_BINARY")


def run_composer_checks(root):
    command = composer_command()
    for arguments in (["validate", "--no-check-all", "--strict"],
                      ["install", "--dry-run", "--no-dev", "--no-scripts", "--no-interaction", "--no-progress", "--prefer-dist"]):
        require(subprocess.run([*command, *arguments], cwd=root).returncode == 0, "Composer check failed")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument("--rows", action="store_true")
    parser.add_argument("--check", action="store_true")
    parser.add_argument("--composer-check", action="store_true")
    args = parser.parse_args()
    rows = load(args.root / "docker/private-vendors.lock.json")
    if args.rows:
        for row in rows:
            print(row["key"], row["repository"]["path"], row["commit"], sep="\t")
        return
    # Preflight every existing checkout before any fetch/checkout.
    for row in rows:
        path = safe_checkout(args.root, row)
        if args.check or path.exists():
            check_checkout(path, row, exact=args.check)
    for row in rows:
        path = safe_checkout(args.root, row)
        if not args.check:
            if not path.exists():
                path.parent.mkdir(parents=True, exist_ok=True)
                result = subprocess.run(["git", "clone", "--no-checkout", f"https://github.com/{row['repository']['path']}.git", str(path)], capture_output=True)
                require(result.returncode == 0, "vendor clone failed")
            if git(path, "cat-file", "-e", row["commit"] + "^{commit}", check=False).returncode:
                git(path, "fetch", "--quiet", "--no-tags", "origin", row["commit"])
            git(path, "checkout", "--detach", row["commit"])
            check_checkout(path, row)
        print(f"Vendor {row['key']} verified at {row['commit']}")
    if args.composer_check:
        composer_contract(args.root, rows)
        run_composer_checks(args.root)


if __name__ == "__main__":
    try:
        main()
    except (ValueError, OSError, KeyError, TypeError) as error:
        print(f"Vendor validation failed: {error}", file=sys.stderr)
        sys.exit(1)
