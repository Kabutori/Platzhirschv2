<?php
namespace App\Core\Module;
final class PackageVersion
{
    public static function read(string $manifest): string
    {
        $data = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        $version = $data['version'] ?? '';
        if (!is_string($version) || !preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $version)) {
            throw new \LogicException('Package must declare a fixed semantic version.');
        }
        return $version;
    }
}
