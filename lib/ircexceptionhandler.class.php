<?php
/**
 * Irc Exception Handler class file
 *  
 * @package NerderyIrcProjectBot
 */

/**
 * Irc Exception Handler
 * 
 * @package NerderyIrcProjectBot
 * @author Jansen Price <jansen.price@nerdery.com>
 * @version $Id$
 */
class IrcExceptionHandler
{
    /**
     * Init handlers
     * 
     * @return void
     */
    public static function initHandlers()
    {
        set_exception_handler(array('IrcExceptionHandler', 'handle'));
        set_error_handler(array('IrcExceptionHandler', 'handleError'));
    }

    /**
     * Handle error
     * 
     * @return void
     */
    public static function handleError()
    {
        list($errno, $message, $file, $line) = func_get_args();

        $message = self::_error_code($errno) . ": " . $message . " in " . $file . ":" . $line;

        Logger::log('ERROR', $message);
    }

    /**
     * Handle exception
     * 
     * @param Exception $e
     * @return void
     */
    public static function handle(Exception $e)
    {
        Logger::log('EXCEPTION', $e->getMessage());
        Logger::log('EXCEPTION', '^ ' . self::getInformativeMessage($e));
    }

    /**
     * Get the message from the exception that includes the file and line number
     *
     * @param mixed $e Exception object
     * @return void
     */
    public static function getInformativeMessage($e)
    {
        return "Error code #" . $e->getCode() . " in file " . $e->getFile() . " on line " . $e->getLine() .".";
    }

    /**
     * Convert an error code into the PHP error constant name
     *
     * @param int $code The PHP error code
     * @return string
     */
    private static function _error_code($code) {
        $error_levels = array(
            1     => 'E_ERROR',
            2     => 'E_WARNING',
            4     => 'E_PARSE',
            8     => 'E_NOTICE',
            16    => 'E_CORE_ERROR',
            32    => 'E_CORE_WARNING',
            64    => 'E_COMPILE_ERROR',
            128   => 'E_COMPILE_WARNING',
            256   => 'E_USER_ERROR',
            512   => 'E_USER_WARNING',
            1024  => 'E_USER_NOTICE',
            2048  => 'E_STRICT',
            4096  => 'E_RECOVERABLE_ERROR',
            8192  => 'E_DEPRECATED',
            16384 => 'E_USER_DEPRECATED',
        );
        return $error_levels[$code];
    }
}
