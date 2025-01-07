<?php

namespace MauticPlugin\SimplecrmInfoBipSmsBundle\Controller;

use Mautic\CoreBundle\Controller\FormController;
use Mautic\CoreBundle\Factory\PageHelperFactoryInterface;
use Mautic\CoreBundle\Form\Type\DateRangeType;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\CoreBundle\Model\AuditLogModel;
use Mautic\LeadBundle\Controller\EntityContactsTrait;
use MauticPlugin\SimplecrmInfoBipSmsBundle\Entity\Sms;
use MauticPlugin\SimplecrmInfoBipSmsBundle\Model\SmsModel;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Mautic\PluginBundle\Helper\IntegrationHelper;

class SmsController extends FormController
{
    use EntityContactsTrait;

    /**
     * @param int $page
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function indexAction(Request $request, IntegrationHelper $integrationHelper, $page = 1)
    {
        /** @var \MauticPlugin\SimplecrmInfoBipSmsBundle\Model\SmsModel $model */
        $model = $this->getModel('infobipsms');

        // set some permissions
        $permissions = $this->security->isGranted(
            [
                'sms:smses:viewown',
                'sms:smses:viewother',
                'sms:smses:create',
                'sms:smses:editown',
                'sms:smses:editother',
                'sms:smses:deleteown',
                'sms:smses:deleteother',
                'sms:smses:publishown',
                'sms:smses:publishother',
            ],
            'RETURN_ARRAY'
        );

        if (!$permissions['sms:smses:viewown'] && !$permissions['sms:smses:viewother']) {
            return $this->accessDenied();
        }

        if ($request->getMethod() == 'POST') {
            $this->setListFilters();
        }

        $session = $request->getSession();

        //set limits
        $limit = $session->get('mautic.sms.limit', $this->coreParametersHelper->get('default_pagelimit'));
        $start = ($page === 1) ? 0 : (($page - 1) * $limit);
        if ($start < 0) {
            $start = 0;
        }

        $search = $request->get('search', $session->get('mautic.sms.filter', ''));
        $session->set('mautic.sms.filter', $search);

        $filter = ['string' => $search];

        if (!$permissions['sms:smses:viewother']) {
            $filter['force'][] =
                [
                    'column' => 'e.createdBy',
                    'expr'   => 'eq',
                    'value'  => $this->user->getId(),
                ];
        }

        $orderBy    = $session->get('mautic.sms.orderby', 'e.name');
        $orderByDir = $session->get('mautic.sms.orderbydir', $this->getDefaultOrderDirection());

        $smss = $model->getEntities([
            'start'      => $start,
            'limit'      => $limit,
            'filter'     => $filter,
            'orderBy'    => $orderBy,
            'orderByDir' => $orderByDir,
        ]);

        
        $count = count($smss);
        if ($count && $count < ($start + 1)) {
            //the number of entities are now less then the current page so redirect to the last page
            if ($count === 1) {
                $lastPage = 1;
            } else {
                $lastPage = (floor($count / $limit)) ?: 1;
            }

            $session->set('mautic.sms.page', $lastPage);

            //set the return URL
            $returnUrl = $this->generateUrl('mautic_sms_index', ['page' => $lastPage]);

            return $this->postActionRedirect([
                'returnUrl'       => $returnUrl,
                'viewParameters'  => ['page' => $lastPage],
                //'contentTemplate' => 'MauticPlugin\SimplecrmInfoBipSmsBundle:Sms:index',
                'contentTemplate' => 'MauticPlugin\SimplecrmInfoBipSmsBundle\Controller\SmsController::indexAction',
                'passthroughVars' => [
                    'activeLink'    => '#mautic_sms_index',
                    'mauticContent' => 'sms',
                ],
            ]);
        }
        $session->set('mautic.sms.page', $page);

        // CSTM - start IntegrationHelper object
        $integration = $integrationHelper->getIntegrationObject('InfoBip');
        // CSTM - end

        return $this->delegateView([
            'viewParameters' => [
                'searchValue' => $search,
                'items'       => $smss,
                'totalItems'  => $count,
                'page'        => $page,
                'limit'       => $limit,
                'tmpl'        => $request->get('tmpl', 'index'),
                'permissions' => $permissions,
                'model'       => $model,
                'security'    => $this->security,
                'configured'  => ($integration && $integration->getIntegrationSettings()->getIsPublished()),
            ],
            'contentTemplate' => '@SimplecrmInfoBipSms/Sms/list.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_sms_index',
                'mauticContent' => 'sms',
                'route'         => $this->generateUrl('mautic_sms_index', ['page' => $page]),
            ],
        ]);
    }

    /**
     * Loads a specific form into the detailed panel.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function viewAction(Request $request, $objectId)
    {
        /** @var \MauticPlugin\SimplecrmInfoBipSmsBundle\Model\SmsModel $model */
        $model    = $this->getModel('infobipsms');
        $security = $this->security;

        /** @var \MauticPlugin\SimplecrmInfoBipSmsBundle\Entity\Sms $sms */
        $sms = $model->getEntity($objectId);
        //set the page we came from
        $page = $request->getSession()->get('mautic.sms.page', 1);

        if ($sms === null) {
            //set the return URL
            $returnUrl = $this->generateUrl('mautic_sms_index', ['page' => $page]);

            return $this->postActionRedirect([
                'returnUrl'       => $returnUrl,
                'viewParameters'  => ['page' => $page],
                //'contentTemplate' => 'SimplecrmInfoBipSmsBundle:Sms:index',
                'contentTemplate' => 'MauticPlugin\SimplecrmInfoBipSmsBundle\Controller\SmsController::indexAction',
                'passthroughVars' => [
                    'activeLink'    => '#mautic_sms_index',
                    'mauticContent' => 'sms',
                ],
                'flashes' => [
                    [
                        'type'    => 'error',
                        'msg'     => 'mautic.sms.error.notfound',
                        'msgVars' => ['%id%' => $objectId],
                    ],
                ],
            ]);
        } elseif (!$this->security->hasEntityAccess(
            'sms:smses:viewown',
            'sms:smses:viewother',
            $sms->getCreatedBy()
        )
        ) {
            return $this->accessDenied();
        }

        // Audit Log
        $auditLogModel = $this->getModel('core.auditlog');
        \assert($auditLogModel instanceof AuditLogModel);
        $logs = $auditLogModel->getLogForObject('sms', $sms->getId(), $sms->getDateAdded());

        // Init the date range filter form
        $dateRangeValues = $request->get('daterange', []);
        $action          = $this->generateUrl('mautic_sms_action', ['objectAction' => 'view', 'objectId' => $objectId]);
        $dateRangeForm   = $this->formFactory->create(DateRangeType::class, $dateRangeValues, ['action' => $action]);
        $entityViews     = $model->getHitsLineChartData(
            null,
            new \DateTime($dateRangeForm->get('date_from')->getData()),
            new \DateTime($dateRangeForm->get('date_to')->getData()),
            null,
            ['sms_id' => $sms->getId()]
        );

        // Get click through stats
        $trackableLinks = $model->getSmsClickStats($sms->getId());

        return $this->delegateView([
            'returnUrl'      => $this->generateUrl('mautic_sms_action', ['objectAction' => 'view', 'objectId' => $sms->getId()]),
            'viewParameters' => [
                'sms'         => $sms,
                'trackables'  => $trackableLinks,
                'logs'        => $logs,
                'isEmbedded'  => $request->get('isEmbedded') ? $request->get('isEmbedded') : false,
                'permissions' => $security->isGranted([
                    'sms:smses:viewown',
                    'sms:smses:viewother',
                    'sms:smses:create',
                    'sms:smses:editown',
                    'sms:smses:editother',
                    'sms:smses:deleteown',
                    'sms:smses:deleteother',
                    'sms:smses:publishown',
                    'sms:smses:publishother',
                ], 'RETURN_ARRAY'),
                'security'    => $security,
                'entityViews' => $entityViews,
                'contacts'    => $this->forward(
                    //'SimplecrmInfoBipSmsBundle:Sms:contacts',
                    'MauticPlugin\SimplecrmInfoBipSmsBundle\Controller\SmsController::contactsAction',
                    [
                        'objectId'   => $sms->getId(),
                        'page'       => $request->getSession()->get('mautic.sms.contact.page', 1),
                        'ignoreAjax' => true,
                    ]
                )->getContent(),
                'dateRangeForm' => $dateRangeForm->createView(),
            ],
            //'contentTemplate' => 'SimplecrmInfoBipSmsBundle:Sms:details.html.php',
            'contentTemplate' => '@SimplecrmInfoBipSms/Sms/details.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_sms_index',
                'mauticContent' => 'sms',
            ],
        ]);
    }

    /**
     * Generates new form and processes post data.
     *
     * @param Sms $entity
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function newAction(Request $request, $entity = null)
    {
        /** @var \MauticPlugin\SimplecrmInfoBipSmsBundle\Model\SmsModel $model */
        $model = $this->getModel('infobipsms');

        if (!$entity instanceof Sms) {
            /** @var \MauticPlugin\SimplecrmInfoBipSmsBundle\Entity\Sms $entity */
            $entity = $model->getEntity();
        }

        $method  = $request->getMethod();
        $session = $request->getSession();

        if (!$this->security->isGranted('sms:smses:create')) {
            return $this->accessDenied();
        }

        //set the page we came from
        $page   = $session->get('mautic.sms.page', 1);
        $action = $this->generateUrl('mautic_sms_action', ['objectAction' => 'new']);
        $sms          = $request->request->get('sms') ?? [];
        $updateSelect = 'POST' === $method
            ? ($sms['updateSelect'] ?? false)
            : $request->get('updateSelect', false);

        if ($updateSelect) {
            $entity->setSmsType('template');
        }

        // create the form
        $form = $model->createForm($entity, $this->formFactory, $action, ['update_select' => $updateSelect]);

        ///Check for a submitted form and process it
        if ($method == 'POST') {
            $valid = false;
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {

                    // CSTM: Upload Media File feature - start
                    $upload_file = $form['uploadFile']->getData();

                    if ($upload_file !== null && $upload_file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                        // Call the handleFileUpload method
                        $uploadResult = $this->handleFileUpload($upload_file, $request);

                        // If the file is uploaded successfully
                        if ($uploadResult['success']) {
                            // If the message type is WhatsApp, save the public URL to the entity
                            if ($entity->getMessageType() == 'WhatsApp') {
                                $entity->setMediaUrl($uploadResult['publicUrl']);
                            }
                        } 
                    }
                    // CSTM: Upload Media File feature - end  


                    // form is valid so process the data
                    $model->saveEntity($entity);
                    
                    $this->addFlashMessage(
                        'mautic.core.notice.created',
                        [
                            '%name%'      => $entity->getName(),
                            '%menu_link%' => 'mautic_sms_index',
                            '%url%'       => $this->generateUrl(
                                'mautic_sms_action',
                                [
                                    'objectAction' => 'edit',
                                    'objectId'     => $entity->getId(),
                                ]
                            ),
                        ]
                    );

                    //Redirect to the view page if the save button is clicked
                    if ($this->getFormButton($form, ['buttons', 'save'])->isClicked()) {
                        $viewParameters = [
                            'objectAction' => 'view',
                            'objectId'     => $entity->getId(),
                        ];
                        $returnUrl = $this->generateUrl('mautic_sms_action', $viewParameters);
                        $template  = 'MauticPlugin\SimplecrmInfoBipSmsBundle\Controller\SmsController::viewAction';
                    } else {
                        // return edit view so that all the session stuff is loaded
                        return $this->editAction($request, $entity->getId(), true);
                    }
                }
            } else {
                $viewParameters = ['page' => $page];
                $returnUrl      = $this->generateUrl('mautic_sms_index', $viewParameters);
                $template       = 'MauticPlugin\SimplecrmInfoBipSmsBundle\Controller\SmsController::indexAction';
                //clear any modified content
                $session->remove('mautic.sms.'.$entity->getId().'.content');
            }
            
            $passthrough = [
                'activeLink'    => 'mautic_sms_index',
                'mauticContent' => 'sms',
            ];

            // Check to see if this is a popup
            if (isset($form['updateSelect'])) {
                $template    = false;
                $passthrough = array_merge(
                    $passthrough,
                    [
                        'updateSelect' => $form['updateSelect']->getData(),
                        'id'           => $entity->getId(),
                        'name'         => $entity->getName(),
                        'group'        => $entity->getLanguage(),
                    ]
                );
            }
            //Redirect to the view page if the save button is clicked
            if ($cancelled || ($valid && $this->getFormButton($form, ['buttons', 'save'])->isClicked())) {
                return $this->postActionRedirect(
                    [
                        'returnUrl'       => $returnUrl,
                        'viewParameters'  => $viewParameters,
                        'contentTemplate' => $template,
                        'passthroughVars' => $passthrough,
                    ]
                );
            }
        }

        return $this->delegateView(
            [
                'viewParameters' => [
                    'form' => $form->createView(),
                    'sms'  => $entity,
                ],
                'contentTemplate' => '@SimplecrmInfoBipSms/Sms/form.html.twig',
                'passthroughVars' => [
                    'activeLink'    => '#mautic_sms_index',
                    'mauticContent' => 'sms',
                    'updateSelect'  => InputHelper::clean($request->query->get('updateSelect')),
                    'route'         => $this->generateUrl(
                        'mautic_sms_action',
                        [
                            'objectAction' => 'new',
                        ]
                    ),
                ],
            ]
        );
    }

    /**
     * @param bool $ignorePost
     * @param bool $forceTypeSelection
     *
     * @return array|\Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function editAction(Request $request, $objectId, $ignorePost = false, $forceTypeSelection = false)
    {
        /** @var \MauticPlugin\SimplecrmInfoBipSmsBundle\Model\SmsModel $model */
        $model   = $this->getModel('infobipsms');
        $method  = $request->getMethod();
        $entity  = $model->getEntity($objectId);
        $session = $request->getSession();
        $page    = $session->get('mautic.sms.page', 1);

        // set the return URL
        $returnUrl = $this->generateUrl('mautic_sms_index', ['page' => $page]);

        // set the post action variables
        $postActionVars = [
            'returnUrl'       => $returnUrl,
            'viewParameters'  => ['page' => $page],
            'contentTemplate' => 'MauticPlugin\SimplecrmInfoBipSmsBundle\Controller\SmsController::indexAction',
            'passthroughVars' => [
                'activeLink'    => 'mautic_sms_index',
                'mauticContent' => 'sms',
            ],
        ];

        //not found
        if ($entity === null) {
            return $this->postActionRedirect(
                array_merge(
                    $postActionVars,
                    [
                        'flashes' => [
                            [
                                'type'    => 'error',
                                'msg'     => 'mautic.sms.error.notfound',
                                'msgVars' => ['%id%' => $objectId],
                            ],
                        ],
                    ]
                )
            );
        } elseif (!$this->security->hasEntityAccess(
            'sms:smses:viewown',
            'sms:smses:viewother',
            $entity->getCreatedBy()
        )
        ) {
            return $this->accessDenied();
        } elseif ($model->isLocked($entity)) {
            // deny access if the entity is locked
            return $this->isLocked($postActionVars, $entity, 'sms');
        }

        //Create the form
        $action = $this->generateUrl('mautic_sms_action', ['objectAction' => 'edit', 'objectId' => $objectId]);
        $sms          = $request->request->get('sms') ?? [];
        $updateSelect = 'POST' === $method
            ? ($sms['updateSelect'] ?? false)
            : $request->get('updateSelect', false);

        $form = $model->createForm($entity, $this->formFactory, $action, ['update_select' => $updateSelect]);

        ///Check for a submitted form and process it
        if (!$ignorePost && $method == 'POST') {
            $valid = false;
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {

                    // CSTM: process File Upload  -- start
                    $upload_file = $form['uploadFile']->getData();

                    if ($upload_file !== null && $upload_file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                        // Call the handleFileUpload method
                        $uploadResult = $this->handleFileUpload($upload_file, $request);

                        if ($uploadResult['success']) {
                            // If the message type is WhatsApp, save the public URL to the entity
                            if ($entity->getMessageType() == 'WhatsApp') {
                                $entity->setMediaUrl($uploadResult['publicUrl']);
                            }
                        } 
                    }
                    // CSTM: process File Upload -- end

                    // form is valid so process the data
                    $model->saveEntity($entity, $this->getFormButton($form, ['buttons', 'save'])->isClicked());
                    $this->addFlashMessage(
                        'mautic.core.notice.updated',
                        [
                            '%name%'      => $entity->getName(),
                            '%menu_link%' => 'mautic_sms_index',
                            '%url%'       => $this->generateUrl(
                                'mautic_sms_action',
                                [
                                    'objectAction' => 'edit',
                                    'objectId'     => $entity->getId(),
                                ]
                            ),
                        ],
                        'warning'
                    );
                }
            } else {
                //clear any modified content
                $session->remove('mautic.sms.'.$objectId.'.content');
                //unlock the entity
                $model->unlockEntity($entity);
            }

            $passthrough = [
                'activeLink'    => 'mautic_sms_index',
                'mauticContent' => 'sms',
            ];

            $template = 'MauticPlugin\SimplecrmInfoBipSmsBundle\Controller\SmsController::viewAction';

            // Check to see if this is a popup
            if (isset($form['updateSelect'])) {
                $template    = false;
                $passthrough = array_merge(
                    $passthrough,
                    [
                        'updateSelect' => $form['updateSelect']->getData(),
                        'id'           => $entity->getId(),
                        'name'         => $entity->getName(),
                        'group'        => $entity->getLanguage(),
                    ]
                );
            }

            if ($cancelled || ($valid && $this->getFormButton($form, ['buttons', 'save'])->isClicked())) {
                $viewParameters = [
                    'objectAction' => 'view',
                    'objectId'     => $entity->getId(),
                ];

                return $this->postActionRedirect(
                    array_merge(
                        $postActionVars,
                        [
                            'returnUrl'       => $this->generateUrl('mautic_sms_action', $viewParameters),
                            'viewParameters'  => $viewParameters,
                            'contentTemplate' => $template,
                            'passthroughVars' => $passthrough,
                        ]
                    )
                );
            }
        } else {
            //lock the entity
            $model->lockEntity($entity);
        }

        return $this->delegateView(
            [
                'viewParameters' => [
                    'form'               => $form->createView(),
                    'sms'                => $entity,
                    'forceTypeSelection' => $forceTypeSelection,
                ],
                'contentTemplate' => '@SimplecrmInfoBipSms/Sms/form.html.twig',
                'passthroughVars' => [
                    'activeLink'    => '#mautic_sms_index',
                    'mauticContent' => 'sms',
                    'updateSelect'  => InputHelper::clean($request->query->get('updateSelect')),
                    'route'         => $this->generateUrl(
                        'mautic_sms_action',
                        [
                            'objectAction' => 'edit',
                            'objectId'     => $entity->getId(),
                        ]
                    ),
                ],
            ]
        );
    }

    /**
     * Clone an entity.
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function cloneAction(Request $request, $objectId)
    {
        $model  = $this->getModel('infobipsms');
        $entity = $model->getEntity($objectId);

        if (null != $entity) {
            if (!$this->security->isGranted('sms:smses:create')
                || !$this->security->hasEntityAccess(
                    'sms:smses:viewown',
                    'sms:smses:viewother',
                    $entity->getCreatedBy()
                )
            ) {
                return $this->accessDenied();
            }

            $entity = clone $entity;
        }

        return $this->newAction($request, $entity); 
    }

    /**
     * Deletes the entity.
     *
     * @return Response
     */
    public function deleteAction(Request $request, $objectId)
    {
        $page      = $request->getSession()->get('mautic.sms.page', 1);
        $returnUrl = $this->generateUrl('mautic_sms_index', ['page' => $page]);
        $flashes   = [];

        $postActionVars = [
            'returnUrl'       => $returnUrl,
            'viewParameters'  => ['page' => $page],
            'contentTemplate' => 'MauticPlugin\SimplecrmInfoBipSmsBundle\Controller\SmsController::indexAction',
            'passthroughVars' => [
                'activeLink'    => 'mautic_sms_index',
                'mauticContent' => 'sms',
            ],
        ];

        if (Request::METHOD_POST === $request->getMethod()) {
            $model  = $this->getModel('infobipsms');
            \assert($model instanceof SmsModel);
            $entity = $model->getEntity($objectId);

            if (null === $entity) {
                $flashes[] = [
                    'type'    => 'error',
                    'msg'     => 'mautic.sms.error.notfound',
                    'msgVars' => ['%id%' => $objectId],
                ];
            } elseif (!$this->security->hasEntityAccess(
                'sms:smses:deleteown',
                'sms:smses:deleteother',
                $entity->getCreatedBy()
            )
            ) {
                return $this->accessDenied();
            } elseif ($model->isLocked($entity)) {
                return $this->isLocked($postActionVars, $entity, 'sms');
            }

            $model->deleteEntity($entity);

            $flashes[] = [
                'type'    => 'notice',
                'msg'     => 'mautic.core.notice.deleted',
                'msgVars' => [
                    '%name%' => $entity->getName(),
                    '%id%'   => $objectId,
                ],
            ];
        } //else don't do anything

        return $this->postActionRedirect(
            array_merge(
                $postActionVars,
                ['flashes' => $flashes]
            )
        );
    }

    /**
     * Deletes a group of entities.
     *
     * @return Response
     */
    public function batchDeleteAction(Request $request)
    {
        $page      = $request->getSession()->get('mautic.sms.page', 1);
        $returnUrl = $this->generateUrl('mautic_sms_index', ['page' => $page]);
        $flashes   = [];

        $postActionVars = [
            'returnUrl'       => $returnUrl,
            'viewParameters'  => ['page' => $page],
            'contentTemplate' => 'MauticPlugin\SimplecrmInfoBipSmsBundle\Controller\SmsController::indexAction',
            'passthroughVars' => [
                'activeLink'    => '#mautic_sms_index',
                'mauticContent' => 'sms',
            ],
        ];

        if (Request::METHOD_POST == $request->getMethod()) {
            $model = $this->getModel('infobipsms');
            \assert($model instanceof SmsModel);
            $ids   = json_decode($request->query->get('ids', '{}'));

            $deleteIds = [];

            // Loop over the IDs to perform access checks pre-delete
            foreach ($ids as $objectId) {
                $entity = $model->getEntity($objectId);

                if (null === $entity) {
                    $flashes[] = [
                        'type'    => 'error',
                        'msg'     => 'mautic.sms.error.notfound',
                        'msgVars' => ['%id%' => $objectId],
                    ];
                } elseif (!$this->security->hasEntityAccess(
                    'sms:smses:viewown',
                    'sms:smses:viewother',
                    $entity->getCreatedBy()
                )
                ) {
                    $flashes[] = $this->accessDenied(true);
                } elseif ($model->isLocked($entity)) {
                    $flashes[] = $this->isLocked($postActionVars, $entity, 'sms', true);
                } else {
                    $deleteIds[] = $objectId;
                }
            }

            // Delete everything we are able to
            if (!empty($deleteIds)) {
                $entities = $model->deleteEntities($deleteIds);

                $flashes[] = [
                    'type'    => 'notice',
                    'msg'     => 'mautic.sms.notice.batch_deleted',
                    'msgVars' => [
                        '%count%' => count($entities),
                    ],
                ];
            }
        } //else don't do anything

        return $this->postActionRedirect(
            array_merge(
                $postActionVars,
                ['flashes' => $flashes]
            )
        );
    }

    /**
     * @return JsonResponse|Response
     */
    public function previewAction($objectId)
    {
        /** @var \MauticPlugin\SimplecrmInfoBipSmsBundle\Model\SmsModel $model */
        $model    = $this->getModel('infobipsms');
        $sms      = $model->getEntity($objectId);
        $security = $this->security;

        if (null !== $sms && $security->hasEntityAccess('sms:smses:viewown', 'sms:smses:viewother')) {
            return $this->delegateView([
                'viewParameters' => [
                    'sms' => $sms,
                ],
                'contentTemplate' => '@SimplecrmInfoBipSms/Sms/preview.html.twig',
            ]);
        }

        return new Response('', Response::HTTP_NOT_FOUND);
    }

    /**
     * @param int $page
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function contactsAction(
        Request $request,
        PageHelperFactoryInterface $pageHelperFactory,
        $objectId,
        $page = 1
    ) {
        return $this->generateContactsGrid(
            $request,
            $pageHelperFactory,
            $objectId,
            $page,
            'sms:smses:view',
            'sms',
            'sms_message_stats',
            'sms',
            'sms_id'
        );
    }

    protected function getModelName(): string
    {
        return 'infobipsms';
    }

    protected function getDefaultOrderDirection(): string
    {
        return 'DESC';
    }

    /**
     * CSTM: Handle File Upload
     */
    private function handleFileUpload($file, Request $request)
    {   
        // Allowed file types -- cstm
        $allowedTypes = [
            // Image types
            'image/jpeg', 'image/jpg', 'image/png', 'image/gif',
            'image/bmp', 'image/webp', 'image/tiff',
            
            // Video types
            'video/mp4', 'video/mpeg', 'video/x-msvideo',
            'video/quicktime', 'video/webm',
            
            // Audio types
            'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/aac', 'audio/mp4', 'audio/x-ms-wma', 'audio/mp3',

            // Document types
            'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // Word
            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // Excel
            'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', // PowerPoint
            'application/rtf', 'application/x-rtf', // RTF
            'application/vnd.oasis.opendocument.text', 'application/vnd.oasis.opendocument.spreadsheet', // OpenDocument
        ];
        
        // Maximum file size allowed
        $maxSize = 5 * 1024 * 1024; // 5MB
        
        // Check if the MIME type is valid
        if (!in_array($file->getMimeType(), $allowedTypes)) {
            return ['success' => false, 'error' => 'Invalid file type. Allowed types: ' . implode(', ', $allowedTypes)];
        }

        // Check if the file size is within the allowed limit (5MB)
        if ($file->getSize() > $maxSize) {
            return ['success' => false, 'error' => 'File size exceeds the 5MB limit.'];
        }

        if (in_array($file->getMimeType(), $allowedTypes) && $file->getSize() <= $maxSize) {

            // Determine upload directory based on file type
            if (strpos($file->getMimeType(), 'image/') !== false) {
                $uploadDir = 'media/images/'; // For images
            } elseif (strpos($file->getMimeType(), 'video/') !== false) {
                $uploadDir = 'media/videos/'; // For videos
            } elseif (strpos($file->getMimeType(), 'audio/') !== false) {
                $uploadDir = 'media/audios/'; // For audios
            } elseif (strpos($file->getMimeType(), 'application/') !== false || strpos($file->getMimeType(), 'text/') !== false) {
                $uploadDir = 'media/files/'; // For documents
            } else {
                $uploadDir = 'media/files/'; // Default for other file types
            }

            // Generate a unique file name
            $fileName = uniqid() . '.' . $file->guessExtension();
            $filePath = $uploadDir . $fileName;

            // Move the file to the upload directory
            $file->move($uploadDir, $fileName);

            // Generate public URL with base URL
            $baseUrl = $request->getSchemeAndHttpHost();
            $publicUrl = $baseUrl . '/' . $filePath;

            // Return the public URL
            return ['success' => true, 'publicUrl' => $publicUrl];
        }else {
            return ['success' => false, 'error' => 'No file uploaded.'];
        }
    }
}
