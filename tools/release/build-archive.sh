#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Production archive (zip) of a commit, built in Docker (no host PHP or Node): runtime files with
# vendor/ (no development dependencies) and the built frontend, public/.htaccess for Apache, the
# Docker files with the sources they need, and a config/config.php ready to edit (app.public_url
# null: app:boot refuses to start until it is set). No test, development tool or secret.
# --config <file> ships that config.php instead (an instance's own configuration, which must
# hold no secret: the secret stays in .env or the environment).
# Usage: tools/release/build-archive.sh <git ref> <version> [--locales en,fr] [--config file] [--out dist]
set -euo pipefail
cd "$(dirname "$0")/../.."
repo=$PWD
ref=${1:?git ref}; version=${2:?version}; shift 2
locales=''; out=dist; config=''
while [ $# -gt 0 ]; do
  case "$1" in
    --locales) locales=$2; shift 2 ;;
    --out) out=$2; shift 2 ;;
    --config) config=$(cd "$(dirname "$2")" && pwd)/$(basename "$2"); shift 2 ;;
    *) echo "unknown option $1" >&2; exit 2 ;;
  esac
done
mkdir -p "$out"; out=$(cd "$out" && pwd)
work=$(mktemp -d); trap 'rm -rf "$work"' EXIT
name="quietlink-$version"
git -C "$repo" archive --format=tar --prefix="$name/" "$ref" | tar -x -C "$work"
src="$work/$name"
docker run --rm --platform linux/amd64 -u "$(id -u):$(id -g)" -e HOME=/tmp -e COMPOSER_HOME=/tmp/c -v "$src:/src" -w /src --entrypoint sh \
  composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac \
  -c 'composer install --quiet --no-dev --classmap-authoritative --no-interaction --no-scripts --ignore-platform-reqs'
docker run --rm --platform linux/amd64 -u "$(id -u):$(id -g)" -e HOME=/tmp -v "$src:/src" -w /src \
  node:24-alpine@sha256:ebfe2f90462722a7a4de65e91990e97fe0d401c70e0e762c5b53302f905ec1c1 \
  sh -c 'npm ci --ignore-scripts --no-audit --no-fund --loglevel=error > /dev/null && npx vite build > /dev/null 2>&1 && rm -rf node_modules'
keep="bin config datas docker frontend public src templates translations vendor tests/vectors compose.yaml composer.json composer.lock package.json package-lock.json tsconfig.json vite.config.ts box.json LICENSE README.md CHANGELOG.md SECURITY.md docs/README-admin.md docs/decisions/ADR-0011-pre-release-before-audit.md"
pkg="$work/pkg/$name"; mkdir -p "$pkg"
for path in $keep; do mkdir -p "$pkg/$(dirname "$path")"; cp -a "$src/$path" "$pkg/$path"; done
rm -rf "$pkg/frontend/tests" "$pkg/docker/qa" "$pkg/docker/e2e" "$pkg/config/config.php" "$pkg/config/reference.php"
mkdir -p "$pkg/var"
# Deny-all for the directories next to public/, should the document root wrongly be the project
# directory (Apache; .env at the project root is covered by public/ being the only document root).
for dir in config datas var; do printf 'Require all denied\n' > "$pkg/$dir/.htaccess"; done
sed -e "s#'public_url' => 'https://quietlink.example.test',#'public_url' => null, // REQUIRED: your https origin, e.g. 'https://paste.example.org' (app:boot refuses null)#" \
    "$pkg/config/config.php.example" > "$pkg/config/config.php"
grep -q "'public_url' => null, // REQUIRED" "$pkg/config/config.php"
if [ -n "$config" ]; then
  grep -q "return \[" "$config" || { echo "$config is not a QuietLink config.php" >&2; exit 2; }
  if grep -Eq "^[^/]*QUIETLINK_APP_SECRET[A-Z_]*['\"]? *(=>|=)" "$config"; then echo "$config must not hold the secret" >&2; exit 2; fi
  cp "$config" "$pkg/config/config.php"
elif [ -n "$locales" ]; then
  list=$(printf "'%s', " ${locales//,/ }); list="[${list%, }]"
  sed -i.bak -e "s#'enabled_locales' => null,#'enabled_locales' => $list,#" "$pkg/config/config.php" && rm -f "$pkg/config/config.php.bak"
  grep -q "'enabled_locales' => \[" "$pkg/config/config.php"
fi
commit=$(git -C "$repo" rev-parse --short "$ref")
cat > "$pkg/INSTALL.txt" <<TXT
QuietLink $version - production archive (commit $commit)

EVALUATION PRE-RELEASE: the sp-proto/v1 protocol has not yet been independently reviewed
(docs/decisions/ADR-0011-pre-release-before-audit.md). Do not use it for real secrets.

Contents: PHP code with vendor/ (no development dependencies), built frontend (public/build),
public/.htaccess (Apache), templates, translations, empty datas/ and var/ directories, and the
Docker files with the sources they need. No .env is shipped: you create it (step 2).

Shared hosting (Apache, no root) - docs/README-admin.md section 4.3:
1. Upload the files; set the site's document root to the public/ directory; PHP >= 8.3 with
   intl, mbstring, openssl, sodium; Apache with mod_rewrite, mod_headers and AllowOverride
   allowing Options, FileInfo, Indexes, Limit (public/.htaccess).
2. php bin/console app:secret:generate --output .env --dotenv   (secret file, never printed)
3. Edit config/config.php: app.public_url (required, https). chmod 600 config/config.php .env
   (PHP running as the account owning the files, as on most shared hosts).
4. php bin/console app:boot --dry-run, then php bin/console app:boot (the dry run writes
   nothing), then php bin/console app:config:check --format=json (exit 0 = ready).
5. Schedule php bin/console app:purge-expired as often as the host allows (hourly is enough).
6. curl -sI https://<host>/ must show one Strict-Transport-Security header (section 4.3).
Shared hosting defaults accept network filesystems (NFS) with a warning; see section 4.3 for
what that costs and set storage.allow_unsupported_fs = false on a dedicated server.

Dedicated server: Docker (section 3) or PHP-FPM + Nginx/Apache with systemd (section 4).

Integrity: SHA-256 of this archive in $name.zip.sha256.
TXT
stamp=$(git -C "$repo" log -1 --format=%cd --date=format-local:%Y%m%d%H%M.%S "$ref")
find "$pkg" -exec touch -h -t "$stamp" {} +
rm -f "$out/$name.zip"
(cd "$work/pkg" && find "$name" -print | LC_ALL=C sort | zip -q -X -@ "$out/$name.zip")
(cd "$out" && { sha256sum "$name.zip" 2>/dev/null || shasum -a 256 "$name.zip"; } > "$name.zip.sha256" && cat "$name.zip.sha256")
