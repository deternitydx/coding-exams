<?php
/**
 * This file contains the main command logic. 
 *
 * @author Robbie Hott
 * @license https://opensource.org/licenses/Apache-2.0 Apache-2.0
 * (except str_putcsv as noted below)
 */
include("../../vendor/autoload.php");

/**
ini_set("display_errors", 1);
ini_set("track_errors", 1);
ini_set("html_errors", 1);
error_reporting(E_ALL);
*/

use \Monolog\Logger;
use \Monolog\Handler\StreamHandler;
// Set up the global log stream
$loglevel = Logger::DEBUG;
$log = new StreamHandler(\manager\Config::$LOG_FILE, $loglevel);

$helper = new \manager\control\Helper();

// helper function for CSV
// From: https://gist.github.com/johanmeiring/2894568
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
    case "open_exam":
        echo $helper->openExam();
        break;
    case "close_exam":
        echo $helper->closeExam();
        break;
    case "grade":
        echo $helper->gradeExam();
        break;
    case "grade_stats":
        echo $helper->gradeExamStats();
        break;
    case "grade_random":
        echo $helper->gradeOne();
        break;
    case "grade_one":
        echo $helper->gradeOne(); // checks for input "p"
        break;
    case "similarities":
        echo $helper->checkSimilarities();
        break;
    case "save_grade":
        echo $helper->saveGrade();
        break;
    case "cancel_grade":
        echo $helper->cancelGrade();
        break;
    case "grade_checkin":
        echo $helper->checkinAllUngraded();
        break;
    case "save_grade_next":
        echo $helper->saveNextGrade();
        break;
    case "download":
        echo $helper->downloadGrades();
        break;
    case "report":
        echo $helper->showReport();
        break;
    case "add_participant":
        echo $helper->showAddParticipant();
        break;
    case "add_participant_post":
        echo $helper->addParticipant();
        break;
    case "full_report":
        echo $helper->showGrades();
        break;
    case "view_student_exam":
        echo $helper->showStudentExam();
        break;
    case "autograde":
        echo $helper->runJUnitClass();
        break;
    default:
        echo $helper->showHome();
        break;
}


