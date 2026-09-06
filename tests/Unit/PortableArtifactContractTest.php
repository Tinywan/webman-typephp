<?php

declare(strict_types=1);

it('ships the dynamic portable-dir launcher contract', function (): void {
    $entrypoint = file_get_contents(dirname(__DIR__, 2) . '/docker/entrypoint.sh');
    $dockerfile = file_get_contents(dirname(__DIR__, 2) . '/docker/Dockerfile');
    if ($entrypoint === false || $dockerfile === false) {
        throw new RuntimeException('Unable to load Docker artifact files.');
    }

    expect(str_contains($entrypoint, './vendor/bin/tpc.php'))->toBeFalse();
    expect(str_contains($entrypoint, 'tpc=(php /opt/typephp/vendor/bin/tpc.php --no-progress)'))->toBeFalse();
    expect(str_contains($entrypoint, 'copy_file /dev/stdin'))->toBeFalse();

    foreach ([
        'tpc=(php /opt/typephp/vendor/bin/tpc.php)',
        '/usr/local/bin/tpc',
        '"${tpc[@]}" "$project_file" --no-progress',
        '$workspace/project.linux.yml',
        '$build_dir/project.linux.yml',
        "awk '/^output:[[:space:]]*/",
        'validate_relative_path "$project_output"',
        'mkdir -p "$workspace/$(dirname -- "$project_output")"',
        'normalized_output="$(dirname -- "$project_output")/$(basename -- "$project_output" | tr \'-\' \'_\')"',
        'normalized_compiled_bin="$workspace/$normalized_output"',
        'if [[ ! -f "$compiled_bin" && -f "$normalized_compiled_bin" ]]',
        'validate_relative_path "$normalized_output"',
        'webman-server.bin',
        'LD_LIBRARY_PATH',
        'libphpx',
        'libphp.so',
        'libsodium.so*',
        'libargon2.so*',
        'liblzma.so*',
        'LD_LIBRARY_PATH=/opt/typephp/vendor/swoole/phpx/lib:/usr/lib ldd',
        'is_allowed_library',
        'is_platform_library',
        'libc.so*',
        'trap cleanup_stage_on_failure EXIT',
        'trap - EXIT',
        'install -m 0755 /dev/stdin "$stage_dir/start.sh"',
        'TYPEPHP_OUTPUT_DIR must be a safe project-relative path.',
        'path="${path//\\\\//}"',
        "'..'",
    ] as $required) {
        expect($entrypoint)->toContain($required);
    }

    expect($dockerfile)->toContain(
        'tinywan/typephp-linux-x64:v0.7.0@sha256:92a5afbae1da2d3d7d942d8c9754091e00a1c2a527a4ac758a863e132af3453a',
    );
});
