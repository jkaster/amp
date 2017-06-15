<?php
function sqlRows($sql, $db, &$error) 
{
    //$clean = mysql_real_escape_string($sql, $db);
    $error = "";
    try 
    {
        $query = $db->query($sql);

    }
    catch (PDOException $ex) 
    { 
        $error = $ex->getMessage();
        // writeLine("BAD SQL:$sql");
        // writeLine($ex->getMessage());
        return NULL;
    }

    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function iscommandline()
{
    if (PHP_SAPI === 'cli') 
    {
        return true;
    }
    else 
    {
        return false;
    }
}

/**
* Get options from the command line or web request
* 
* @param string $options
* @param array $longopts
* @return array
*/
function getoptreq ($options, $longopts)
{
   if (PHP_SAPI === 'cli' || empty($_SERVER['REMOTE_ADDR']))  // command line
   {
      return getopt($options, $longopts);
   }
   else if (isset($_REQUEST))  // web script
   {
      $found = array();

      $shortopts = preg_split('@([a-z0-9][:]{0,2})@i', $options, 0, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
      $opts = array_merge($shortopts, $longopts);

      foreach ($opts as &$opt)
      {
         if (substr($opt, -2) === '::')  // optional
         {
            $key = substr($opt, 0, -2);

            if (isset($_REQUEST[$key]) && !empty($_REQUEST[$key]))
               $found[$key] = $_REQUEST[$key];
            else if (isset($_REQUEST[$key]))
               $found[$key] = false;
         }
         else if (substr($opt, -1) === ':')  // required value
         {
            $key = substr($opt, 0, -1);

            if (isset($_REQUEST[$key]) && !empty($_REQUEST[$key]))
               $found[$key] = $_REQUEST[$key];
         }
         else if (ctype_alnum($opt))  // no value
         {
            if (isset($_REQUEST[$opt]))
               $found[$opt] = false;
         }
      }

      return $found;
   }

   return false;
}

function normalizeSQL($sql)
{
    // strip operator+quote values
    $opQuote = '/([\!\=\>\<])\s*([\'"][\s\w\d]+[\'"])/';
    $opQuoteStrip = '${1}""';
    // strip quote+operator values
    $quoteOp = '/([\'"][\s\w\d]+[\'"])\s*([\!\=\>\<])/';
    $quoteOpStrip = '""${1}';
    // strip operator+number values
    $opNum = '/([\!\=\>\<])\s*(\d+)/';
    $opNumStrip = '${1}0'; 
    // strip number+operator values
    $numOp = '/(\d+)\s*([\!\=\>\<])/';
    $numOpStrip = '0${1}'; 
    $btwOp = '/BETWEEN\s*(\d+)\s*AND\s*(\d+)/i';
    $btwStrip = 'BETWEEN 0 AND 1';
    $sql = preg_replace($opQuote, $opQuoteStrip, $sql);
    $sql = preg_replace($quoteOp, $quoteOpStrip, $sql);
    $sql = preg_replace($opNum, $opNumStrip, $sql);
    $sql = preg_replace($numOp, $numOpStrip, $sql);
    $sql = preg_replace($btwOp, $btwStrip, $sql);
    return $sql;
}

function logQueries($logFile, $onlySelect = false, $maxLogs = -1) 
{
    $queries = [];
    $pattern = '/\\s+\\d+\\s+Query\\s+(.*)/';
    $select = '/select .*/i';
    $notSetOrShow = '/^((set\\s+)|(show\\s+))/';
    $handle = @fopen($logFile, 'r');
    $row = 0;
    $matches = [];
    $done = false;
    $filter = $onlySelect ? $select : $notSetOrShow;
    if ($handle) 
    {
        while (($line = fgets($handle, 4096))) 
        {
            if (preg_match($pattern, $line, $matches)) 
            {
                foreach ($matches as &$match) 
                {
                    if (preg_match($filter, $match, $queryMatch))
                    {
                        $queries[] = $queryMatch[0];
                        $row++;
                    }
                }
            }
            if (($maxLogs !== -1) and ($row >= $maxLogs)) 
            {
                $done = true;
                break;
            }
        }
        if ((!$done) and (!feof($handle))) 
        {
            echo "Error: unexpected error reading $logFile\n";
        }
        fclose($handle);
    }
    return $queries;
}

function addIssue(&$issues, $entry) 
{
    // writeLine("ISSUE:".$entry);
    $issues[] = $entry;
}

function flagIssue(&$issues, $value, $flag, $flagValues) 
{
    if (in_array(strtolower($value),$flagValues)) 
    {
        addIssue($issues, $flag.' is '.$value);
    }    
}

function writeLine($line) 
{
    echo $line."\n";
}

function jsonFile($fileName, $obj)
{
    file_put_contents($fileName, utf8_encode(json_encode($obj, JSON_PRETTY_PRINT)));
    return $fileName;
}

function debug_to_console( $data ) 
{
    if ( is_array( $data ) )
        $output = "<script>console.log( 'Debug Objects: " . implode( ',', $data) . "' );</script>";
    else
        $output = "<script>console.log( 'Debug Objects: " . $data . "' );</script>";

    echo $output;
}
?>