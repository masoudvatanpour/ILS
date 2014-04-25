<?php
// a configuration file
require "config.php";
// a file with common functions
require "common.php";

// this script is very memory-consuming, so get rid of the memory limit on the php scripts
ini_set('memory_limit', '-1');

// whether we want to run calculations pertaining only to walls and obstacles
// useful for debugging if we do not want to generate all possible data structures 
// (e.g., $grid, $movables, etc.) with each run of the script
$onlyWallsAndObstacles = false;

/****** body of the script ******/

// an array of ALL walkable grid points (white area, doorways, movables, destinations)
$grid = array();
// a 2D hashmap of pixels and ids of corresponding grid points
$pixelMatrix = array();
// an array of grid points that belong to destinations (or else "areas of interest")
$destination = array();
// an array of grid points that belong to movables
$movable = array();

$img = @imagecreatefrompng($floorplanFile);
$imgCl = @imagecreatefrompng($floorplanFile);
$imgWidth = imagesx($img);
$imgHeight = imagesy($img);
$pixelWidth = round($condoWidth/$imgWidth, 3);
$pixelGridStep = round($imgWidth*$gridStep/$condoWidth);
$halfLowerPixelGridStep = floor($pixelGridStep / 2);
$halfUpperPixelGridStep = ceil($pixelGridStep / 2);  
$pixelGridDiag = round(sqrt(2*$pixelGridStep*$pixelGridStep), 2);
// the grid will start with a padding equal to a half-grid step
$gridPadding = round($pixelGridStep/2);

// color allocation
$black = imagecolorallocate($img, 0, 0, 0);
$white = imagecolorallocate($img, 255, 255, 255);
$green = imagecolorallocate($img, 0, 255, 0);
$blue = imagecolorallocate($img, 0, 0, 255);
$red = imagecolorallocate($img, 255, 0, 0);

if(!$onlyWallsAndObstacles) {
// parsing the grid of pixels with a grid step $pixelGridStep
$gridCounter = 0;
for($x = $gridPadding; $x<$imgWidth; $x += $pixelGridStep)
{
    for($y = $gridPadding; $y<$imgHeight; $y += $pixelGridStep)
    {
        $color = imagecolorat($img, $x, $y);
        if(in_array($color, $walkable))
        {
            $realCoord = convertPixelsToRealCoord($x, $y);
            $grid[$gridCounter] = array('x' => $x, 'y' => $y, 
                'realX' => $realCoord['x'], 'realY' => $realCoord['y'], 
                'neighbors' => array(), 'isolate' => 0);
            $pixelMatrix[$x][$y] = $gridCounter;
            if($color == $colors['destination'])
            {
                //imagesetpixel($img, $x, $y, $white);
                $destination[$gridCounter] = array('x' => $x, 'y' => $y, 'neighbors' => array());
            } 
            elseif($color == $colors['movable'])
            {
                $movable[] = array('x' => $x, 'y' => $y, 'neighbors' => array());
                //imagesetpixel($img, $x, $y, $black);
            }
//          else
//              imagesetpixel($img, $x, $y, $black);
            $gridCounter++;
        }
    }
}
// serializaing $pixelMatrix, other files will be serialized after additional processing
file_put_contents($pixelMatrixFile, serialize($pixelMatrix));
echo "pixelMatrix data structure has been saved...\n";

// determine the pixels that belong to the walls, here we can't use the grid approximation
// several pixels might significantly affect the detecting range of a sensor (due to ray-tracing)
$wallMatrix = array();
// same goes to the doors
$doorMatrix = array();
// and obstacles
$obstacleMatrix = array();
// we'll be collecting all the black pixels in a matrix
// because accessing the matrix element is ~3 times faster then directly checking the pixel color (from tests)
for($x = 0; $x < $imgWidth; $x++)
{
    for($y = 0; $y < $imgHeight; $y++)
    {
        $color = imagecolorat($img, $x, $y);
        if($color == $colors['walls'])
            $wallMatrix[$x][$y] = 1;
        elseif($color == $colors['door'])
            $doorMatrix[$x][$y] = 1;
        elseif($color == $colors['obstacle'])
            $obstacleMatrix[$x][$y] = 1;
        // erase the movables from the image used further for debugging
        elseif($color == $colors['movable'])
            imagesetpixel($img, $x, $y, $white);
        // to make a clean floorplan with only non-walkable objects
        if(!in_array($color, $nonWalkable))
            imagesetpixel($imgCl, $x, $y, $white);
    }
}
file_put_contents($wallMatrixFile, serialize($wallMatrix));
file_put_contents($doorMatrixFile, serialize($doorMatrix));
file_put_contents($obstacleMatrixFile, serialize($obstacleMatrix));
echo "pixel matrices of walls and doors have been saved...\n";
imagepng($imgCl, $cleanFloorplan);
echo "saving $cleanFloorplan...\n";
imagedestroy($imgCl);

// different approach for finding neighbors because of very dense grid!
// and considerations of body diameter -> nodes that don't have enough padding will be isolated
$bodyRadiusPixel = round(($bodyDiameter/2)/$pixelWidth);
$diagonalPixel = round(sqrt($bodyRadiusPixel*$bodyRadiusPixel/2));
$gridSize = count($grid);
imagesetthickness($img, 3);
for($i=0; $i<$gridSize; $i++)
{
    // each point is checked whether it's too close to obstacles
    // first initial check of the boundaries
    $x = $grid[$i]['x']; $y = $grid[$i]['y'];
    if($x < $bodyRadiusPixel || $x > $imgWidth - $bodyRadiusPixel
            || $y < $bodyRadiusPixel || $y > $imgHeight - $bodyRadiusPixel)
    {
        $grid[$i]['isolate'] = 1;
        continue;
    }
    // next more thorough check for obstacles in 8 surrounding points of the same radius
    $colorCheck = array();
    $colorCheck[($x + $bodyRadiusPixel).",$y"] = imagecolorat($img, $x + $bodyRadiusPixel, $y);
    $colorCheck[($x - $bodyRadiusPixel).",$y"] = imagecolorat($img, $x - $bodyRadiusPixel, $y);
    $colorCheck["$x,".($y + $bodyRadiusPixel)] = imagecolorat($img, $x, $y + $bodyRadiusPixel);
    $colorCheck["$x,".($y - $bodyRadiusPixel)] = imagecolorat($img, $x, $y - $bodyRadiusPixel);
    $colorCheck[($x + $diagonalPixel).",".($y + $diagonalPixel)] = imagecolorat($img, $x + $diagonalPixel, $y + $diagonalPixel);
    $colorCheck[($x - $diagonalPixel).",".($y + $diagonalPixel)] = imagecolorat($img, $x - $diagonalPixel, $y + $diagonalPixel);
    $colorCheck[($x - $diagonalPixel).",".($y - $diagonalPixel)] = imagecolorat($img, $x - $diagonalPixel, $y - $diagonalPixel);
    $colorCheck[($x + $diagonalPixel).",".($y - $diagonalPixel)] = imagecolorat($img, $x + $diagonalPixel, $y - $diagonalPixel);
    $colorCheck[($x + $pixelGridStep).",$y"] = imagecolorat($img, $x + $pixelGridStep, $y);
    $colorCheck[($x - $pixelGridStep).",$y"] = imagecolorat($img, $x - $pixelGridStep, $y);
    $colorCheck["$x,".($y + $pixelGridStep)] = imagecolorat($img, $x, $y + $pixelGridStep);
    $colorCheck["$x,".($y - $pixelGridStep)] = imagecolorat($img, $x, $y - $pixelGridStep);
    $colorCheck[($x + $pixelGridStep).",".($y + $pixelGridStep)] = imagecolorat($img, $x + $pixelGridStep, $y + $pixelGridStep);
    $colorCheck[($x - $pixelGridStep).",".($y - $pixelGridStep)] = imagecolorat($img, $x - $pixelGridStep, $y - $pixelGridStep);
    $colorCheck[($x + $pixelGridStep).",".($y - $pixelGridStep)] = imagecolorat($img, $x + $pixelGridStep, $y - $pixelGridStep);
    $colorCheck[($x - $pixelGridStep).",".($y + $pixelGridStep)] = imagecolorat($img, $x - $pixelGridStep, $y + $pixelGridStep);

    if(count(array_intersect($colorCheck, $nonWalkable)))
    {
        $grid[$i]['isolate'] = 1;
    }
//        imagesetpixel($img, $x, $y, $green);
//        imagesetpixel($img, $x + 1, $y, $green);
//        imagesetpixel($img, $x + 1, $y + 1, $green);
//        imagesetpixel($img, $x, $y + 1, $green);
//        imagesetpixel($img, $x + $bodyRadiusPixel, $y, $black);
//        imagesetpixel($img, $x - $bodyRadiusPixel, $y, $black);
//        imagesetpixel($img, $x, $y - $bodyRadiusPixel, $black);
//        imagesetpixel($img, $x, $y + $bodyRadiusPixel, $black);
//        imagesetpixel($img, $x + $diagonalPixel, $y + $diagonalPixel, $black);
//        imagesetpixel($img, $x - $diagonalPixel, $y - $diagonalPixel, $black);
//        imagesetpixel($img, $x - $diagonalPixel, $y + $diagonalPixel, $black);
//        imagesetpixel($img, $x + $diagonalPixel, $y - $diagonalPixel, $black);
}

//another loop now that we know which nodes are isolated, we can assign neighbors
for($i=0; $i<$gridSize; $i++)
{
    if(!$grid[$i]['isolate'])
    {
        $x = $grid[$i]['x']; $y = $grid[$i]['y'];
        for($k=$x-$pixelGridStep; $k<=$x+$pixelGridStep; $k+=$pixelGridStep)
        {
            for($l=$y-$pixelGridStep; $l<=$y+$pixelGridStep; $l+=$pixelGridStep)
            {
                if(!($k==$x && $l==$y) && isset($pixelMatrix[$k][$l]) && !$grid[$pixelMatrix[$k][$l]]['isolate'])
                {
                    $grid[$i]['neighbors'][] = $pixelMatrix[$k][$l];
                }
            }
        }
    }
}
imagesetthickness($img, 1);
// special case for destinations - they are very close to obstacles 
// but still should be reachable
$bodyRadiusByStep = ceil($bodyRadiusPixel/$pixelGridStep);
foreach($destination as $i=>$val)
{
    $x = $val['x'];
    $y = $val['y'];
    $gridPoint = $grid[$pixelMatrix[$x][$y]];
    if($gridPoint['isolate'])
    {
        $added = false;
        $minDist = -1;
        $closestGrid = -1;
        for($k=$x-$bodyRadiusByStep*$pixelGridStep; $k<=$x+$bodyRadiusByStep*$pixelGridStep; $k+=$pixelGridStep)
        {
            for($l=$y-$bodyRadiusByStep*$pixelGridStep; $l<=$y+$bodyRadiusByStep*$pixelGridStep; $l+=$pixelGridStep)
            {
                if(!($k==$x && $l==$y) && isset($pixelMatrix[$k][$l]) && !$grid[$pixelMatrix[$k][$l]]['isolate'])
                {
//                        if(imagecolorat($img, $k, $l) == $colors['destination'])
//                            continue;
                    $dist = distance($x, $y, $k, $l);
                    if($dist <= $pixelGridDiag)
                    {
                        // add the destination point to the neighbors of the closest point
                        $grid[$pixelMatrix[$k][$l]]['neighbors'][] = $pixelMatrix[$x][$y];
                        $grid[$pixelMatrix[$x][$y]]['neighbors'][] = $pixelMatrix[$k][$l];
                        imageline($img, $k, $l, $x, $y, $green);
                        $added = true;
                    }
                    if($minDist<0 || $minDist>=$dist)
                    {
                        $minDist = $dist;
                        $closestGrid = $pixelMatrix[$k][$l];
                    }
                }
            }
        }
        // if didn't add neighbors yet
        if(!$added && $closestGrid>0)
        {
            imagesetthickness($img, 3);
            imageline($img, $x-1, $y, $x+1, $y, $white);
            imageline($img, $grid[$closestGrid]['x']-1, $grid[$closestGrid]['y'], 
                    $grid[$closestGrid]['x']+1, $grid[$closestGrid]['y'], $blue);
            imagesetthickness($img, 1);
            addIsolateNeighbors($closestGrid, $pixelMatrix[$x][$y]);
        }
    }
}
//echo count($destination)."\n";
file_put_contents($gridFile, serialize($grid));
echo "grid data structure has been saved...\n";

//adding information about neighboring nodes (adjacency list)
findNeighbors($destination, $pixelGridDiag);
$destinationGroups = array();
// now that we know adjacent nodes, we can find connected sets of nodes
findConnectedSets($destination, $destinationGroups);
// saving the serialized data structure
file_put_contents($destinationFile, serialize($destinationGroups));
echo "destinationGroups data structure has been saved...\n";

// building adjacency list for the movables
findNeighbors($movable, $pixelGridDiag);
// find grid points that belong to the movables and are connected into sets
$movableGroups = array();
findConnectedSets($movable, $movableGroups);
// find the boundaires of the movable furniture to be able 
// to fit it into random places within $movableArea
// the framework assumes that movables are rectangular and aligned with 
// the boundaries of the $movableArea
foreach($movableGroups as $key => $val){
    $minX = 0;
    $minY = 0;
    $maxX = 0;
    $maxY = 0;
    $groupSize = count($val);
    for($i = 0; $i < $groupSize; $i++){
        if(!$minX || $movable[$val[$i]]['x'] < $minX)
            $minX = $movable[$val[$i]]['x'];
        if(!$minY || $movable[$val[$i]]['y'] < $minY)
            $minY = $movable[$val[$i]]['y'];
        if($movable[$val[$i]]['x'] > $maxX)
            $maxX = $movable[$val[$i]]['x'];
        if($movable[$val[$i]]['y'] > $maxY)
            $maxY = $movable[$val[$i]]['y'];
    }
    $movableGroups[$key]['minX'] = $minX;
    $movableGroups[$key]['minY'] = $minY;
    $movableGroups[$key]['maxX'] = $maxX;
    $movableGroups[$key]['maxY'] = $maxY;
    $movableGroups[$key]['grid'] = array();
}

// finding a set of points that can be occupied by the movables
// taking into account their own boundaries
$movableGrid = array();
for($i = 0; $i < $gridSize; $i++){
    if($grid[$i]['realX'] >= $movableArea['minX'] && $grid[$i]['realX'] <= $movableArea['maxX'] &&
            $grid[$i]['realY'] >= $movableArea['minY'] && $grid[$i]['realY'] <= $movableArea['maxY'])
    {
        $movableGrid[] = $i;
        //imageline($img, $grid[$i]['x'] - 3, $grid[$i]['y'], $grid[$i]['x'] + 3, $grid[$i]['y'], $blue);
        //imageline($img, $grid[$i]['x'], $grid[$i]['y'] - 3, $grid[$i]['x'], $grid[$i]['y'] + 3, $blue);
    }
}

$movableGridSize = count($movableGrid);
foreach($movableGroups as $key => $val)
{
    $movWidthPix = $val['maxX'] - $val['minX'];
    $movHeightPix = $val['maxY'] - $val['minY'];
    // try every point for being a successful candidate to transfer a movable to
    for($i = 0; $i < $movableGridSize; $i++){
        //echo $i."\n";
        $passedTest = true;
        $coveredNodes = array();
        $node = $grid[$movableGrid[$i]];
        $xPixel = $node['x'];
        $yPixel = $node['y'];
        //echo "node: {$movableGrid[$i]}; $xPixel $yPixel \n";
        for($x = $xPixel; $x <= $xPixel + $movWidthPix; $x = $x + $pixelGridStep)
        {
            for($y = $yPixel; $y <= $yPixel + $movHeightPix; $y = $y + $pixelGridStep)
            {
                $realCoord = convertPixelsToRealCoord($x, $y);
                if($realCoord['x'] > $movableArea['maxX'] || $realCoord['x'] < $movableArea['minX'] ||
                        $realCoord['y'] > $movableArea['maxY'] || 
                        $realCoord['y'] < $movableArea['minY'] || !isset($pixelMatrix[$x][$y]))
                {
                    $passedTest = false;
                    break 2;
                }
                $coveredNodes[$pixelMatrix[$x][$y]] = "$x,$y";
            }
        }
        if($passedTest){
            $movableGroups[$key]['grid'][] = $coveredNodes;
            imagesetthickness($img, 2);
            imageline($img, $xPixel, $yPixel, $xPixel+$movWidthPix, $yPixel, $green);
            imageline($img, $xPixel+$movWidthPix, $yPixel, $xPixel+$movWidthPix, $yPixel+$movHeightPix, $green);
            imageline($img, $xPixel+$movWidthPix, $yPixel+$movHeightPix, $xPixel, $yPixel+$movHeightPix, $green);
            imageline($img, $xPixel, $yPixel+$movHeightPix, $xPixel, $yPixel, $green);
        }
    }
}
file_put_contents($movableFile, serialize($movableGroups));
echo "movableGroups data structure has been saved...\n";
imagepng($img, "output.png");
echo "take a look at output.png for graphical info that might help with debugging.\n";
imagedestroy($img);
//print_r($movableGroups);
//print_r($destinationGroups);
// free up memory
unset($grid);
unset($pixelMatrix);
unset($doorMatrix);
unset($movableGroups);
unset($destination);
unset($destinationGroups);
}

$im = imagecreatetruecolor($imgWidth, $imgHeight);
// sets background to white
imagefill($im, 0, 0, $white);
// in case wall matrix has already been serialized
if((!isset($wallMatrix) || !count($wallMatrix)) && file_exists($wallMatrixFile)) {
    echo "Retrieving a wall matrix from $wallMatrixFile...\n";
    $wallMatrix = unserialize(file_get_contents($wallMatrixFile));
}

$wallsPoly = array();
$wallSet = array();
foreach($wallMatrix as $x => $val)
{
    foreach($val as $y => $v)
    {
        $wallSet["$x $y"]['neighbors'] = array();
        for($i = $x-1; $i <= $x+1; $i++)
            for($j = $y-1; $j <= $y+1; $j++)
                if($i!=$x || $j!=$y)
                    if(isset($wallMatrix[$i][$j]))
                        $wallSet["$x $y"]['neighbors'][] = "$i $j";
    }
}
$wallGroups = array();
findConnectedSets($wallSet, $wallGroups);

foreach($wallGroups as $key => $group)
{
    $wallsPoly[$key] = convertToPolygon($group);
}
// free up memory
unset($wallMatrix);

// in case obstacle matrix has already been serialized
if((!isset($obstacleMatrix) || !count($obstacleMatrix)) && file_exists($obstacleMatrixFile)) {
    echo "Retrieving an obstacle matrix from $obstacleMatrixFile...\n";
    $obstacleMatrix = unserialize(file_get_contents($obstacleMatrixFile));
}
$obstaclesPoly = array();
$obstacleSet = array();
foreach($obstacleMatrix as $x => $val)
{
    foreach($val as $y => $v)
    {
        $obstacleSet["$x $y"]['neighbors'] = array();
        for($i = $x-1; $i <= $x+1; $i++)
            for($j = $y-1; $j <= $y+1; $j++)
                if($i!=$x || $j!=$y)
                    if(isset($obstacleMatrix[$i][$j]))
                        $obstacleSet["$x $y"]['neighbors'][] = "$i $j";
    }
}
$obstacleGroups = array();
findConnectedSets($obstacleSet, $obstacleGroups);

foreach($obstacleGroups as $key => $group)
{
    $obstaclesPoly[$key] = convertToPolygon($group);

}
//file_put_contents($obstaclesFileName, serialize($obstaclesPoly));

$roomsFile = 'Walls.txt';

$fp = fopen($roomsFile, 'w');
$realCoord = convertPixelsToRealCoord($imgWidth, 0);
fwrite($fp, "4\n0,0;{$realCoord['x']},0;{$realCoord['x']},{$realCoord['y']};0,{$realCoord['y']}\n");
fclose($fp);

$obstaclesFile = 'Obstacles.txt';
$allObstacles = array_merge($wallsPoly, $obstaclesPoly);

$fp = fopen($obstaclesFile, 'w');
fwrite($fp, count($allObstacles)."\n");
foreach($allObstacles as $val)
{
    fwrite($fp, count($val)."\n");
    foreach($val as $coord)
    {
        $realCoord = convertPixelsToRealCoord($coord['x'], $coord['y']);
        fwrite($fp, $realCoord['x']." ".$realCoord['y']."\n");
    }
}
fclose($fp);

foreach($wallsPoly as $poly)
{
    drawPoly($poly, $im, $black);
//    foreach($poly as $k => $val)
//        imageline($im, $val['x'], $val['y'], $polygon[($k+1)%count($polygon)]['x'], $polygon[($k+1)%count($polygon)]['y'], $blue);

}

foreach($obstaclesPoly as $val)
{
    $grey = imagecolorallocate($im, 128, 128, 128);
    drawPoly($val, $im, $grey);
}

$imageName = "obstacles.png";
echo "Room coordinates are saved into $roomsFile\n";
echo "Copy $roomsFile inside the Localizer project folder\n";
echo "Obstacles and walls are drawn in $imageName and saved into $obstaclesFile\n";
echo "Copy $obstaclesFile inside the Simulator project folder\n";
imagepng($im, $imageName);
imagedestroy($im);
?>