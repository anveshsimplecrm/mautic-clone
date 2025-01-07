<?php

namespace MauticPlugin\SimplecrmInfoBipSmsBundle\Model;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\ChannelBundle\Entity\MessageQueue;
use Mautic\ChannelBundle\Model\MessageQueueModel;
use Mautic\CoreBundle\Event\TokenReplacementEvent;
use Mautic\CoreBundle\Helper\CacheStorageHelper;
use Mautic\CoreBundle\Helper\Chart\ChartQuery;
use Mautic\CoreBundle\Helper\Chart\LineChart;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Model\AjaxLookupModelInterface;
use Mautic\CoreBundle\Model\FormModel;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\LeadBundle\Entity\DoNotContactRepository;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PageBundle\Model\TrackableModel;
use MauticPlugin\SimplecrmInfoBipSmsBundle\Entity\Sms;
use MauticPlugin\SimplecrmInfoBipSmsBundle\Entity\Stat;
use MauticPlugin\SimplecrmInfoBipSmsBundle\Event\SmsEvent;
use MauticPlugin\SimplecrmInfoBipSmsBundle\Event\SmsSendEvent;
use MauticPlugin\SimplecrmInfoBipSmsBundle\Form\Type\SmsType;
use MauticPlugin\SimplecrmInfoBipSmsBundle\SmsEvents;
use MauticPlugin\SimplecrmInfoBipSmsBundle\Api\InfoBipApi;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * @extends FormModel<Sms>
 *
 * @implements AjaxLookupModelInterface<Sms>
 */
class SmsModel extends FormModel implements AjaxLookupModelInterface
{
    public function __construct(
        protected TrackableModel $pageTrackableModel,
        protected LeadModel $leadModel,
        protected MessageQueueModel $messageQueueModel,
        private CacheStorageHelper $cacheStorageHelper,
        protected InfoBipApi $smsApi,
        EntityManagerInterface $em,
        CorePermissions $security,
        EventDispatcherInterface $dispatcher,
        UrlGeneratorInterface $router,
        Translator $translator,
        UserHelper $userHelper,
        LoggerInterface $mauticLogger,
        CoreParametersHelper $coreParametersHelper
        )
    {
        parent::__construct($em, $security, $dispatcher, $router, $translator, $userHelper, $mauticLogger, $coreParametersHelper);
    }

    /**
     * @return \MauticPlugin\SimplecrmInfoBipSmsBundle\Entity\SmsRepository
     */
    public function getRepository()
    {
        return $this->em->getRepository(\MauticPlugin\SimplecrmInfoBipSmsBundle\Entity\Sms::class);
    }

    /**
     * @return \MauticPlugin\SimplecrmInfoBipSmsBundle\Entity\StatRepository
     */
    public function getStatRepository()
    {
        return $this->em->getRepository(\MauticPlugin\SimplecrmInfoBipSmsBundle\Entity\Stat::class);
    }

    public function getPermissionBase(): string
    {
        return 'sms:smses';
    }

    /**
     * Save an array of entities.
     *
     * @param iterable<Sms> $entities
     */
    public function saveEntities($entities, $unlock = true): void
    {
        // iterate over the results so the events are dispatched on each delete
        $batchSize = 20;
        $i         = 0;
        foreach ($entities as $entity) {
            $isNew = ($entity->getId()) ? false : true;

            // set some defaults
            $this->setTimestamps($entity, $isNew, $unlock);

            if ($dispatchEvent = $entity instanceof Sms) {
                $event = $this->dispatchEvent('pre_save', $entity, $isNew);
            }

            $this->getRepository()->saveEntity($entity, false);

            if ($dispatchEvent) {
                $this->dispatchEvent('post_save', $entity, $isNew, $event);
            }

            if (0 === ++$i % $batchSize) {
                $this->em->flush();
            }
        }
        $this->em->flush();
    }

    /**
     * @param array $options
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     * @throws MethodNotAllowedHttpException
     */
    public function createForm($entity, FormFactoryInterface $formFactory, $action = null, $options = []): FormInterface
    {
        if (!$entity instanceof Sms) {
            throw new MethodNotAllowedHttpException(['Sms']);
        }
        if (!empty($action)) {
            $options['action'] = $action;
        }

        return $formFactory->create(SmsType::class, $entity, $options);
    }

    /**
     * Get a specific entity or generate a new one if id is empty.
     */
    public function getEntity($id = null): ?Sms
    {
        if (null === $id) {
            $entity = new Sms();
        } else {
            $entity = parent::getEntity($id);
        }

        return $entity;
    }

    /**
     * @param array            $options
     * @param array<int, Lead> $leads
     */
    public function sendSms(Sms $sms, $sendTo, $options = [], array &$leads = []): array
    {
        $channel = $options['channel'] ?? null;
        $listId  = $options['listId'] ?? null;

        if ($sendTo instanceof Lead) {
            $sendTo = [$sendTo];
        } elseif (!is_array($sendTo)) {
            $sendTo = [$sendTo];
        }

        $sentCount     = 0;
        $failedCount   = 0;
        $results       = [];
        $contacts      = [];
        $fetchContacts = [];
        foreach ($sendTo as $lead) {
            if (!$lead instanceof Lead) {
                $fetchContacts[] = $lead;
            } else {
                $contacts[$lead->getId()] = $lead;
                $leads[$lead->getId()]    = $lead;
            }
        }

        if ($fetchContacts) {
            $foundContacts = $this->leadModel->getEntities(
                [
                    'ids' => $fetchContacts,
                ]
            );

            foreach ($foundContacts as $contact) {
                $contacts[$contact->getId()] = $contact;
                $leads[$contact->getId()]    = $contact;
            }
        }

        if (!$sms->isPublished()) {
            foreach ($contacts as $leadId => $lead) {
                $results[$leadId] = [
                    'sent'   => false,
                    'status' => 'mautic.sms.campaign.failed.unpublished',
                ];
            }

            return $results;
        }

        $contactIds = array_keys($contacts);

        /** @var DoNotContactRepository $dncRepo */
        $dncRepo = $this->em->getRepository(\Mautic\LeadBundle\Entity\DoNotContact::class);
        $dnc     = $dncRepo->getChannelList('sms', $contactIds);

        if (!empty($dnc)) {
            foreach ($dnc as $removeMeId => $removeMeReason) {
                $results[$removeMeId] = [
                    'sent'   => false,
                    'status' => 'mautic.sms.campaign.failed.not_contactable',
                ];

                unset($contacts[$removeMeId], $contactIds[$removeMeId]);
            }
        }

        if (!empty($contacts)) {
            $messageQueue    = $options['resend_message_queue'] ?? null;
            $campaignEventId = (is_array($channel) && 'campaign.event' === $channel[0] && !empty($channel[1])) ? $channel[1] : null;

            $queued = $this->messageQueueModel->processFrequencyRules(
                $contacts,
                'sms',
                $sms->getId(),
                $campaignEventId,
                3,
                MessageQueue::PRIORITY_NORMAL,
                $messageQueue,
                'sms_message_stats'
            );

            if ($queued) {
                foreach ($queued as $queue) {
                    $results[$queue] = [
                        'sent'   => false,
                        'status' => 'mautic.sms.timeline.status.scheduled',
                    ];

                    unset($contacts[$queue]);
                }
            }

            $stats = [];
            if (count($contacts)) {
                /** @var Lead $lead */
                foreach ($contacts as $lead) {
                    $leadId = $lead->getId();
                    $stat = $this->createStatEntry($sms, $lead, $channel, false, $listId, $messageId); //mandatory to get data in the $stat, if commented or removed, will get an error of $stat is null  @ToDo: Need to review properly
                    $leadPhoneNumber = $lead->getMobile();
                    if (empty($leadPhoneNumber)) {
                        $leadPhoneNumber = $lead->getPhone();
                    }

                    if (empty($leadPhoneNumber)) {
                        $results[$leadId] = [
                            'sent'   => false,
                            'status' => 'mautic.sms.campaign.failed.missing_number',
                        ];

                        continue;
                    }

                    $smsEvent = new SmsSendEvent($sms->getMessage(), $lead);
                    $smsEvent->setSmsId($sms->getId());
                    $this->dispatcher->dispatch($smsEvent, SmsEvents::SMS_ON_SEND);

                    if ($stat !== null) {
                        $tokenEvent = $this->dispatcher->dispatch(
                            new TokenReplacementEvent(
                                $smsEvent->getContent(),
                                $lead,
                                [
                                    'channel' => [
                                        'sms',
                                        $sms->getId(),
                                        'sms' => $sms->getId(),
                                    ],
                                    'stat'    => $stat->getTrackingHash(),
                                ]
                            ),
                            SmsEvents::TOKEN_REPLACEMENT
                        );
                    } else {
                        // Log or handle the case when $stat is null
                        // fwrite($fp, "\n Sms Messages TrackingHash empty: ");
                        // You can either throw an exception or skip this step depending on your needs
                    }
                    
                    
                    // CSTM: WhatsApp Integration - start
                    $message_type = 'mautic.sms.sms';
                    if($sms->getMessageType() == 'WhatsApp'){
                        $message_type = 'mautic.sms.whatsapp';
                    }
                    // CSTM: WhatsApp Integration - end
                    
                    $sendResult = [
                        'sent'    => false,
                        'type'    => $message_type,
                        'status'  => 'mautic.sms.timeline.status.delivered',
                        'id'      => $sms->getId(),
                        'name'    => $sms->getName(),
                        'content' => $tokenEvent->getContent(),
                    ];

                    $metadata = $this->smsApi->sendSms($leadPhoneNumber, $tokenEvent->getContent(), $sms->getMessageType(), $sms->getWhatsappType(), $sms->getWhatsappButton(), $sms->getMediaUrl(), $sms->getTextSmsAccount());                    
                    if (false === $metadata) {
                        $sendResult['status'] = false;
                        $apiResponse = 'false';
                        $stat->setIsFailed(true);
                        ++$failedCount;
                    }else if($metadata['status'] == 'error'){ 
                        $sendResult['status'] = false;
                        $apiResponse = $metadata['response'];
                        $stat->setIsFailed(true);
                        ++$failedCount;
                    }else {
                        $sendResult['sent'] = true;
                        // CSTM: WhatsApp Integration - start
                        if ($sms->getMessageType() == 'WhatsApp') {
                            $jsonresponse = json_decode($metadata['response']);
                            $sendResult['response_id'] = $jsonresponse->messageId;
                        }
                        $apiResponse = $metadata['response'];
                        // CSTM: WhatsApp Integration - end
                        ++$sentCount;
                    }
                    $sendResult['response'] = $apiResponse;
                    $response_Id = $sendResult['response_id'] ?? null;
                    //$stats[] = $this->createStatEntry($sms, $lead, $channel, true, $apiResponse);
                    $stat = $this->createStatEntry($sms, $lead, $channel, false, $listId, $response_Id);
                    $stats[] = $stat;
                    unset($stat);
                    $results[$leadId] = $sendResult;
                    unset($smsEvent, $tokenEvent, $sendResult, $metadata);
                    
                    // CSTM -- START Save message id to the sms_messages table
                    if ($response_Id && isset($response_Id)) {
                        // $responseId = $metadata['response']['messageId'] ?? null;
                        
                        if ($sms->getMessageType() == 'WhatsApp' && $response_Id) {
                            $sms->setMessageId($response_Id); // Save responseId in Sms entity
                            // Persist Sms entity
                            $this->getRepository()->saveEntity($sms);
                        }
                    }
                    // CSTM -- END 
                }
            }
        }

        if ($sentCount || $failedCount) {
            $this->getRepository()->upCount($sms->getId(), 'sent', $sentCount);
            $this->getStatRepository()->saveEntities($stats);
            $this->em->clear(Stat::class);
        }

        return $results;
    }

    /**
     * @param bool $persist
     *
     * @throws \Exception
     */
    public function createStatEntry(Sms $sms, Lead $lead, $source = null, $persist = true, $listId = null, $messageId = null): Stat
    {
        $stat = new Stat();
        $stat->setDateSent(new \DateTime());
        $stat->setLead($lead);
        $stat->setSms($sms);
        if (null !== $listId) {
            $stat->setList($this->leadModel->getLeadListRepository()->getEntity($listId));
        }
        if (is_array($source)) {
            $stat->setSourceId($source[1]);
            $source = $source[0];
        }
        $stat->setSource($source);
        $stat->setTrackingHash(str_replace('.', '', uniqid('', true)));

        // CSTM: For Read Receipt -- START
        if ($messageId) {
            $stat->setMessageId($messageId);
        }
        // CSTM: For Read Receipt - END
        
        if ($persist) {
            $this->getStatRepository()->saveEntity($stat); //Save message id to the sms_message_stats table
        }

        return $stat;
    }

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException
     */
    protected function dispatchEvent($action, &$entity, $isNew = false, Event $event = null): ?Event
    {
        if (!$entity instanceof Sms) {
            throw new MethodNotAllowedHttpException(['Sms']);
        }

        switch ($action) {
            case 'pre_save':
                $name = SmsEvents::SMS_PRE_SAVE;
                break;
            case 'post_save':
                $name = SmsEvents::SMS_POST_SAVE;
                break;
            case 'pre_delete':
                $name = SmsEvents::SMS_PRE_DELETE;
                break;
            case 'post_delete':
                $name = SmsEvents::SMS_POST_DELETE;
                break;
            default:
                return null;
        }

        if ($this->dispatcher->hasListeners($name)) {
            if (empty($event)) {
                $event = new SmsEvent($entity, $isNew);
                $event->setEntityManager($this->em);
            }

            $this->dispatcher->dispatch($event, $name);

            return $event;
        } else {
            return null;
        }
    }

    /**
     * Joins the page table and limits created_by to currently logged in user.
     */
    public function limitQueryToCreator(QueryBuilder &$q): void
    {
        $q->join('t', MAUTIC_TABLE_PREFIX.'sms_messages', 's', 's.id = t.sms_id')
            ->andWhere('s.created_by = :userId')
            ->setParameter('userId', $this->userHelper->getUser()->getId());
    }

    /**
     * Get line chart data of hits.
     *
     * @param char      $unit          {@link php.net/manual/en/function.date.php#refsect1-function.date-parameters}
     * @param string    $dateFormat
     * @param array     $filter
     * @param bool      $canViewOthers
     */
    public function getHitsLineChartData($unit, \DateTime $dateFrom, \DateTime $dateTo, $dateFormat = null, $filter = [], $canViewOthers = true): array
    {
        $flag = null;

        if (isset($filter['flag'])) {
            $flag = $filter['flag'];
            unset($filter['flag']);
        }

        $chart = new LineChart($unit, $dateFrom, $dateTo, $dateFormat);
        $query = new ChartQuery($this->em->getConnection(), $dateFrom, $dateTo);

        if (!$flag || $flag === 'total_and_unique') {
            $filter['is_failed'] = 0;
            $q = $query->prepareTimeDataQuery('sms_message_stats', 'date_sent', $filter);

            if (!$canViewOthers) {
                $this->limitQueryToCreator($q);
            }

            $data = $query->loadAndBuildTimeData($q);
            $chart->setDataset($this->translator->trans('mautic.sms.show.total.sent'), $data);
        }

		// CSTM: For Read Receipt - start
        // For "read" SMS messages
        if (!$flag || 'read' === $flag) {
            $filter['is_failed'] = 0;  // Ensure failed messages are excluded
            $filter['is_read'] = 1;    // Only count messages that have been read
            $q                   = $query->prepareTimeDataQuery('sms_message_stats', 'date_sent', $filter);  // Fetch based on date_sent (read messages)

            if (!$canViewOthers) {
                $this->limitQueryToCreator($q);
            }

            $data = $query->loadAndBuildTimeData($q);
            $chart->setDataset($this->translator->trans('mautic.sms.show.read'), $data);
        }

        // For "failed" SMS messages
        if (!$flag || 'failed' === $flag) {
            $filter['is_failed'] = 1;  // Only count messages that have failed
            $q = $query->prepareTimeDataQuery('sms_message_stats', 'date_sent', $filter);  // Fetch based on date_sent (failed messages)

            if (!$canViewOthers) {
                $this->limitQueryToCreator($q);  // Limit results if user cannot view other people's data
            }

            $data = $query->loadAndBuildTimeData($q);  // Load and build time data for failed messages
            $chart->setDataset($this->translator->trans('mautic.sms.show.failed'), $data);  // Set dataset for failed messages in chart
        }
        // CSTM: For Read Receipt - end

        return $chart->render();
    }

    /**
     * @return Stat
     */
    public function getSmsStatus($idHash)
    {
        return $this->getStatRepository()->getSmsStatus($idHash);
    }

    /**
     * Search for an sms stat by sms and lead IDs.
     *
     * @return array
     */
    public function getSmsStatByLeadId($smsId, $leadId)
    {
        return $this->getStatRepository()->findBy(
            [
                'sms'  => (int) $smsId,
                'lead' => (int) $leadId,
            ],
            ['dateSent' => 'DESC']
        );
    }

    /**
     * Get an array of tracked links.
     */
    public function getSmsClickStats($smsId): array
    {
        return $this->pageTrackableModel->getTrackableList('sms', $smsId);
    }

    /**
     * @param string $filter
     * @param int    $limit
     * @param int    $start
     * @param array  $options
     */
    public function getLookupResults($type, $filter = '', $limit = 10, $start = 0, $options = []): array
    {
        $results = [];
        switch ($type) {
            case 'sms':
            case 'infobipsms':
            case SmsType::class:
                $entities = $this->getRepository()->getSmsList(
                    $filter,
                    $limit,
                    $start,
                    $this->security->isGranted($this->getPermissionBase().':viewother'),
                    isset($options['template']) ? $options['template'] : false
                );

                foreach ($entities as $entity) {
                    $results[$entity['language']][$entity['id']] = $entity['name'];
                }

                // sort by language
                ksort($results);

                break;
        }

        return $results;
    }
}
