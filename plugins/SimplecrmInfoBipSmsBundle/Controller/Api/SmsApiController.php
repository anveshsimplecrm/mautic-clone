<?php

namespace MauticPlugin\SimplecrmInfoBipSmsBundle\Controller\Api;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\ApiBundle\Controller\CommonApiController;
use Mautic\ApiBundle\Helper\EntityResultHelper;
use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\AppVersion;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\LeadBundle\Controller\LeadAccessTrait;
use MauticPlugin\SimplecrmInfoBipSmsBundle\Entity\Sms;
use MauticPlugin\SimplecrmInfoBipSmsBundle\Model\SmsModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * @extends CommonApiController<Sms>
 */
class SmsApiController extends CommonApiController
{
    use LeadAccessTrait;

    /**
     * @var SmsModel|null
     */
    protected $model;

    public function __construct(CorePermissions $security, Translator $translator, EntityResultHelper $entityResultHelper, RouterInterface $router, FormFactoryInterface $formFactory, AppVersion $appVersion, RequestStack $requestStack, ManagerRegistry $doctrine, ModelFactory $modelFactory, EventDispatcherInterface $dispatcher, CoreParametersHelper $coreParametersHelper, MauticFactory $factory)
    {
        $smsModel = $modelFactory->getModel('infobipsms');
        \assert($smsModel instanceof SmsModel);

        $this->model           = $smsModel;
        $this->entityClass     = Sms::class;
        $this->entityNameOne   = 'sms';
        $this->entityNameMulti = 'smses';

        parent::__construct($security, $translator, $entityResultHelper, $router, $formFactory, $appVersion, $requestStack, $doctrine, $modelFactory, $dispatcher, $coreParametersHelper, $factory);
    }

    /**
     * @return JsonResponse|Response
     */
    public function receiveAction()
    {
        $body = $this->request->get('Body');
        $from = $this->request->get('From');

        if ($body === 'STOP' && $this->factory->getHelper('sms')->unsubscribe($from)) {
            return new Response('<Response><Sms>You have been unsubscribed.</Sms></Response>', 200, ['Content-Type' => 'text/xml; charset=utf-8']);
        }

        // Return an empty response
        return new Response();
    }
}
