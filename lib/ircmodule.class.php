<?php
/**
 * Irc Module interface file
 *
 * @package NerderyIrcProjectBot
 */

/**
 * Irc Module interface
 * 
 * @package NerderyIrcProjectBot
 * @author Jansen Price <jansen.price@nerdery.com>
 * @version $Id$
 */
interface IrcModule
{
    public function configure($cfg);
    public function init($irc);
    public function handleMessage($irc, $message);
    public function processIntervalEvents($irc);
    public function getHelpMessages();
}
