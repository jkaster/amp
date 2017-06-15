<?php

$hostname = "localhost"; // MySQL server location
$logUser = "root"; // account for user who can read mysql.general_log and create databases and tables
$logPassword = "root"; // password for user who can read mysql.general_log and create databases and tables
$logSource = "queries.log"; // either a file name or "table" for table-based query logs
$mode = "merge"; // either "merge" or "each" for the profile results. 
$profileDatabase = "pa_contao"; // the name of the profiled database
$profiledUser = "sa[sa] @ localhost [127.0.0.1]"; // the name of the profiled user - should be different than $user. Only for table-based logs.
$maxLogs = -1; // number of log entries to analyze. Set to -1 for all.
$maxRows = 200; // maximum number of rows that should be retrievable in a profiled query
$maxDuration = 0.002; // maximum allowed length of time for a query to complete before generating a profiler warning
$analysisDatabase = "amp"; // the name of the analysis database
$summaryTable = "summary"; // the name of the summary table in the analysis database

?>