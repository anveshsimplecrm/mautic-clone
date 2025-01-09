<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $excludes = [
    ];

    $services->load('MauticPlugin\\SimplecrmInfoBipSmsBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');

    $services->load('MauticPlugin\\SimplecrmInfoBipSmsBundle\\Entity\\', '../Entity/*Repository.php')
        ->tag(\Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\ServiceRepositoryCompilerPass::REPOSITORY_SERVICE_TAG);

    $services->alias('mautic.infobipsms.model.infobipsms', \MauticPlugin\SimplecrmInfoBipSmsBundle\Model\SmsModel::class);
    $services->alias('mautic.infobipsms.repository.stat', \MauticPlugin\SimplecrmInfoBipSmsBundle\Entity\StatRepository::class);
};
