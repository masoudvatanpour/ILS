#!/bin/bash
rm -f *.txt
echo "Convert Floor Plan ==============================================================="
php convertFloorplan.php
echo
echo "Produce Adtivity Script =========================================================="
php produceActivityScript.php 100 uniform
echo
echo "Produce Trace ===================================================================="
php produceTrace.php activityScript.txt
echo
echo "Produce Heatmap =================================================================="
php produceHeatmap.php uniform
echo
echo "Get Final Heatmap ================================================================"
php placeSensors.php optimal 1 smooth_score_uniform_0.3noise.txt 5
echo
echo "=================================================================================="
#rm -f *.txt
