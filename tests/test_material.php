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
