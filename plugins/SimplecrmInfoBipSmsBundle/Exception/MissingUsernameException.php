<?php
namespace MauticPlugin\SimplecrmInfoBipSmsBundle\Exception;

class MissingUsernameException extends \Exception
{
    protected $message = 'Missing SMS Username';
}
