<?php
/**
 * Logger class file
 *
 * @package NerderyIrcProjectBot
 */

/**
 * Logger class
 * 
 * @package NerderyIrcProjectBot
 * @author Jansen Price <jansen.price@nerdery.com>
 * @version $Id$
 */
class Logger
{
    /**
     * Log
     * 
     * @param mixed $service
     * @param mixed $string
     * @return void
     */
    public static function log($service, $string)
    {
        if (is_array($string)) {
            $string = implode("\n", $string);
        }

        echo ' >> ' . str_pad(substr($service,0,10), 10, ' ') . '  ' . trim($string) . "\n";
    }
}
