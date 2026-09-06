<?php

declare(strict_types=1);

it('publishes the builder image only as an exact versioned Linux amd64 release', function (): void {
    $workflow = file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/docker-publish.yml');
    if ($workflow === false) {
        throw new RuntimeException('Unable to load Docker publish workflow.');
    }

    foreach ([
        "tags:\n      - 'v*'",
        'workflow_dispatch:',
        'version:',
        'vMAJOR.MINOR.PATCH',
        'Push the exact version tag to Docker Hub',
        'docker/setup-buildx-action@v4',
        'docker/login-action@v4',
        'docker/metadata-action@v6',
        'docker/build-push-action@v7',
        'type=ref,event=tag',
        "type=raw,value=\${{ inputs.version }},enable=\${{ github.event_name == 'workflow_dispatch' }}",
        'platforms: linux/amd64',
        'cache-from: type=gha',
        'cache-to: type=gha,mode=max',
        'provenance: mode=max',
        'sbom: true',
        'DOCKER_USERNAME',
        'DOCKER_PASSWORD',
    ] as $required) {
        expect($workflow)->toContain($required);
    }

    foreach ([':latest', ':alpine', 'type=raw,value=latest'] as $forbidden) {
        expect($workflow)->not->toContain($forbidden);
    }
});
