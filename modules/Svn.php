<?php

include_once 'lib/ircmodule.class.php';

class Module_Svn implements IrcModule
{
    /**
     * Configuration array
     * 
     * @var array
     */
    protected $_cfg = array();

    /**
     * Svn log entries
     *
     * @var array
     */
    protected $_entries = array();

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
            throw new Exception('[mod_svn] missing required configuration parameter: $url', 101);
        }

        $this->_cfg = $cfg;

        $this->_data = $this->getData();
    }

    /**
     * Initialize this module
     * 
     * @param mixed $irc The IrcServer object
     * @return void
     */
    public function init($irc)
    {
    }

    /**
     * Get the configured URL to the SVN path
     *
     * @return string
     */
    public function getUrl()
    {
        return $this->_cfg['url'];
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

        if ($target == $irc->getSelfNick()) {
            // Since the target was directly to the bot, it means we are
            // in private chat, so the target is the person who requested information
            $target = $nick;
        } else {
            // Address the person who requested the information
            $response = $nick . ': ';
        }

        $response .= '[mod_svn] Update on ' . date('m/d/y H:m:s', $this->_data[0])
            . ' by ' . $this->_data[1] . ' (' . $this->_data[2]. ')';

        $irc->sendMessage($target, $response);
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
        $this->refreshData($irc);
    }

    /**
     * Get data
     * 
     * @return array;
     */
    public function getData()
    {
        $path = realpath(dirname(__FILE__));
        $svnFile = file_get_contents($path . '/svn/data/update.txt');
        $data    = explode(',', $svnFile);

        return $data;
    }

    /**
     * Refresh the data from the svn server
     *
     * @param object $irc The IrcServer object
     * @return void
     */
    public function refreshData($irc)
    {
        $this->log('Refreshing data from SVN');
        $cmd = 'svn log -l5 "' . $this->getUrl() . '" --non-interactive';

        $this->log($cmd);

        if (isset($this->_cfg['username'])) {
            $cmd .= ' --username "' . $this->_cfg['username'] . '"';
        }

        if (isset($this->_cfg['password'])) {
            $cmd .= ' --password "' . $this->_cfg['password'] . '"';
        }
        
        $cmd .= ' 2>&1';

        exec($cmd, $output, $status);
        if ($status != 0) {
            throw new Exception('[mod_svn] Error: ' . implode("\n", $output));
        }

        $entries = $this->_readSvnLog($output);
        $latestEntry = reset($entries);
        $entries = array_reverse($entries);

        $newEntries = array();
        foreach ($entries as $entry) {
            $rev = $entry['revision'];
            if (!isset($this->_entries[$rev])) {
                $newEntries[$rev] = $entry;
            }
        }

        if (count($newEntries)) {
            $this->notifyEntries($newEntries, $irc);
        } else {
            $this->log('No new commits');
        }

        // Save these entries for next time
        $this->_entries = $entries;
    }

    /**
     * Notify connect channels of new entries
     *
     * @param array $entries
     * @param object $irc IrcServer object
     * @return void
     */
    public function notifyEntries($entries, $irc)
    {
        $this->log('Sending commit log messages');

        $response = $this->_createResponse($entries);

        foreach ($irc->getJoinedChannels() as $channel) {
            $this->sendMessage($irc, $channel, $response);
            usleep(200000); // sleep 200 ms to avoid excess flood boot-offs
        }
    }

    /**
     * Create response messages from svn log entries
     *
     * @param array $entries Svn log entries
     * @return string
     */
    protected function _createResponse($entries)
    {
        $messages = array();

        foreach ($entries as $entry) {
            $messages[] = $entry['revision'] . ' '
                . $entry['user'] . ' '
                . date('Y-m-d H:i A', $entry['ts']) . ' >> ' 
                . $entry['comment'];
        }

        return implode("\n", $messages);
    }

    /**
     * Read an svn log
     *
     * @param array $log Array output from svn log command
     * @return array
     */
    protected function _readSvnLog($log)
    {
        $count = count($log);
        $entries = array();
        $entryBuffer = false;
        $entryBufferComment = '';

        for ($i = 0; $i < $count; $i++) {
            $line = $log[$i];
            if (substr($line, 0, 5) == '-----') {
                if ($entryBuffer) {
                    // Save the last entry parsed
                    $entryBuffer['comment'] = $entryBufferComment;
                    $entries[$entryBuffer['revision']] = $entryBuffer;

                    $entryBuffer = false;
                    $entryBufferComment = '';
                }

                // parse an entry
                $i++;
                if (!isset($log[$i])) {
                    // last line
                    break;
                }
                $values = explode(' | ', $log[$i]);

                $timestamp = strtotime(substr($values[2], 0, 25));
                $entryBuffer = array(
                    'revision' => $values[0],
                    'user'     => $values[1],
                    'datetime' => $values[2],
                    'ts'       => $timestamp,
                    'lines'    => $values[3],
                );
            } else {
                // gather the comments
                $entryBufferComment .= trim($line);
            }
        }

        return $entries;
    }

    /**
     * Get help messages for this module
     * 
     * @return array
     */
    public function getHelpMessages()
    {
        return array('.svn' => 'Show latest commit and revision number for configured project SVN repository.');
    }

    /**
     * Log message for mod_svn
     *
     * @param string $message Message to log
     * @return void
     */
    public function log($message)
    {
        Logger::log('[mod_svn]', $message);
    }
}
