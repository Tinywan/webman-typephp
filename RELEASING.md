# Builder image releases

The Phase 1 builder image is published as `tinywan/typephp-webman-builder`.
The plugin default is deliberately pinned to `v0.0.10`; consumers should use a
versioned tag (or an image digest) for reproducible builds.

## Prerequisites

Configure these repository secrets before publishing:

- `DOCKER_USERNAME`: the Docker Hub account that can push `tinywan/typephp-webman-builder`.
- `DOCKER_PASSWORD`: a Docker Hub access token for that account. Do not use an account password.

The workflow publishes Linux `amd64` images only. It creates provenance and an
SBOM when the GitHub Actions runner and Docker Buildx support those attestations.

## Release procedure

1. Verify the Dockerfile and portable-dir contract tests locally.
2. Create and push a Git tag in the form `vMAJOR.MINOR.PATCH`, for example
   `v0.0.10`.
3. The `Publish TypePHP Webman Builder` workflow pushes only the exact
   Docker tag matching that Git tag.
4. Inspect the workflow result, image digest, provenance, and SBOM in Docker
   Hub/GitHub before updating the plugin's default image tag.

For a deliberate rebuild, use **Run workflow** and provide the same safe version
tag format, for example `v0.0.10`. The explicit **push** input makes the manual
run behavior clear: leave it enabled to publish only that exact tag, or disable
it for a build-only verification. A manual run never infers a version from a
branch name.

The workflow never publishes `latest`, `alpine`, or any other floating tag.
Do not use a manual run to overwrite a released version unless that replacement
is intentional and has been reviewed.
