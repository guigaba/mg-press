<?php

namespace Mindgruve\MgPress;

use Symfony\Component\Filesystem\Filesystem;
use Composer\Script\Event;
use Symfony\Component\Finder\Finder;

class ComposerScripts
{

    /**
     * @param Event $e
     */
    static public function postInstall(Event $e)
    {
        self::symlinkWordpressContent($e);
        self::generateWordpressConfig($e);
    }

    /**
     * @param Event $e
     */
    static public function postDeploy(Event $e)
    {
        self::generateWordpressConfig($e);
    }

    /**
     * Symlink Wordpress Folders
     * www/wp/wp-content/plugins -> plugins
     * www/wp/wp-contnet/themes  -> themes
     *
     * @param Event $e
     */
    static public function symlinkWordpressContent(Event $e)
    {
        $fs        = new Filesystem();
        $io        = $e->getIO();
        $finder    = new Finder();
        $extra     = $e->getComposer()->getPackage()->getExtra();
        $muPlugins = isset($extra['wp-mu-plugins']) ? $extra['wp-mu-plugins'] : array();

        try {

            // symlink wordpress content folder
            $contentPath = __DIR__ . '/../../../www/wp/wp-content';
            $fs->remove(array($contentPath));
            $newContentPath = '../../src/Mindgruve/WPContent';
            $fs->symlink($newContentPath, $contentPath);
            $io->write('<info>Symlinked Wordpress Plugin and Themes</info>');

            // symlink wordpress vendor plugins
            if (!$fs->exists(__DIR__ . '/../../../vendor/wpackagist/')) {
                $fs->mkdir(__DIR__ . '/../../../vendor/wpackagist/plugins/');
            }

            $finder->directories()->in(__DIR__ . '/../../../vendor/wpackagist/plugins/')->depth('== 0');
            foreach ($finder as $directory) {

                $name = $directory->getFilename();
                if (in_array($name, $muPlugins)) {
                    $targetPath = __DIR__ . '/../../../src/Mindgruve/WPContent/mu-plugins';
                    $relPath    = $fs->makePathRelative($directory, $targetPath);
                } else {
                    $targetPath = __DIR__ . '/../../../src/Mindgruve/WPContent/plugins';
                    $relPath    = $fs->makePathRelative($directory, $targetPath);
                }
                $fs->symlink($relPath, $targetPath . '/' . $name);
            }
            $io->write('<info>Symlinked Wordpress Vendor Plugins</info>');


            // autogenerating mu-plugin init
            foreach ($muPlugins as $muPlugin) {
                $file                = __DIR__ . '/../../../src/Mindgruve/WPContent/mu-plugins/' . $muPlugin . '/' . $muPlugin . '.php';
                $data                = self::get_file_data($file);
                $data['plugin_name'] = $muPlugin;
                $loader              = new \Twig_Loader_Filesystem(__DIR__);
                $twig                = new \Twig_Environment($loader);
                $rendered            = $twig->render('mu-plugin-init.php.twig', $data);
                file_put_contents(
                    __DIR__ . '/../../../src/Mindgruve/WPContent/mu-plugins/init-' . $muPlugin . '.php',
                    $rendered
                );
                $twig->loadTemplate('mu-plugin-init.php.twig');
            }

            // symlink wordpress vendor themes
            if (!$fs->exists(__DIR__ . '/../../../vendor/wpackagist/themes/')) {
                $fs->mkdir(__DIR__ . '/../../../vendor/wpackagist/themes/');
            }

        } catch (\Exception $e) {
            echo $e->getMessage();

            $io->write(
                '<error>Unable to symlink the plugin and theme directory for WordPress.  You may have to manually symlink these folders</error>'
            );
        }
    }

    public static function generateWordpressConfig(Event $e)
    {
        $fs        = new Filesystem();
        $io        = $e->getIO();
        
        // copy wp-config file.
        $fs->copy(__DIR__ . '/wp-config.php.tmp', __DIR__ . '/../../../www/wp/wp-config.php');

        // copy config dist file
        $config     = __DIR__ . '/../../../config/wpConfig.yml';
        $configDist = __DIR__ . '/../../../config/wpConfig.yml.dist';
        if ($fs->exists($configDist) && !$fs->exists($config)) {
            $fs->copy($configDist, $config);
        }

        $io->write('<info>Copied Wordpress Config Page</info>');
    }

    /**
     * Read Comments from Plugin
     * https://core.trac.wordpress.org/browser/tags/4.1/src/wp-admin/includes/plugin.php
     * @param $file
     * @param string $context
     * @return array
     */
    public static function get_file_data($file, $context = '')
    {
        $default_headers = array(
            'Name'        => 'Plugin Name',
            'PluginURI'   => 'Plugin URI',
            'Version'     => 'Version',
            'Description' => 'Description',
            'Author'      => 'Author',
            'AuthorURI'   => 'Author URI',
            'TextDomain'  => 'Text Domain',
            'DomainPath'  => 'Domain Path',
            'Network'     => 'Network',
            // Site Wide Only is deprecated in favor of Network.
            '_sitewide'   => 'Site Wide Only',
        );


        // We don't need to write to the file, so just open for reading.
        $fp = fopen($file, 'r');

        // Pull only the first 8kiB of the file in.
        $file_data = fread($fp, 8192);

        // PHP will close file handle, but we are good citizens.
        fclose($fp);

        // Make sure we catch CR-only line endings.
        $file_data = str_replace("\r", "\n", $file_data);

        foreach ($default_headers as $field => $regex) {
            if (preg_match('/^[ \t\/*#@]*' . preg_quote($regex, '/') . ':(.*)$/mi', $file_data, $match) && $match[1]) {
                $default_headers[$field] = $match[1];
            } else {
                $default_headers[$field] = '';
            }
        }

        return $default_headers;
    }
} 