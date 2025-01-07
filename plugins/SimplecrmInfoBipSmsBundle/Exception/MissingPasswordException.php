<?php
namespace MauticPlugin\SimplecrmInfoBipSmsBundle\Exception;

class MissingPasswordException extends \Exception
{
    protected $message = 'Missing SMS Password';
}
