<?php
namespace MauticPlugin\SimplecrmInfoBipSmsBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Mautic\ApiBundle\Serializer\Driver\ApiMetadataDriver;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\FormEntity;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Form\Validator\Constraints\LeadListAccess;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Mapping\ClassMetadata;

class Sms extends FormEntity
{
    /**
     * @var int
     */
    private $id;

    /**
     * @var string
     */
    private $name;
    
    /**
     * @var string
     */
    private $messageType;
    
    /**
     * @var string
     */
    private $whatsappType;

    /**
     * @var string
     */
    private $whatsappButton;
    
    /**
     * @var string
     */
    private $mediaUrl;

    /**
     * @var string
     */
    private $textSmsAccount;

    /**
     * @var string|null
     */
    private $description;

    /**
     * @var string
     */
    private $language = 'en';

    /**
     * @var string
     */
    private $message;

    /**
     * @var \DateTimeInterface
     */
    private $publishUp;

    /**
     * @var \DateTimeInterface
     */
    private $publishDown;

    /**
     * @var int
     */
    private $sentCount = 0;

    /**
     * @var \Mautic\CategoryBundle\Entity\Category|null
     **/
    private $category;

    /**
     * @var ArrayCollection<int, \Mautic\LeadBundle\Entity\LeadList>
     */
    private $lists;

    /**
     * @var ArrayCollection<int, \MauticPlugin\SimplecrmInfoBipSmsBundle\Entity\Stat>
     */
    private $stats;

    /**
     * @var string|null
     */
    private $smsType = 'template';

    /**
     * @var int
     */
    private $pendingCount = 0;

    // CSTM: For Read Receipt - start
	/**
     * @var int
     */
    private $readCount = 0;

    /**
     * @var int
     */
    private $failedCount = 0;

    /**
     * @var int
     */
    private $deliveredCount = 0;
    
    // CSTM: For Read Receipt - end


    public function __clone()
    {
        $this->id        = null;
        $this->stats     = new ArrayCollection();
        $this->sentCount = 0;
        $this->readCount = 0;
        // CSTM: For Read Receipt - start
        $this->failedCount = 0;
        $this->deliveredCount = 0;
        // CSTM: For Read Receipt - end

        parent::__clone();
    }

    public function __construct()
    {
        $this->lists = new ArrayCollection();
        $this->stats = new ArrayCollection();
    }

    /**
     * Clear stats.
     */
    public function clearStats(): void
    {
        $this->stats = new ArrayCollection();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('sms_messages')
            ->setCustomRepositoryClass(\MauticPlugin\SimplecrmInfoBipSmsBundle\Entity\SmsRepository::class);

        $builder->addIdColumns();

        $builder->createField('language', 'string')
            ->columnName('lang')
            ->build();

        $builder->createField('message', 'text')
            ->build();

        $builder->createField('smsType', 'text')
            ->columnName('sms_type')
            ->nullable()
            ->build();
        
        $builder->createField('messageType', 'text')
            ->columnName('message_type')
            ->nullable()
            ->build();
        
        $builder->createField('whatsappType', 'text')
            ->columnName('whatsapp_type')
            ->nullable()
            ->build();

        $builder->createField('whatsappButton', 'text')
            ->columnName('whatsapp_button')
            ->nullable()
            ->build();
                    
        $builder->createField('mediaUrl', 'text')
            ->columnName('media_url')
            ->nullable()
            ->build();
        
        $builder->createField('textSmsAccount', 'text')
            ->columnName('text_sms_account')
            ->nullable()
            ->build();

        $builder->addPublishDates();

        $builder->createField('sentCount', 'integer')
            ->columnName('sent_count')
            ->build();

        // CSTM: For Read Receipt - start
        $builder->createField('readCount', 'integer')
            ->columnName('read_count')
            ->build();

        $builder->createField('failedCount', 'integer')
            ->columnName('failed_count')
            ->build();

        $builder->createField('deliveredCount', 'text')
            ->columnName('delivered_count')
            ->nullable()
            ->build();
        // CSTM: For Read Receipt - end

        $builder->addCategory();

        $builder->createManyToMany('lists', \Mautic\LeadBundle\Entity\LeadList::class)
            ->setJoinTable('sms_message_list_xref')
            ->setIndexBy('id')
            ->addInverseJoinColumn('leadlist_id', 'id', false, false, 'CASCADE')
            ->addJoinColumn('sms_id', 'id', false, false, 'CASCADE')
            ->fetchExtraLazy()
            ->build();

        $builder->createOneToMany('stats', 'Stat')
            ->setIndexBy('id')
            ->mappedBy('sms')
            ->cascadePersist()
            ->fetchExtraLazy()
            ->build();
    }

    public static function loadValidatorMetadata(ClassMetadata $metadata): void
    {
        $metadata->addPropertyConstraint(
            'name',
            new NotBlank(
                [
                    'message' => 'mautic.core.name.required',
                ]
            )
        );
        $metadata->addPropertyConstraint(
            'messageType',
            new NotBlank(
                [
                    'message' => 'mautic.sms.messageType.required',
                ]
            )
        );

        $metadata->addConstraint(new Callback([
            'callback' => function (Sms $sms, ExecutionContextInterface $context): void {
                $type = $sms->getSmsType();
                if ('list' == $type) {
                    $validator  = $context->getValidator();
                    $violations = $validator->validate(
                        $sms->getLists(),
                        [
                            new NotBlank(
                                [
                                    'message' => 'mautic.lead.lists.required',
                                ]
                            ),
                            new LeadListAccess(),
                        ]
                    );

                    foreach ($violations as $violation) {
                        $context->buildViolation($violation->getMessage())
                            ->atPath('lists')
                            ->addViolation();
                    }
                }
            },
        ]));
    }

    /**
     * Prepares the metadata for API usage.
     */
    public static function loadApiMetadata(ApiMetadataDriver $metadata): void
    {
        $metadata->setGroupPrefix('sms')
            ->addListProperties(
                [
                    'id',
                    'name',
                    'messageType',
                    'whatsappType',
                    'whatsappButton',
                    'mediaUrl',
                    'textSmsAccount',
                    'message',
                    'language',
                    'category',
                ]
            )
            ->addProperties(
                [
                    'publishUp',
                    'publishDown',
                    'sentCount',
                    'readCount',  // cstm
                    'failedCount', // cstm
                    'deliveredCount', // cstm
                ]
            )
            ->build();
    }

    protected function isChanged($prop, $val)
    {
        $getter  = 'get'.ucfirst($prop);
        $current = $this->$getter();

        if ('category' == $prop || 'list' == $prop) {
            $currentId = ($current) ? $current->getId() : '';
            $newId     = ($val) ? $val->getId() : null;
            if ($currentId != $newId) {
                $this->changes[$prop] = [$currentId, $newId];
            }
        } else {
            parent::isChanged($prop, $val);
        }
    }

    /**
     * @return mixed
     */
    public function getName()
    {
        return $this->name;
    }

    public function getMessageType()
    {
        return $this->messageType;
    }
    public function getWhatsappType()
    {
        return $this->whatsappType;
    }
    
    public function getWhatsappButton()
    {
        return $this->whatsappButton;
    }
    public function getMediaUrl()
    {
        return $this->mediaUrl;
    }
    public function getTextSmsAccount() {
        return $this->textSmsAccount;
    }
    /**
     * @param string $name
     *
     * @return $this
     */
    public function setName($name)
    {
        $this->isChanged('name', $name);
        $this->name = $name;

        return $this;
    }
            
    public function setMessageType($messageType)
    {
        $this->isChanged('messageType', $messageType);
        $this->messageType = $messageType;

        return $this;
    }

    public function setWhatsappType($whatsappType)
    {
        $this->isChanged('whatsappType', $whatsappType);
        $this->whatsappType = $whatsappType;

        return $this;
    }
    
    public function setWhatsappButton($whatsappButton)
    {
        $this->isChanged('whatsappButton', $whatsappButton);
        $this->whatsappButton = $whatsappButton;

        return $this;
    }
    
    public function setMediaUrl($mediaUrl)
    {
        $this->isChanged('mediaUrl', $mediaUrl);
        $this->mediaUrl = $mediaUrl;

        return $this;
    }
    public function setTextSmsAccount($textSmsAccount) 
    {
        $this->isChanged('textSmsAccount', $textSmsAccount);
        $this->textSmsAccount = $textSmsAccount;

        return $this;
    }
    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * @param string $description
     */
    public function setDescription($description): void
    {
        $this->isChanged('description', $description);
        $this->description = $description;
    }

    /**
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return mixed
     */
    public function getCategory()
    {
        return $this->category;
    }

    /**
     * @return $this
     */
    public function setCategory($category)
    {
        $this->isChanged('category', $category);
        $this->category = $category;

        return $this;
    }

    /**
     * @return string
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @param string $message
     */
    public function setMessage($message): void
    {
        $this->isChanged('message', $message);
        $this->message = $message;
    }

    /**
     * @return mixed
     */
    public function getLanguage()
    {
        return $this->language;
    }

    /**
     * @return $this
     */
    public function setLanguage($language)
    {
        $this->isChanged('language', $language);
        $this->language = $language;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getPublishDown()
    {
        return $this->publishDown;
    }

    /**
     * @return $this
     */
    public function setPublishDown($publishDown)
    {
        $this->isChanged('publishDown', $publishDown);
        $this->publishDown = $publishDown;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getPublishUp()
    {
        return $this->publishUp;
    }

    /**
     * @return $this
     */
    public function setPublishUp($publishUp)
    {
        $this->isChanged('publishUp', $publishUp);
        $this->publishUp = $publishUp;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getSentCount()
    {
        return $this->sentCount;
    }

    /**
     * @return $this
     */
    public function setSentCount($sentCount)
    {
        $this->sentCount = $sentCount;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getLists()
    {
        return $this->lists;
    }

    /**
     * Add list.
     *
     * @return Sms
     */
    public function addList(LeadList $list)
    {
        $this->lists[] = $list;

        return $this;
    }

    /**
     * Remove list.
     */
    public function removeList(LeadList $list): void
    {
        $this->lists->removeElement($list);
    }

    /**
     * @return mixed
     */
    public function getStats()
    {
        return $this->stats;
    }

    /**
     * @return string
     */
    public function getSmsType()
    {
        return $this->smsType;
    }

    /**
     * @param string $smsType
     */
    public function setSmsType($smsType): void
    {
        $this->isChanged('smsType', $smsType);
        $this->smsType = $smsType;
    }

    /**
     * @param int $pendingCount
     *
     * @return Sms
     */
    public function setPendingCount($pendingCount)
    {
        $this->pendingCount = $pendingCount;

        return $this;
    }

    /**
     * @return int
     */
    public function getPendingCount()
    {
        return $this->pendingCount;
    }

 /**
     * @return int
     */
    public function getReadCount()
    {
        return $this->readCount;
    }

    /**
     * @param int $readCount
     *
     * @return $this
     */
    public function setReadCount(int $readCount)
    {
        $this->readCount = $readCount;

        return $this;
    }

    /**
     * @return int
     */
    public function getFailedCount()
    {
        return $this->failedCount;
    }

    /**
     * @param int $failedCount
     *
     * @return $this
     */
    public function setFailedCount(int $failedCount)
    {
        $this->failedCount = $failedCount;

        return $this;
    }

     /**
     * @param string $deliveredCount
     *
     * @return $this
     */
    public function setDeliveredCount($deliveredCount)
    {
        $this->deliveredCount = $deliveredCount;
        return $this;
    }

    /**
     * @return string
     */
    public function getDeliveredCount()
    {
        return $this->deliveredCount;
    }
}