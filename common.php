<?php
/****** functions used by scripts in this directory ******/

function distance($x1, $y1, $x2, $y2)
{
    return sqrt(($x1-$x2)*($x1-$x2) + ($y1-$y2)*($y1-$y2));
}

function addStats($line)
{
    $fp = fopen('stats.txt', 'a');
    fwrite($fp, $line);
    fclose($fp);
}

// pixel matrix parsing starts from the left top corner (0;0), however, the rest of the software 
// (Simulator, Localizer) assumes (0;0) to be in the left bottom corner
// therefore, everywhere where a transition from pixels to real meters occurs
// we reverse the y-coordinate
function convertPixelsToRealCoord($x, $y)
{
    global $imgHeight, $pixelWidth;
    $realX = round($x * $pixelWidth, 2);
    $realY = round((($imgHeight - 1) - $y) * $pixelWidth, 2);
    return array('x' => $realX, 'y' => $realY);
}

// function that performs a reverse action to the previous one
function convertRealCoordToPixels($x, $y)
{
    global $imgHeight, $pixelWidth;
    $pixelX = round($x/$pixelWidth);
    $pixelY = ($imgHeight - 1) - round($y/$pixelWidth);
    return array('x' => $pixelX, 'y' => $pixelY);
}

function findNeighbors(&$set, $radius)
{
    foreach($set as $key1 => $val1)
    {
        foreach($set as $key2 => $val2)
        {
            // keys are sorted ascendingly, so no point of calculating twice
            if($key1<$key2)
            {
                if(round(distance($set[$key1]['x'],$set[$key1]['y'],$set[$key2]['x'],$set[$key2]['y']), 2) <= $radius)
                {
                    $set[$key1]['neighbors'][] = $key2;
                    $set[$key2]['neighbors'][] = $key1;
                }
            }
        }
    }
}

function addIsolateNeighbors($start, $goal)
{
    global $grid, $pixelGridStep, $pixelMatrix, $img, $blue;
    $x1 = $grid[$start]['x'];
    $y1 = $grid[$start]['y'];
    $x2 = $grid[$goal]['x'];
    $y2 = $grid[$goal]['y'];
    $minDist = -1;
    $closestGrid = -1;
    for($k=$x1-$pixelGridStep; $k<=$x1+$pixelGridStep; $k+=$pixelGridStep)
    {
        for($l=$y1-$pixelGridStep; $l<=$y1+$pixelGridStep; $l+=$pixelGridStep)
        {
            if(!($k==$x1 && $l==$y1) && isset($pixelMatrix[$k][$l]))
            {
                $dist = distance($k, $l, $x2, $y2);
                if($minDist<0 || $minDist>$dist)
                {
                    $minDist = $dist;
                    $closestGrid = $pixelMatrix[$k][$l];
                }
            }
        }
    }
    if($closestGrid>0)
    {
        $grid[$start]['neighbors'][] = $closestGrid;
        $grid[$closestGrid]['neighbors'][] = $start;
        imageline($img, $x1, $y1, $grid[$closestGrid]['x'], $grid[$closestGrid]['y'], $blue);
        if($closestGrid != $goal)
            addIsolateNeighbors($closestGrid, $goal);
    }
}

function findConnectedSets($set, &$subsets)
{
    $allExplored = array();
    $explored = array();
    foreach($set as $vertex=>$val)
    {
        if(isset($allExplored[$vertex]))  // in_array is almost 10 times slower!!!
            continue;
        DFS($vertex, $set, $explored);
        $allExplored += $explored;
        $subsets[] = array_keys($explored);
        $explored = array();
    }
}

function DFS($vertex, $set, &$explored)
{
    // add the current vertex to explored vertices
    $explored[$vertex] = 1;
    foreach($set[$vertex]['neighbors'] as $ver)
    {
        if(isset($explored[$ver]))  //if(in_array($ver, $explored)) almost 10 times slower!
            continue;
        DFS($ver, $set, $explored);
    }
}

function aStar($source, $goal, $obstacles)
{
    global $grid;
    // preordering of nodes for the heuristic cost estimate
    $hScore = array();
    foreach($grid as $key=>$val)
    {
        if(isset($obstacles[$key]))
            continue;
        $hScore[$key] = distance($val['x'], $val['y'], $grid[$goal]['x'], $grid[$goal]['y']);
    }
    // start A* from the source until we find the destination
    $openSet = array($source => 1);
    $closedSet = array();
    $cameFrom = array();
    $gScore = array();
    $gScore[$source] = 0;
    // we'll keep an f score of each node in the array
    $fScore = array();
    $fScore[$source] = $gScore[$source] + $hScore[$source];
    while(count($openSet))
    {
        // find an element with the smallest f-value
        $min_val = null;
        $min_key = null;
        foreach($openSet as $key => $val)
        {
            if ($min_val == null || $fScore[$key] < $min_val) 
            {
                $min_val = $fScore[$key];
                $min_key = $key;
            }
        }
        $current = $min_key;

        if($current == $goal)
            return reconstructPath($cameFrom, $cameFrom[$goal]);
        
        // remove the current node from open set and move to the closed set
        unset($openSet[$current]);
        $closedSet[$current] = 1;
        foreach($grid[$current]['neighbors'] as $key => $node)
        {
            // skip already discovered nodes
            if(isset($closedSet[$node]) || isset($obstacles[$node]))
                continue;
            $tentativeIsBetter = false;
            $tentativeGscore = $gScore[$current] + distance(
                    $grid[$current]['x'], 
                    $grid[$current]['y'],
                    $grid[$node]['x'],
                    $grid[$node]['y']
            );
            if(!isset($openSet[$node]))
            {
                $openSet[$node] = 1;
                $tentativeIsBetter = true;
            }
            elseif($tentativeGscore < $gScore[$node])
            {
                $tentativeIsBetter = true;
            }
            
            if($tentativeIsBetter)
            {
                $cameFrom[$node] = $current;
                $gScore[$node] = $tentativeGscore;
                $fScore[$node] = $gScore[$node] + $hScore[$node];
            }
        }
    }
    return false;
}

function reconstructPath($cameFrom, $current)
{
    if(isset($cameFrom[$current]))
    {
        return array_merge(reconstructPath($cameFrom, $cameFrom[$current]), array($current));
    }
    else
        return array($current);
}

function genRandomObstacle(&$movableObstacles, &$otherObstacles)
{
    global $movableGroups, $grid, $obstacleGridPercent;
    // randomize position of movables
    foreach($movableGroups as $key => $val){
        $set = false;
        $range = count($val['grid']);
        while(!$set) {
            $block = $val['grid'][rand(0, $range - 1)];
            if(count(array_intersect($block, $movableObstacles)))
                continue;
            $movableObstacles += $block;
            $set = true;
        }
    }
    $obstacleGrid = $movableObstacles;

    $gridSize = count($grid);
    $numberBlocked = round($obstacleGridPercent * $gridSize);
    for($i = 0; $i < $numberBlocked; $i++){
        //echo (count($obstacleGrid))."\n";
        $chosen = false;
        while(!$chosen) {
            $point = rand(0, $gridSize - 1);
            if(isset($obstacleGrid[$point]))
                continue;
            $xPixel = $grid[$point]['x'];
            $yPixel = $grid[$point]['y'];
            $obstacleGrid[$point] = $otherObstacles[$point] = "$xPixel,$yPixel";
            $chosen = true;
        }
    }

    return $obstacleGrid;
}

function findPath($source, $goal, $img=null, $showObstacles=false, &$score=null)
{
    global $grid, $black, $green;
    // let's find out which parts of a grid will be made randomly unavailable
    $movableObst = array();
    $otherObst = array();
    $obstacleGrid = genRandomObstacle($movableObst, $otherObst);

    // in case it is coincidentally also an obstacle
    if(isset($obstacleGrid[$source]))
        unset($obstacleGrid[$source]);
    if($grid[$source]['isolate'] && count($grid[$source]['neighbors']) == 1
            && isset($obstacleGrid[$grid[$source]['neighbors'][0]]))
        unset($obstacleGrid[$grid[$source]['neighbors'][0]]);

    if(isset($obstacleGrid[$goal]))
        unset($obstacleGrid[$goal]);
    if($grid[$goal]['isolate'] && count($grid[$goal]['neighbors']) == 1
            && isset($obstacleGrid[$grid[$goal]['neighbors'][0]]))
        unset($obstacleGrid[$grid[$goal]['neighbors'][0]]);
    // echo "*** path from $source to $goal ***\n";
    $path = aStar($source, $goal, $obstacleGrid);
    $path[] = $goal;
    $pathSize = count($path);
    // if path is longer than just the goal
    if($pathSize > 1)
    {
        // the source is skipped in the following loop but we still want to count it
        if(isset($score))
            $score[$path[0]]++;

        if(isset($img)) 
        {
            if($showObstacles)
            {
                imagesetthickness($img, 10);
                foreach($movableObst as $gridPoint => $pix)
                {
                    //$pixel = explode(",", $pix);
                    $pixel[0] = $grid[$gridPoint]['x'];
                    $pixel[1] = $grid[$gridPoint]['y'];
                    imageline($img, $pixel[0]-5, $pixel[1], $pixel[0]+5, $pixel[1], $green);
                }
            }
            imagesetthickness($img, 3);
            if($showObstacles)
            {
                foreach($otherObst as $gridPoint => $pix)
                {
                    $pixel = explode(",", $pix);
                    imageline($img, $pixel[0]-1, $pixel[1], $pixel[0]+1, $pixel[1], $black);
                }
            }
        }
        if(isset($img) || isset($score))
        {
            for($k = 1; $k < $pathSize; $k++)
            {
                if(isset($img))
                {
                    $x1 = $grid[$path[$k - 1]]['x'];
                    $y1 = $grid[$path[$k - 1]]['y'];
                    $x2 = $grid[$path[$k]]['x'];
                    $y2 = $grid[$path[$k]]['y'];
                    imageline($img, $x1, $y1, $x2, $y2, $black);
                }
                if(isset($score))
                    $score[$path[$k]]++;
            }
        }
        if(isset($img))
        {
            $xPixel = $grid[$source]['x'];
            $yPixel = $grid[$source]['y'];
            imageline($img, $xPixel - 1, $yPixel, $xPixel + 1, $yPixel, $green);
            $xPixel = $grid[$goal]['x'];
            $yPixel = $grid[$goal]['y'];
            imageline($img, $xPixel - 1, $yPixel, $xPixel + 1, $yPixel, $green);
        }
        //echo "source $source goal $goal path". count($path)."\n";
        return $path;
    }
    // try another time with other random obstacles
    else
        return findPath($source, $goal, $img, $showObstacles, $score);
}

function findBoundaryGrid($x, $y, $sensWidth, $sensHeight, 
        &$leftBoundary, &$righBoundary, &$topBoundary, &$bottomBoundary, 
        &$xFirstGrid, &$yFirstGrid, &$xLastGrid, &$yLastGrid)
{
    global $imgWidth, $imgHeight, $gridPadding, $pixelGridStep;
    //$leftBoundary = $x - floor($sensWidth / 2) > 0 ? $x - floor($sensWidth / 2) : 0;
    // using of "ceil" is preferred for boundary cases (subjectively) to avoid asymetric coverage
    $leftBoundary = $x - ceil($sensWidth / 2) > 0 ? $x - ceil($sensWidth / 2) : 0;
    $righBoundary = $x + ceil($sensWidth / 2) < $imgWidth ? $x + ceil($sensWidth / 2) : $imgWidth - 1;
    //$topBoundary = $y - floor($sensHeight / 2) > 0 ? $y - floor($sensHeight / 2) : 0;
    $topBoundary = $y - ceil($sensHeight / 2) > 0 ? $y - ceil($sensHeight / 2) : 0;
    $bottomBoundary = $y + ceil($sensHeight / 2) < $imgHeight ? $y + ceil($sensHeight / 2) : $imgHeight - 1;
    //find the first grid point that lies within the boundaries
    $xFirstGrid = $leftBoundary <= $gridPadding ? $gridPadding : $leftBoundary + 
         + ($pixelGridStep - ($leftBoundary - $gridPadding) % $pixelGridStep) % $pixelGridStep;
    $yFirstGrid = $topBoundary <= $gridPadding ? $gridPadding : $topBoundary + 
        ($pixelGridStep - ($topBoundary - $gridPadding) % $pixelGridStep) % $pixelGridStep;
    // the last grid point coords
    $xLastGrid = $righBoundary - ($righBoundary - $gridPadding) % $pixelGridStep;
    $yLastGrid = $bottomBoundary - ($bottomBoundary - $gridPadding) % $pixelGridStep;
    //echo "sensor range: $leftBoundary $righBoundary $topBoundary $bottomBoundary; 
    //grid: $xFirstGrid $yFirstGrid $xLastGrid $yLastGrid\n";
}

// this function applies ray tracing algorithm to the sensor footprint,
// visualizes the resulting footprint, and calculates a cumulative heat score of the sensor
function rayTraceSensorCoverage($sensWidth, $sensHeight, $xSensor=-1, $ySensor=-1)
{
    global $gridPadding, $imgWidth, $imgHeight, $sensPaddingPix, $pixelGridStep, $pixelMatrix,
            $wallMatrix, $doorMatrix, $doorOpenProb, $showImage, $img, $black, $green;
    
    $xInit = $gridPadding;
    $yInit = $gridPadding;
    $xRange = $imgWidth - $sensPaddingPix;
    $yRange = $imgHeight - $sensPaddingPix;
    $visualize = false;
    // if all we want is to visualize coverage of a single sensor
    if($xSensor>-1 && $ySensor>-1)
    {
        // eliminate iterations in for loops
        $xInit = $xSensor;
        $yInit = $ySensor;
        $xRange = $xSensor;
        $yRange = $ySensor;
        $visualize = $showImage;
    }
    
    $s = microtime(TRUE);
    $sensorInfo = array();
    $counter = 0;
    for($x = $xInit; $x <= $xRange; $x+=$pixelGridStep){
        for($y = $yInit; $y <= $yRange; $y+=$pixelGridStep){
            // if the tested position belongs to the wall, continue to the next one
            if(isset($wallMatrix[$x][$y]) || isset($doorMatrix[$x][$y]))
                continue;
            $sensorInfo[$x][$y]['covered'] = "";
            $sensorInfo[$x][$y]['area'] = 0;
            $sensorInfo[$x][$y]['grid'] = 0;
            findBoundaryGrid($x, $y, $sensWidth, $sensHeight, 
                &$leftBoundary, &$righBoundary, &$topBoundary, &$bottomBoundary, 
                &$xGrid, &$yGrid, &$xLast, &$yLast);
            if($visualize)
            {
//                imageline($img, $leftBoundary, $topBoundary, $righBoundary, $topBoundary, $black);
//                imageline($img, $righBoundary, $topBoundary, $righBoundary, $bottomBoundary, $black);
//                imageline($img, $righBoundary, $bottomBoundary, $leftBoundary, $bottomBoundary, $black);
//                imageline($img, $leftBoundary, $bottomBoundary, $leftBoundary, $topBoundary, $black);
            }

            for($i = $xGrid; $i <= $righBoundary; $i+=$pixelGridStep)
            {
                for($j = $yGrid; $j <= $bottomBoundary; $j+=$pixelGridStep)
                {
                    // sum up the scores of all the grid points that belong within this area
                    // first find the line of sight between the grid point and the sensor and check for black(walls) pixels
                    $isVisible = true;
                    $behindDoor = false;
                    if($x != $i){
                        $slope = ($y - $j) / ($x - $i);
                        //echo "x: $i; y: $j; slope: $slope\n";
                        // if the slope is less or equal 45 degrees
                        if(abs($slope) <= 1)
                        {
                            // boundaries of the region between the sensor and the current grid point
                            $xLeft = $x < $i ? $x : $i;
                            $xRight = $x > $i ? $x : $i;
                            for($xi = $xLeft; $xi <= $xRight; $xi++)
                            {
                                // if at least one pixel within the line of sight is black - the grid point is skipped
                                $yi = round($slope * ($xi - $x) + $y);
                                if(isset($wallMatrix[$xi][$yi]))
                                {
                                    $isVisible = false;
                                    break;
                                }
                                elseif(isset($doorMatrix[$xi][$yi]))
                                {
                                    $behindDoor = true;
                                }
                            }
                        }
                    }
                    if($x == $i || abs($slope) > 1)
                    {
                        $yLeft = $y <= $j ? $y : $j;
                        $yRight = $y > $j ? $y : $j;
                        for($yi = $yLeft; $yi <= $yRight; $yi++)
                        {
                            $xi = ($x == $i) ? $x : round(($yi - $y)/$slope + $x);
                            // if at least one pixel within the line of sight is black - the grid point is skipped
                            if(isset($wallMatrix[$xi][$yi]))
                            {
                                $isVisible = false;
                                break;
                            }
                            elseif(isset($doorMatrix[$xi][$yi]))
                            {
                                $behindDoor = true;
                            }
                        }
                    }
                    if($isVisible)
                    {
                        // add up the score if it's a valid grid point
                        if(isset($pixelMatrix[$i][$j]))
                            // increment the counter of covered grid points as well
                            $sensorInfo[$x][$y]['grid']++;
                        // add this point to the set of covered points (even if it's not in the grid 
                        // since the grid skips obstacles)
                        $sensorInfo[$x][$y]['covered'] .= "$i $j;";
                        if($behindDoor)
                        {
                            if(!isset($sensorInfo[$x][$y]['door']))
                                $sensorInfo[$x][$y]['door'] = "";
                            $sensorInfo[$x][$y]['door'] .= "$i $j;";
                        }
                        // increment the covered area (approximated as the number of points)
                        $sensorInfo[$x][$y]['area']++;
                        //echo "covered ($i; $j)\n";
                        if($visualize)
                        {
                            imageline($img, $x, $y, $i, $j, $black);
//                            if($behindDoor)
//                            {
//                                imagesetthickness($img, 3);
//                                imageline($img, $i - 1, $j, $i + 1, $j, $green);
//                                imagesetthickness($img, 1);
//                            }
                        }
                    }
                }
            }
            // the actual sensor position
            if($visualize)
            {
                imagesetthickness($img, 6);
                imageline($img, $x - 3, $y, $x + 3, $y, $green);
                imagesetthickness($img, 1);
            }
            $counter++;
            if($counter%100 == 0)
                echo "processed $counter grid points... memory: ".memory_get_usage().
                    "; real memory: ".memory_get_usage(true)."\n";
        }
    }
    // if it wasn't a single sensor
    if($xSensor==-1 && $ySensor==-1)
    {
        echo "TOTAL $counter grid points\n";
        echo microtime(TRUE) - $s;
        echo "\n";
        echo "peak memory: ".memory_get_peak_usage()." real peak memory: ".memory_get_peak_usage(true)."\n";
    }
    return $sensorInfo;
}

// function for testing whether three points lie on a straight line
// $tolerance allows to resolve special case when the three adjacent pixels are being tested
// but do not appear to lie on a line
function testStraightLine($one, $two, $three, $tolerance=2)
{
//    return round(($one['x'] - $two['x'])/($one['y'] - $two['y']), 2) ==
//        round(($two['x'] - $three['x'])/($two['y'] - $three['y']), 2);
    $dist1 = round(distance($one['x'], $one['y'],  $two['x'], $two['y']), 2);
    $dist2 = round(distance($two['x'], $two['y'], $three['x'], $three['y']), 2);
    $dist3 = round(distance($one['x'], $one['y'], $three['x'], $three['y']), 2);
    if($dist1 < $tolerance && $dist2 < $tolerance && $dist3 < $tolerance)
        return true;
    else
        return $dist1 + $dist2 == $dist3;
}

// vertices should be added to the polygon clockwise
function addPolyVertices($vertices)
{
    $polygon = array();
    foreach($vertices as $i=>$val)
    {
        $polySize = count($polygon);
        if($polySize < 2)
        {
            $polygon[] = $val;
        }
        else
        {
            $last = $polygon[$polySize-1];
            $secondLast = $polygon[$polySize-2];
            // check whether the three points lie on the same line
            if(testStraightLine($secondLast, $last, $val))
                // replace the previous point with the current one
                $polygon[$polySize-1] = $val;
            else
                $polygon[] = $val;
        }
    }
    // last round of checks
    $polySize = count($polygon);
    if($polySize > 3)
    {
        $i = $polySize - 2;
        while($i < $polySize)
        {
            $one = $polygon[$i%$polySize];
            $two = $polygon[($i+1)%$polySize];
            $three = $polygon[($i+2)%$polySize];
            if(testStraightLine($one, $two, $three))
            {
                // if the second point is actually the very first vertex in the polygon ($polygon[0])
                if(($i+1)%$polySize == 0)
                {
                    $polygon[($i+1)%$polySize] = $one;
                    unset($polygon[$i%$polySize]);
                    $polySize--;
                }
                else
                {
                    unset($polygon[($i+1)%$polySize]);
                    $polySize--;
                }
            }
            else
                $i++;
        }
    }
    return $polygon;
}

// given a group of pixels adjacent to each other, find a corresponding polygon 
// of the shape's boundaries
function convertToPolygon($set)
{
    // create a more convenient hashmap
    $map = array();
    $vertices = array();
    foreach($set as $pix)
    {
        $p = explode(" ", $pix);
        $map[$p[0]][$p[1]] = 1;
    }
    foreach($map as $k => $val)
    {
        ksort($map[$k]);
    }
    ksort($map);
    $keepWalking = true;
    $currentX = $startX = key($map);
    $currentY = $startY = key(current($map));
    $vertices[] = array('x' => $startX, 'y' => $startY);
    // assuming that the previous move has been from east, that is agent is facing west
    $prevX = $currentX - 1;
    $prevY = $currentY;
    $direction = 'west';
    // array of possible directions in a form [x][y]
    // 1, 0 - west; 0, 1 - south; -1, 0 - east; 0, -1 - north
    $dirArr = array();
    $dirArr[1][0] = 0;//'west';
    $dirArr[0][1] = 1;//'south';
    $dirArr[-1][0] = 2;//'east';
    $dirArr[0][-1] = 3;//'north';
    $dirIndex = array();
    // the order of directions is important, this is left-hand wall-following
    // that is the indices of directions in $nextMove match numbers in $dirArr
    $nextMove = array(
        array('x' => 1, 'y' => 0), //west
        array('x' => 0, 'y' => 1), //south
        array('x' => -1, 'y' => 0), //east
        array('x' => 0, 'y' => -1) //north
    );
    while($keepWalking)
    {
        $deltaX = $currentX - $prevX;
        $deltaY = $currentY - $prevY;
        $startIndex = $dirArr[$deltaX][$deltaY];
        $found = false;
        for($i = 0; $i < 4; $i++)
        {
            $index = ($i + $startIndex)%4;
            $newX = $currentX + $nextMove[$index]['x'];
            $newY = $currentY + $nextMove[$index]['y'];
            $lind = ($index+3)%4;
            $leftHandSideCurrX = $currentX + $nextMove[($index+3)%4]['x'];
            $leftHandSideCurrY = $currentY + $nextMove[($index+3)%4]['y'];
            $leftHandSideNewX = $newX + $nextMove[($index+3)%4]['x'];
            $leftHandSideNewY = $newY + $nextMove[($index+3)%4]['y'];
            if(isset($map[$newX][$newY]) && (!isset($map[$leftHandSideCurrX][$leftHandSideCurrY]) ||
                !isset($map[$leftHandSideNewX][$leftHandSideNewY])))
            {
                // if we switched a direction, add a vertex
                if($i > 0)
                    $vertices[] = array('x' => $currentX, 'y' => $currentY);
                $prevX = $currentX;
                $prevY = $currentY;
                $currentX = $newX;
                $currentY = $newY;
                $found = true;
                if($newX == $startX && $newY == $startY)
                    $keepWalking = false;
                break;
            }
        }
        if(!$found)
        {
            echo "ERROR! no new direction was found! Exiting (otherwise will get stuck in an infinite loop)...\n";
            break;
        }
    }
    $polygon = addPolyVertices($vertices);
    echo "found a polygon with ".count($polygon)." vertices\n";
    return $polygon;
}

function drawPoly($polygon, $image, $colorPoly, $xSensor=null, $ySensor=null, $colorSensor=null, $sensorID=null, $orientation="h")
{
    $count = count($polygon);
    imagesetthickness($image, 3);
    for($i=0; $i<$count; $i++)
    {
        imageline($image, $polygon[$i]['x'], $polygon[$i]['y'], 
                $polygon[($i+1)%$count]['x'], $polygon[($i+1)%$count]['y'], $colorPoly);
    }
    if(isset($xSensor) && isset($ySensor) && isset($colorSensor))
    {
        if($orientation == "h")
        {
            imagesetthickness($image, 17);
            imageline($image, $xSensor - 11, $ySensor, $xSensor + 11, $ySensor, $colorSensor);
            imagesetthickness($image, 3);
        }
        else
        {
            imagesetthickness($image, 17);
            imageline($image, $xSensor, $ySensor - 11, $xSensor, $ySensor + 11, $colorSensor);
            imagesetthickness($image, 3);
        }
    }
    if(isset($sensorID) && isset($xSensor) && isset($ySensor))
    {
        //$x = $xSensor - 5 > 0 ? $xSensor - 5 : $xSensor + 5;
        //$y = $ySensor - 20 > 0 ? $ySensor - 20 : $ySensor + 20;
        $xOffset = strlen($sensorID)*4;
        imagestring($image, 10, $xSensor - $xOffset, $ySensor - 8, $sensorID, $colorPoly);
    }
}

function checkLineOfSight($x, $y, $xSensor, $ySensor)
{
    global $wallMatrix;
    $lastVisible = array('x' => $xSensor, 'y' => $ySensor);
    // first find the line of sight between the pixel and the sensor and check for black(walls) pixels
    if($x != $xSensor){
        $slope = ($y - $ySensor) / ($x - $xSensor);
        //echo "x: $i; y: $j; slope: $slope\n";
        // if the slope is less or equal 45 degrees
        if(abs($slope) <= 1)
        {
            // we will traverse the line of sight starting from the sensor to the current pixel
            // the direction is important since we'll keep track of the last visible pixel
            $increment = $x < $xSensor ? -1 : 1;
            for($xi = $xSensor; abs($xi-$xSensor)<=abs($x-$xSensor); $xi+=$increment)
            {
                $yi = round($slope * ($xi - $x) + $y);
                // if found a black pixel (wall), save the last visible pixel and break
                if(isset($wallMatrix[$xi][$yi]))
                    break;
                $lastVisible = array('x' => $xi, 'y' => $yi);
            }
        }
    }
    if($x == $xSensor || abs($slope) > 1)
    {
        $increment = $y < $ySensor ? -1 : 1;
        for($yi = $ySensor; abs($yi-$ySensor)<=abs($y-$ySensor); $yi+=$increment){
            $xi = ($x == $xSensor) ? $xSensor : round(($yi - $y)/$slope + $x);
            // if found a black pixel (wall), save the last visible pixel and break
            if(isset($wallMatrix[$xi][$yi]))
                break;
            $lastVisible = array('x' => $xi, 'y' => $yi);
        }
    }
    return $lastVisible;
}

function sensorPoly($placementKey, $image=null, $sensorId=null)
{
    global $gridPadding, $imgWidth, $imgHeight, $sensWidthPix, $sensHeightPix, $wallMatrix,
            $blue, $green, $existPolyFile, $existingPolygons, $existingPolySerialized;
    
    $pixels = explode("|", substr($placementKey, 1));
    $xSensor = $pixels[0];
    $ySensor = $pixels[1];
    $orientation = $placementKey[0];
    // if the the existing polygons haven't been retrieved from cache yet
    if(!count($existingPolygons))
    {
        if(file_exists($existPolyFile)) 
        {
            $existingPolySerialized = file_get_contents($existPolyFile);
            $existingPolygons = unserialize($existingPolySerialized);
        }
    }
    // if such polygon has been calculated already, return
    if(isset($existingPolygons[$placementKey]))
        $polygon = $existingPolygons[$placementKey];
    else
    {
        $polygon = array();
        if($orientation == "h")
        {
            $sensWidth = $sensWidthPix;
            $sensHeight = $sensHeightPix;
        }
        else
        {
            $sensWidth = $sensHeightPix;
            $sensHeight = $sensWidthPix;
        }
        $visible = array();
        findBoundaryGrid($xSensor, $ySensor, $sensWidth, $sensHeight, 
            &$leftBoundary, &$righBoundary, &$topBoundary, &$bottomBoundary, 
            &$xGrid, &$yGrid, &$xLast, &$yLast);
        $xInit = $xGrid - $gridPadding > 0 ? $xGrid - $gridPadding : 0;
        $yInit = $yGrid - $gridPadding > 0 ? $yGrid - $gridPadding : 0;
        $xEnd = $xLast + $gridPadding < $imgWidth ? $xLast + $gridPadding : $imgWidth;
        $yEnd = $yLast + $gridPadding < $imgHeight ? $yLast + $gridPadding : $imgHeight;

        // first traverse the north side
        $y = $yInit;
        for($x = $xInit; $x < $xEnd; $x++)
        {
            $visible[] = checkLineOfSight($x, $y, $xSensor, $ySensor);
        }
        // east side
        $x = $xEnd - 1;
        for($y = $yInit; $y < $yEnd; $y++)
        {
            $visible[] = checkLineOfSight($x, $y, $xSensor, $ySensor);
        }
        // south side
        $y = $yEnd - 1;
        for($x = $xEnd - 1; $x >= $xInit; $x--)
        {
            $visible[] = checkLineOfSight($x, $y, $xSensor, $ySensor);
        }
        // west side
        $x = $xInit;
        for($y = $yEnd - 1; $y >= $yInit; $y--)
        {
            $visible[] = checkLineOfSight($x, $y, $xSensor, $ySensor);
        }

        foreach($visible as $i=>$val)
        {
            $polySize = count($polygon);
            if(!$polySize)
            {
                $polygon[] = $val;
            }
            elseif($polySize < 2)
            {
                if($val['x'] != $polygon[$polySize-1]['x'] || $val['y'] != $polygon[$polySize-1]['y'])
                    $polygon[] = $val;
            }
            else
            {
                $last = $polygon[$polySize-1];
                $secondLast = $polygon[$polySize-2];
                // check whether the three points lie on the same line
                if( ($secondLast['x']==$last['x'] && $last['x']==$val['x']) ||
                       ($secondLast['y']==$last['y'] && $last['y']==$val['y']) )
                {
                    // replace the previous point with the current one
                    $polygon[$polySize-1] = $val;
                }
                else
                    $polygon[] = $val;
            }
        }
        // last round of checks
        $polySize = count($polygon);
        if($polygon[$polySize-1]['x'] == $polygon[0]['x'] && $polygon[$polySize-1]['y'] == $polygon[0]['y'])
            unset($polygon[$polySize-1]);
        $polySize = count($polygon);
        if($polySize > 3)
        {
                $last = $polygon[$polySize-1];
                $secondLast = $polygon[$polySize-2];
                $first = $polygon[0];
                // check whether the three points lie on the same line
                if( ($secondLast['x']==$last['x'] && $last['x']==$first['x']) ||
                       ($secondLast['y']==$last['y'] && $last['y']==$first['y']) )
                {
                    unset($polygon[$polySize-1]);
                }
                $polySize = count($polygon);
                $last = $polygon[$polySize-1];
                $current = $polygon[0];
                $second = $polygon[1];
                // check whether the three points lie on the same line
                if( ($last['x']==$current['x'] && $current['x']==$second['x']) ||
                       ($last['y']==$current['y'] && $current['y']==$second['y']) )
                {
                    $polygon[0] = $last;
                    unset($polygon[$polySize-1]);
                }
        }
        $existingPolygons[$placementKey] = $polygon;
    }
    
    if($image)
        //imagesetpixel($image, $lastVisible['x'], $lastVisible['y'], $blue);
        drawPoly($polygon, $image, $blue, $xSensor, $ySensor, $green, $sensorId, $orientation);
    
    return $polygon;
}

function outputSensorConf($placement, $outputFile)
{
    global $ceilingHeight, $imgHeight;
    $sensors = array();
    foreach($placement as $key)
    {
        $pixels = explode("|", substr($key, 1));
        $newKey = ($pixels[0]/100).",".(($imgHeight-$pixels[1])/100);
        $sensors[$newKey] = sensorPoly($key);
    }
    $counter = 1;
    $fileInput = "";
    foreach($sensors as $key => $val)
    {
        $fileInput .= "3;$counter;$key,$ceilingHeight;";
        $temp = array();
        foreach($val as $coord)
        {
            $temp[] =  ($coord['x']/100).",".(($imgHeight - $coord['y'])/100).",0.0";
        }
        $fileInput .= implode(";", $temp)."\n";
        $counter++;
    }
    file_put_contents($outputFile, trim($fileInput, "\n"));
    echo "Sensor configuration file has been saved into $outputFile.\n";
}

// this function calculates the objective value of a placement 
// using the update-of-heatmap-values mechanism
function calcObjectiveWithUpdate($placement)
{
    global $densityMap, $behindDoorMap, $doorOpenProb;
    $objective = 0;
    $updatedDensityMap = $densityMap;
    foreach($placement as $sensor => $key)
    {
        $increment = $objective;
        $coveredPoints = getCoveredPointsByKey($key);
        foreach($coveredPoints as $pix)
        {
            // if this is a valid grid point that has been covered
            if(isset($densityMap[$pix]))
            {
                // if this particular point is behind a door for this sensor position
                if(isset($behindDoorMap[$key][$pix]))
                    $coverageUnit = $doorOpenProb;
                else
                    $coverageUnit = 1;
                
                $objective += $coverageUnit*$updatedDensityMap[$pix];
                $updatedDensityMap[$pix] -= $coverageUnit;
            }
        }
        //echo "increment: ".($objective - $increment)."\n";
    }
    return $objective;
}

// this function calculates the objective value of a placement 
// using the closed form expression for the objective function
// i.e. sum of sensor coverage models multiplied by coverage utility values (here - density)
// minus the penalty: sum of all possible pairwise products of sensor coverage models
function calcObjectiveWithPenalty($placement)
{
    global $densityMap, $behindDoorMap, $doorOpenProb, $gridBelongsToSensors;
    $coveredUtility = 0;
    $coveredPoints = array();
    $updatedDensityMap = $densityMap;
    foreach($placement as $sensor => $key)
    {
        if (!isset($coveredPoints[$key]))
            $coveredPoints[$key] = getCoveredPointsByKey($key);
        foreach($coveredPoints[$key] as $pix)
        {
            // if this is a valid grid point that has been covered
            if(isset($densityMap[$pix]))
            {
                // if this particular point is behind a door for this sensor position
                if(isset($behindDoorMap[$key][$pix]))
                    $coverageUnit = $doorOpenProb;
                else
                    $coverageUnit = 1;
                
                $coveredUtility += $coverageUnit*$updatedDensityMap[$pix];
            }
        }
    }
    $penalty = 0;
    for ($i = 0; $i < count($placement); $i++)
    {
        for ($j = 1; $j < count($placement); $j++)
        {
            if ($i < $j)
            {
                foreach($coveredPoints[$placement[$i]] as $pix)
                {
                    if(isset($densityMap[$pix]))
                    {
                        if(in_array($pix, $coveredPoints[$placement[$j]]))
                        {
                            if(isset($behindDoorMap[$placement[$i]][$pix]) && 
                                    isset($behindDoorMap[$placement[$j]][$pix]))
                                $penalty += $doorOpenProb*$doorOpenProb;
                            else if(isset($behindDoorMap[$placement[$i]][$pix]) || 
                                    isset($behindDoorMap[$placement[$j]][$pix]))
                                $penalty += $doorOpenProb;
                            else
                                $penalty += 1;
                        }
                    }
                }
            }
        }
    }
    return $coveredUtility - $penalty;
}

// greedy selection of sensors with the maximum updated-heat score
function recurPlacementUpdateHeatmap(&$placement, $maxNumber=0, $sensorPos="", $objective = 0)
{
    global $totalHeatScore, $gridBelongsToSensors, $sensorScoreMap, $gridMap, $densityMap,
        $maxGridCoverage, $behindDoorMap, $doorOpenProb, $densityPerSensor, 
            /*$initialDensityPerSensor,*/ $totalDensity;
    //print_r($densityMap);
    if($sensorPos)
    {
        $placement[] = $sensorPos;
        $currentNumber = count($placement);
        // if we reached the number of sensors, quit
        if($currentNumber == $maxNumber)
        {
            echo "Objective value calculated during greedy selection: $objective\n";
            echo "Objective value calculated with update: ".calcObjectiveWithUpdate($placement)."\n";
            echo "Objective value calculated with penalty: ".calcObjectiveWithPenalty($placement)."\n";
            $tempPlacement = $placement;
            sort($tempPlacement);
            print_r($tempPlacement);
            echo "Objective value for a shuffled placement calculated with update: ".calcObjectiveWithUpdate($tempPlacement)."\n";
            echo "Objective value for a shuffled placement calculated with penalty: ".calcObjectiveWithPenalty($tempPlacement)."\n";
            return;
        }

        $coveredPoints = getCoveredPointsByKey($sensorPos);
        foreach($coveredPoints as $pix)
        {
            // if this is a valid grid point that has been covered
            if(isset($densityMap[$pix]))
            {
                foreach($gridBelongsToSensors[$pix] as $plKey)
                {
                    // if this particular point is behind a door for this sensor position
                    if(isset($behindDoorMap[$plKey][$pix]) && isset($behindDoorMap[$sensorPos][$pix]))
                        $subtract = $doorOpenProb*$doorOpenProb;
                    else if(isset($behindDoorMap[$plKey][$pix]) || isset($behindDoorMap[$sensorPos][$pix]))
                        $subtract = $doorOpenProb;
                    else
                        $subtract = 1;
                    // to ensure that the points with 0 score won't get negative
                    //if ($densityPerSensor[$plKey] > 0)
                        $densityPerSensor[$plKey] -= $subtract;
                }
            }
        }
    }
    // looking for the next highest score
    $maxHeat = 0; $maxVal = 0; $maxValKey = "";
    $alpha = 0.999;// 0.9; // for best placement so far
    $beta = 1; // 0.9; // for best placement so far
    foreach($densityPerSensor as $plKey => $scr)
    {
//        $combinedScore = $alpha*($scr/$totalHeatScore) + (1-$alpha)*
//            ($beta*$gridMapUpdate[$plKey] + (1-$beta)*$gridMap[$plKey]/$maxGridCoverage);
//        $combinedScore = $alpha*($scr/$totalDensity) + (1-$alpha)*
//            ($beta*$gridMapUpdate[$plKey] + (1-$beta)*$gridMap[$plKey]/$maxGridCoverage);
//        $combinedScore = $alpha*($scr/$totalDensity) + (1-$alpha)*
//            ($beta*$uncovered/$maxGridCoverage + (1-$beta)*$sensorScoreMap[$plKey]/$totalHeatScore);
//        $combinedScore = $alpha*($scr/$totalDensity) + (1-$alpha)*
//            ($beta*$gridMap[$plKey]/$maxGridCoverage + (1-$beta)*$initialDensityPerSensor[$plKey]/$totalDensity);
        $combinedScore = $alpha*($scr/$totalDensity) + (1-$alpha)*
            ($beta*$gridMap[$plKey]/$maxGridCoverage + (1-$beta)*$sensorScoreMap[$plKey]/$totalHeatScore);

        if($combinedScore > $maxVal /*&& $scr > 0*/)
        {
            $maxVal = $combinedScore;
            $maxValKey = $plKey;
            $maxHeat = $scr;
        }
    }
    if($maxVal > 0)
    {
        echo "$maxValKey => $maxVal; score => $maxHeat; actual heat => {$sensorScoreMap[$maxValKey]}\n";
        $objective += $maxHeat;
        recurPlacementUpdateHeatmap($placement, $maxNumber, $maxValKey, $objective);
    }
}

function getCoveredPointsByKey($key, &$area=null, &$grid=null, &$x=null, &$y=null, &$xDimension=null, &$yDimension=null)
{
    global $horizSensors, $vertSensors, $sensWidthPix, $sensHeightPix;
    $pixels = explode("|", substr($key, 1));
    $orientation = $key[0];
    if($orientation == "h")
    {
        $scoreScheme = $horizSensors;
        $xDimension = $sensWidthPix;
        $yDimension = $sensHeightPix;
    }
    else
    {
        $scoreScheme = $vertSensors;
        $xDimension = $sensHeightPix;
        $yDimension = $sensWidthPix;
    }
    // find out whether the coverage grid points from the candidate sensor have been covered already
    $x = $pixels[0];
    $y = $pixels[1];
    $area = $scoreScheme[$x][$y]['area'];
    $grid = $scoreScheme[$x][$y]['grid'];
    $covered = $scoreScheme[$x][$y]['covered'];
    $coveredPoints = explode(";", trim($covered, ";"));
    return $coveredPoints;
}

function drawPlacement($placement, $image)
{
    global $horizSensors, $vertSensors, $blue, $green, $red, $sensWidthPix, $sensHeightPix;
    foreach($placement as $key)
    {
        $pixels = explode("|", substr($key, 1));
        $orientation = $key[0];
        if($orientation == "h")
        {
            $scoreScheme = $horizSensors;
            $sensWidth = $sensWidthPix;
            $sensHeight = $sensHeightPix;
        }
        else
        {
            $scoreScheme = $vertSensors;
            $sensWidth = $sensHeightPix;
            $sensHeight = $sensWidthPix;
        }
        // find out whether the coverage grid points from the candidate sensor have been covered already
        $covered = $scoreScheme[$pixels[0]][$pixels[1]]['covered'];
        //rayTraceSensorCoverage($sensWidth, $sensHeight, $pixels[0], $pixels[1]);
        findBoundaryGrid($pixels[0], $pixels[1], $sensWidth, $sensHeight, $l, $r, $t, $b, $minX, $minY, $maxX, $maxY);
        $coveredPoints = explode(";", trim($covered, ";"));
        if(isset($scoreScheme[$pixels[0]][$pixels[1]]['door']))
            $doorPoints = array_fill_keys(explode(";", trim($scoreScheme[$pixels[0]][$pixels[1]]['door'], ";")), 1);
        foreach($coveredPoints as $k => $pix)
        {
            $p = explode(" ", $pix);
            if($p[0] == $minX || $p[0] == $maxX || $p[1] == $minY || $p[1] == $maxY)
                imagesetthickness($image, 3);
            else
                imagesetthickness($image, 1);
            if(isset($doorPoints[$pix]))
            {
                $color = $red;
                if($p[0] == $minX || $p[0] == $maxX || $p[1] == $minY || $p[1] == $maxY)
                imagesetthickness($image, 2);
                else
                    imagesetthickness($image, 1);
                imageline($image, $p[0] - 3, $p[1], $p[0] + 3, $p[1], $color);
                imageline($image, $p[0], $p[1] - 3, $p[0], $p[1] + 3, $color);
            }
            else
            {
                $color = $blue;
                imageline($image, $p[0] - 1, $p[1], $p[0] + 1, $p[1], $color);
            }
        }
        imagesetthickness($image, 6);
        imageline($image, $pixels[0] - 3, $pixels[1], $pixels[0] + 3, $pixels[1], $green);
        imagesetthickness($image, 1);
    }
}

function calcMetrics($placement)
{
    global $score, $pixelMatrix, $sensorCoveredGridNumber, $densityMap;
    $gridScore = 0;
    $totScore = 0;
    $unionOfDensityScore = 0;
    $coveredSet = array();
    $maxGridCoverage = count($score);
    $maxHeatScore = array_sum($score);
    foreach($placement as $sensor => $key)
    {
        $coveredPoints = getCoveredPointsByKey($key);
        foreach($coveredPoints as $pix)
        {
            if(!isset($coveredSet[$pix]))
                $coveredSet[$pix] = array($sensor);
            else
                $coveredSet[$pix][] = $sensor;
        }
    }
    $covScore = count($coveredSet);
    $overlapRegionCount = array();
    $overlapRegions = array();
    $overlapDiagonals = array();
    $sensorOverlapCount = array();
    foreach($coveredSet as $pix => $arr)
    {
        $pixels = explode(" ", $pix);
        // if this is a valid grid point, add to the score
        if(isset($pixelMatrix[$pixels[0]][$pixels[1]]))
        {
            $totScore += $score[$pixelMatrix[$pixels[0]][$pixels[1]]];
            $unionOfDensityScore += $densityMap[$pixels[0]." ".$pixels[1]];
            $gridScore++;
        }
        if(!isset($overlapRegionCount[implode(" ", $arr)]))
            $overlapRegionCount[implode(" ", $arr)] = 1;

        else
            $overlapRegionCount[implode(" ", $arr)]++;
        $overlapRegions[implode(" ", $arr)][$pixels[0]][$pixels[1]] = 1;
    }
    $numberOfSensors = count($placement);
    $numberOfUniqueRegions = count($overlapRegionCount);
    foreach($overlapRegionCount as $key => $v)
    {
        $arr = explode(" ", $key);
        if(!isset($sensorOverlapCount[count($arr)]))
            $sensorOverlapCount[count($arr)] = 1;
        else
            $sensorOverlapCount[count($arr)]++;
        $minX = -1; $minY = -1; $maxX = 0; $maxY = 0;
        foreach($overlapRegions[$key] as $x => $var)
        {
            $minX = ($minX < 0 || $x < $minX) ? $x : $minX;
            $maxX = $x > $maxX ? $x : $maxX;
            foreach($var as $y => $n)
            {
                $minY = ($minY < 0 || $y < $minY) ? $y : $minY;
                $maxY = $y > $maxY ? $y : $maxY;
            }
        }
        $overlapDiagonals[$key] = round(distance($minX, $minY, $maxX, $maxY), 2);
    }
    //print_r($overlapDiagonals);
    $diagonalMean = round(array_sum($overlapDiagonals)/count($overlapDiagonals), 2);
    $stdDevArr = array();
    foreach($overlapDiagonals as $diag)
    {
        $stdDevArr[] = ($diag - $diagonalMean)*($diag - $diagonalMean);
    }
    // additional metrics
    $stdDev = round(sqrt(array_sum($stdDevArr)/count($overlapDiagonals)), 2);
    $gridScorePercent = round($gridScore/$maxGridCoverage, 2);
    $covScorePercent = round($covScore/$sensorCoveredGridNumber, 2);
    $totScorePercent = round($totScore/$maxHeatScore, 2);
    $overRegionsPerSensor = round($numberOfUniqueRegions/$numberOfSensors, 2);
    ksort($sensorOverlapCount);
    // this metric can be interpreted as: the fewer sensors cover a region, the more reliable is detection
    // thus the lower is this value, the better is placement
    $sensorPerRegionTotal = 0;
    foreach($sensorOverlapCount as $number => $regions)
    {
        $sensorPerRegionTotal += $number*$regions;
    }

    $stats = array(
        'number' => $numberOfSensors,
        'coverage' => $covScorePercent,
        'grid' => $gridScorePercent,
        'heat' => $totScorePercent,
        'regions' => $numberOfUniqueRegions,
        'meanDiag' => $diagonalMean,
        'stdDiag' => $stdDev,
        'diagSum' => $diagonalMean+$stdDev,
        'sensRegionCoef' => $sensorPerRegionTotal,
        'sensorPerRegion' => $sensorOverlapCount,
        'union of covered density' => $unionOfDensityScore
    );
    
    //print_r($sensorOverlapCount);
    //addStats($stats);
    return $stats;
}

function outputPlacementInfo($placement, $polyImage="", $gridImage="", $outputStats=0, 
        $outputSensorConf="", $outputFaultConf="", &$imgPl=null)
{
    global $cleanFloorplan;
    echo "Outputting placement data...\n";
    $numberOfSensors = count($placement);
    $cliOut = false;
    if(!$numberOfSensors)
    {
        echo "EMPTY placement!\n";
        return;
    }
    if($gridImage)
    {
        $img = @imagecreatefrompng($cleanFloorplan);
        drawPlacement($placement, $img);
        $imageName = "$gridImage-$numberOfSensors-sens.png";
        echo "A grid image has been saved into $imageName.\n";
        imagepng($img, $imageName);
        imagedestroy($img);
    }
    if($polyImage)
    {
        if(!isset($imgPl))
            $imgPl = @imagecreatefrompng($cleanFloorplan);
        $counter = 1;            
        foreach($placement as $key)
        {
            if($cliOut)
                echo "$counter => \"$key\",\n";
            sensorPoly($key, $imgPl, $counter);
            $counter++;
        }
        $imageName = "$polyImage-$numberOfSensors-sens.png";
        echo "An image with sensor polygons has been saved into $imageName.\n";
        imagepng($imgPl, $imageName);
        imagedestroy($imgPl);
    }
    if($outputStats)
    {
        $stats = calcMetrics($placement);
        print_r($stats);
    }
    if($outputSensorConf !== "")
        outputSensorConf($placement, "$outputSensorConf.txt");
    
    if($outputFaultConf)
    {
        $counter = 1;
        foreach($placement as $key=>$val)
        {
            $faultyPlacement = $placement;
            // delete one sensor - one faulty sensor in the placement
            unset($faultyPlacement[$key]);
            outputSensorConf($faultyPlacement, "$outputFaultConf.$counter.txt");
            $counter++;
        }
    }
}
/****** the end of functions ******/
?>