<?php

namespace PackageVersions;

use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\Locker;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

final class Installer implements PluginInterface, EventSubscriberInterface
{
    private static $generatedClassTemplate = <<<'PHP'
<?php

namespace PackageVersions;

/**
 * This class is generated by ocramius/package-versions, specifically by
 * @see \PackageVersions\Installer
 *
 * This file is overwritten at every run of `composer install` or `composer update`.
 */
%s
{
    const ROOT_PACKAGE_NAME = '%s';
    const VERSIONS = %s;

    private function __construct()
    {
    }

    /**
     * @throws \OutOfBoundsException if a version cannot be located
     */
    public static function getVersion(string $packageName) : string
    {
        if (isset(self::VERSIONS[$packageName])) {
            return self::VERSIONS[$packageName];
        }

        throw new \OutOfBoundsException(
            'Required package "' . $packageName . '" is not installed: cannot detect its version'
        );
    }
}

PHP;

    /**
     * {@inheritDoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        // Nothing to do here, as all features are provided through event listeners
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'dumpVersionsClass',
            ScriptEvents::POST_UPDATE_CMD  => 'dumpVersionsClass',
        ];
    }

    /**
     * @param Event $composerEvent
     *
     * @return void
     *
     * @throws \RuntimeException
     */
    public static function dumpVersionsClass(Event $composerEvent)
    {
        $composer = $composerEvent->getComposer();
        $rootPackage = $composer->getPackage();
        $versions = iterator_to_array(self::getVersions($composer->getLocker(), $rootPackage));

        if (!array_key_exists('ocramius/package-versions', $versions)) {
            //plugin must be globally installed - we only want to generate versions for projects which specifically
            //require ocramius/package-versions
            return;
        }

        $versionClass = self::generateVersionsClass($rootPackage->getName(), $versions);

        self::writeVersionClassToFile($versionClass, $composer, $composerEvent->getIO());
    }

    private static function generateVersionsClass(string $rootPackageName, array $versions) : string
    {
        return sprintf(
            self::$generatedClassTemplate,
            'fin' . 'al ' . 'cla' . 'ss ' . 'Versions', // note: workaround for regex-based code parsers :-(
            $rootPackageName,
            var_export($versions, true)
        );
    }

    /**
     * @param string $versionClassSource
     * @param Composer $composer
     * @param IOInterface $io
     *
     * @return void
     *
     * @throws \RuntimeException
     */
    private static function writeVersionClassToFile(string $versionClassSource, Composer $composer, IOInterface $io)
    {
        $installPath = self::locateRootPackageInstallPath($composer->getConfig(), $composer->getPackage())
            . '/src/PackageVersions/Versions.php';

        if (! file_exists(dirname($installPath))) {
            $io->write('<info>ocramius/package-versions:</info> Package not found (probably scheduled for removal); generation of version class skipped.');

            return;
        }

        $io->write('<info>ocramius/package-versions:</info>  Generating version class...');

        file_put_contents($installPath, $versionClassSource);
        chmod($installPath, 0664);

        $io->write('<info>ocramius/package-versions:</info> ...done generating version class');
    }

    /**
     * @param Config               $composerConfig
     * @param RootPackageInterface $rootPackage
     *
     * @return string
     *
     * @throws \RuntimeException
     */
    private static function locateRootPackageInstallPath(
        Config $composerConfig,
        RootPackageInterface $rootPackage
    ) : string {
        if ('ocramius/package-versions' === self::getRootPackageAlias($rootPackage)->getName()) {
            return dirname($composerConfig->get('vendor-dir'));
        }

        return $composerConfig->get('vendor-dir') . '/ocramius/package-versions';
    }

    private static function getRootPackageAlias(RootPackageInterface $rootPackage) : PackageInterface
    {
        $package = $rootPackage;

        while ($package instanceof AliasPackage) {
            $package = $package->getAliasOf();
        }

        return $package;
    }

    /**
     * @param Locker               $locker
     * @param RootPackageInterface $rootPackage
     *
     * @return \Generator|\string[]
     */
    private static function getVersions(Locker $locker, RootPackageInterface $rootPackage) : \Generator
    {
        $lockData = $locker->getLockData();

        $lockData['packages-dev'] = $lockData['packages-dev'] ?? [];

        foreach (array_merge($lockData['packages'], $lockData['packages-dev']) as $package) {
            yield $package['name'] => $package['version'] . '@' . (
                $package['source']['reference']?? $package['dist']['reference'] ?? ''
            );
        }

        yield $rootPackage->getName() => $rootPackage->getPrettyVersion() . '@' . $rootPackage->getSourceReference();
    }
}
