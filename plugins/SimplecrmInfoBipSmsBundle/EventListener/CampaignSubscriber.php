<?php
namespace MauticPlugin\SimplecrmInfoBipSmsBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use MauticPlugin\SimplecrmInfoBipSmsBundle\Form\Type\SmsSendType;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\SimplecrmInfoBipSmsBundle\Model\SmsModel;
use MauticPlugin\SimplecrmInfoBipSmsBundle\SmsEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CampaignSubscriber implements EventSubscriberInterface
{
    /**
     * @var IntegrationHelper
     */
    protected $integrationHelper;

    /**
     * @var SmsModel
     */
    protected $smsModel;

    /**
     * CampaignSubscriber constructor.
     *
     * @param IntegrationHelper $integrationHelper
     * @param SmsModel          $smsModel
     */
    public function __construct(
        IntegrationHelper $integrationHelper,
        SmsModel $smsModel
    ) {
        $this->integrationHelper = $integrationHelper;
        $this->smsModel          = $smsModel;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD     => ['onCampaignBuild', 0],
            SmsEvents::ON_CAMPAIGN_TRIGGER_ACTION => ['onCampaignTriggerAction', 0],
        ];
    }

    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        $integration = $this->integrationHelper->getIntegrationObject('InfoBip');

        if ($integration && $integration->getIntegrationSettings()->getIsPublished()) {
            $event->addAction(
                'sms.send_text_sms',
                [
                    'label'            => 'mautic.campaign.sms.send_text_sms',
                    'description'      => 'mautic.campaign.sms.send_text_sms.tooltip',
                    'eventName'        => SmsEvents::ON_CAMPAIGN_TRIGGER_ACTION,
                    'formType'         => SmsSendType::class,
                    'formTypeOptions'  => ['update_select' => 'campaignevent_properties_sms'],
                    'formTheme'        => '@SimplecrmInfoBipSms/FormTheme/SmsSendList/smssend_list_row.html.twig',
                    'channel'          => 'sms',
                    'channelIdField'   => 'sms',
                ]
            );
        }
    }

    public function onCampaignTriggerAction(CampaignExecutionEvent $event): void
    {
        $lead  = $event->getLead();
        $smsId = (int) $event->getConfig()['sms'];
        $sms   = $this->smsModel->getEntity($smsId);

        if (!$sms) {
            $event->setFailed('mautic.sms.campaign.failed.missing_entity');

            return;
        }

        if (!$sms->isPublished()) {
            $event->setFailed('mautic.sms.campaign.failed.unpublished');

            return;
        }

        $result = $this->smsModel->sendSms($sms, $lead, ['channel' => ['campaign.event', $event->getEvent()['id']]])[$lead->getId()];

        if ('Authenticate' === $result['status']) {
            // Don't fail the event but reschedule it for later
            $event->setResult(false);

            return;
        }

        if (!empty($result['sent'])) {
            $event->setChannel('sms', $sms->getId());
            $event->setResult($result);
        } else {
            $result['failed'] = true;
            $result['reason'] = $result['status'];
            $event->setResult($result);
        }
    }
}
