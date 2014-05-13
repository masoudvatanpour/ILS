<?php
// include a configuration file
require "config.php";
// include a file with common functions
require "common.php";

// this script is very memory-consuming, so get rid of the memory limit on the php scripts
ini_set('memory_limit', '-1');

/****** various configurations and input parameters ******/
$placementType = '';
$numberOfSensors = 0;
$scoreFile = '';
$maxOverlap = 3;
$folder = '';
// flags for various types of placements
$plcmntManual = 0;
$plcmntRandom = 0;
$plcmntOptimal = 0;
// processing input parameters
if(isset($argv) && count($argv) > 1 || count($_GET) > 1)
{
    if(isset($argv[1]) && in_array($argv[1], $typesOfPlacement))
        $placementType = $argv[1];
    if(count($_GET) && isset($_GET['type']))
        $placementType = trim($_GET['type']);
    $numberOfSensors = isset($argv[2]) ? intval($argv[2]) : 0;
    // number of sensors can also be specified as a get parameter for random placements
    $numberOfSensors = count($_GET) && isset($_GET['number']) ? intval($_GET['number']) : $numberOfSensors;
    $scoreFile = isset($argv[3]) ? $argv[3] : '';
    $maxOverlap = isset($argv[4]) ? floatval($argv[4]) : $maxOverlap;
    if(isset($argv[5]) && floatval($argv[5]) > 0)
        $doorOpenProb = floatval($argv[5]);
    if(isset($argv[6]))
    {
        $folder = trim($argv[6]);
        if($folder && !is_dir($folder))
        {
            echo "Creating a folder $folder...\n";
            mkdir($folder);
        }
        // adding backslashes for Windows paths, will have to change this for a different system
        $folder .= "\\";
    }
    
    if($placementType)
    {
        switch($placementType)
        {
            case $typesOfPlacement[0]:
                $plcmntManual = 1; break;
            case $typesOfPlacement[1]:
                $plcmntRandom = 1; break;
            case $typesOfPlacement[2]:
                $plcmntOptimal = 1; break;
        }
    }
}
else
{
    echo "ERROR! Missing arguments.\n".
        "Usage: php placeSensors.php <type of placement> <number of sensors> <score filename>\n".
        "<max overlap> <probability of open door> <folder>\n\n".
        "<type of placement>\tone of ".count($typesOfPlacement)." options: ".implode(", ", $typesOfPlacement).
            " MANDATORY\n\n".
        "<number of sensors>\thow many sensors should be used in a placement MANDATORY\n\n".
        "<score filename>\tname of a file with a heat score, calculated by \n".
            "\t\t\tproduceHeatmap.php script. It is recommended to use \n".
            "\t\t\tthe smoothed version of the score (the file name\n".
            "\t\t\tstarts with 'smooth_score...')\n".
            "\t\t\tMANDATORY only for optimal placement\n\n".
        "<max overlap>\t\tpreferable number of overlapping sensors, depends on\n".
            "\t\t\tthe number of sensors and how much overlap is desired\n".
            "\t\t\t(default: $maxOverlap, try values between 3 and 6), not mandatory\n\n".
        "<probability of open door> how likely that the door is open throughout the day,\n".
            "\t\t\taffects the overall heat score assigned to a sensor seeing\n".
            "\t\t\tthrough a doorway (default: $doorOpenProb), not mandatory\n\n".
        "<folder>\t\tname of a folder where the output files (data, images) will\n".
            "\t\t\tbe stored (will be created if does not exist), not mandatory\n\n".
        "IMPORTANT! The order of arguments matters so if you wish to skip some and use\n".
            "the ones that appear later, just put some random characters as placeholders.\n\n";
    exit;
}

// checking how the script is run (cli or browser)
// if run in the browser, will output html with a resulting image
if(isset($_SERVER['REQUEST_METHOD']))
    $showImage = 1;
else
    $showImage = 0;

// flag for drawing the final optimized sensor placements over the map of density
$showSensorsOverDensity = 1;

/****** the end of configuration ******/


/****** actual body of the script ******/

$img = @imagecreatefrompng($cleanFloorplan);
$imgWidth = imagesx($img);
$imgHeight = imagesy($img);
$pixelWidth = round($condoWidth/$imgWidth, 3);
$pixelGridStep = round($imgWidth*$gridStep/$condoWidth);
$halfLowerPixelGridStep = floor($pixelGridStep / 2);
$halfUpperPixelGridStep = ceil($pixelGridStep / 2);  
$pixelGridDiag = round(sqrt(2*$pixelGridStep*$pixelGridStep), 2);
// the grid will start with a padding equal to a half-grid step
$gridPadding = round($pixelGridStep/2);
$black = imagecolorallocate($img, 0, 0, 0);
$white = imagecolorallocate($img, 255, 255, 255);
$green = imagecolorallocate($img, 0, 255, 0);
$blue = imagecolorallocate($img, 0, 0, 255);
$red = imagecolorallocate($img, 255, 0, 0);

$grid = array();
$score = array();
$pixelMatrix = array();
$wallMatrix = array();
$doorMatrix = array();
$existingPolygons = array();
$existingPolySerialized = "";

echo "Unserializing various data structures...\n";
// for manual and random placements score file is not required
if($plcmntOptimal)
{
    if(file_exists($scoreFile)) 
        $score = unserialize(file_get_contents($scoreFile));
    else
    {
        echo "ERROR! $scoreFile does not exist. Run 'php produceHeatmap.php' first to generate this file.\n";
        exit;
    }
    if(file_exists($gridFile))
    {
        $grid = unserialize(file_get_contents($gridFile));
        $gridSize = count($grid);
    }
    else
    {
        echo "ERROR! $gridFile does not exist. Run 'php convertFloorplan.php' first to generate this file.\n";
        exit;
    }
}

if(file_exists($pixelMatrixFile))
    $pixelMatrix = unserialize(file_get_contents($pixelMatrixFile));
else
{
    echo "ERROR! $pixelMatrixFile does not exist. Run 'php convertFloorplan.php' first to generate this file.\n";
    exit;
}

if(file_exists($wallMatrixFile))
    $wallMatrix = unserialize(file_get_contents($wallMatrixFile));
else
{
    echo "ERROR! $wallMatrixFile does not exist. Run 'php convertFloorplan.php' first to generate this file.\n";
    exit;
}

if(file_exists($doorMatrixFile))
    $doorMatrix = unserialize(file_get_contents($doorMatrixFile));
else
{
    echo "ERROR! $doorMatrixFile does not exist. Run 'php convertFloorplan.php' first to generate this file.\n";
    exit;
}

/***** Sensor placement related calculations *****/

// adjusted sensor range considered at the given height in pixels
$sensWidthPix = intval(($ceilingHeight / $sensorRangeAtHeight['height']) * $sensorRangeAtHeight['x'] / $pixelWidth);
$sensHeightPix = intval(($ceilingHeight / $sensorRangeAtHeight['height']) * $sensorRangeAtHeight['y'] / $pixelWidth);
// minimal padding between the wall and the actual sensor position in pixels
$sensPaddingPix = round(($sensorWidth / 2) / $pixelWidth);

// find possible coverage for horizontal and vertical orientation of the sensors
$horizSensorsFile = "horizSensors.txt";
$horizSensors = array();
if(file_exists($horizSensorsFile))
    $horizSensors = unserialize(file_get_contents($horizSensorsFile));
else
{
    $horizSensors = rayTraceSensorCoverage($sensWidthPix, $sensHeightPix);
    file_put_contents($horizSensorsFile, serialize($horizSensors));
}
// this number is used in calStats()
$sensorCoveredGridNumber = 0;
foreach($horizSensors as $x => $row)
    foreach($row as $y => $val)
        $sensorCoveredGridNumber++;

$vertSensorsFile = "vertSensors.txt";
$vertSensors = array();
if(file_exists($vertSensorsFile))
    $vertSensors = unserialize(file_get_contents($vertSensorsFile));
else
{
    $vertSensors = rayTraceSensorCoverage($sensHeightPix, $sensWidthPix);
    file_put_contents($vertSensorsFile, serialize($vertSensors));
}

//now that we have the score for every sensor position, let's find the best positions (greedily)
if($plcmntManual)
{
    switch($numberOfSensors)
    {
        case 5:
            $placemArr = array("h215|155", "h855|155", "v555|335", "h915|425", "h215|475");
            break;
        case 6:
            $placemArr = array("h215|155", "v595|245", "v905|165",
                "h605|535", "h915|425", "h215|475");
            break;
        case 7:
            $placemArr = array("v135|155", "v385|155", "v635|245",
        "v905|155", "h915|425", "h605|535", "h215|475");
            break;
        case 8:
            $placemArr = array("v135|155", "v355|155", "h625|115", "h625|315",
        "v905|155", "h915|425", "h605|535", "h215|475");
            break;
        case 9:
            $placemArr = array("v135|155", "v355|155", "h625|115", "h625|315",
                "v905|155", "h915|425", "h605|535", "v135|475", "v355|475");
            break;
        case 10:
            $placemArr = array("v105|155", "v305|155", "v505|155", "v705|155", "v905|155",
                "h615|335", "h915|425", "h605|535", "v135|475", "v335|475");
            break;
        case 11:
            $placemArr = array("v105|155", "v295|155", "v485|155", "v675|155", "h905|95", 
                "h905|225", "h915|425", "h615|335", "h605|535", "v335|475", "v135|475");
            break;
        case 12:
            $placemArr = array("v105|155", "v275|155", "v445|155", "h655|95", 
                "h655|225", "h905|95", "h905|225", "h915|425",
                "h615|335", "h605|535", "v135|475", "v335|475");
            break;
        case 13:
            $placemArr = array("h155|95", "h155|225", "h405|95", "h405|225", 
                "h655|95", "h655|225", "h905|95", "h905|225",
                "h915|425", "h615|335", "h605|535", "v135|475", "v335|475");
            break;
        case 14:
            $placemArr = array("h155|95", "h155|225", "h405|95", "h405|225", 
                "h655|95", "h655|225", "h905|95", "h905|225", "h915|425",
                "h615|335", "h605|535", "v105|475", "h325|405", "h325|535");
            break;
        case 15:
            $placemArr = array("h155|95", "h155|225", "h405|95", "h405|225", 
                "h655|95", "h655|225", "h905|95", "h905|225", "h595|525", "v795|425",
                "v965|425", "h595|345", "v105|475", "h325|405", "h325|535");
            break;
        case 16:
            $placemArr = array("h155|95", "h155|225", "h405|95", "h405|225", 
                "h655|95", "h655|225", "h905|95", "h905|225", "h595|525", "v795|425",
                "v965|425", "h595|345", "h345|415", "h125|415", "h345|535", "h125|535");
            break;
        case 17:
            $placemArr = array("h155|95", "h155|225", "h405|95", "h405|225", 
                "h655|95", "h655|225", "h905|95", "h905|225", "h595|525", 
                "h595|345", "v715|425", "v865|425", "v975|425", 
                "h345|415", "h125|415", "h345|535", "h125|535");
            break;
        case 18:
            $placemArr = array("h155|95", "h155|225", "h405|95", "h405|225", 
                "h655|95", "h655|225", "h905|95", "h905|225", 
                "h595|345", "v715|425", "v865|425", "v975|425", "v555|555",
                "v665|555", "h345|415", "h125|415", "h345|535", "h125|535");
            break;
        case 19:
            $placemArr = array("h155|95", "h155|225", "h375|95", "h375|225", 
                "h595|95", "h595|225", "h595|345", "v745|135", "v745|305", 
                "h905|95", "h905|225", "v865|425", "v975|425", "v555|555",
                "v665|555", "h345|415", "h125|415", "h345|535", "h125|535");
            break;
        case 20:
            $placemArr = array("h145|95", "h145|215", "h335|95", "h335|215",
                "h525|95", "h525|215", "h715|95", "h715|215", "h905|95", "h905|215",
                "h595|345", "v715|425", "v865|425", "v975|425", "v555|555",
                "v665|555", "h345|415", "h125|415", "h345|535", "h125|535");
        default:
            echo "There is no manual placement configuration for $numberOfSensors sensors\n";
            exit;
    }
    outputPlacementInfo($placemArr, $folder."polygons-manual", "", 0, $folder."SensorsMan$numberOfSensors");
    //outputPlacementInfo($placemArr, "", "", 1, "", $folder."SensorsManFau$numberOfSensors");
    
    $imageName = "polygons-manual-$numberOfSensors-sens.png";
    if($showImage)
        echo '<img src="'.$folder.$imageName.'" border="1px">';
    
    
//    
//    echo "total time for creating maps: ".(microtime(TRUE)-$s)."\n";
//    if(!$sensorScoreMapFileExists)
//        file_put_contents($sensorScoreMapFile, serialize($sensorScoreMap));
//    if(!$densityPerSensorFileExists)
//        file_put_contents($densityPerSensorFile, serialize($densityPerSensor));
//    if(!$behindDoorMapFileExists)
//        file_put_contents($behindDoorMapFile, serialize($behindDoorMap));
//    
//    $s = microtime(TRUE);
//    $placemArr = array();
//    //$initialDensityPerSensor = $densityPerSensor;
//    recurPlacementUpdateHeatmap($placemArr, $numberOfSensors);
//    //file_put_contents($placementFile, serialize($placemArr));
//    echo "total time for finding a placement: ".(microtime(TRUE)-$s)."\n";
//    outputPlacementInfo($placemArr, $folder."polygons$fileNameSuffixPic", "", 1,$folder."SensorsOpt$fileNameSuffix", "", $img);
//    outputPlacementInfo($placemArr, "", "", 1, "", $folder."SensorsFau$fileNameSuffix");
//    
//    
}

if($plcmntRandom)
{
    $suffix = isset($_GET['suffix']) ? '.'.intval($_GET['suffix']) : "";
    $positions = array();
    foreach($horizSensors as $x => $row)
    {
        foreach($row as $y => $val)
        {
            $positions[] = "h$x|$y";
            $positions[] = "v$x|$y";
        }
    }
    $size = count($positions);
    $bucketSize = round($size/$numberOfSensors);
    //echo "$size\n";
    $count = 0;
    $takenPos = array();
    $placemArr = array();
    while($count < $numberOfSensors)
    {
        $range = ($count + 1)*$bucketSize;
        $range = $range > $size ? $size : $range;
        $sensor = $positions[rand($range - $bucketSize, $range - 1)];
        $pos = substr($sensor, 1);
        if(!isset($takenPos[$pos]))
        {
            $takenPos[$pos] = 1;
            $placemArr[] = $sensor;
            $count++;
        }
    }
    outputPlacementInfo($placemArr, $folder."polygons-random-$numberOfSensors$suffix", 
            "", 0, $folder."SensorsRan$numberOfSensors$suffix");
    $imageName = "polygons-random-$numberOfSensors$suffix-$numberOfSensors-sens.png";
    if($showImage)
        echo '<img src="'.$folder.$imageName.'" border="1px">';
}

if($plcmntOptimal)
{
    // if number of sensors is inputted as a float, it will reflect in the file names
    $fileNameSuffix = isset($argv[2]) ? trim($argv[2]) : "";
    $fileNameSuffix .= ".$maxOverlap";
    $fileNameSuffixPic = "$fileNameSuffix-door$doorOpenProb";
    $placementFile = $folder."placement$fileNameSuffix.txt";
    if(file_exists($placementFile))
    {
        $placemArr = unserialize(file_get_contents($placementFile));
        //outputPlacementInfo($placemArr, $folder."polygons$fileNameSuffixPic", "", 1, "Opt$fileNameSuffix");
        outputPlacementInfo($placemArr, $folder."polygons$fileNameSuffixPic.1", "", 1);
        //outputPlacementInfo($placemArr, "", "", 1, "", $folder."SensorsFau$fileNameSuffix");
    }
    else
    {
        $s = microtime(TRUE);

        $sensorScoreMap = array();
        $sensorScoreMapFile = "sensorPosSum_door$doorOpenProb"."_$scoreFile.txt";
        $sensorScoreMapFileExists = file_exists($sensorScoreMapFile);
        if($sensorScoreMapFileExists)
            $sensorScoreMap = unserialize(file_get_contents($sensorScoreMapFile));

        $densityPerSensor = array();
        $densityPerSensorFile = $folder."densitySum_max$maxOverlap"."_door$doorOpenProb"."_$scoreFile.txt";
        $densityPerSensorFileExists = file_exists($densityPerSensorFile);
        if($densityPerSensorFileExists)
            $densityPerSensor = unserialize(file_get_contents($densityPerSensorFile));

        $behindDoorMap = array();
        $behindDoorMapFile = "behindDoorMap.txt";
        $behindDoorMapFileExists = file_exists($behindDoorMapFile);
        if($behindDoorMapFileExists)
            $behindDoorMap = unserialize(file_get_contents($behindDoorMapFile));

        $heatScoreMap = array();
        foreach($score as $index=>$val)
        {
            $pix = $grid[$index]['x']." ".$grid[$index]['y'];
            $heatScoreMap[$pix] = $val;
        }

        $maxGridCoverage = count($grid);
        $totalHeatScore = array_sum($score);
        $maxHeatScore = max($score);
        echo "max overlap $maxOverlap\n";
        $heatThreshold = $maxHeatScore/$maxOverlap;
        echo "heat divider $heatThreshold\n";
        $densityMap = array();
        foreach($heatScoreMap as $pix=>$val)
        {
            $densityMap[$pix] = ceil($val/$heatThreshold)<$maxOverlap ?
                ceil($val/$heatThreshold) : $maxOverlap;
        }
        $totalDensity = array_sum($densityMap);

        if($showSensorsOverDensity)
        {
            foreach($densityMap as $pix=>$val) 
            {
                $p = explode(" ", $pix);
                $xPixel = $p[0];
                $yPixel = $p[1];
                $color = imagecolorallocate($img, 255, 255 - round(255 * $val / $maxOverlap), 
                        255 - round(255 * $val / $maxOverlap));
                for($x = $xPixel - $halfLowerPixelGridStep; $x < $xPixel + $halfUpperPixelGridStep ; $x++){
                    for($y = $yPixel - $halfLowerPixelGridStep; $y < $yPixel + $halfUpperPixelGridStep ; $y++){
                        if($x >= 0 && $x < $imgWidth && $y >= 0 && $y < $imgHeight){
                            $currentColor = imagecolorat($img, $x, $y);
                            if($currentColor != $colors['walls'] && $currentColor != $colors['obstacle'])
                                imagesetpixel($img, $x, $y, $color);
                        }
                    }
                }
            }
//                $imageName = $folder."density$fileNameSuffix.png";
//                imagepng($img, $imageName);
//                imagedestroy($img);
//                exit;
        }

        // define a reverse hash map for looking up grid within specific sensor position
        // this map is faster to calculate every time than to read from a file (takes 3 minutes!)
        //$s = microtime(TRUE);
        $gridBelongsToSensors = array();
        $gridMap = array();
        foreach($horizSensors as $x => $row)
        {
            foreach($row as $y => $val)
            {
                $gridMap["h$x|$y"] = $val['grid'];
                $gridMap["v$x|$y"] = $vertSensors[$x][$y]['grid'];
                if(!$behindDoorMapFileExists)
                {
                    if(isset($val['door']))
                    {
                        $doorArr = explode(";", trim($val['door'], ";"));
                        $behindDoorMap["h$x|$y"] = array_fill_keys($doorArr, 1);
                    }
                    if(isset($vertSensors[$x][$y]['door']))
                    {   
                        $doorArr = explode(";", trim($vertSensors[$x][$y]['door'], ";"));
                        $behindDoorMap["v$x|$y"] = array_fill_keys($doorArr, 1);
                    }
                }
                $coveredPoints = explode(";", trim($val['covered'], ";"));
                if(!$densityPerSensorFileExists)
                    $densityPerSensor["h$x|$y"] = 0;
                if(!$sensorScoreMapFileExists)
                    $sensorScoreMap["h$x|$y"] = 0;
                foreach($coveredPoints as $pix)
                {
                    if((!$sensorScoreMapFileExists || !$densityPerSensorFileExists) && isset($heatScoreMap[$pix]))
                    {
                        if(isset($behindDoorMap["h$x|$y"][$pix]))
                        {
                            if(!$sensorScoreMapFileExists)
                                $sensorScoreMap["h$x|$y"] += $doorOpenProb*$heatScoreMap[$pix];
                            if(!$densityPerSensorFileExists)
                                $densityPerSensor["h$x|$y"] += $doorOpenProb*$densityMap[$pix];
                        }
                        else
                        {
                            if(!$sensorScoreMapFileExists)
                                $sensorScoreMap["h$x|$y"] += $heatScoreMap[$pix];
                            if(!$densityPerSensorFileExists)
                                $densityPerSensor["h$x|$y"] += $densityMap[$pix];
                        }
                    }
                    if(!isset($gridBelongsToSensors[$pix]))
                        $gridBelongsToSensors[$pix] = array();
                    $gridBelongsToSensors[$pix][] = "h$x|$y";
                }

                $coveredPoints = explode(";", trim($vertSensors[$x][$y]['covered'], ";"));
                if(!$densityPerSensorFileExists)
                    $densityPerSensor["v$x|$y"] = 0;
                if(!$sensorScoreMapFileExists)
                    $sensorScoreMap["v$x|$y"] = 0;
                foreach($coveredPoints as $pix)
                {
                    if((!$sensorScoreMapFileExists || !$densityPerSensorFileExists) && isset($heatScoreMap[$pix]))
                    {
                        if(isset($behindDoorMap["v$x|$y"][$pix]))
                        {
                            if(!$sensorScoreMapFileExists)
                                $sensorScoreMap["v$x|$y"] += $doorOpenProb*$heatScoreMap[$pix];
                            if(!$densityPerSensorFileExists)
                                $densityPerSensor["v$x|$y"] += $doorOpenProb*$densityMap[$pix];
                        }
                        else
                        {
                            if(!$sensorScoreMapFileExists)
                                $sensorScoreMap["v$x|$y"] += $heatScoreMap[$pix];
                            if(!$densityPerSensorFileExists)
                                $densityPerSensor["v$x|$y"] += $densityMap[$pix];
                        }
                    }
                    if(!isset($gridBelongsToSensors[$pix]))
                        $gridBelongsToSensors[$pix] = array();
                    $gridBelongsToSensors[$pix][] = "v$x|$y";
                }
                //echo "h$x|$y score {$densityPerSensor["h$x|$y"]}; v$x|$y score {$densityPerSensor["v$x|$y"]}\n";
            }
        }
//            echo count($gridBelongsToSensors)."; memory: ".memory_get_usage().
//                    "; real memory: ".memory_get_usage(true)."\n";
        echo "total time for creating maps: ".(microtime(TRUE)-$s)."\n";
        if(!$sensorScoreMapFileExists)
            file_put_contents($sensorScoreMapFile, serialize($sensorScoreMap));
        if(!$densityPerSensorFileExists)
            file_put_contents($densityPerSensorFile, serialize($densityPerSensor));
        if(!$behindDoorMapFileExists)
            file_put_contents($behindDoorMapFile, serialize($behindDoorMap));

        $s = microtime(TRUE);
        $placemArr = array();
        //$initialDensityPerSensor = $densityPerSensor;
        recurPlacementUpdateHeatmap($placemArr, $numberOfSensors);
        //file_put_contents($placementFile, serialize($placemArr));
        echo "total time for finding a placement: ".(microtime(TRUE)-$s)."\n";
        outputPlacementInfo($placemArr, $folder."polygons$fileNameSuffixPic", "", 1, 
                $folder."SensorsOpt$fileNameSuffix", "", $img);
        outputPlacementInfo($placemArr, "", "", 1, "", $folder."SensorsFau$fileNameSuffix");
    }
}

//some visual tests
//findPath($destinationGroups[2][0], $destinationGroups[0][0]);
//findPath($destinationGroups[2][0], $destinationGroups[12][0]);
//$ar = rayTraceSensorCoverage($sensWidthPix, $sensHeightPix, "", 545, 375); //print_r($ar);
//$ar = rayTraceSensorCoverage($sensHeightPix, $sensWidthPix, "", 755, 335); //print_r($ar);
//drawPlacement(array("h545|375", "v755|335"), $img);
//sensorPoly("h545|375", $img);
//sensorPoly("v755|335", $img);
//sensorPoly("h405|365", $img, 1);
//sensorPoly("v445|505", $img, 2);
//sensorPoly("v545|305", $img, 1);
//sensorPoly("v545|445", $img, 2);
//sensorPoly("v555|355", $img, 1);
//sensorPoly("h655|315", $img, 2);

$newPolySerialized = serialize($existingPolygons);
if(count($existingPolygons) && $existingPolySerialized != $newPolySerialized)
{
    file_put_contents($existPolyFile, $newPolySerialized);
}

if($showImage && !$plcmntRandom && !$plcmntManual)
{
    $imageName = "new_floorplan.png";
    imagepng($img, $imageName);
    echo '<img src="'.$imageName.'" border="1px">';
}
// suppres a warning in case the image has already been destroyed
@imagedestroy($img);
?>