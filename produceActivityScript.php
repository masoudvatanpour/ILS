<?php
// include a configuration file
require "config.php";
// include a file with common functions
require "common.php";

/****** actual body of the script ******/

// default values, if nothing is passed on command line
$mobilityModel = "uniform";
$numberOfGoals = 0;
$activityScriptFile = "activityScript.txt";

// processing input parameters
if(count($argv) > 1)
{
    for($i = 1; $i < count($argv); $i++)
    {
        if(in_array($argv[$i], array_keys($mobilityModelWeights)))
            $mobilityModel = $argv[$i];
        elseif(intval($argv[$i]) > 0)
            $numberOfGoals = intval($argv[$i]);
        elseif(trim($argv[$i]))
            $activityScriptFile = trim($argv[$i]);
    }
}
if(count($argv) <= 1 || $numberOfGoals == 0)
{
    echo "Please specify the number of goals (i.e., how many transitions\n".
        "between different pairs of objects should be made)\n".
        "and/or a type of a mobility model (".
        count($mobilityModelWeights)." options: ".
        implode(", ", array_keys($mobilityModelWeights)).", default is uniform)\n".
        "and the name of the output file (not mandatory).\n";
    exit;
}

$destinationGroups = array();

echo "Unserializing destinations data structure...\n";
if(file_exists($destinationFile))
    $destinationGroups = unserialize(file_get_contents($destinationFile));
else
{
    echo "ERROR! $destinationFile does not exist. Run 'php convertFloorplan.php' first to generate this file.\n";
    exit;
}

// weights of destination/goal objects
$destinationWeight = $mobilityModelWeights[$mobilityModel];

$minimumWeight = 0;
$numberDestinationGroups = count($destinationGroups);
echo $numberDestinationGroups;
for($i = 0; $i < $numberDestinationGroups; $i++)
{
    if($destinationWeight[$i] < $minimumWeight || !$minimumWeight)
        $minimumWeight = $destinationWeight[$i];
}
if(!$minimumWeight)
{
    echo "ERROR! Mobility model is not properly specified (all weights appear zero).\n";
    exit;
}
// a multiplier that assures that the smallest number is an integer
$multiplier = round(1 / $minimumWeight);
$activityScript = array();
$randToDestination = array();
for($i = 0; $i < $numberDestinationGroups; $i++)
{
    $range = round($multiplier*$destinationWeight[$i]);
    for($j = 0; $j < $range; $j++)
    {
        $randToDestination[] = $i;
    }
}
//print_r($randToDestination);
$randRange = count($randToDestination) - 1;

while(count($activityScript) != $numberOfGoals)
{
    $ind = rand(0, $randRange);
    // if the new goal is the same as the previous, skip it
    // (cannot build a path from an object to itself)
    if(end($activityScript) == $randToDestination[$ind])
        continue;
    $activityScript[] = $randToDestination[$ind];
}
//print_r($activityScript);
echo "activity script is saved into $activityScriptFile.\n";
file_put_contents($activityScriptFile, serialize($activityScript));
?>