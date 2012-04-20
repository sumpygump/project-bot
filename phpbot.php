#!/usr/bin/env php
<?php
// First lets set the timeout limit to 0 so the page wont time out.
set_time_limit(0);
date_default_timezone_set('America/Chicago');

echo "IRC Bot loading...\n";

// Include path
set_include_path(
    implode(
        PATH_SEPARATOR,
        array(
            get_include_path(),
            realpath(dirname(__FILE__)),
        )
    )
);

include_once 'lib/ircexceptionhandler.class.php';
include_once 'lib/ircconfig.class.php';
include_once 'lib/ircserver.class.php';
include_once 'lib/logger.class.php';

IrcExceptionHandler::initHandlers();

$configFile = realpath(dirname(__FILE__)) . '/config/main.ini';

if (isset($argv[1])) {
    $configFile = $argv[1];
}

if (!file_exists($configFile)) {
    echo "Cannot load config file '" . $configFile . "'. Exiting.\n";
    exit(1);
}

$config = new IrcConfig($configFile);

// Open the socket connection to the IRC server
$server = new IrcServer($config);
