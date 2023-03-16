<?php

include 'vendor/autoload.php';

$cron = '0 15 0 0 1,2';
$str = (new Panlatent\CronExpressionDescriptor\ExpressionDescriptor($cron, 'en_AU'))->getDescription();
$str = preg_replace('#0([1-9]):#', '$1:', $str);

echo $str;
