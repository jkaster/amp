<?php
/*
Copyright (C) 2016 - 2017 by John F. Kaster, All Rights Reserved Worldwide
AMP (Analyzer for MySQL Performance) analyzes log files to determine performance costs.
To capture log entries to a table:
1. set global log_output = 'TABLE';
2. set global general_log = 'ON';
3. run the operations you want to capture
4. set global general_log = 'OFF';
5. run this analyzer
The analyzer will
- select all queries for the profiled user from the mysql.general_log table or
  from the specified log file (see amp.config.php for more information)
- report on issues via:
    - explain extended
    - show warnings
    - query cost
    - profile
- save query analysis to the `amp` database table `summary` by default
*/

ini_set('memory_limit', '-1'); // to maximize available memory
include_once "amp.box.php";
include_once "amp.classes.php";
$configFile = getConfig();
include_once $configFile;

$session = new Session($hostname, $logUser, $logPassword, $profiledUser, 
    $profileDatabase, $logSource, $mode,
    $maxLogs, $maxRows, $maxDuration,
    $analysisDatabase, $summaryTable);
    
$session->analyze();

function getConfig()
{
    $arguments = getoptreq("c::", array("config::"));
    if(isset($arguments["c"])){
        $config = $arguments["c"];
    }
    else if(isset($arguments["config"])){
        $config = $arguments["config"];
    }
    else{
        $config = "amp.config.php";
    }        
    return $config;
}

?>