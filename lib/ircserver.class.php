<?php
/**
 * Nerdery IRC Project Bot Class file
 *  
 * @package NerderyIrcProjectBot
 */

require_once "ircmessage.class.php";

/**
 * Irc Server Bot
 * 
 * @package NerderyIrcProjectBot
 * @author Justin King <justin.king@nerdery.com>
 * @author Jansen Price <jansen.price@nerdery.com>
 * @version $Id$
 */
class IrcServer
{
    /**
     * Version
     *
     * @var string
     */
    const VERSION = "0.9.1";

    /**
     * Configuration object
     * 
     * @var mixed
     */
    protected $_cfg;

    /**
     * IRC connection socket
     * 
     * @var mixed
     */
    public $conn;

    /**
     * Name of server
     * 
     * @var mixed
     */
    protected $_server;

    /**
     * Nickname of this bot
     * 
     * @var string
     */
    protected $_selfNick = '';

    /**
     * Source string of this bot
     * 
     * @var string
     */
    protected $_selfSource = '';

    /**
     * Timeout interval in seconds
     * 
     * @var int
     */
    protected $_timeoutInterval = 60;

    /**
     * Registered modules
     * 
     * @var array
     */
    protected $_registeredModules = array();

    /**
     * Registered commands
     * @var array
     */
    protected $_registeredCommands = array();
    
    /**
     * Constructor
     * 
     * @return void
     */
    public function __construct($cfg = array())
    {
        $this->_setConfig($cfg);
        $this->_registerModules();

        $result = $this->connect(
            $this->_cfg->server->hostname,
            $this->_cfg->server->port,
            $this->_cfg->user->nick,
            $this->_cfg->user->realname
        );

        foreach ($this->_cfg->channels->autojoin as $channel) {
            $this->joinChannel($channel);
        }

        foreach ($this->_cfg->channels->autopart as $channel) {
            $this->partChannel($channel);
        }

        $this->listen();
    }

    /**
     * Set configuration object
     * 
     * @param mixed $cfg Array or IrcConfig object
     * @return void
     */
    protected function _setConfig($cfg)
    {
        if (is_array($cfg)) {
            $cfg = new IrcConfig();
            $cfg->loadArray($cfg);
        }

        $this->_cfg = $cfg;
    }

    /**
     * Register modules
     * 
     * @return void
     */
    protected function _registerModules()
    {
        foreach ($this->_cfg->modules as $name => $settings) {
            $this->registerModule($name, $settings);
        }
    }

    /**
     * Register a module
     * 
     * @param string $name Module name
     * @param array $settings Configuration settings
     * @return void
     */
    public function registerModule($name, $settings)
    {
        if (isset($settings['load']) && !$settings['load']) {
            // only register if not explicitly set to not load
            return;
        }

        if (isset($settings['class'])) {
            $className = $settings['class'];
        } else {
            $name = ucfirst(preg_replace('/[^A-Za-z_]/', '', $name));
            $className = 'Module_' . $name;
            $moduleName = strtolower($name);
        }

        // If class doesn't exist, attempt to include the file
        if (!class_exists($className)) {
            $file = 'modules/' . $name . '.php';
            include_once $file;
        }

        // If it still doesn't exist, fail
        if (!class_exists($className)) {
            Logger::log("PHPBOT", "Failed to load module $name. Class $className not found.");
            return false;
        }

        // Instantiate module class
        try {
            $module = new $className($settings);
        } catch (Exception $e) {
            Logger::log(
                'PHPBOT',
                "Failed to load module $name. Error message: " . $e->getMessage()
                . ", File:" . $e->getFile() . ':' . $e->getLine()
            );
            return false;
        }

        Logger::log("PHPBOT", "Loaded module $name.");

        $this->_registeredModules[$moduleName] = $module;
    }

    /**
     * Initialize the registered modules
     * 
     * @return void
     */
    public function initializeModules()
    {
        foreach ($this->_registeredModules as $module) {
            $module->init($this);
        }
    }

    /**
     * Register a command
     * 
     * @param mixed $module Name of module
     * @param mixed $function Method name in module
     * @return void
     */
    public function registerCommand($module, $function)
    {
    }

    /**
     * Connect to a server
     * 
     * @param string $server Server
     * @param int $port Port
     * @param string $nickname Nickname to use
     * @return bool
     */
    public function connect($server, $port, $nickname, $realname)
    {
        $this->_server = $server;
        $this->_selfNick = $nickname;
        $this->_selfSource = ':' . $nickname;

        Logger::log("PHPBOT", "Connecting to $server:$port");
        $this->conn = fsockopen($server, $port, $errno, $errstr, 2);
        Logger::log("PHPBOT", "Authenticating");

        if ($this->conn) {
            //Ok, we have connected to the server, now we have to send the login commands.
            $this->_sendCommand("PASS NOPASS\n"); //Sends the password not needed for most servers
            $this->_sendCommand("NICK $nickname\n"); //sends the nickname
            $this->_sendCommand("USER $nickname test localhost :$realname\n"); //sends the user must have 4 paramters

            // While connected to the server
            while (!feof($this->conn)) { 
                $buffer = fgets($this->conn, 1024); //get a line of data from the server
                $message = new IrcMessage($buffer);
                
                if (trim($buffer) != '') {
                    Logger::log("AUTH", $buffer); //display the received data from the server 
                }

                // wait for motd end status code 
                switch ($message->command) {
                    case 376:
                    case 422:
                        Logger::log('PHPBOT', 'Connection established.');
                        return true;
                        //$this->_sendCommand("WHO $nickname\n");
                        break;
                    case 352:
                        // formally checked for a /who response
                        // return true;
                        break;
                }
            }
        } else {
            Logger::log("PHPBOT", "Not connected\n");
            return false;
        }      

        return true;
    }

    /**
     * Listen to the channel
     * 
     * @return void
     */
    public function listen() 
    {
        stream_set_timeout($this->conn, $this->_timeoutInterval);
        $this->initializeModules();

        // While connected to the server
        while (!feof($this->conn)) {
            $buffer = fgets($this->conn, 1024); //get a line of data from the server

            if (trim($buffer) != '')  {
                Logger::log("SERVER", $buffer); //display the received data from the server 
            }
        
            $message = new IrcMessage($buffer);

            switch ($message->command) {
                case 'PING' :
                    $this->_sendCommand('PONG ' . $message->body);
                    break;
                case '' :
                    break;
                default :
                    if (preg_match('/^[0-9][0-9][0-9]$/', $message->command)) {
                        $this->_handleNumericReply($message);
                        continue;
                    }
                    if (method_exists($this, '_' . $message->command)) {
                        $result = $this->{'_' . $message->command}($message);
                    } else {
                        if ($message->command != '') {
                            Logger::log("PHPBOT", "No method found for: " . $message->command);
                        }
                    }
                    break;                
            };

            $info = stream_get_meta_data($this->conn);
            if ($info['timed_out']) {
                $this->executeTimeoutEvents();
            }
        } 
        
        Logger::log('PHPBOT', 'Connection to server lost.');
    }

    /**
     * Execute events registered to occur on an interval
     *
     * @return void
     */
    public function executeTimeoutEvents()
    {
        // Modules could register to have events occur
        // during this time.
        Logger::log('EVENTS', 'Timeout events cycle');

        foreach ($this->_registeredModules as $module) {
            $module->processIntervalEvents($this);
        }
    }
    
    /**
     * Join a channel
     * 
     * @param string $channel Channel name
     * @return void
     */
    public function joinChannel($channel)
    {
        $this->_sendCommand("JOIN $channel\n");
    }

    /**
     * Part a channel
     * 
     * @param string $channel Channel name
     * @return void
     */
    public function partChannel($channel)
    {
        if (!$channel) {
            return;
        }
        $this->_sendCommand("PART $channel\n");
    }
    
    /**
     * Send a message
     * 
     * @param string $target Target
     * @param string $message Message
     * @return void
     */
    public function sendMessage($target, $message)
    {
        $this->_sendCommand($this->_selfSource . ' PRIVMSG ' . $target . ' :' . $message . "\n");    
    }
    
    /**
     * Quit channel
     * 
     * @param string $message Parting message
     * @return void
     */
    public function quit($message = "I never could reach the top shelf! \r\n")
    {
        $this->_sendCommand('QUIT :' . $message);
    }

    /**
     * Send a command to the IRC server
     * 
     * @param string $cmd Command
     * @return void
     */
    private function _sendCommand($cmd)
    {
        $cmd .= "\r\n";
        fwrite($this->conn, $cmd, strlen($cmd));
        Logger::log("CLIENT", $cmd);
    }
    
    /**
     * Handler for private message command
     * 
     * @param object $message IrcMessage object
     * @return void
     */
    private function _PRIVMSG($message) 
    {
        $nick = $message->getNick();

        if ($message->target == $this->getSelfNick()) {
            // Private chat -- the target should be the person who requested information
            $target = $message->getNick();
        } else {
            $target = $message->target;
        }

        $parts = explode(' ', $message->body);

        // Shift off the first word
        $firstWord = array_shift($parts);

        $body      = implode(' ', $parts);
        //$arguments = trim(substr($body, strpos($body, ' ')));

        // Handle CTCP commands
        if (substr($message->body, 0, 1) == chr(1)) {
            Logger::log('CTCP', 'Received CTCP Message');
            return $this->_handleCtcpMessage($target, $firstWord, $body);
        }

        // Someone addressed this bot directly
        if ($firstWord == $this->_selfNick . ':') {
            $this->sendMessage($target, $nick . ': Keep in mind I am just a bot.');
            return true;
        }

        // Detect if the word sleep was used
        if (strpos(strtolower($body), 'sleep') !== false && strpos($body, 'Pfffff') === false) {
            $this->sendMessage($target, "Pfffff. Sleep. Sleep is wrong.");
        }

        if (substr($firstWord, 0, 1) == '.') {
            $command = trim(substr($firstWord, 1));
            switch ($command) {
                case 'die' :
                    $this->quit();
                    break;
                case 'help':
                    if ($message->target != $this->getSelfNick()) {
                        $this->sendMessage($target, $this->_selfNick . " sent a private message to $nick.");
                    }
                    $this->_sendPrivateHelpMessage($nick);
                    break;
                case 'modules':
                    $response = "Loaded modules: " . implode(', ', array_keys($this->_registeredModules));
                    $this->sendMessage($target, $response);
                    break;
                default : 
                    if (isset($this->_registeredModules[$command])) {
                        $module = $this->_registeredModules[$command];
                        // Modify the message body so it doesn't contain the command
                        $message->body = $body;
                        $module->handleMessage($this, $message);
                    } else {
                        $this->sendMessage($target, 'Command not found: ' . $firstWord);
                    }
                  break;
            }
        }
    }

    /**
     * Handle a CTCP message
     *
     * @param string $target Target (channel or user)
     * @param string $type CTCP Message type
     * @param string $body Remaining params sent in message
     * @return bool
     */
    protected function _handleCtcpMessage($target, $type, $body)
    {
        if (substr($type, 0, 1) == chr(1)) {
            $type = str_replace("\001", '', substr($type, 1));
        }

        switch ($type) {
        case 'VERSION':
            $message = 'NerderyIrcProjectBot:' . self::VERSION . ':' . PHP_OS . ' PHP ' . PHP_VERSION;
            break;
        default:
            $message = "CTCP message of type '$type' is not handled by this bot.";
            break;
        }

        $this->_sendCommand($this->_selfSource . ' NOTICE ' . $target . ' :' . "\001" . $type . ' ' . $message . "\001");    
        return true;
    }

    /**
     * Send a private message to a nick
     * 
     * @param string $nick Nick
     * @return void
     */
    protected function _sendPrivateHelpMessage($nick)
    {
        $this->sendMessage($nick, "Hi. I am the project bot version 1.0. I help with development projects. You can interact with me using the following commands:");
        $this->sendMessage($nick, "Basic commands:");
        $this->sendMessage($nick, "  .help ~ This help message.");
        $this->sendMessage($nick, "  .modules ~ A list of loaded modules.");
        $this->sendMessage($nick, "  .die ~ Make me log off.");

        $messages = array();
        foreach ($this->_registeredModules as $moduleName => $module) {
            $this->sendMessage($nick, $moduleName . ' module commands:');
            foreach ($module->getHelpMessages() as $command => $message) {
                $this->sendMessage($nick, "  " . $command . " ~ " . $message);
                usleep(200000); // Sleep for 200 ms to avoid excess flood boots
            }
        }

        // $this->sendMessage($nick, "I also greet people when they join the channel, because I'm friendly.");
    }

    /**
     * Handler for MODE command
     * 
     * @param object $message IrcMessage object
     * @return void
     */
    protected function _MODE($message)
    {
        Logger::log('PHPBOT', "MODE information: " . $message->body);
    }

    /**
     * Handler for NOTICE command
     * 
     * @param object $message IrcMessage object
     * @return void
     */
    protected function _NOTICE($message)
    {
        Logger::log('PHPBOT', "NOTICE from " . $message->source . ": " . $message->body);
    }

    /**
     * Handler for JOIN command
     * 
     * @param object $message IrcMessage object
     * @return void
     */
    protected function _JOIN($message)
    {
        $nick = $message->getNick();

        $parts  = explode(' ', $message->body);
        $target = substr(array_shift($parts), 1); // strip off the :

        Logger::log('PHPBOT', "$nick just joined.");

        if ($nick == $this->_selfNick) {
            // Set the self source so I can use it when sending messages
            $this->_selfSource = $message->source;
        }
    }

    /**
     * Handler for QUIT command
     * 
     * @param object $message IrcMessage object
     * @return void
     */
    protected function _QUIT($message)
    {
        Logger::log('PHPBOT', $message->getNick() . ' just quit.');
    }

    /**
     * Handle Numeric Reply from server
     *
     * See RFC 2812, section 5 for a complete list of replies
     * http://www.irchelp.org/irchelp/rfc/rfc2812.txt
     * 
     * @param object $message IrcMessage object
     * @return void
     */
    protected function _handleNumericReply($message)
    {
        switch ($message->command) {
        case '353':
            Logger::log('PHPBOT', "Names: " . $message->body);
            break;
        default:
            break;
        }
    }

    /**
     * Get Self Nick
     * 
     * @return string
     */
    public function getSelfNick()
    {
        return $this->_selfNick;
    }

    /**
     * Get Joined channels
     * 
     * @return array
     */
    public function getJoinedChannels()
    {
        return $this->_cfg->channels->autojoin;
    }
}
