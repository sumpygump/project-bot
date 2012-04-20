<?php

include_once 'lib/ircmodule.class.php';

/**
 * Trac Module
 * 
 * @uses IrcModule
 * @package 
 * @author Jansen Price <jansen.price@nerdery.com>
 * @version $Id$
 */
class Module_Trac implements IrcModule
{
    /**
     * Configuration array
     * 
     * @var array
     */
    protected $_cfg = array();

    /**
     * Timeline from trac RSS feed
     * 
     * @var array
     */
    protected $_timeline = array();

    /**
     * Last reported timeline item guid
     * 
     * @var int
     */
    protected $_lastGuid = 0;

    /**
     * Last reported timeline item link
     *
     * @var string
     */
    protected $_lastLink = '';

    /**
     * Form token for trac
     *
     * @var string
     */
    protected $_formToken = '';

    /**
     * Construct
     * 
     * @param mixed $cfg Configuration settings
     * @return void
     */
    public function __construct($cfg)
    {
        $this->configure($cfg);
    }

    /**
     * Configure this module
     * 
     * @param mixed $cfg
     * @return void
     */
    public function configure($cfg)
    {
        if (!isset($cfg['url'])) {
            throw new Exception('[mod_trac] missing required configuration parameter: $url', 101);
        }

        // Enforce trailing slash
        if (substr($cfg['url'], -1) != '/') {
            $cfg['url'] .= '/';
        }

        $this->_cfg = $cfg;
    }

    /**
     * Initialize this module
     * 
     * @param mixed $irc The IrcServer object
     * @return void
     */
    public function init($irc)
    {
        //$this->refreshTimeline();
        $this->processIntervalEvents($irc);
    }

    /**
     * Handle an Irc Message
     * 
     * @param mixed $irc The IrcServer object
     * @param mixed $message The IrcMessage object
     * @return void
     */
    public function handleMessage($irc, $message)
    {
        $nick   = $message->getNick();
        $target = $message->target;
        $body   = trim($message->body);

        $response = $this->_parseSubcommand($body, $nick);

        if (trim($response == '')) {
            return false;
        }

        if ($target == $irc->getSelfNick()) {
            // Since the target was directly to the bot, it means we are
            // in private chat, so the target is the person who requested information
            $target = $nick;
        } else {
            // Address the person who requested the information
            $response = $nick . ': ' . $response;
        }

        $this->sendMessage($irc, $target, $response);
    }

    /**
     * Parse subcommand and return a response to send the the channel
     *
     * @param string $body Body of subcommand
     * @param string $nick Nick of person who initiated message
     * @return string
     */
    protected function _parseSubcommand($body, $nick)
    {
        if ($body == '') {
            return $this->_cfg['url'];
        }

        if (is_numeric($body)) {
            return $this->getTicketUrl($body);
        }

        $args = IrcMessage::splitArgs($body);

        $subcommand = array_shift($args);
        $response   = '';

        switch ($subcommand) {
        case 'changeset':
        case 'cs':
            if (!isset($args[0])) {
                return "Missing changeset number";
            }
            $response = $this->getChangesetUrl($args[0]);
            break;
        case 'create':
            if (!isset($args[0])) {
                return "Missing ticket details";
            }
            $newTicketLink = $this->createTicket($args, $nick);
            if ($newTicketLink != '') {
                $response = $newTicketLink;
            }
            break;
        case 'ticket':
        case 't':
            if (!isset($args[0])) {
                return "Missing ticket number";
            }
            $response = $this->getTicketUrl($args[0]);
            break;
        case 'timeline':
        case 'tl':
            $response = $this->getTimeline();
            break;
        case '^^':
            if ($this->_lastLink != '') {
                $response = $this->_lastLink;
            }
            break;
        case 'url': // passthru
        default:
            $response = $this->_cfg['url'];
            break;
        }

        return $response;
    }

    /**
     * Get a URL to a ticket number
     *
     * @param int $ticketNumber Ticket number
     * @return string
     */
    public function getTicketUrl($ticketNumber)
    {
        if (!is_numeric($ticketNumber)) {
            return "Invalid ticket number; Must be numeric";
        }

        return $this->_cfg['url'] . 'ticket/' . $ticketNumber;
    }

    /**
     * Get a URL to a changeset
     *
     * @param int $changesetId Changeset id
     * @return string
     */
    public function getChangesetUrl($changesetId)
    {
        if (!is_numeric($changesetId)) {
            return "Invalid changeset number; Must be numeric";
        }

        return $this->_cfg['url'] . 'changeset/' . $changesetId;
    }

    /**
     * Send message and be smart about line breaks
     * 
     * @param object $irc IrcServer object
     * @param string $target Target
     * @param string $response Response
     * @return void
     */
    public function sendMessage($irc, $target, $response)
    {
        if (false !== strpos($response, "\n")) {
            $responses = explode("\n", $response);
            foreach ($responses as $response) {
                if (trim($response) != '') {
                    $irc->sendMessage($target, $response);
                }
            }
        } else {
            $irc->sendMessage($target, $response);
        }

        // Reset the last link
        $this->_lastLink = '';
    }

    /**
     * Interval events
     * 
     * @param mixed $irc The IrcServer object
     * @return void
     */
    public function processIntervalEvents($irc)
    {
        Logger::log('[mod_trac]', 'Refreshing timeline');
        $this->refreshTimeline();

        $response = $this->getTimeline(true);

        if ($response) {
            Logger::log('[mod_trac]', 'Sending timeline messages');
            foreach ($irc->getJoinedChannels() as $channel) {
                $this->sendMessage($irc, $channel, $response);
                usleep(200000); // sleep 200 ms to avoid excess flood boot-offs
            }
            // Set the last link for the last item sent
            $lastItem = end($this->_timeline);
            $this->_lastLink = $lastItem['link'];
        } else {
            Logger::log('[mod_trac]', 'No new timeline messages');
        }
    }

    /**
     * Refresh timeline
     * 
     * @return void
     */
    public function refreshTimeline()
    {
        $url = $this->_cfg['url'] . 'timeline?ticket=on&changeset=on&milestone=on&wiki=on&max=5&daysback=90&format=rss';
        Logger::log('[mod_trac]', 'Loading Timeline ' . $url);

        if ($this->_cfg['user']) {
            $userpass = $this->_cfg['user'] . ':' . $this->_cfg['password'];
            $context = stream_context_create(
                array(
                    'http' => array(
                        'header'  => "Authorization: Basic " . base64_encode($userpass)
                    )
                )
            );
            $rss = file_get_contents($url, false, $context);
        } else {
            $rss = file_get_contents($url);
        }

        try {
            $xml = simplexml_load_string($rss);
        } catch (Exception $e) {
            Logger::log('[mod_trac]', 'Cannot load RSS xml.');
            return false;
        }

        if (!isset($xml->channel)
            || !isset($xml->channel->item)
        ) {
            Logger::log('[mod_trac]', 'RSS xml missing channel or item.');
            return false;
        }

        $timeline = array();
        foreach ($xml->channel->item as $item) {
            $itemArray = (array) $item;
            $guid = (int) substr($item->guid, 1);

            $timestamp = (int) (strtotime($item->pubDate));
            $itemArray['pubDate'] = date('Y-m-d h:i A', $timestamp);

            $itemArray['link'] = $item->link;

            $namespaces = $item->getNamespaces(true);
            if (isset($namespaces['dc'])) {
                $dc = $item->children($namespaces['dc']);
                $itemArray['creator'] = (string) $dc->creator;
            } else {
                $itemArray['creator'] = '';
            }

            $timeline[$guid] = $itemArray;
        }

        $this->_timeline = array_reverse($timeline, true);
    }

    /**
     * Get timeline formatted for irc messages
     * 
     * @return string
     */
    public function getTimeline($sinceLast = false)
    {
        if (empty($this->_timeline)) {
            return false;
        }

        $response = array();
        foreach ($this->_timeline as $guid => $item) {
            $itemText = $item['pubDate'];
            if ($item['creator']) {
                $itemText .= ' (' . $item['creator'] . ')';
            }
            $itemText .= ' : ' . $item['title'];

            if (false == $sinceLast) {
                $response[] = $itemText;
            } else {
                if ($guid > $this->_lastGuid) {
                    $response[] = $itemText;
                }
            }
        }

        // Set the last guid to the last item's timestamp.
        if ($sinceLast) {
            $this->_lastGuid = $guid;
        }

        return implode("\n", $response);
    }

    /**
     * Fetch the form token by making a request to trac to populate cookies
     *
     * @return void
     */
    public function fetchFormToken()
    {
        if ($this->_formToken) {
            return true;
        }

        $url = $this->_cfg['url'] . 'newticket';

        $http = $this->_getHttp();

        $http->fetch($url);
        if ($http->httpCode == 200
            && file_exists($http->cookieFile)
        ) {
            $this->_formToken = $this->_readFormToken($http->cookieFile);
        }
    }

    /**
     * Read the form token from the headers
     *
     * @param mixed $headers
     * @return void
     */
    protected function _readFormToken($cookieFile)
    {
        $contents = file_get_contents($cookieFile);

        // Retrieve the value of the cookie 'trac_form_token'
        $found = preg_match('/trac_form_token\t(.*)\n/', $contents, $matches);

        if ($found && isset($matches[1])) {
            return trim($matches[1]);
        }

        return false;
    }

    /**
     * Create ticket
     *
     * @param array $args Message with ticket details
     * @param string $nick Nick of creator
     * @return string Resulting URL of new ticket or empty string
     */
    public function createTicket($args, $nick)
    {
        $this->fetchFormToken();
        $summary = array_shift($args);
        $body = implode(' ', $args);

        $post = array(
            '__FORM_TOKEN' => $this->_formToken,
            'field_summary' => $summary,
            'field_description' => $body,
            'field_reporter' => $nick,
            'field_status' => 'new',
            'submit' => 'Create Ticket',
        );

        $url = $this->_cfg['url'] . 'newticket';
        $http = $this->_getHttp();
        
        $response = $http->fetch($url, 'POST', $post);

        // The reply is a redirect to the newly created ticket
        if (isset($http->httpHeader['location'])) {
            return $http->httpHeader['location'];
        }

        return '';
    }

    /**
     * Get Http object
     *
     * @return object Trac_Http
     */
    protected function _getHttp()
    {
        $http = new Trac_Http();

        if ($this->_cfg['user']) {
            $userpass = $this->_cfg['user'] . ':' . $this->_cfg['password'];
            $http->setUserPass($userpass);
        }

        return $http;
    }

    /**
     * Get help messages for this module
     * 
     * @return array
     */
    public function getHelpMessages()
    {
        return array(
            '.trac' => 'Show URL to trac instance for configured project',
            '.trac url' => 'Show URL to trac instance for configured project',
            '.trac timeline' => 'Show a list of the latest timeline updates for configured trac instance (shortcut: .trac tl)',
            '.trac <ticket number>' => 'Show URL to a specific trac ticket',
            '.trac ticket <ticket number>' => 'Show URL to a specific trac ticket (shortcut: .trac t)',
            '.trac changeset <changeset number>' => 'Show URL to a specific changeset (shortcut: .trac cs)',
            '.trac ^^' => 'Show URL to most recent reported timeline item',
        );
    }
}

/**
 * Http cURL wrapper class
 *
 * @package 
 * @author Jansen Price <jansen.price@nerdery.com>
 * @version $Id$
 */
class Trac_Http
{
    /**
     * Contains the last HTTP status code returned
     *
     * @var int
     */
    public $httpCode;

    /**
     * Contains information for the last HTTP request
     *
     * @var mixed
     */
    public $httpInfo;

    /**
     * Contains the last HTTP headers returned
     *
     * @var mixed
     */
    public $httpHeader;

    /**
     * URL most recently fetched
     *
     * @var string
     */
    public $url;

    /**
     * User Agent
     *
     * @var string
     */
    public $userAgent = 'IRC PHPBot Trac Module v0.1';

    /**
     * Request timeout setting
     *
     * @var int
     */
    public $timeout = 30;

    /**
     * Connect timeout setting
     *
     * @var int
     */
    public $connectTimeout = 30; 

    /**
     * Storage location of cookie file
     *
     * @var string
     */
    public $cookieFile = '.trac.cookiejar.txt';

    /**
     * Username and password for basic authentication
     *
     * @var string
     */
    protected $_userPass = '';

    /**
     * Constructor
     *
     * @return void
     */
    public function __construct()
    {
        $path = realpath(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
        $this->cookieFile = $path . $this->cookieFile;
    }

    /**
     * Set username and password for basic authentication
     *
     * @param string $userPass Username and password
     * @return object Self
     */
    public function setUserPass($userPass)
    {
        $this->_userPass = $userPass;
        return $this;
    }

    /**
     * Make an HTTP request
     *
     * @return API results
     */
    public function fetch($url, $method = 'GET', $postfields = NULL)
    {
        $this->httpInfo = array();
        $ci = curl_init();

        /* Curl settings */
        curl_setopt($ci, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ci, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ci, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ci, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ci, CURLOPT_HEADERFUNCTION, array($this, 'getHeader'));
        curl_setopt($ci, CURLOPT_HEADER, false);
        curl_setopt($ci, CURLOPT_COOKIEFILE, $this->cookieFile); 
        curl_setopt($ci, CURLOPT_COOKIEJAR, $this->cookieFile);

        if ($this->_userPass) {
            curl_setopt($ci, CURLOPT_HTTPAUTH, CURLAUTH_BASIC) ; 
            curl_setopt($ci, CURLOPT_USERPWD, $this->_userPass);
            curl_setopt($ci, CURLOPT_SSLVERSION, 3); 
            curl_setopt($ci, CURLOPT_SSL_VERIFYPEER, false); 
            curl_setopt($ci, CURLOPT_SSL_VERIFYHOST, 2);
        }

        switch ($method) {
        case 'POST':
            curl_setopt($ci, CURLOPT_POST, true);
            if (!empty($postfields)) {
                curl_setopt($ci, CURLOPT_POSTFIELDS, $postfields);
            }
            break;
        case 'DELETE':
            curl_setopt($ci, CURLOPT_CUSTOMREQUEST, 'DELETE');
            if (!empty($postfields)) {
                $url = "{$url}?{$postfields}";
            }
        }

        curl_setopt($ci, CURLOPT_URL, $url);
        $response = curl_exec($ci);
        $this->httpCode = curl_getinfo($ci, CURLINFO_HTTP_CODE);
        $this->httpInfo = array_merge($this->httpInfo, curl_getinfo($ci));
        $this->url = $url;
        curl_close ($ci);

        return $response;
    }

    /**
     * Get the header info to store.
     *
     * @param resource $ch CURL resource
     * @param string $header Header information
     * @return void
     */
    public function getHeader($ch, $header)
    {
        $i = strpos($header, ':');

        if (!empty($i)) {
            $key = str_replace('-', '_', strtolower(substr($header, 0, $i)));
            $value = trim(substr($header, $i + 2));
            $this->httpHeader[$key] = $value;
        }

        return strlen($header);
    }

}
