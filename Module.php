<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */

namespace Mattermost;

class Module
{
    /**
     * Swarm's composer class map only covers the modules shipped with the product, so a
     * custom module has to register its own PSR-4 autoloader. The module manager creates
     * this object before it reads the module config, which is the first place that needs
     * Mattermost classes (IConfig constants), so the constructor is the right hook.
     */
    public function __construct()
    {
        self::registerAutoloader();
    }

    /**
     * Registers a PSR-4 autoloader for the Mattermost\ namespace rooted at src/.
     * Safe to call more than once.
     */
    public static function registerAutoloader(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        $prefix     = __NAMESPACE__ . '\\';
        $baseDir    = __DIR__ . '/src/';
        spl_autoload_register(
            function (string $class) use ($prefix, $baseDir) {
                if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                    return;
                }
                $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        );
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }
}
