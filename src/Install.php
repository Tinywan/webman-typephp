<?php

/**
 * @desc Webman 插件生命周期安装与卸载器
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/05
 */
declare(strict_types=1);

namespace Tinywan\Typephp;

class Install
{
    public const WEBMAN_PLUGIN = true;

    /**
     * @var array<string, string>
     */
    protected static $pathRelation = [
        'config/plugin/tinywan/typephp' => 'config/plugin/tinywan/typephp',
    ];

    /**
     * Install.
     *
     * @return void
     */
    public static function install()
    {
        static::installByRelation();
    }

    /**
     * Uninstall.
     *
     * @return void
     */
    public static function uninstall()
    {
        self::uninstallByRelation();
    }

    /**
     * installByRelation.
     *
     * @return void
     */
    public static function installByRelation()
    {
        if (!function_exists('base_path') || !function_exists('copy_dir')) {
            return;
        }
        foreach (static::$pathRelation as $source => $dest) {
            $pos = strrpos($dest, '/');
            if ($pos) {
                $parent_dir = base_path() . '/' . substr($dest, 0, $pos);
                if (!is_dir($parent_dir)) {
                    mkdir($parent_dir, 0777, true);
                }
            }
            copy_dir(__DIR__ . "/{$source}", base_path() . "/{$dest}");
        }
    }

    /**
     * uninstallByRelation.
     *
     * @return void
     */
    public static function uninstallByRelation()
    {
        if (!function_exists('base_path') || !function_exists('remove_dir')) {
            return;
        }
        foreach (static::$pathRelation as $source => $dest) {
            $path = base_path() . "/{$dest}";
            if (!is_dir($path) && !is_file($path)) {
                continue;
            }
            remove_dir($path);
        }
    }
}
