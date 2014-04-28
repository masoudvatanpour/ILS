#!/bin/bash
rm -f *.txt
php convertFloorplan.php
echo
echo "=================================================================================="
php produceActivityScript.php 10 uniform
echo
echo "=================================================================================="
php produceTrace.php activityScript.txt
echo
echo "=================================================================================="
php produceHeatmap.php uniform
echo
echo "=================================================================================="
rm -f *.txt
