<?php
/**
 * Nerdery IRC Message Class file
 *  
 * @package NerderyIrcProjectBot
 */

/**
 * Irc Message Class
 * 
 * @package NerderyIrcProjectBot
 * @author Jansen Price <jansen.price@nerdery.com>
 * @version $Id$
 */
class IrcMessage
{
    /**
     * The source of the message
     * 
     * @var string
     */
    public $source = '';

    /**
     * The command of the message
     * 
     * @var string
     */
    public $command = '';

    /**
     * The target of the message
     * 
     * @var string
     */
    public $target = '';

    /**
     * The body of the message
     * 
     * @var string
     */
    public $body = '';

    /**
     * Provide a raw IRC message to construct object
     * 
     * @param string $inputMessage IRC Message
     * @return void
     */
    public function __construct($inputMessage)
    {
        $this->parseMessage($inputMessage);
    }

    /**
     * Parse message
     * 
     * @param string $input IRC Message
     * @return vool
     */
    public function parseMessage($input)
    {
        if (empty($input)) {
            return;
        }

        $args = self::splitArgs($input);

        // If the first arg starts with : it is the source
        if (substr($args[0], 0, 1) == ':') {
            $this->source = trim(array_shift($args));
            $bodyOffset = strpos($input, ':', 1);
        } else {
            $bodyOffset = strpos($input, ':');
        }

        // The next arg is the command
        $this->command = trim(array_shift($args));

        // Anything between here and the colon is the target
        $remainder = trim(implode(' ', $args));
        $colon     = strpos($remainder, ':');
        if ($colon !== false && $colon != 0) {
            $this->target = trim(substr($remainder, 0, $colon));
            // shift if off so it doesn't polute the body
            array_shift($args);
        }

        // The rest is the body
        // (Don't include the colon)
        $this->body = substr($input, $bodyOffset);
        $colon      = strpos($this->body, ':');
        if ($colon !== false && $colon != 0) {
            $this->body = substr($this->body, $colon + 1);
        } else {
            $this->body = substr($this->body, 1);
        }

        return true;
    }

    /**
     * Split a string by whitespace and preserve quoted strings
     * 
     * @param mixed $input
     * @return void
     */
    public static function splitArgs($input)
    {
        $args = preg_split(
            "/[\s,]*\\\"([^\\\"]+)\\\"[\s,]*|" . "[\s,]*'([^']+)'[\s,]*|" . "[\s,]+/",
            trim($input), 0,
            PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE
        );

        return $args;
    }

    /**
     * Get the Nickname from a source string
     * 
     * @param string $source Source string
     * @return string
     */
    public function getNick()
    {
        if (!$this->source) {
            return '';
        }

        return substr($this->source, 1, strpos($this->source, '!') - 1);
    }
}
