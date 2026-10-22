<?php
/**
 * Material.php against the JSON it is generated from.
 *
 * The simulator reads the JSON and the PHP reads Material, so a scenario edited
 * without `npm run build` would change one engine and not the other — and the
 * deployed game would not be the one the simulator measured. Tests are not
 * deployed, so reading the files here is allowed.
 */
declare(strict_types=1);

use Bga\Games\IronAndWhisper\Material;

function test_material_is_exactly_the_json_it_was_built_from(): void
{
    $root = dirname(__DIR__);
    $paths = [];
    foreach (['data', 'maps', 'scenarios'] as $dir) {
        foreach (glob("{$root}/{$dir}/*.json") as $file) {
            $paths[] = "{$dir}/" . basename($file);
        }
    }
    sort($paths);

    $built = array_keys(Material::FILES);
    sort($built);
    assertSame($paths, $built, 'Material.php lists different files from the JSON on disk: run npm run build');

    foreach ($paths as $path) {
        $decoded = json_decode((string) file_get_contents("{$root}/{$path}"), true, flags: JSON_THROW_ON_ERROR);
        assertSame($decoded, Material::FILES[$path], "{$path} has changed since Material.php was built: run npm run build");
    }
}

function test_nothing_the_game_ships_reads_a_file_at_runtime(): void
{
    // BGA's pre-release check refuses these outright; catching them here is
    // cheaper than a Studio build.
    $forbidden = ['file_get_contents', 'fopen', 'file_put_contents', 'readfile', 'scandir', 'glob('];
    $found = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/modules/php')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        foreach (file($file->getPathname()) as $number => $line) {
            foreach ($forbidden as $name) {
                if (str_contains($line, $name)) {
                    $found[] = basename($file->getPathname()) . ':' . ($number + 1) . " {$name}";
                }
            }
        }
    }
    assertSame([], $found);
}
