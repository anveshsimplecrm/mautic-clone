<?php

namespace MauticPlugin\SimplecrmInfoBipSmsBundle\Form\Type;

use Doctrine\ORM\EntityManager;
use Mautic\CategoryBundle\Form\Type\CategoryListType;
use Mautic\CoreBundle\Form\DataTransformer\IdToEntityModelTransformer;
use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\CoreBundle\Form\EventListener\CleanFormSubscriber;
use Mautic\CoreBundle\Form\EventListener\FormExitSubscriber;
use Mautic\CoreBundle\Form\Type\FormButtonsType;
use Mautic\CoreBundle\Form\Type\PublishDownDateType;
use Mautic\CoreBundle\Form\Type\PublishUpDateType;
use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Mautic\LeadBundle\Form\Type\LeadListType;
use MauticPlugin\SimplecrmInfoBipSmsBundle\Entity\Sms;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\LocaleType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
// CSTM: Upload Media File feature - start
use Symfony\Component\Form\Extension\Core\Type\FileType;
// CSTM: Upload Media File feature - end

/**
 * @extends AbstractType<Sms>
 */
class SmsType extends AbstractType 
{
    public function __construct(
        private EntityManager $em
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventSubscriber(new CleanFormSubscriber(['content' => 'html', 'customHtml' => 'html']));
        $builder->addEventSubscriber(new FormExitSubscriber('sms.sms', $options));

        $builder->add(
            'name', 
            TextType::class, 
            [
            'label' => 'mautic.sms.form.internal.name',
            'label_attr' => ['class' => 'control-label'],
            'attr' => ['class' => 'form-control'],
            ]
        );

        $builder->add(
            'messageType', ChoiceType::class, [
            'label' => 'mautic.sms.form.internal.messageType',
            'label_attr' => ['class' => 'control-label'],
            'attr' => ['class' => 'form-control',
                //'onchange' => "(function(){ if(document.getElementById('sms_messageType').value == 'Text'){document.getElementById('sms_whatsappType').value = ''; document.getElementById('sms_whatsappButton').value = ''; document.getElementById('sms_whatsappType').parentNode.parentNode.style.display = 'none';document.getElementById('sms_whatsappButton').parentNode.parentNode.style.display = 'none';}else{document.getElementById('sms_whatsappType').parentNode.parentNode.style.display = 'block';document.getElementById('sms_whatsappButton').parentNode.parentNode.style.display = 'block';}})()"],
                'onchange' => "showwhatsappfield(this.value)"],
            'choices' => array(''=>'', 'Text' => 'Text', 'WhatsApp' => 'WhatsApp' ),
                ]
        );
		$whatsappType_choice = array(''=>'', 'Text Only' => 'HSM', 'With Image' => 'image', 'With Document' => 'file', 'With Video' => 'video', 'With Audio' => 'audio' );
        $builder->add(
                'whatsappType', ChoiceType::class, [
            'label' => 'mautic.sms.form.internal.whatsappType',
            'label_attr' => ['class' => 'control-label'],
            'attr' => ['class' => 'form-control',
                'onchange' => "showhidemedia(this.value)"],
            //'choices' => array(''=>'', 'HSM' => 'Text Only', 'image' => 'With Image', 'file' => 'With Document', 'video' => 'With Video', 'audio' => 'With Audio' ),
            'choices' => $whatsappType_choice,
                'required' => false,
                    ]
        );

        // CSTM: SMS Budget Estimate Changes - start
        /*$ch = curl_init();
        $curlurl =  "https://".$_SERVER['HTTP_HOST']."/smsDetails.php";
        $curlConfig = array(
            CURLOPT_URL            => $curlurl,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => array(
                'param' => 'sms_account',
            )
        );
        curl_setopt_array($ch, $curlConfig);
        $smsAccounts = curl_exec($ch);
        curl_close($ch);

        $smsAccountsDecode = (array) json_decode($smsAccounts);*/
        // CSTM: SMS Budget Estimate Changes - end

        $builder->add(
            'textSmsAccount',
            ChoiceType::class,
            [
                'label' => 'mautic.sms.form.internal.textSmsAccount',
                'label_attr' => ['class' => 'control-label'],
                'attr' => ['class' => 'form-control'],
                'choices' => array('Promotional' => 'Promotional', 'Transactional' => 'Transactional'),
                //'choices' => $smsAccountsDecode, // CSTM: SMS Budget Estimate Changes
                'required' => false,
            ]
        );
        
        $builder->add(
            'whatsappButton',
            ChoiceType::class,
            [
                'label' => 'mautic.sms.form.internal.whatsappButton',
                'label_attr' => ['class' => 'control-label'],
                'attr' => ['class' => 'form-control'],
                'choices' => array(''=>'', 'true' => 'Yes', 'false' => 'No'),
                'required' => false,
            ]
        );
        
        $builder->add(
            'mediaUrl',
            TextType::class,
            [
                'label' => 'mautic.sms.form.internal.mediaUrl',
                'label_attr' => ['class' => 'control-label'],
                'attr' => ['class' => 'form-control'],
                'required' => false,
            ]
        );
        
        $builder->add(
            'description',
            TextareaType::class,
            [
                'label' => 'mautic.sms.form.internal.description',
                'label_attr' => ['class' => 'control-label'],
                'attr' => ['class' => 'form-control'],
                'required' => false,
            ]
        );

        $builder->add(
            'message',
            TextareaType::class,
            [
                'label' => 'mautic.sms.form.message',
                'label_attr' => ['class' => 'control-label'],
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 6,
                ],
            ]
        );

        $builder->add('isPublished', YesNoButtonGroupType::class);

        // add lead lists
        $transformer = new IdToEntityModelTransformer($this->em, \Mautic\LeadBundle\Entity\LeadList::class, 'id', true);
        $builder->add(
            $builder->create(
                'lists',
                LeadListType::class,
                [
                    'label'      => 'mautic.email.form.list',
                    'label_attr' => ['class' => 'control-label'],
                    'attr'       => [
                        'class'        => 'form-control',
                    ],
                    'multiple' => true,
                    'expanded' => false,
                    'required' => true,
                ]
            )
                ->addModelTransformer($transformer)
        );

        $builder->add('publishUp', PublishUpDateType::class);
        $builder->add('publishDown', PublishDownDateType::class);

        // add category
        $builder->add(
            'category',
            CategoryListType::class,
            [
                'bundle' => 'sms',
            ]
        );

        $builder->add(
            'language',
            LocaleType::class,
            [
                'label' => 'mautic.core.language',
                'label_attr' => ['class' => 'control-label'],
                'attr' => [
                    'class' => 'form-control',
                ],
                'required' => false,
            ]
        );
        $builder->add('smsType', HiddenType::class);
        $builder->add('buttons', FormButtonsType::class);

        // CSTM: Upload Media File feature - start
        $builder->add(
            'uploadFile',
            FileType::class,
            [
                'label' => 'mautic.sms.form.file.upload',
                'label_attr' => ['class' => 'control-label'],
                'attr' => ['class' => 'form-control'],
                'required' => false,
                'mapped' => false
            ]
        );
        // CSTM: Upload Media File feature - end

        if (!empty($options['update_select'])) {
            $builder->add(
                'buttons',
                FormButtonsType::class,
                [
                    'apply_text' => false,
                ]
            );
            $builder->add(
                'updateSelect',
                HiddenType::class,
                [
                    'data' => $options['update_select'],
                    'mapped' => false,
                ]
            );
        } else {
            $builder->add(
                'buttons',
                FormButtonsType::class
            );
        }

        if (!empty($options['action'])) {
            $builder->setAction($options['action']);
        }
    }

    public function setDefaultOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(
                [
                    'data_class' => null,
                ]
        );

        $resolver->setOptional(['update_select']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(
            [
                'data_class' => Sms::class,
            ]
        );

        $resolver->setDefined(['update_select']);
    }
}
