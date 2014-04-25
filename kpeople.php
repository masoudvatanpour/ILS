<?php
// include a configuration file
require "config.php";
// include a file with common functions
require "common.php";
// include script for multiple execution

/****** actual body of the script ******/
    
//Run the produce Activity script multilpe times
$array = array(array("goal" => 2, "model" => "uniform", "fileName" => "activityScript0.txt"),
               array("goal" => 2, "model" => "uniform", "fileName" => "activityScript1.txt"),
               array("goal" => 2, "model" => "uniform", "fileName" => "activityScript2.txt"));
echo 'producing traces ... \n';
for($i=0; $i < sizeof($array); $i++)
{
    exec( "php produceActivityScript.php {$array[$i]['goal']} {$array[$i]['model']} {$array[$i]['fileName']}");
    exec( "php produceTrace.php {$array[$i]['fileName']}");
}
/*
$all = array();
//Combine the raw traces into one file
for($i=0; $i < sizeof($array); $i++){
    $handle = @fopen("RawTrace_{$array[$i]['fileName']}", "r");
    if ($handle){
        while (($buffer = fgets($handle, 4096)) !== false) {
            list($t, $x, $y ) = explode( ' ', trim($buffer) );
            $all['t'] = $t;
            echo "$t \n";
        }
        if (!feof($handle)) {
            echo "Error: unexpected fgets() fail\n";
        }
        fclose($handle);
    }
}
  */  
//    print_r(array_sort($people, 'age', SORT_DESC)); // Sort by oldest first
//    print_r(array_sort($people, 'surname', SORT_ASC)); // Sort by surname
?>