<?php
// include a configuration file
require "config.php";
// include a file with common functions
require "common.php";

$mobilityModel = '';
// processing input parameters
if(count($argv) > 1)
{
    for($i = 1; $i < count($argv); $i++)
    {
        if(in_array($argv[$i], array_keys($mobilityModelWeights)))
            $mobilityModel = $argv[$i];
        elseif(floatval($argv[$i]) > 0)
            $obstacleGridPercent = floatval($argv[$i]);
    }
}

if(!$mobilityModel)
{
    echo "Please specify a type of a mobility model (".
        count($mobilityModelWeights)." options: ".
        implode(", ", array_keys($mobilityModelWeights))."\n".
        "and a percent of obstructed grid (as a fraction of 1, default $obstacleGridPercent) .\n";
    exit;
}

/****** actual body of the script ******/

$scoreFile = "score_".$mobilityModel."_".$obstacleGridPercent."noise.txt";
$grid = array();
$pixelMatrix = array();
$destinationGroups = array();
$movableGroups = array();
$score = array();

echo "Unserializing various data structures...\n";
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
if(file_exists($pixelMatrixFile))
    $pixelMatrix = unserialize(file_get_contents($pixelMatrixFile));
else
{
    echo "ERROR! $pixelMatrixFile does not exist. Run 'php convertFloorplan.php' first to generate this file.\n";
    exit;
}

if(file_exists($scoreFile))
    $score = unserialize(file_get_contents ($scoreFile));
else 
{
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
        echo "ERROR! $movableGroupsFile does not exist. Run 'php convertFloorplan.php' first to generate this file.\n";
        exit;
    }

    $img = @imagecreatefrompng($cleanFloorplan);
    $black = imagecolorallocate($img, 0, 0, 0);
    $green = imagecolorallocate($img, 0, 255, 0);
    // weights of destination/goal objects
    $destinationWeight = $mobilityModelWeights[$mobilityModel];
    // initialize heatmap score array
    for($i = 0; $i < $gridSize; $i++)
    {
        $score[$i] = 0;
    }

    $minimumWeight = 0;
    $numberDestinationGroups = count($destinationGroups);
    $maxSetSize = 0;
    for($i = 0; $i < $numberDestinationGroups; $i++)
    {
        if($destinationWeight[$i] < $minimumWeight || !$minimumWeight)
            $minimumWeight = $destinationWeight[$i];
        if(count($destinationGroups[$i]) > $maxSetSize)
            $maxSetSize = count($destinationGroups[$i]);
    }
    if(!$minimumWeight)
    {
        echo "ERROR! Mobility model is not properly specified (all weights appear zero).\n";
        exit;
    }
    // a multiplier that assures that the smallest number is an integer
    // and also mutliplies by an integer of choice 
    $multiplier = round(1 / $minimumWeight);

    $destMaxNumberRun = array();
    $numberDestinationGroups = count($destinationGroups);
    for($i = 0; $i < $numberDestinationGroups; $i++)
    {
        // "2" in the following formula ensures that every destination has at least two paths
        // (that is, it becomes a source and a goal at least once)
        // that is, it is connected with at least two paths with every other destination
        // multiplied byt he number of grod points i nthe biggest destination group
        $destMaxNumberRun[$i] = round($multiplier * $destinationWeight[$i]) * 
            2 * ($numberDestinationGroups - 1) * $maxSetSize;
    }

    echo "initial number of runs:\n";
    print_r($destMaxNumberRun);
    echo "total sum: ".array_sum($destMaxNumberRun)."\n";

    $s = microtime(TRUE);
    for($i = 0; $i < count($destinationGroups); $i++)
    {
        $s1 = microtime(true);
        $groupSize = count($destinationGroups[$i]);
        $wrapAround = intval($maxSetSize/$groupSize);
        $leftOver = $maxSetSize%$groupSize;
        for($j = 0; $j < count($destinationGroups); $j++)
        {
            if($i == $j)
                continue;
            echo "current pair $i - $j\n";
            $goalSetSize = count($destinationGroups[$j]);
            // exhaustively walk through all the points in the source set
            for($k = 0; $k<$wrapAround*$groupSize; $k++)
            {
                // random choice of the point in the goal set
                $goal = $destinationGroups[$j][rand(0, $goalSetSize - 1)];
                findPath($destinationGroups[$i][$k%$groupSize], $goal, $img, false, $score);
            }
            if($leftOver>0)
            {
                // randomized choice of both source and goal
                for($k=0; $k<$leftOver; $k++)
                {
                    $source = $destinationGroups[$i][rand(0, $groupSize - 1)];
                    $goal = $destinationGroups[$j][rand(0, $goalSetSize - 1)];
                    findPath($source, $goal, $img, false, $score);
                }
            }
            $destMaxNumberRun[$i] -= $maxSetSize;
            $destMaxNumberRun[$j] -= $maxSetSize;
        }
        echo "finished ".($i+1)." run in ".(microtime(TRUE)-$s1)."\n";
        echo "*** runs left:\n";
        print_r($destMaxNumberRun);
    }
    // this array will keep track of destinations that can be used for more paths
    for($i = 0; $i<count($destMaxNumberRun); $i++)
    {
        if($destMaxNumberRun[$i] > 0)
            $validDestinations[] = $i;
    }
    echo "valid destinations: ".implode(', ', $validDestinations)."\n";
    // randomized part of path-finding
    for($i = 0; $i < count($destinationGroups); $i++){
        // now that we ran through all possible combinations, let's get the needed weights for each destination
        while($destMaxNumberRun[$i] > 0) {
            if(count($validDestinations) > 1){
                $randDest = rand(0, count($validDestinations) - 1);
                $destSetId = $validDestinations[$randDest];
                if($destMaxNumberRun[$destSetId] < 1){
                    //echo "reached 0 for $destSetId\n";
                    unset($validDestinations[$randDest]);
                    // reorder the values so that it's easy to generate a new random set
                    $validDestinations = array_values($validDestinations);
                    echo "*** runs left:\n";
                    print_r($destMaxNumberRun);
                    echo "*** reset valid dest\n";
                    echo implode(', ', $validDestinations)."\n";
                    continue;
                }
            }
            // meaning it's the last node with the biggest weight
            elseif(count($validDestinations) == 1){
                echo "*** last valid node is $i\n";
                //then just overuse some random paths
                $destSetId = rand(0, count($destinationGroups) - 1);
            }
            if($destSetId == $i)
                continue;
            $source = $destinationGroups[$i][rand(0, count($destinationGroups[$i]) - 1)];
            $goal = $destinationGroups[$destSetId][rand(0, count($destinationGroups[$destSetId]) - 1)];
            findPath($source, $goal, $img, false, $score);
            $destMaxNumberRun[$i]--;
            $destMaxNumberRun[$destSetId]--;
        }
        echo "finished destination $i\n";
    }
    echo "total time: ".(microtime(TRUE)-$s)."\n";
    echo "Saving a file with a heatmap score $scoreFile.\n";
    file_put_contents($scoreFile, serialize($score));
    $imageName = "paths_".str_replace('.txt', '', $scoreFile).".png";
    echo "Saving an image with all the paths $imageName.\n";
    imagepng($img, $imageName);
    imagedestroy($img);
}

// generate the actual heatmap image based on the raw score
$heatmapImg = @imagecreatefrompng($cleanFloorplan);
$imgWidth = imagesx($heatmapImg);
$imgHeight = imagesy($heatmapImg);
$pixelWidth = round($condoWidth/$imgWidth, 3);
$pixelGridStep = round($imgWidth*$gridStep/$condoWidth);
$halfLowerPixelGridStep = floor($pixelGridStep / 2);
$halfUpperPixelGridStep = ceil($pixelGridStep / 2);
$max = max($score);
// use gridSize as the count of score (identical sizes)
for($i = 0; $i < $gridSize; $i++){
    $xPixel = $grid[$i]['x'];
    $yPixel = $grid[$i]['y'];
    $color = imagecolorallocate($heatmapImg, 
            255 - round(255 * $score[$i] / $max), 255, 255 - round(255 * $score[$i] / $max));
    for($x = $xPixel - $halfLowerPixelGridStep; $x < $xPixel + $halfUpperPixelGridStep; $x++){
        for($y = $yPixel - $halfLowerPixelGridStep; $y < $yPixel + $halfUpperPixelGridStep; $y++){
            if($x >= 0 && $x < $imgWidth && $y >= 0 && $y < $imgHeight){
                $currentColor = imagecolorat($heatmapImg, $x, $y);
                if(!in_array($currentColor, array($colors['walls'], $colors['obstacle'])))
                    imagesetpixel($heatmapImg, $x, $y, $color);
            }
        }
    }
}
$imageName = "heatmap_".str_replace('.txt', '', $scoreFile).".png";
echo "Saving an image with the heatmap $imageName.\n";
imagepng($heatmapImg, $imageName);
imagedestroy($heatmapImg);

/*** generate the smoothed heatmap image (filling up the empty cells,
 surrounded by color, based on interpolated values ***/

// first, create a more convenient data structure
$heatScoreMap = array();
foreach($score as $index=>$val)
{
    $pix = $grid[$index]['x']." ".$grid[$index]['y'];
    $heatScoreMap[$pix] = $val;
}
//echo "heat sum before smoothing: ".array_sum($heatScoreMap)."\n";
$smoothHeatScore = array();
$smoothHeatScoreFile = "smooth_$scoreFile";

$s = microtime(TRUE);
// number of pixels in a spiral side
$n = 3;
$runFor = 4*$n - 1;
foreach($heatScoreMap as $pix=>$val)
{
    $p = explode(" ", $pix);
    // grid id for reverse compatibility with the previous score data structure
    // whose indices correspond to grid indices
    $gridID = $pixelMatrix[$p[0]][$p[1]];
    $smoothHeatScore[$gridID] = $val;
    $counter = 0;
    $sum = 0;
    $x = $p[0] - $pixelGridStep;
    $y = $p[1] - $pixelGridStep;
    $increment = $pixelGridStep;
    $xChange = 1; $yChange = 0;
    $xIncr = 0; $yIncr = 0;
    $round = 0;
    $subsequentPixels = "";
    while($round < $runFor)
    {
        $x += $xIncr;
        $y += $yIncr;
        if(isset($heatScoreMap["$x $y"]) && $heatScoreMap["$x $y"] > 0)
        {
            $sum += $heatScoreMap["$x $y"];
            $subsequentPixels .= "1";
            $counter++;
        }
        else
            $subsequentPixels .= "0";
        $round++;
        if($round%$n == 0)
        {
            if($round%(2*$n) == 0)
                $increment = -$increment;
            $xChange = !$xChange;
            $yChange = !$yChange;
            $round++;

        }
        $xIncr = $xChange ? $increment : 0;
        $yIncr = $yChange ? $increment : 0;
    }
    if($val)
    {
        $sum += $val;
        $counter++;
        $smoothHeatScore[$gridID] = round($sum / $counter);
    }
    // if at least half of the surrounding points have score>0
    elseif($counter >= 4)
    {
        if($counter == 4 || $counter == 5)
        {
            preg_match_all('!(.)\\1*!', $subsequentPixels, $match);
            $strLengths = array(0 => 0, 1 => 0);
            foreach($match[0] as $val)
            {
                $length = strlen($val);
                $strLengths[$val[0]] = $length > $strLengths[$val[0]] ? 
                                        $length : $strLengths[$val[0]];
            }
            //print_r($match); print_r($strLengths); //max(array_map('strlen', $m[0]));
            if($strLengths[1] == $counter || $strLengths[0] == (8 - $counter))
                continue;
        }
        // divide the sum by the total number of pixels
        $smoothHeatScore[$gridID] = round($sum / $counter);
    }
}
//echo "heat sum after smoothing: ".array_sum($smoothHeatScore)."\n";
echo "Saving a smoothed heatmap score into $smoothHeatScoreFile\n";
file_put_contents($smoothHeatScoreFile , serialize($smoothHeatScore));

$img = @imagecreatefrompng('clean_floorplan.png');
$maxSmoothScore = max($smoothHeatScore);
foreach($smoothHeatScore as $k=>$val) 
{
    $xPixel = $grid[$k]['x'];
    $yPixel = $grid[$k]['y'];
    $color = imagecolorallocate($img, 255 - round(255 * $val / $maxSmoothScore),  
            255 - round(255 * $val / $maxSmoothScore), 255);
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
$imageName = "smooth_$imageName";
echo "Saving an image with the smoothed heatmap $imageName.\n";
imagepng($img, $imageName);
imagedestroy($img);
?>