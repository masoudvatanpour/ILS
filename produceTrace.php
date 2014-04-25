<?php
// include a configuration file
require "config.php";
// include a file with common functions
require "common.php";

/****** actual body of the script ******/
$activityScriptFile = 'activityScript.txt';
// processing input parameters
if(count($argv) > 1)
{
    $activityScriptFile = trim($argv[1]);
}
else
{
    echo "Please specify the activity script file name.\n".
            "If you don't have one, run 'php produceActivityScript.php'\n";
    exit;
}

$grid = array();
$destinationGroups = array();
$movableGroups = array();
$activityScript = array();

echo "Unserializing various data structures...\n";
if(file_exists($activityScriptFile))
{
    $activityScript = unserialize(file_get_contents($activityScriptFile));
}
else
{
    echo "ERROR! $activityScriptFile does not exist. Run 'php produceActivityScript.php' first to generate this file.\n";
    exit;
}

if(file_exists($gridFile))
    $grid = unserialize(file_get_contents($gridFile));
else
{
    echo "ERROR! $gridFile does not exist. Run 'php convertFloorplan.php' first to generate this file.\n";
    exit;
}

if(file_exists($destinationFile))
    $destinationGroups = unserialize(file_get_contents($destinationFile));
else
{
    echo "ERROR! $destinationFile does not exist. Run 'php convertFloorplan.php' first to generate this file.\n";
    exit;
}

if(file_exists($movableFile))
{
    $movableGroups = unserialize(file_get_contents($movableFile));
}
else
{
    echo "ERROR! $movableFile does not exist. Run 'php convertFloorplan.php' first to generate this file.\n";
    exit;
}

$img = @imagecreatefrompng($cleanFloorplan);
$black = imagecolorallocate($img, 0, 0, 0);
$green = imagecolorallocate($img, 0, 255, 0);
$imgWidth = imagesx($img);
$imgHeight = imagesy($img);
$pixelWidth = round($condoWidth/$imgWidth, 3);

$trace = array();
$scriptLength = count($activityScript);
$start = $activityScript[0];
$startSetSize = count($destinationGroups[$start]);
$startNode = $destinationGroups[$start][rand(0, $startSetSize - 1)];
$trace[] = $startNode;
for($i = 1; $i < $scriptLength; $i++)
{
    $goal = $activityScript[$i];
    $goalSetSize = count($destinationGroups[$goal]);
    $goalNode = $destinationGroups[$goal][rand(0, $goalSetSize - 1)];
    $pathSegment = findPath($startNode, $goalNode, $img);
    // get rid of the start node in the path segment
    // since it has already been included at the previous step
    $trace = array_merge($trace, array_slice($pathSegment, 1));
    // next path will start from the previous goal
    $startNode = $goalNode;
}
//$img2 = @imagecreatefrompng($cleanFloorplan);
//for($k = 1; $k < count($trace); $k++)
//{
//    $x1 = $grid[$trace[$k - 1]]['x'];
//    $y1 = $grid[$trace[$k - 1]]['y'];
//    $x2 = $grid[$trace[$k]]['x'];
//    $y2 = $grid[$trace[$k]]['y'];
//    imageline($img2, $x1, $y1, $x2, $y2, $black);
//}
//imagepng($img2, 'test.png');
//imagedestroy($img2);
// assuming that it takes around 100ms to travel 0.1m (roughly 500 ms - 0.5m) 
// between two grid cells
$timeUnit = round($gridStep/0.1)*100;
// in case the previous calculation evaluated to 0
$timeUnit = $timeUnit ? $timeUnit : 100;
$traceStr = '';
$timestamp = 0;
$waitThreshold = 10; // 10 percent of the times we have wating
for($k = 0; $k < count($trace); $k++)
{
    $x = $grid[$trace[$k]]['x'];
    $y = $grid[$trace[$k]]['y'];
    $randNum = rand(0, 100);
    if($randNum < $waitThreshold)
    {
        $randVal = rand(0, 100);
        $waitAmount = round($randVal*$timeUnit);
        $traceStr .= "#Wait for $waitAmount seconds ... att {$realCoord['x']}\t{$realCoord['y']} \n";
        $timestamp += $waitAmount;
    }
    
    $realCoord = convertPixelsToRealCoord($x, $y);
    $traceStr .= "$timestamp\t{$realCoord['x']}\t{$realCoord['y']}\n";
    $timestamp += $timeUnit;
}
$imageName = 'trace_'.str_replace('.txt', '', $activityScriptFile).'.png';
$fileName = 'RawTrace_'.$activityScriptFile;
echo "Saving trace image into $imageName and trace data into $fileName.\n".
"You may now copy the file $fileName into the Simulator/FinlaExps directory\n".
"and watch animation by running the following command:\n".
"java -jar dist/Simulator.jar 4 ".str_replace('.txt', '', $fileName)." Sensors";
file_put_contents($fileName, $traceStr);
imagepng($img, $imageName);
imagedestroy($img);
?>