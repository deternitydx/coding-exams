<?php
ini_set("display_errors", 1);
ini_set("track_errors", 1);
ini_set("html_errors", 1);
error_reporting(E_ALL);

include("../../vendor/autoload.php");

use \Monolog\Logger;
use \Monolog\Handler\StreamHandler;
// Set up the global log stream
$loglevel = Logger::DEBUG;
$log = new StreamHandler(\manager\Config::$LOG_FILE, $loglevel);

$helper = new \manager\control\Helper();

// helper function for CSV
if (!function_exists('str_putcsv')) {
    function str_putcsv($input, $delimiter = ',', $enclosure = '"') {
        $fp = fopen('php://temp', 'r+b');
        fputcsv($fp, $input, $delimiter, $enclosure);
        rewind($fp);
        $data = rtrim(stream_get_contents($fp), "\n");
        fclose($fp);
        return $data;
    }
}


$input = array();
$in = array_merge($_POST, $_GET);
foreach ($in as $k => $v) {
    $input[str_replace("_", " ", $k)] = $v;
}
$helper->setInput($input);

$command = "";
if(isset($input["c"]))
    $command = $input["c"];

switch ($command) {
    case "exam":
        echo $helper->showExam();
        break;
    case "save_exam":
        header("Content-type:application/json;charset=utf-8");
        echo $helper->handleSaveExam($input);
        break;
    case "submit_exam":
        echo $helper->handleSubmitExam($input);
        break;
    case "create_course":
        echo $helper->createCourse();
        break;
    case "new_course":
        echo $helper->newCourse();
        break;
    case "new_exam":
        echo $helper->newExam();
        break;
    case "create_exam":
        echo $helper->createExam();
        break;
    case "grade":
        echo $helper->gradeExam();
        break;
    case "grade_random":
        echo $helper->gradeRandom();
        break;
    case "save_grade":
        echo $helper->saveGrade();
        break;
    case "cancel_grade":
        echo $helper->cancelGrade();
        break;
    case "save_grade_next":
        echo $helper->saveNextGrade();
        break;
    case "download":
        echo $helper->downloadGrades();
        break;
    default:
        echo $helper->showHome();
        break;
}


