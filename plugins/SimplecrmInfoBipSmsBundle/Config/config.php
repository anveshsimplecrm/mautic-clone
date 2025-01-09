<?php

return [
    'name' => 'SimpleCRM InfoBipSMS',
    'description' => 'Send sms or whatsapp using gupshup api',
    'version' => '5.1.1',
    'author' => 'SimpleCRM',
    'services' => [
        'helpers' => [
            'mautic.infobip.helper.sms' => [
                'class' => \MauticPlugin\SimplecrmInfoBipSmsBundle\Helper\SmsHelper::class,
                'arguments' => [
                    'doctrine.orm.entity_manager',
                    'mautic.lead.model.lead',
                    'mautic.helper.phone_number',
                    'mautic.infobipsms.model.infobipsms',
                    'mautic.helper.integration',
                ],
                'alias' => 'infobipsms_helper',
            ],
        ],
        'other' => [
            'mautic.infobip.sms.api' => [
                'class' => \MauticPlugin\SimplecrmInfoBipSmsBundle\Api\InfoBipApi::class,
                'arguments' => [
                    'mautic.page.model.trackable',
                    'mautic.helper.phone_number',
                    'mautic.helper.integration',
                    'monolog.logger.mautic',
                ],
                'alias' => 'infobipsms_api',
            ],
        ],
        'integrations' => [
            'mautic.integration.infobip' => [
                'class'     => \MauticPlugin\SimplecrmInfoBipSmsBundle\Integration\InfoBipIntegration::class,
                'arguments' => [
                    'event_dispatcher',
                    'mautic.helper.cache_storage',
                    'doctrine.orm.entity_manager',
                    'session',
                    'request_stack',
                    'router',
                    'translator',
                    'logger',
                    'mautic.helper.encryption',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.company',
                    'mautic.helper.paths',
                    'mautic.core.model.notification',
                    'mautic.lead.model.field',
                    'mautic.plugin.model.integration_entity',
                    'mautic.lead.model.dnc',
                ],
            ],
        ],
    ],
    'routes' => [
        'main' => [
            'mautic_sms_index' => [
                'path' => '/infobipsms/{page}',
                'controller' => 'MauticPlugin\SimplecrmInfoBipSmsBundle\Controller\SmsController::indexAction',
            ],
            'mautic_sms_action' => [
                'path' => '/infobipsms/{objectAction}/{objectId}',
                'controller' => 'MauticPlugin\SimplecrmInfoBipSmsBundle\Controller\SmsController::executeAction',
            ],
            'mautic_sms_contacts' => [
                'path' => '/infobipsms/view/{objectId}/contact/{page}',
                'controller' => 'MauticPlugin\SimplecrmInfoBipSmsBundle\Controller\SmsController::contactsAction',
            ],
        ],
        'public' => [
            'mautic_receive_sms' => [
                'path' => '/infobipsms/receive',
                'controller' => 'SimplecrmInfoBipSmsBundle:Api\SmsApi:receive',
            ],
        ],
        'api' => [
            'mautic_api_smsesstandard' => [
                'standard_entity' => true,
                'name' => 'smses',
                'path' => '/infobipsmses',
                'controller' => 'SimplecrmInfoBipSmsBundle:Api\SmsApi',
            ],
        ],
    ],
    'menu' => [
        'main' => [
            'items' => [
                'mautic.sms.smses' => [
                    'route' => 'mautic_sms_index',
                    'access' => ['sms:smses:viewown', 'sms:smses:viewother'],
                    'parent' => 'mautic.core.channels',
                    'checks' => [
                        'integration' => [
                            'InfoBip' => [
                                'enabled' => true,
                            ],
                        ],
                    ],
                    'priority' => 70,
                ],
            ],
        ],
    ],
    'parameters' => [
        'sms_enabled' => false,
        'sms_username' => null,
        'sms_password' => null,
        'sms_sending_phone_number' => null,
        'sms_frequency_number' => null,
        'sms_frequency_time' => null,
    ],
];
