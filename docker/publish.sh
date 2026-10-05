#!/usr/bin/env bash
# Builds the image and pushes it to GHCR, so deployments only pull it. Run on a machine with
# Docker and buildx:
#
#   docker/publish.sh            # clean tree only; tags :latest and :<short sha>
#   docker/publish.sh --dirty    # allow uncommitted changes; tags :<short sha>-dirty only
#
# When HEAD is a release tag (v1.2.3), the image is also tagged :1.2.3, :1.2 and :1.
#
# Log in once with a classic token that has write:packages (or set GHCR_TOKEN):
#   docker login ghcr.io -u <github-user>
#
# Builds amd64 and arm64 by default, on a docker-container builder the script creates (the default
# docker driver cannot build several platforms). The foreign platform runs under QEMU (`docker run
# --privileged --rm tonistiigi/binfmt --install all` once). Build one platform with
# PLATFORM=linux/amd64.
#
# A new package starts private: make it public once in its settings on GitHub.
set -euo pipefail

REPOSITORY="${REPOSITORY:-frs-projects/modsync-web}"
IMAGE="ghcr.io/${REPOSITORY}"
PLATFORM="${PLATFORM:-linux/amd64,linux/arm64}"
BUILDER="${BUILDER:-modsync-web}"

cd "$(dirname "$0")/.."

allow_dirty=false
if [[ "${1:-}" == "--dirty" ]]; then
    allow_dirty=true
fi

revision="$(git rev-parse HEAD)"
tag="$(git rev-parse --short HEAD)"
release="$(git describe --tags --exact-match --match 'v[0-9]*' 2> /dev/null || true)"
clean=true

if [[ -n "$(git status --porcelain)" ]]; then
    if [[ "$allow_dirty" != true ]]; then
        echo "Working tree has uncommitted changes. Commit them or pass --dirty." >&2
        exit 1
    fi
    tag="${tag}-dirty"
    clean=false
fi

tags=(--tag "${IMAGE}:${tag}")
version="$tag"

if [[ "$clean" == true ]]; then
    tags+=(--tag "${IMAGE}:latest")

    if [[ "$release" =~ ^v([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
        major="${BASH_REMATCH[1]}"
        minor="${BASH_REMATCH[2]}"
        patch="${BASH_REMATCH[3]}"
        version="${major}.${minor}.${patch}"
        tags+=(--tag "${IMAGE}:${version}" --tag "${IMAGE}:${major}.${minor}" --tag "${IMAGE}:${major}")
    fi
fi

# The default "docker" driver builds one platform at a time; several need a docker-container
# builder, created once and reused.
builder=()
if [[ "$PLATFORM" == *,* ]]; then
    if ! docker buildx inspect "$BUILDER" > /dev/null 2>&1; then
        docker buildx create --name "$BUILDER" --driver docker-container > /dev/null
    fi
    builder=(--builder "$BUILDER")
fi

if [[ -n "${GHCR_TOKEN:-}" ]]; then
    echo "$GHCR_TOKEN" | docker login ghcr.io -u "${GHCR_USER:-$(git config user.name)}" --password-stdin
fi

# GHCR reads these labels: source links the package to the repository, description and
# licenses show on the package page.
docker buildx build \
    ${builder[@]+"${builder[@]}"} \
    --platform "$PLATFORM" \
    --provenance=false \
    --label "org.opencontainers.image.source=https://github.com/${REPOSITORY}" \
    --label "org.opencontainers.image.url=https://github.com/${REPOSITORY}" \
    --label "org.opencontainers.image.revision=${revision}" \
    --label "org.opencontainers.image.version=${version}" \
    --label "org.opencontainers.image.created=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    --label "org.opencontainers.image.licenses=AGPL-3.0-or-later" \
    "${tags[@]}" \
    --push \
    .

echo "Pushed ${IMAGE}:${tag}${release:+ (${release})}"

if command -v gh > /dev/null; then
    visibility="$(gh api "/orgs/${REPOSITORY%%/*}/packages/container/${REPOSITORY##*/}" --jq .visibility 2> /dev/null || true)"
    if [[ -n "$visibility" && "$visibility" != "public" ]]; then
        echo "Warning: the package is '${visibility}', so others cannot pull it." >&2
        echo "Make it public at https://github.com/orgs/${REPOSITORY%%/*}/packages/container/package/${REPOSITORY##*/}/settings" >&2
    fi
fi
