<?php
//include_once $_SERVER['DOCUMENT_ROOT']."/amp.box.php";
include_once "amp.box.php";

class Session {
    public $hostname;
    public $logUser;
    public $logPassword;
    public $logDB;
    public $profileUser;
    public $profileDatabase;
    public $profileDB;
    public $logSource;
    public $mode;
    public $maxLogs;
    public $maxRows;
    public $maxDuration;
    public $analysisDatabase;
    public $summaryTable;
    public $output;
    public $jsonFile;
    public $explainer = "explain ";
    public $saveDB;
    public $saver;
    public $finder;
    public $errors = [];

    protected $mergeMode;
    
    function __construct($hostname, $logUser, $logPassword, $profiledUser, 
        $profileDatabase, $logSource = 'table', $mode = 'merge',
        $maxLogs = -1, $maxRows = 200, $maxDuration = 0.002,
        $analysisDatabase = 'amp', $summaryTable = 'summary')
    {
        $this->hostname = $hostname;
        $this->logUser = $logUser;
        $this->logPassword = $logPassword;
        $this->profileDatabase = $profileDatabase;
        $this->profileUser = $profiledUser;
        $this->logSource = $logSource;
        $this->mode = strtolower($mode);
        $this->maxLogs = $maxLogs;
        $this->maxRows = $maxRows;
        $this->maxDuration = $maxDuration;
        $this->analysisDatabase = $analysisDatabase;
        $this->summaryTable = $summaryTable;
        $this->isMergeMode = 'merge' === $mode;
        
        // connect to the databases
        try 
        {
            $this->logDB = new PDO("mysql:host=$this->hostname;dbname=mysql;charset=utf8mb4", 
                $logUser, $logPassword, array(
                    // PDO::ATTR_EMULATE_PREPARES => false, 
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true, 
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));  
            $this->profileDB = new PDO("mysql:host=$this->hostname;dbname=$this->profileDatabase;charset=utf8mb4",
                $logUser, $logPassword, array( 
                    // PDO::ATTR_EMULATE_PREPARES => false, 
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        }
        catch (PDOException $e) 
        {
            print "Error!: " . $e->getMessage() . "<br/>";
            die();
        }
        $this->init();
    }
    
    function __destruct()
    {
        $this->logDB == null;
        $this->profileDB == null;
        $this->saveDB == null;
        $this->saver == null;
    }

    protected function init()
    {
        $dbhost = $this->hostname;
        $dbname = $this->analysisDatabase;
        $table = $this->summaryTable;
        try 
        {
            $this->logDB->query("create database if not exists $dbname");
            $this->logDB->query("drop table if exists $dbname.$table");
            $this->logDB->query("create table $dbname.$table (
                    hash VARCHAR(32) NOT NULL,
                    command TEXT,
                    normalized TEXT,
                    cost DOUBLE,
                    frequency INT,
                    issueCount INT,
                    issues TEXT,
                    errorCount INT,
                    warningCount INT,
                    allWarnings TEXT,
                    duration DOUBLE,
                    cpuUser DOUBLE,
                    cpuSystem DOUBLE,
                    score DOUBLE,
                    PRIMARY KEY (hash)
                );");
            $this->saveDB = new PDO("mysql:dbname=$dbname;host=$dbhost", 
                $this->logUser, $this->logPassword);
            $this->saveDB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->saver = $this->saveDB->prepare("INSERT INTO $dbname.$table 
                (hash, command, normalized, cost, frequency, issueCount, issues, errorCount, warningCount, 
                    allWarnings, duration, cpuUser, cpuSystem, score) 
                VALUES
                (:hash, :command, :normalized, :cost, :frequency, :issueCount, :issues, :errorCount, :warningCount, 
                    :allWarnings, :duration, :cpuUser, :cpuSystem, :score) 
                ON DUPLICATE KEY UPDATE cost=VALUES(cost),frequency=VALUES(frequency),duration=VALUES(duration),
                    cpuUser=VALUES(cpuUser),cpuSystem=VALUES(cpuSystem),score=VALUES(score)
                ;"); 
            $this->finder = $this->saveDB->prepare("SELECT * FROM $dbname.$table WHERE hash=:hash LIMIT 1;");
        } 
        catch (PDOException $e) 
        {
            writeLine("Error creating $dbname.$table!: $e->getMessage()<br/>");
            die();
        }
    }

    public function addError($error)
    {
        // $this->errors[$error]++;
        if (isset($this->errors[$error]) || array_key_exists($error, $this->errors)) 
        {
            $this->errors[$error]++;
        }
        else
        {
            $this->errors[$error] = 1;
        }
    }

    protected function showOptions()
    {
        writeLine('AMP: Analyzer for MySQL Performance v0.8');
        writeLine("hostname: '$this->hostname' // MySQL server location");
        writeLine("logUser: '$this->logUser' // account for user who can read mysql.general_log and create databases and tables");
        writeLine("logSource: '$this->logSource' // either 'table' or name of log file");
        writeLine("mode: '$this->mode' // either 'merge' or 'each'"); 
        writeLine("profileDatabase: '$this->profileDatabase' // name of the profiled database");
        writeLine("profileUser: '$this->profileUser' // name of the user for the profiled database");
        if ($this->maxLogs < 1) 
        {
            writeLine("maxLogs: $this->maxLogs // Processing all log entries");
        }
        else
        {
            writeLine("maxLogs: $this->maxLogs // Processing up to $this->maxLogs log entries");
        }
        writeLine("maxRows: '$this->maxRows' // maximum number of rows that should be retrievable in a profiled query");  
        writeLine("maxDuration: '$this->maxDuration' // maximum allowed seconds for a query to complete before generating a profiler warning");
        writeLine("analysisDatabase: '$this->analysisDatabase' // name of the analysis database");
        writeLine("summaryTable: '$this->summaryTable' // the name of the summary table in '$this->analysisDatabase'"); 
    }
        
    public function analyze()
    {
        $this->showOptions();
        
        $this->checkMyISAM();
        $entryCount = 0;
        $badCount = 0;
        $summaryCount = 0;
        
        $workFile = "workfile.txt";
        if (!file_exists($workFile)) 
        {
            $workFile = $this->logQuery();
        }
        $handle = fopen($workFile, "r") or die("can't open $workFile for reading!");

        $i = 0;
        while ($query = trim(fgets($handle))) 
        {
            // writeLine("query: $query");
            $i++;

            $entry = new Entry($query, $this);
            if ($entry->cost != null) 
            { 
                $entryCount++;
                $summaryCount += $this->addSummary($entry);
            }
            else
            {
                $badCount++;
            }

            if (($i % 1000) == 0) 
            {
                echo "$i.";
            }
        }
        fclose($handle);
        writeLine("\n$entryCount good entries processed");
        writeLine("$summaryCount unique summary records saved to $this->analysisDatabase.$this->summaryTable");
        writeLine("$badCount bad entries skipped:");
        foreach($this->errors as $key => $value)
        {
            writeLine("  $value $key");
        } 
    }
    
    protected function addSummary($entry)
    {
        $result = 1;
        $summary;
        if ($this->finder->execute(array(':hash' => $entry->hash))
            && ($row = $this->finder->fetch()))
        {
            $summary = $this->updateSummary($row, $entry);
            $result = 0; // not adding a new one, just updating
        }
        else {
            $summary = new Summary($entry);
        }

        $this->save($summary);
        return $result;
    }

    protected function updateSummary($row, $entry)
    {
        $summary = new Summary($entry);
        if ($summary->cost < $entry->cost)
        {
            $summary->cost = $entry->cost;
        }
        $summary->duration += $row{'duration'};
        $summary->cpuUser += $row{'cpuUser'};
        $summary->cpuSystem += $row{'cpuSystem'};
        $summary->frequency += $row{'frequency'};
        return $summary;
    }

    protected function save($summary)
    {
        try 
        {
            $summary->calcScore();
            $this->saver->bindValue(":hash", $summary->hash, PDO::PARAM_STR);
            $this->saver->bindValue(":command", $summary->sql, PDO::PARAM_STR);
            $this->saver->bindValue(":cost", $summary->cost);
            $this->saver->bindValue(":frequency", $summary->frequency, PDO::PARAM_INT);
            $this->saver->bindValue(":issueCount", $summary->issueCount, PDO::PARAM_INT);
            $this->saver->bindValue(":issues", $summary->issues, PDO::PARAM_STR);
            $this->saver->bindValue(":errorCount", $summary->errorCount, PDO::PARAM_INT);
            $this->saver->bindValue(":warningCount", $summary->warningCount, PDO::PARAM_INT);
            $this->saver->bindValue(":allWarnings", $summary->warnings, PDO::PARAM_STR);
            $this->saver->bindValue(":duration", $summary->duration);
            $this->saver->bindValue(":cpuUser", $summary->cpuUser);
            $this->saver->bindValue(":cpuSystem", $summary->cpuSystem);
            $this->saver->bindValue(":score", $summary->score);
            $this->saver->bindValue(":normalized", $summary->normalized, PDO::PARAM_STR);
            $this->saver->execute();
        } 
        catch (PDOException $ex) 
        {
            writeLine("Error saving summary: ".$ex->getMessage()."<br/>");
            die();
        }
    }

    protected function checkMyISAM()
    {
        $tables = $this->profileDB->query("SHOW TABLE STATUS FROM `$this->profileDatabase`;");
        while ($row = $tables->fetch(PDO::FETCH_ASSOC))
        {
            /*
            http://stackoverflow.com/a/3857366/74137
            PHP code to convert database to InnoDB:
            <?php
            // connect your database here first 
            // 

            // Actual code starts here 

            $sql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = 'your_database_name' 
                AND ENGINE = 'MyISAM'";

            $rs = mysqli_query($sql);

            while($row = mysqli_fetch_array($rs))
            {
                $tbl = $row[0];
                $sql = "ALTER TABLE `$tbl` ENGINE=INNODB";
                mysqli_query($sql);
            }
            ?>
            */
            if (strcasecmp($row{'Engine'},"MyISAM") == 0) 
            {
                writeLine("Recommendation: Use InnoDB rather than MyISAM.
See http://stackoverflow.com/a/3857366/74137 for more information.        
Run this SQL statement (in the mysql client, phpMyAdmin, or wherever) to retrieve 
all the MyISAM tables in your database.

Replace name_of_your_db with your database name.

SET @DATABASE_NAME = 'name_of_your_db';

SELECT  CONCAT('ALTER TABLE `', table_name, '` ENGINE=InnoDB;') AS sql_statements
FROM    information_schema.tables AS tb
WHERE   table_schema = @DATABASE_NAME
AND     `ENGINE` = 'MyISAM'
AND     `TABLE_TYPE` = 'BASE TABLE'
ORDER BY table_name DESC;
Then, copy the output and run as a new SQL query.");
                break;
            }
        }
    }
    
    protected function logQuery()
    { 
        $queries = [];
        $ver = $this->logDB->query("SELECT VERSION();")->fetch();
        // writeLine("ver:$ver");
        // in MySQL 5.5, only SELECT can be explained http://dev.mysql.com/doc/refman/5.5/en/explain.html
        // in MySQL 5.6 and up, all DML commands can be explained http://dev.mysql.com/doc/refman/5.6/en/explain.html 
        // $onlySelect = ($ver < "5.6");
        if ($ver < "5.7") 
        {
            $this->explainer = "explain extended ";
        }
        else 
        {
            $this->explainer = "explain ";
        }
        
        $onlySelect = true;
        if (strcasecmp($this->logSource, "db") === 0) 
        {
            writeLine("getting queries from DB");
            $ver = $this->logDB->server_info;
            $logQuery = "select argument from mysql.general_log
                where user_host like '$this->profileUser' and command_type = 'Query'";
            if (onlySelect) 
            {
                $logQuery = $logQuery." and argument like 'SELECT%'";
            } 
            else 
            {
                $logQuery = $logQuery." and argument not like 'SET%' and argument not like 'SHOW%'";
            }        

            if ($this->maxLogs > 0)
            {
                $logQuery = $logQuery." LIMIT $this->maxLogs";
            }
            
            $result = $this->logDB->query($logQuery);
            while ($row = $result->fetch()) 
            {
                $queries[] = $row['argument'];
            }
        }
        else 
        {
            writeLine("reading queries from $this->logSource");
            $queries = logQueries($this->logSource, $onlySelect, $this->maxLogs);

        }
        writeLine("creating workFile.txt for ".count($queries)." matching queries");
        $handle = fopen("workFile.txt", "w") or die("can't open workFile.txt");
        foreach ($queries as &$query) 
        {
            fwrite($handle, trim($query)."\n");
        }
        fclose($handle);
        $queries = null;
        return "workFile.txt";
    }
    
}

class Summary 
{
    public $sql;
    public $cost;
    public $frequency = 1;
    public $issueCount;
    public $errorCount;
    public $issues = '';
    public $warningCount;
    public $warnings = '';
    public $duration = 0.0;
    public $cpuUser = 0.0;
    public $cpuSystem = 0.0;
    public $score;
    public $normalized;
    public $hash;
    
    function __construct($entry)
    {
        $this->sql = $entry->sql;
        $this->normalized = $entry->normalized;
        $this->hash =$entry->hash;
        $this->cost = $entry->cost;
        $this->issueCount = count($entry->issues);
        $this->warningCount = $entry->warnings->warningCount;
        $this->errorCount = $entry->warnings->errorCount;
        foreach ($entry->issues as &$issue)
        {
            $this->issues .= $issue."<br/>\n";
        }
        foreach ($entry->warnings->list as &$warning)
        {
            $this->warnings .= $warning->message."<br/>\n";
        }
        foreach ($entry->profiles->list as &$profile)
        {
            $this->duration += $profile->duration;
            $this->cpuUser += $profile->cpuUser;
            $this->cpuSystem += $profile->cpuSystem;
        }
    }
    
    public function calcScore()
    {
        $this->score = $this->frequency * (
            $this->cost * 1000 
            + $this->warningCount * 2
            + $this->issueCount * 5 
            + $this->errorCount * 10 
            + $this->duration * 1000 
            + $this->cpuUser * 100
            + $this->cpuSystem * 200);
    }
}

class Entry 
{
    protected $server;
    protected $session;
    
    public $sql;
    public $cost = null;
    public $warnings = null;
    public $profiles = [];
    public $issues = [];
    public $score = -1;
    public $normalized;
    public $hash;

    function __construct($sql, $session) 
    {
        $this->session = $session;
        $this->server = $session->profileDB;
        $this->sql = $sql;
        $this->normalized = normalizeSQL($sql);
        if ($session->isMergeMode) 
        {
            $this->hash = md5($this->normalized);
        }
        else 
        {
            $this->hash = md5($this->sql);
        }
        $this->analyze();
    }

    public function analyze()
    {
        if (! $this->explainExtended()) return null;

        $this->processWarnings();
        $this->processCost();
        $this->profile();
        $this->calcScore();
        /*
        if (count($this->issues) > 0) 
        {
            writeLine($this->sql);
            foreach ($this->issues as $issue) 
            {
                writeLine("  ".$issue);
            }
        }
        */
        return $this;
    }

    protected function explainExtended()
    {
        $explanations = sqlRows($this->session->explainer . $this->sql, $this->server, $error);

        if (!$explanations) 
        {
            $this->session->addError($error);
            return NULL;
        }

        foreach ($explanations as &$row) 
        {
            $explanation = new Explanation($row);
            $this->explanations[] = $explanation;
            $explanation->analyze($this->issues, $this->session);
        }
        return $this;

    }
    
    protected function processWarnings()
    {
        $warnings = sqlRows("show warnings;", $this->server, $error);
        $this->warnings = new Warnings($warnings, $this->issues);
        return $this;
    }
    
    protected function processCost()
    {
        $this->cost = 0.0;
        $statuses = sqlRows("show status like 'last_query_cost';", $this->server, $error);
        // Last Query Cost http://dev.mysql.com/doc/refman/5.5/en/server-status-variables.html#statvar_Last_query_cost
        foreach($statuses as &$status) 
        {
            $this->cost += $status{'Value'};
        }
        if ($this->cost > 0.0) 
        {
            addIssue($issues, 'cost:'.$this->cost);
        }
        return $this;
    }
    
    protected function profile()
    {
        $this->profiles = new Profiles($this->sql, $this->server, $this->session);
        $this->profiles->analyze($issues);
        return $this;
    }

    protected function calcScore()
    {
        if ($this->score == -1)
        {
            // calculate score
        }
        return $this;
    }
   
}

class Explanation 
{
// EXPLAIN explained http://dev.mysql.com/doc/refman/5.5/en/explain-output.html 
// EXPLAIN EXTENDED http://dev.mysql.com/doc/refman/5.5/en/explain-extended.html
// id, select_type, table, type, possible_keys, key, key_len, ref, rows, filtered, Extra
    public $id;
    public $selectType;
    public $table;
    public $type;
    public $possibleKeys;
    public $key;
    public $key_len;
    public $ref;
    public $rows;
    public $filtered;
    public $extra;
    
    function __construct($row)
    {
        $this->id = $row{'id'};
        $this->selectType = $row{'select_type'};
        $this->table = $row{'table'};
        $this->type = $row{'type'};
        $this->possibleKeys = $row{'possible_keys'};
        $this->key = $row{'key'};
        $this->key_len = $row{'key_len'};
        $this->ref = $row{'ref'};
        $this->rows = $row{'rows'};
        $this->filtered = $row{'filtered'};
        $this->extra = $row{'Extra'};
    }
    
    static function badSelectTypes()
    {
        return ["dependent union", "dependent subquery", "uncacheable subquery", "uncacheable union"];
    }
    
    static function badJoinTypes()
    {
        return ["fulltext", "ref_or_null"];
    }
    
    function analyze(&$issues, $session) 
    {
        flagIssue($issues, $this->selectType, "Select Type", static::badSelectTypes());
        flagIssue($issues, $this->type, "Join Type", static::badJoinTypes());
        if (empty($this->key)) 
        {
            addIssue($issues, "No key. Possible keys='".$this->possibleKeys."'");
        }
        if ($this->rows > $session->maxRows) 
        {
            addIssue($issues, $this->rows." rows exceeds the row limit of ".$session->maxRows);
        }
    }
}

class Warnings 
{
    public $list = [];
    public $warningCount = 0;
    public $errorCount = 0;
    
    function __construct($rows, &$issues)
    {
        // SHOW WARNINGS http://dev.mysql.com/doc/refman/5.5/en/show-warnings.html
        foreach($rows as &$row) 
        {
            $level = strtolower($row{'Level'});
            $warning = new Warning($row{'Level'}, $row{'Code'}, $row{'Message'});
            $this->list[] = $warning;
            if ($level != 'note') 
            {
                addIssue($issues, $warning->level.':'.$warning->message);
                if ($level == 'warning') 
                {
                    $this->warningCount++;
                }
                else 
                {
                    $this->errorCount++;
                }

            }
        }
    }
    
}

class Warning 
{
    public $level;
    public $code;
    public $message;
    function __construct($level, $code, $message)
    {
        $this->level = $level;
        $this->code = $code;
        $this->message = $message;
    }
}

class Profiles 
{
    protected $server;
    protected $session;
    protected $sql;
    public $list = [];
    
    function __construct($sql, $server, $session)
    {
        $this->sql = $sql;
        $this->server = $server;
        $this->session = $session;
    }
    
    // http://dev.mysql.com/doc/refman/5.5/en/show-profile.html
    function analyze(&$issues)
    {
        // putting a transaction in here in case the sql command changes data
        $this->server->exec("START TRANSACTION");
        $this->server->exec("SET profiling = 1;");
        
        try
        {
            $q = $this->server->query($this->sql);
            $profiles = $this->server->query("SHOW PROFILES;");
            // writeLine(count($profiles)." Profiles=".json_encode($profiler));
            foreach ($profiles as $row)
            {
                $id = $row{'Query_ID'};
                $profiling = sqlRows(
                    "SELECT * FROM INFORMATION_SCHEMA.PROFILING WHERE QUERY_ID = ".$id." ORDER BY SEQ;", 
                    $this->server, $error);
                foreach ($profiling as &$info)
                {
                    $profile = new Profile($info);
                    $this->list[] = $profile;
                    $profile->analyze($issues, $this->session);
                }
            }
        }
        catch (PDOException $ex) 
        { 
            writeLine("BAD SQL:$this->sql");
            writeLine($ex->getMessage());
            // exit(1);
        }
        
        // turn profiling back off
        $this->server->query("SET profiling = 0;");
        // roll back any potential data changes
        $this->server->query("ROLLBACK");
    }
    
}

// http://dev.mysql.com/doc/refman/5.5/en/profiling-table.html
/*
INFORMATION_SCHEMA Name	SHOW Name	Remarks
QUERY_ID	Query_ID	 numeric statement identifier.
SEQ		    sequence number indicating the display order for rows with the same QUERY_ID value.
STATE	Status	 profiling state to which the row measurements apply.
DURATION	Duration	 indicates how long statement execution remained in the given state, in seconds.
CPU_USER	CPU_user	 indicates user CPU use, in seconds.
CPU_SYSTEM	CPU_system	 indicates system CPU use, in seconds.
CONTEXT_VOLUNTARY	Context_voluntary	 indicates how many voluntary context switches occurred.
CONTEXT_INVOLUNTARY	Context_involuntary	 indicates how many involuntary context switches occurred.
BLOCK_OPS_IN	Block_ops_in	 number of block input operations
BLOCK_OPS_OUT	Block_ops_out	 number of block output operations
MESSAGES_SENT	Messages_sent	 communication messages sent
MESSAGES_RECEIVED	Messages_received	 communication messages received
PAGE_FAULTS_MAJOR	Page_faults_major	 number of major page faults
PAGE_FAULTS_MINOR	Page_faults_minor	 number of minor page faults
SWAPS	Swaps	 how many swaps occurred
SOURCE_FUNCTION	Source_function	 Source code location information
SOURCE_FILE	Source_file	 Source code location information
SOURCE_LINE	Source_line	 Source code location information
*/
class Profile 
{
    public $queryId;
    public $seq;
    public $state;
    public $duration;
    public $query;
    public $cpuUser;
    public $cpuSystem;
    public $contextVoluntary;
    public $contextInvoluntary;
    public $blockOpsIn;
    public $blockOpsOut;
    public $messagesSent;
    public $messagesReceived;
    public $pageFaultsMajor;
    public $pageFaultsMinor;
    public $swaps;
    public $sourceFunction;
    public $sourceFile;
    public $sourceLine;
    public $score = -1;
    
    function __construct($row)
    {
        $this->queryId = $row{'QUERY_ID'};
        $this->seq = $row{'SEQ'};
        $this->duration = $row{'DURATION'};
        $this->state = $row{'STATE'};
        $this->cpuUser = $row{'CPU_USER'};
        $this->cpuSystem = $row{'CPU_SYSTEM'};
        $this->contextVoluntary = $row{'CONTEXT_VOLUNTARY'};
        $this->contextInvoluntary = $row{'CONTEXT_INVOLUNTARY'};
        $this->blockOpsIn = $row{'BLOCK_OPS_IN'};
        $this->blockOpsOut = $row{'BLOCK_OPS_OUT'};
        $this->messagesSent = $row{'MESSAGES_SENT'};
        $this->messagesReceived = $row{'MESSAGES_RECEIVED'};
        $this->pageFaultsMajor = $row{'PAGE_FAULTS_MAJOR'};
        $this->pageFaultsMinor = $row{'PAGE_FAULTS_MINOR'};
        $this->swaps = $row{'SWAPS'};
        $this->sourceFunction = $row{'SOURCE_FUNCTION'};
        $this->sourceFile = $row{'SOURCE_FILE'};
        $this->sourceLine = $row{'SOURCE_LINE'};
    }
    
    public function analyze(&$issues, $session) 
    {
        $this->score = 0.0;
        if ($this->duration > $session->maxDuration) 
        {
            $this->tally(10, $issues, "Duration ".$this->duration."  exceeds max duration of ".$session->maxDuration);
        }
    }
    
    public function tally($increment, &$issues, $entry)
    {
        $this->score += $increment;
        $issues[] = $entry;
    }
    
}
?>