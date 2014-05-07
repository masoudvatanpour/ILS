<?php
/****** various configurable parameters ******/

//all units are meters
$condoWidth = 10.02; // hight is 651 px * 1.75 cm/px = 11.39 meters, width is 573 px * 1.75 cm/px = 10.02 meters
$gridStep = 0.1;
$bodyDiameter = 0.4;

// boundaries of area that can be taken by the movables (meters)
// assuming that the left bottom corner of the floorplan is (0,0) meters
$movableArea = array(
    'minX' => 0,
    'minY' => 5,
    'maxX' => 4.87,
    'maxY' => 10.02
);
// sensor range is horizontal rectangular, in meters, defined as dimensions of a cross-section at specified height
$sensorRangeAtHeight = array(
    'height' => 2,
    'x' => 2,
    'y' => 1.4,
    'maxZ' => 5
);
$ceilingHeight = 2.4;
// in meters, the physical sensor width, should be considered if the sensor is placed right next to a wall
$sensorWidth = 0.06;

// other (non-meter) parameters

// percent of the grid nodes randomly made unwalkable at each path generation
$obstacleGridPercent = 0.3;
// probability that the door is open
$doorOpenProb = 0.1;

// color-coding scheme:
// make sure that the floorplan image has 32 bit of color depth
// or change the color numerical values with respect to the color depth of the image
$colors = array();
$colors['grid'] = 0xffffff;
$colors['walls'] = 0x000000;
$colors['obstacle'] = 0xc0c0c0;
$colors['destination'] = 0xff0000;
$colors['movable'] = 0x00ff00;
$colors['door'] = 0xffff00;

// an array of all the colors except walls and obstacles
$walkable = array($colors['grid'], $colors['destination'], $colors['movable'], $colors['door']);
// "non-walkable" colors
$nonWalkable = array($colors['walls'], $colors['obstacle']);

// initial floorplan image
$floorplanFile = "floorplan.png";
// clean floorplan - i.e. contains only walls and obstacles and all other elements erased
// used as a base for all other images (heatmaps, placements, etc.)
$cleanFloorplan = "clean_floorplan.png";

// names of files with serialized versions of the data structures described above
$gridFile = "grid.txt";
$destinationFile = "destinationGroups.txt";
$movableFile = "movableGroups.txt";
$pixelMatrixFile = "pixelMatrix.txt";
$wallMatrixFile = "wallMatrix.txt";
$doorMatrixFile = "doorMatrix.txt";
$obstacleMatrixFile = "obstacleMatrix.txt";
$existPolyFile = "sensorPolygons.txt";

// types of sensor placement
$typesOfPlacement = array('manual', 'random', 'optimal');

// types of mobility models, 
// IMPORTANT! These will change with a different floorplan or if more objects are added!
// indices on the left correspond to the "destination" objects from the floorplan (i.e. $destinationGroups)
// numbers on the right represent weights of each object importance in a daily routine
// current weight assignment is based on the number of times an object can be used within a single day
$mobilityModelWeights = array(
    // more active occupant is likely to use all the objects fairly frequently
    "active" => array(
        0 => 3, // fireplace
        1 => 5, // left recliner
        2 => 2, // bed, used in the morning, night
        3 => 3, // right recliner
        4 => 7, // living room table
        5 => 4, // washroom sink
        6 => 4, // toilet
        7 => 2, // bathtub
        8 => 1, // left side of the kitchen counter
        9 => 1, // washing machine, once a day
        10 => 5, // right side of the kitchen counter
        11 => 5, // kitchen sink
        12 => 3, // stove and microwave
        13 => 7, // fridge
        14 => 3 // entrance door
    ),
    // passive occupant
    "passive" => array(
        0 => 0.5, // fireplace is not frequently visited, let's say about once in two days
        1 => 2, // left recliner
        2 => 5, // bed, about 5 times (come and leave), morning, night, naps, getup during the night
        3 => 2, // right recliner
        4 => 4, // living room table
        5 => 6, // washroom sink
        6 => 6, // toilet
        7 => 1, // bathtub
        8 => 1, // left side of the kitchen counter
        9 => 0.2, // washing machine, once in 5 days or so
        10 => 3, // right side of the kitchen counter
        11 => 3, // kitchen sink
        12 => 3, // stove and microwave
        13 => 4, // fridge
        14 => 1 // entrance door, used once a day on average
    ),
    // uniform mobility model - useful for tests
    "uniform" => array(
        0 => 1, // fireplace
        1 => 1, // left recliner
        2 => 1, // bed, used in the morning, night
        3 => 1, // right recliner
        4 => 1, // living room table
        5 => 1, // washroom sink
        6 => 1, // toilet
        7 => 1, // bathtub
        8 => 1, // left side of the kitchen counter
        9 => 1, // washing machine, once a day
        10 => 1, // right side of the kitchen counter
        11 => 1, // kitchen sink
        12 => 1, // stove and microwave
        13 => 1, // fridge
        14 => 1, // entrance door
        15 => 1,
        16 => 1,
        17 => 1,
        18 => 1,
        19 => 1,
        20 => 1,
        21 => 1,
        22 => 1,
        23 => 1,
        24 => 1,
        26 => 1,
        27 => 1
    )
);
?>
