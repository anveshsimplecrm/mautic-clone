<?php
namespace MauticPlugin\SimplecrmInfoBipSmsBundle\Api;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Mautic\CoreBundle\Helper\PhoneNumberHelper;
use Mautic\PageBundle\Model\TrackableModel;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Monolog\Logger;

class InfoBipApi extends AbstractSmsApi
{
    private $username;
    private $password;

    /**
     * @var \Services_InfoBip
     */
    protected $client;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var string
     */
    protected $sendingPhoneNumber;

    /**
     * InfoBipApi constructor.
     *
     * @param TrackableModel    $pageTrackableModel
     * @param PhoneNumberHelper $phoneNumberHelper
     * @param IntegrationHelper $integrationHelper
     * @param Logger            $logger
     */
    public function __construct(TrackableModel $pageTrackableModel, PhoneNumberHelper $phoneNumberHelper, IntegrationHelper $integrationHelper, Logger $logger)
    {
        $this->logger = $logger;

        $integration = $integrationHelper->getIntegrationObject('InfoBip');

        if ($integration && $integration->getIntegrationSettings()->getIsPublished()) {
//            $this->sendingPhoneNumber = $integration->getIntegrationSettings()->getFeatureSettings()['sending_phone_number'];

            $keys = $integration->getDecryptedApiKeys();

            //$this->client = new \Services_InfoBip($keys['username'], $keys['password']);
            $this->username = isset($keys['username']) ? $keys['username'] : '';
            $this->password = isset($keys['password']) ? $keys['password'] : '';
        }

        parent::__construct($pageTrackableModel);
    }

    /**
     * @param string $number
     *
     * @return string
     */
    protected function sanitizeNumber($number)
    {
        $util = PhoneNumberUtil::getInstance();
        $parsed = $util->parse($number, 'US');

        return $util->format($parsed, PhoneNumberFormat::E164);
    }

    /**
     * @param string $number
     * @param string $content
     *
     * @return bool|string
     */
    public function sendSms($number, $content, $messageType, $whatsappType, $whatsappButton, $mediaUrl, $textSmsAccount)
    {
        // Open the log file
        // $log_file = "whatappmessage_".date("Ymd") . ".log";
        // $fp1 = fopen($log_file, 'a');

        if ($number === null) {
            return false;
        }
        
        $messageBody = $content;
        $returnArray = array();
        
        // Logging the initial input parameters
        // fwrite($fp1, "Send SMS Parameters:\nNumber: $number\nMessage: $content\nMessageType: $messageType\nWhatsAppType: $whatsappType\nMediaUrl: $mediaUrl\n\n");

        // Remove all non-numeric characters
        $number = preg_replace('/\D/', '', $number);

        // Remove prefix "0"
        $number = ltrim($number, '0');
        
        // Check the number length and format accordingly
        if (strlen($number) == 9) {
            $number = "94" . $number;
        } elseif (strlen($number) == 10) {
            $number = "91" . $number;
        } elseif (strlen($number) >= 11) {
            $number = $number;
        } else {
            $returnArray = array(
                'status' => 'error',
                'response' => 'Invalid number ' . $number
            );
            
            return $returnArray;
        }

        try {
            if ($messageType == 'WhatsApp') {
                $isTemplate = 'false';
                $msg_type = 'HSM';
                $media_url = '';

                // Process WhatsApp message type and media URL
                if (!empty($whatsappButton)) {
                    $isTemplate = $whatsappButton;
                }
                if (!empty($whatsappType)) {
                    $msg_type = $whatsappType;
                }
                if (!empty($mediaUrl)) {
                    $media_url = $mediaUrl;
                }

                $source = "917834811114";
                $destination = $number;
                $appname = "BaselineMarketing";
                $url = "https://api.gupshup.io/wa/api/v1/msg";
                $curl = curl_init();

                // Construct the message data for different message types
                if ($msg_type == 'image') {
                    $newmessage = [
                        "type" => $msg_type,
                        "previewUrl" => $media_url,
                        "originalUrl" => $media_url,
                        "caption" => $messageBody,
                        "filename" => "Sample.jpg"
                    ];
                    
                    $encodedmessage = json_encode($newmessage, JSON_UNESCAPED_SLASHES);
                    // URL encode the entire string
                    $encodedmessage = rawurlencode($encodedmessage);
                    $postdata = "channel=whatsapp&source=$source&destination=$destination&message=$encodedmessage&src.name=$appname";
                    
                } elseif (in_array($msg_type, ['file', 'video', 'audio'])) {
                    $postdata = "src.name=$appname&channel=whatsapp&source=$source&destination=$destination&message=%7B%22type%22:%22$msg_type%22,%22url%22:%22$media_url%22,%22caption%22:%22$messageBody%22,%22filename%22:%22File%22%7D";
                } else {
                    $postdata = "src.name=$appname&channel=whatsapp&source=$source&destination=$destination&message=%7B%22type%22%3A%22text%22,%22text%22:%22$messageBody%22%7D";
                }
                
                // Logging the constructed post data
                // fwrite($fp1, "WhatsApp Post Data:\n" . print_r($postdata, true) . "\n\n");

                // Set up cURL request
                curl_setopt_array($curl, array(
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => $postdata,
                    CURLOPT_HTTPHEADER => array(
                        'apikey: d26bd352e14b4f01cf3eea3ec58841eb',
                        'Content-Type: application/x-www-form-urlencoded',
                        'Accept: application/json'
                    ),
                ));

                $curl_scraped_page = curl_exec($curl);
                curl_close($curl);

                $jsonresponse = json_decode($curl_scraped_page, true);

                // Logging the API response
                // fwrite($fp1, "API Response:\n" . print_r($jsonresponse, true) . "\n\n");

                $returnArray = array(
                    'status' => 'success',
                    'response' => $curl_scraped_page
                );
                 // Logging the Text API response
                // fwrite($fp1, "Text Message API Response:\n" . print_r($returnArray, true) . "\n\n");

                return $returnArray;
            }

            if ($messageType == 'Text') {
                if ($textSmsAccount == 'Transactional') {
                    $user_id = '2000192513';
                    $password = 'A8Ab52aApEpP';
                } else {
                    $user_id = '2000192513';
                    $password = 'A8Ab52aApEpP';
                }

                // CSTM: SMS Budget Estimate Changes- start
                /*if (!empty($textSmsAccount) && strpos(base64_decode($textSmsAccount), '||') !== false) {
                    $textSmsAccountArray =  explode('||', base64_decode($textSmsAccount));
                    $user_id = $textSmsAccountArray[0];
                    $password = $textSmsAccountArray[1];
                } else {
                    $user_id = '2000192513';
                    $password = 'A8Ab52aApEpP';
                }*/
                // CSTM: SMS Budget Estimate Changes- end

                $curl = curl_init();
                $message = rawurlencode($messageBody);
                $endpoint_url = "https://enterprise.smsgupshup.com/GatewayAPI/rest?method=SendMessage&send_to=$number&msg=$message&msg_type=TEXT&userid=$user_id&auth_scheme=plain&password=$password&v=1.1&format=text";

                curl_setopt_array($curl, array(
                    CURLOPT_URL => $endpoint_url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'GET',
                ));
                
                $curl_scraped_page = curl_exec($curl);
                curl_close($curl);

                $curl_output = explode('|', strip_tags($curl_scraped_page));
                $returnArray = array(
                    'status' => trim($curl_output[0]),
                    'response' => strip_tags($curl_scraped_page)
                );

              

                return $returnArray;
            }
            
        } catch (Exception $e) {
            $this->logger->addWarning($e->getMessage(), ['exception' => $e]);

            // Log the exception
            // fwrite($fp1, "Exception: " . $e->getMessage() . "\n\n");

            // Close the log file
            return false;
        }

        // fclose($fp1); 
        return true;
    }
}
