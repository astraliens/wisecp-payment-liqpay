<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitf02718860324a9799096911f902640c4
{
    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
        'LiqPay' => __DIR__ . '/..' . '/liqpay/liqpay/LiqPay.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->classMap = ComposerStaticInitf02718860324a9799096911f902640c4::$classMap;

        }, null, ClassLoader::class);
    }
}
