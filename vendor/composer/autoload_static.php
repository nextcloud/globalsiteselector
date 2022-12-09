<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitGlobalSiteSelector
{
    public static $prefixLengthsPsr4 = array (
        'O' => 
        array (
            'OCA\\GlobalSiteSelector\\' => 23,
        ),
        'F' => 
        array (
            'Firebase\\JWT\\' => 13,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'OCA\\GlobalSiteSelector\\' => 
        array (
            0 => __DIR__ . '/../..' . '/lib',
        ),
        'Firebase\\JWT\\' => 
        array (
            0 => __DIR__ . '/..' . '/firebase/php-jwt/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
        'Firebase\\JWT\\BeforeValidException' => __DIR__ . '/..' . '/firebase/php-jwt/src/BeforeValidException.php',
        'Firebase\\JWT\\CachedKeySet' => __DIR__ . '/..' . '/firebase/php-jwt/src/CachedKeySet.php',
        'Firebase\\JWT\\ExpiredException' => __DIR__ . '/..' . '/firebase/php-jwt/src/ExpiredException.php',
        'Firebase\\JWT\\JWK' => __DIR__ . '/..' . '/firebase/php-jwt/src/JWK.php',
        'Firebase\\JWT\\JWT' => __DIR__ . '/..' . '/firebase/php-jwt/src/JWT.php',
        'Firebase\\JWT\\Key' => __DIR__ . '/..' . '/firebase/php-jwt/src/Key.php',
        'Firebase\\JWT\\SignatureInvalidException' => __DIR__ . '/..' . '/firebase/php-jwt/src/SignatureInvalidException.php',
        'OCA\\GlobalSiteSelector\\AppInfo\\Application' => __DIR__ . '/../..' . '/lib/AppInfo/Application.php',
        'OCA\\GlobalSiteSelector\\BackgroundJobs\\UpdateLookupServer' => __DIR__ . '/../..' . '/lib/BackgroundJobs/UpdateLookupServer.php',
        'OCA\\GlobalSiteSelector\\Command\\UsersUpdate' => __DIR__ . '/../..' . '/lib/Command/UsersUpdate.php',
        'OCA\\GlobalSiteSelector\\Controller\\MasterController' => __DIR__ . '/../..' . '/lib/Controller/MasterController.php',
        'OCA\\GlobalSiteSelector\\Controller\\SlaveController' => __DIR__ . '/../..' . '/lib/Controller/SlaveController.php',
        'OCA\\GlobalSiteSelector\\Exceptions\\MasterUrlException' => __DIR__ . '/../..' . '/lib/Exceptions/MasterUrlException.php',
        'OCA\\GlobalSiteSelector\\GlobalSiteSelector' => __DIR__ . '/../..' . '/lib/GlobalSiteSelector.php',
        'OCA\\GlobalSiteSelector\\Listener\\AddContentSecurityPolicyListener' => __DIR__ . '/../..' . '/lib/Listener/AddContentSecurityPolicyListener.php',
        'OCA\\GlobalSiteSelector\\Listeners\\DeletingUser' => __DIR__ . '/../..' . '/lib/Listeners/DeletingUser.php',
        'OCA\\GlobalSiteSelector\\Listeners\\UserCreated' => __DIR__ . '/../..' . '/lib/Listeners/UserCreated.php',
        'OCA\\GlobalSiteSelector\\Listeners\\UserDeleted' => __DIR__ . '/../..' . '/lib/Listeners/UserDeleted.php',
        'OCA\\GlobalSiteSelector\\Listeners\\UserLoggedOut' => __DIR__ . '/../..' . '/lib/Listeners/UserLoggedOut.php',
        'OCA\\GlobalSiteSelector\\Listeners\\UserLoggingIn' => __DIR__ . '/../..' . '/lib/Listeners/UserLoggingIn.php',
        'OCA\\GlobalSiteSelector\\Listeners\\UserUpdated' => __DIR__ . '/../..' . '/lib/Listeners/UserUpdated.php',
        'OCA\\GlobalSiteSelector\\Lookup' => __DIR__ . '/../..' . '/lib/Lookup.php',
        'OCA\\GlobalSiteSelector\\Master' => __DIR__ . '/../..' . '/lib/Master.php',
        'OCA\\GlobalSiteSelector\\Migration\\Version0110Date20180925143400' => __DIR__ . '/../..' . '/lib/Migration/Version0110Date20180925143400.php',
        'OCA\\GlobalSiteSelector\\PublicCapabilities' => __DIR__ . '/../..' . '/lib/PublicCapabilities.php',
        'OCA\\GlobalSiteSelector\\Service\\SlaveService' => __DIR__ . '/../..' . '/lib/Service/SlaveService.php',
        'OCA\\GlobalSiteSelector\\Slave' => __DIR__ . '/../..' . '/lib/Slave.php',
        'OCA\\GlobalSiteSelector\\TokenHandler' => __DIR__ . '/../..' . '/lib/TokenHandler.php',
        'OCA\\GlobalSiteSelector\\UserBackend' => __DIR__ . '/../..' . '/lib/UserBackend.php',
        'OCA\\GlobalSiteSelector\\UserDiscoveryModules\\IUserDiscoveryModule' => __DIR__ . '/../..' . '/lib/UserDiscoveryModules/IUserDiscoveryModule.php',
        'OCA\\GlobalSiteSelector\\UserDiscoveryModules\\ManualUserMapping' => __DIR__ . '/../..' . '/lib/UserDiscoveryModules/ManualUserMapping.php',
        'OCA\\GlobalSiteSelector\\UserDiscoveryModules\\UserDiscoverySAML' => __DIR__ . '/../..' . '/lib/UserDiscoveryModules/UserDiscoverySAML.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitGlobalSiteSelector::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitGlobalSiteSelector::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInitGlobalSiteSelector::$classMap;

        }, null, ClassLoader::class);
    }
}
