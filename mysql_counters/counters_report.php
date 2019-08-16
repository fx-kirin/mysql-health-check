<?php

/**
* Copyright (c) 2009-2014 Vladimir Fedorkov (http://astellar.com/)
* All rights reserved.                                                         
*                                                                              
* Redistribution and use in source and binary forms, with or without
* modification, are permitted provided that the following conditions
* are met:
* 1. Redistributions of source code must retain the above copyright
*    notice, this list of conditions and the following disclaimer.
* 2. Redistributions in binary form must reproduce the above copyright
*    notice, this list of conditions and the following disclaimer in the
*    documentation and/or other materials provided with the distribution.
* 
* THIS SOFTWARE IS PROVIDED BY THE AUTHOR AND CONTRIBUTORS ``AS IS'' AND
* ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
* IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
* ARE DISCLAIMED.  IN NO EVENT SHALL THE AUTHOR OR CONTRIBUTORS BE LIABLE
* FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
* DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS
* OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION)
* HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
* LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY
* OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF
* SUCH DAMAGE.
*/

//error_reporting(0);

include 'fix_mysql.inc.php';

//Host title used to identify host in history table
$host_title = $argv[1];
$mysql_host = $argv[2];
$mysql_user = $argv[3];
$mysql_pass = @$argv[4];
$mysql_port = @$argv[5];

if (empty($host_title)) 
    die("Stop. Can't continue without host title. Please see check_run.sh for details");
if (empty($mysql_host) || empty($mysql_user))
    die("Stop. Can't continue without MySQL credentials. Please edit MYSQL_HOST and MYSQL_USER in check_run.sh");


// Configuration 

// Where to store historical data
$daily_db = "astellar";
$daily_table = "daily_stats";

// Pause between stats collection, seconds.
$gather_time = 10;

// Number of data collection (default = 3: uptime, live delta1 and live delta2)
$gather_passes = 3;

/*
  vars are stats values
  [name] => [current value], [relative1], [relative2], etc
*/
$vars = array();

function get_stats()
{
    global $gather_passes, $gather_time, $host_title;
    global $mysql_user, $mysql_pass, $mysql_host, $mysql_port;
    $stats = array();
    mysql_connect("$mysql_host:$mysql_port", $mysql_user, $mysql_pass) or die("Can't connect to MySQL, please check connection parameters reason: " . mysql_error());

    echo "collecting data for $host_title, passes: ";
	for($i = 0; $i < $gather_passes; $i++)
	{
	    // let counters change
	    if ($i > 0) sleep($gather_time);

	    // gather them
		$r = mysql_query("SHOW GLOBAL STATUS");
		while($a = mysql_fetch_assoc($r))
		{
		    $name = $a["Variable_name"];
		    $value = (int)$a["Value"];
		    //print $name . " | " . (() ? "1" : "0") . " | " . $value . " " . $a["Value"] . "\n";
		    if($a["Value"] == "$value") 
		    {
		        //first value?
		        if(empty($vars[$name])) 
		        {
		        	$vars[$name][] = $stats[$name] = $value;
		        } else {
		        	// make second value relative
		        	$vars[$name][] = $value - $stats[$name];
		        	$stats[$name] = $value;
		        }
		    }
		}
		echo $i + 1 . "/" . $gather_passes . " ";
	}
	echo "Done!\n";
    return $vars;
}

function store_raw_stats($vars)
{
    global $host_title, $daily_db, $daily_table;

    //use dailies DB
    mysql_select_db($daily_db) or die("No $daily_db database found, historical data disabled.\n");

    foreach($vars as $name => $val)
    {
        $value = $val[0];
        //echo "Inserting $name => $value pair\n";

        $query = "INSERT INTO $daily_table 
                (st_collect_date, st_collect_host, 
                st_name, st_value, st_value_raw, st_added)
            VALUES 
                (NOW(), '$host_title', 
                '$name', '$value', '$value', NOW())
                ON DUPLICATE KEY UPDATE st_value = '$value', st_value_raw = '$value', st_added = NOW() ";

        $r = mysql_query($query) or die("Can't insert daily data. Reason: " . mysql_error());
    }
}

function relative($stat_name, $pos = 0)
{
    global $vars;
    global $gather_passes;
    if ($pos >= $gather_passes) print "passes count exceeded for $stat_name";
	return ((float)$vars["$stat_name"][$pos])/$vars["Uptime"][$pos];
}

function fancy($stat_name, $pos = 0)
{
    global $vars;
	return number_format(relative($stat_name, $pos));
}

function rw_rate($pos = 0)
{
    global $gather_passes;
    if ($pos >= $gather_passes) print "passes count exceeded";
	$rw_value = @(relative("Com_select", $pos) / (relative("Com_update", $pos) + relative("Com_insert", $pos) + relative("Com_delete", $pos)));
    if (!$rw_value) $rw_value = 0;
    return $rw_value;
}

function fancy_rate($pos = 0)
{
	return number_format(rw_rate($pos));
}

function row($stat_name, $max_review_no = 0, $title = "")
{
    global $vars;

    global $gather_passes;
    if ($max_review_no >= $gather_passes) print "Can't make row: passes count exceeded for $stat_name";
    $title = empty($title) ? $stat_name : $title . " rate";
    printf("%1$-40s", $title);
    for ($i = 0; $i <= $max_review_no; $i++ ) print fancy($stat_name, $i) . "\t";
    print "\n";
}

//Obtain live statistics from MySQL server
$vars = get_stats();

// ***** REPORT *****

print "=========================\n";
print "Details and explanations:\nhttp://astellar.com/mysql-health-check/metrics/\n";
print "=========================\n";
printf("%1$-40s", "Per second averages:"); print "Uptime\tLive#1\tLive#2\n";
print "== Load ==\n";
printf("%1$-40s", "Questions:");   print fancy("Questions") .  "\t" . fancy("Questions", 1) .  "\t" . fancy("Questions",2).  "\n";
printf("%1$-40s", "Queries:");     print fancy("Queries") .    "\t" . fancy("Queries", 1) .    "\t" . fancy("Queries",2).  "\n";
print "== Rates ==\n";
printf("%1$-40s", "Select rate:"); print fancy("Com_select") . "\t" . fancy("Com_select", 1) . "\t" . fancy("Com_select",2).  "\n";
printf("%1$-40s", "Insert rate:"); print fancy("Com_insert") . "\t" . fancy("Com_insert", 1) . "\t" . fancy("Com_insert", 2).  "\n";
printf("%1$-40s", "Update rate:"); print fancy("Com_update") . "\t" . fancy("Com_update", 1) . "\t" . fancy("Com_update", 2).  "\n";
printf("%1$-40s", "Delete rate:"); print fancy("Com_delete") . "\t" . fancy("Com_delete", 1) . "\t" . fancy("Com_delete", 2).  "\n";

printf("%1$-40s", "R/W rate:"); print fancy_rate(0) . "\t" . fancy_rate(1) . "\t" . fancy_rate(2).  "\n";

print "== MySQL IO pressure ==\n";
row("Handler_read_rnd", 2);
row("Innodb_data_writes", 2);
row("Innodb_data_reads", 2);
row("Innodb_dblwr_writes", 2);
row("Innodb_log_writes", 2);

//row("Innodb_pages_read", 2);
//row("Innodb_data_reads", 2);

print "== Key buffer efficiency ==\n";
row("Key_read_requests", 2);
row("Key_reads", 2);

print "== Query-related stats ==\n";
row("Select_scan", 2);
row("Select_full_join", 2);
row("Created_tmp_tables", 2);
row("Created_tmp_disk_tables", 2);

print "== MySQL Locking ==\n";
row("Innodb_log_waits", 2);
row("Table_locks_waited", 2);

print "== MySQL connections ==\n";
row("Threads_created", 2);
row("Connections", 2);
row("Aborted_connects", 2);


print "== Qcache ==\n";
print "Qcache hits total:\t\t" . number_format($vars["Qcache_hits"][0]) . "\n";
print "Qcache inserts:   \t\t" . number_format($vars["Qcache_inserts"][0]) . "\n";
print "Hits/Selects rate:\t\t" . number_format((float)$vars["Qcache_hits"][0]/$vars["Com_select"][0], 2) . "\n";

// history DB part
store_raw_stats($vars);

?>
