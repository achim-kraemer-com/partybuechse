<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Native Lazy Objects von PHP statt generierter Doctrine-Proxys.
 *
 * Ohne diese Einstellung meldet DoctrineBundle auf PHP 8.4+ bei *jedem* Start zwei
 * Deprecations – unter anderem "Class Doctrine\ORM\Proxy\Autoloader is deprecated" –, und die
 * landen über den Monolog-Kanal "deprecation" auf stderr. Im Partybetrieb heisst das: jede
 * Minute ein Eintrag im Cron-Bericht, bei jedem Poll einer im Log.
 *
 * Darum als PHP-Datei und nicht in doctrine.yaml: DoctrineBundle wirft eine LogicException,
 * wenn die Einstellung auf PHP < 8.4 gesetzt ist. Der Server läuft unter 8.5 (Web und
 * deploy.sh/Cron verwenden ausdrücklich /opt/plesk/php/8.5/bin/php), die Tests laufen auch
 * unter 8.2/8.3 – die Bedingung hält beide Fälle offen.
 *
 * Wichtig: Der kompilierte Container hängt damit an der PHP-Version, die ihn gebaut hat.
 * Deshalb baut und bedient immer dieselbe Version die Anwendung, siehe die Festlegung von
 * $PHP in deploy.sh.
 */
return static function (ContainerConfigurator $container): void {
    if (\PHP_VERSION_ID < 80400) {
        return;
    }

    $container->extension('doctrine', [
        'orm' => ['enable_native_lazy_objects' => true],
    ]);
};
