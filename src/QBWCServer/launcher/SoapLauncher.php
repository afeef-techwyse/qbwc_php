<?php
namespace QBWCServer\launcher;
/**
 * Created by PhpStorm.
 * User: Alex
 * Date: 29.10.2015
 * Time: 19:48
 */

class SoapLauncher
{
    public static function start($object)
    {
        // Configure error reporting for QB Web Connector compatibility
        // QBWC expects XML responses, not HTML error pages
        error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
        ini_set('display_errors', 0);
        
        $server = new \SoapServer($object->_config['wsdlPath'], $object->_config['soapOptions']);
        $server->setObject($object);
        $server->handle();
    }
}