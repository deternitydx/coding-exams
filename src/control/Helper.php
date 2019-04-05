<?php
namespace manager\control;

use \manager\Config as Config;

class Helper {

    private $dData;
    private $db;
    private $user;
    private $input;
    private $logger;

    public function __construct() {
        global $log;
        $this->logger = new \Monolog\Logger('Helper');
        $this->logger->pushHandler($log);

        $this->dData = array();
        $this->db = new \manager\control\DatabaseConnector();
        if (!isset($_SERVER["uid"])) {
            die(); // update soon
        }
        $this->user = $this->readUser($_SERVER["uid"]);
        if ($this->user == null)
            die($this->showError());
    }

    public function readUser($uid) {
        $res = $this->db->query("select * from person where uva_id = $1", array($uid));
        $data = $this->db->fetchAll($res);

        if (isset($data[0]) && isset($data[0]["name"])) {
            return [
                "id" => $data[0]["id"],
                "uva_id" => $data[0]["uva_id"],
                "name" => $data[0]["name"]
            ];
        }
        return null;
    }

    public function showExam() {
        // load questions dies with error if student not allowed to take exam
        $this->dData = $this->loadQuestions();
        return $this->display("question"); 
    }

    public function showHome() {
        $this->dData = $this->loadCourses();
        return $this->display("home");
    }

    public function newExam() {
        $data = $this->loadCourses();
        $cid = $this->input["course"];
        $course = null;
        $allowed = false;
        foreach ($data as $y) {
            foreach ($y as $c) {
                if ($c["id"] == $cid && $c["role"] == "Instructor") {
                    $allowed = true;
                    $course = $c;
                }
            }
        }
        if ($allowed) {
            $this->dData = $course;
            return $this->display("newexam");
        }
        die($this->showError("Not Authorized"));
    }

    public function createExam() {
        $data = $this->loadCourses();
        $cid = $this->input["course"];
        $course = null;
        $allowed = false;
        foreach ($data as $y) {
            foreach ($y as $c) {
                if ($c["id"] == $cid && $c["role"] == "Instructor") {
                    $allowed = true;
                    $course = $c;
                }
            }
        }
        if (!$allowed) {
            die($this->showError("Not Authorized"));
        }
        // create the exam
        $res = $this->db->query("insert into exam (course_id, title) values ($1, $2) returning id;", array(
            $course["id"],
            $this->input["name"]
        ));
        $tmp = $this->db->fetchAll($res);
        if (count($tmp) != 1) {
            die($this->showError("Database Error"));
        }
        $eid = $tmp[0]["id"];

        foreach ($this->input["question"] as $k => $q) {
            $res = $this->db->query("insert into question (exam_id, ordering, text, code, correct, rubric, score) values ($1, $2, $3, $4, $5, $6, $7);",
                [
                    $eid,
                    $k, 
                    $q, 
                    isset($this->input["code"][$k]) ? $this->input["code"][$k] : "",
                    isset($this->input["answer"][$k]) ? $this->input["answer"][$k] : "",
                    isset($this->input["rubric"][$k]) ? $this->input["rubric"][$k] : "",
                    isset($this->input["score"][$k]) ? $this->input["score"][$k] : 0
                ]);
        } 

        header("Location: ?");
    }

    public function newCourse() {
        return $this->display("newcourse");
    }


    public function createCourse() {
        $res = $this->db->query("insert into course (uva_id, title, semester, year) values ($1, $2, $3, $4) returning id;", array(
            $this->input["uvaid"], 
            $this->input["name"],
            $this->input["semester"],
            $this->input["year"]
        ));
        $tmp = $this->db->fetchAll($res);
        if (count($tmp) != 1) {
            die($this->showError("Database Error"));
        }
        $cid = $tmp[0]["id"];
        
        $fn = $this->handleFileUpload("roster");
        $fp = fopen($fn, 'r');
        $i = 0;
        while (($line = fgetcsv($fp, 1000, ",")) !== FALSE) {
            if ($i++ > 3) { // skip the first 4 lines
                // check if in the person table (and get pid)
                // if not, add to person table returning pid
                // add to person_course table with role
                // $line[0] = name
                // $line[1] = uva_id
                // $line[3] = role    
                $resP = $this->db->query("select id from person where uva_id = $1", [
                    $line[1]
                ]);
                $allP = $this->db->fetchAll($resP);

                // If person doesn't exist, create them 
                if (!(isset($allP[0]) && isset($allP[0]["id"]))) {
                    $resP = $this->db->query("insert into person (uva_id, name) values ($1, $2) returning id;",
                        [
                            $line[1],
                            $line[0]
                        ]
                    );
                    $allP = $this->db->fetchAll($resP);
                    if (count($allP) != 1) {
                        die($this->showError("Database Error"));
                    }
                }
                $pid = $allP[0]["id"];

                $resPC = $this->db->query("insert into person_course (course_id, person_id, role) values ($1, $2, $3);", [
                    $cid,
                    $pid,
                    $line[3]
                ]);
            }
        }
        fclose($fp);

        return $this->showHome();
    }

    public function handleSaveExam() {
        $result = ["result" => "error", "error"=> "Unknown error occurred"];
        $this->loadQuestions();

        foreach ($this->input["q"] as $k => $q) {
            $res = $this->db->query("select pq.response from person_question pq where pq.person_id = $1
                and pq.question_id = $2 and pq.exam_id = $3;", [$this->user["id"], $q, $this->input["e"]]);
            $all = $this->db->fetchAll($res);

            // If student already has written a partial response, then replace
            if (isset($all[0]) && isset($all[0]["response"])) {
                $res = $this->db->query("update person_question set response = $4 where person_id = $1 and question_id = $2 and exam_id = $3;",
                    [$this->user["id"], $q, $this->input["e"], $this->input["response"][$k]]);
            } else {
                $res = $this->db->query("insert into person_question (person_id, question_id, exam_id, response) values ($1, $2, $3, $4);",
                    [$this->user["id"], $q, $this->input["e"], $this->input["response"][$k]]);
            }
        }
        $result = ["result" => "success", "submission" => $this->input];
        return json_encode($result, JSON_PRETTY_PRINT);
    }

    public function handleSubmitExam() {
        $this->logger->addDebug("Submitting exam", $this->input);
        $saved = json_decode($this->handleSaveExam(), true);
        if ($saved["result"] != "success") {
            die($this->showError("An error occurred while saving the exam.  Please show this page to a TA.", $input));
        }
        $code = substr(sha1($this->user["uva_id"] . time()),0,8);
        $res = $this->db->query("update person_exam set date_taken = now(), code = $3 where person_id = $1 and exam_id = $2;",
            [$this->user["id"], $this->input["e"], $code]);

        $this->dData["code"] = $code;
        return $this->display("submitsuccess");
    }

    public function gradeExam() {
        $data = $this->loadResults();
        // do grading
        $this->dData = $data;
        if (isset($this->input["m"])) {
            $m = $this->input["m"];
            if ($m == "done")
                $this->dData["message"] = "There are no more of that question to grade";
        }
        
        return $this->display("gradehome");
    }

    public function gradeOne() {
        $eid = $this->input["e"];
        
        $uid = null;
        if (isset($this->input["p"]))
            $uid = $this->input["p"];

        $examInfo = [];
        $res = $this->db->query("select 
            e.id, e.date, e.open, e.close, e.title from course c, exam e, person_course pc 
            where e.course_id = c.id and pc.course_id = c.id and pc.person_id = $1 and
                pc.role in ('Instructor', 'Teaching Assistant', 'Secondary Instructor')
                and e.id = $2", 
            [$this->user["id"], $eid]);
        $all = $this->db->fetchAll($res);
        $allowed = false;
        foreach ($all as $row) {
            if ($row["id"] == $eid) {
                $allowed = true;
                $examInfo = $row;
                break;
            }
        }
        
        if (!$allowed)
            die($this->showError("You do not have permissions to view this exam"));

        if (!isset($this->input['q']))
            die($this->showError("No Question"));

        // TODO The select and update NEED TO BE ATOMIC
        $all = [];
        if ($uid == null) {
            // select one at random
            $res = $this->db->query("select pq.person_id, pq.question_id, pq.exam_id,
                pq.response, pq.feedback, pq.score as current_score, q.text, q.code, q.correct, q.rubric, q.score from person_question pq,
                question q where pq.score is null and pq.grader is null and 
                pq.question_id = q.id and pq.question_id = $1
                limit 1;", 
                [$this->input['q']]);
            $all = $this->db->fetchAll($res);
        } else {
            // select the specific one
            $res = $this->db->query("select pq.person_id, pq.question_id, pq.exam_id,
                pq.response, pq.feedback, pq.score as current_score, q.text, q.code, q.correct, q.rubric, q.score from person_question pq,
                question q where pq.person_id = $2 and 
                pq.question_id = q.id and pq.question_id = $1
                limit 1;", 
                [$this->input['q'], $uid]);
            $all = $this->db->fetchAll($res);
        }
        if (isset($all[0]) && isset($all[0]["question_id"])) {
            $data = $all[0];
            $res = $this->db->query("update person_question set grader = $3 where 
                person_id = $1 and question_id = $2;", [
                $data["person_id"],
                $data["question_id"],
                $this->user["id"]
            ]);
        } else {
            header("Location: ?c=grade&e=".$this->input['e']."&m=done");
        }
        if ($uid != null)
            $data["restricted"] = true;
        $this->dData = $data;
        return $this->display("grade_one");
    }

    public function cancelGrade() {
        // clear the grader for this particular grade/question
        if (!isset($this->input['question']) || !isset($this->input["student"]))
            return false; // can't grade without them

        $res = $this->db->query("select *
            from person_question
            where person_id = $1 and question_id = $2 and grader = $3;", 
            [
                $this->input['student'],
                $this->input['question'],
                $this->user["id"]
            ]);
        $all = $this->db->fetchAll($res);

        if (!isset($all[0]) || !isset($all[0]["exam_id"]))
            die($this->showError("You were not allowed to cancel this submission grade")); 

        $res = $this->db->query("update person_question
            set score = null, grader = null, feedback = null
            where person_id = $1 and question_id = $2 and grader = $3;", 
            [
                $this->input['student'],
                $this->input['question'],
                $this->user["id"]
            ]);

        header("Location: ?c=grade&e=".$this->input["exam"]); 
        return true;
    }

    private function saveGradeReal() {
        // save the grade into the database 
        if (!isset($this->input['question']) || !isset($this->input["student"]))
            return false; // can't grade without them

        $res = $this->db->query("select *
            from person_question
            where person_id = $1 and question_id = $2 and grader = $3;", 
            [
                $this->input['student'],
                $this->input['question'],
                $this->user["id"]
            ]);
        $all = $this->db->fetchAll($res);

        if (!isset($all[0]) || !isset($all[0]["exam_id"]))
            return false; // not grading this one!

        $score = 0;
        if ($this->input["score"] != "" && is_numeric($this->input["score"]))
            $score = $this->input["score"];

        $res = $this->db->query("update person_question
            set score = $4, feedback = $5, grade_time = now()
            where person_id = $1 and question_id = $2 and grader = $3;", 
            [
                $this->input['student'],
                $this->input['question'],
                $this->user["id"],
                $score,
                $this->input['comments']
            ]);

        return true;
    }

    public function saveNextGrade() {
        $result = $this->saveGradeReal();

        // check result
        if ($result === false)
           die($this->showError("You were not allowed to grade that submission")); 
        // show the next random one (no need to redirect)
        // reset the input
        $eid = $this->input["exam"];
        $qid = $this->input["question"];
        $this->input = ["e" => $eid, "q" => $qid];
        return $this->gradeOne();
    }
        
    public function saveGrade() {
        $result = $this->saveGradeReal();

        // check result
        if ($result === false)
           die($this->showError("You were not allowed to grade that submission")); 
        
        // show the grading
        header("Location: ?c=grade&e=".$this->input["exam"]); 
        return true; //$this->gradeExam();

    }

    private function loadQuestions($examid = null, $grading = false) {
        $exam = [];
        $eid = $examid;
        if ($examid == null && !isset($this->input["e"]))
            die($this->showError("Unknown Exam"));

        if ($examid == null)
            $eid = $this->input["e"];

        $res = $this->db->query("select 
            e.id, e.date, e.open, e.close from course c, exam e, person_course pc 
            where e.course_id = c.id and pc.course_id = c.id and pc.person_id = $1;", [$this->user["id"]]);
        $all = $this->db->fetchAll($res);
        $allowed = false;
        foreach ($all as $row) {
            if ($row["id"] == $eid) {
                $allowed = true;
                break;
            }
        }

        //TODO code in a password option for SDAC that overrides allowed.  May also hard-code a few passwords for
        // this semester only, so that students can't take it.
        if (!$allowed)
            die($this->showError("You may not take this exam at this time"));

        $res = $this->db->query("select e.exam_id, e.date_taken from person_exam e where e.exam_id = $1 and e.person_id = $2 and e.date_taken is not null", [$eid, $this->user["id"]]);
        $all = $this->db->fetchAll($res);
        if (isset($all[0])) {
            die($this->showError("You may not retake the same exam twice."));
        }

        
        $res = $this->db->query("select e.id as exam_id, e.title, e.open, e.close, e.date, q.* from exam e, question q where e.id = $1 and q.exam_id = e.id order by q.ordering asc", [$eid]);
        $all = $this->db->fetchAll($res);

        foreach ($all as $row) {
            $exam["id"] = $row["exam_id"];
            $exam["title"] = $row["title"];
            $exam["open"] = $row["open"];
            $exam["close"] = $row["close"];
            $exam["date"] = $row["date"];
            if (!isset($exam["questions"]))
                $exam["questions"] = [];
            $exam["questions"][$row["ordering"]] = [
                "id" => $row["id"],
                "text" => $row["text"],
                "code" => $row["code"]
            ];

            $res2 = $this->db->query("select pq.response from person_question pq where pq.person_id = $1
                and pq.question_id = $2 and pq.exam_id = $3;", [$this->user["id"], $row["id"], $row["exam_id"]]);
            $all2 = $this->db->fetchAll($res2);

            // If student already has written a partial response, then replace the original
            if (isset($all2[0]) && isset($all2[0]["response"])) {
                $exam["questions"][$row["ordering"]]["code"] = $all2[0]["response"];
            }
        }
        
        $res2 = $this->db->query("select date_started from person_exam where person_id = $1
            and exam_id = $2;", [$this->user["id"], $row["exam_id"]]);
        $all2 = $this->db->fetchAll($res2);
        if (!(isset($all2[0]) && isset($all2[0]["date_started"]))) {
            $res = $this->db->query("insert into person_exam (person_id, exam_id, date_started) values ($1, $2, now());",
                [$this->user["id"], $row["exam_id"]]);
        }
        return $exam;
    }

    private function loadCourses() {
        $exams = [];
        $res = $this->db->query("select c.title as course, c.year, c.semester, c.uva_id as courseid, c.id, pc.role 
            from course c, person_course pc 
            where pc.course_id = c.id and pc.person_id = $1 
            order by c.year desc;", [$this->user["id"]]);
        $all = $this->db->fetchAll($res);

        foreach ($all as $row) {
            if (!isset($exams[$row["year"]]))
                $exams[$row["year"]] = [];
            if (!isset($exams[$row["year"]][$row["courseid"]]))
                $exams[$row["year"]][$row["courseid"]] = [
                    "title" => $row["course"],
                    "uva_id" => $row["courseid"],
                    "id" => $row["id"],
                    "role" => $row["role"],
                    "exams" => []
                ];
        }
        
        $res = $this->db->query("select c.title as course, c.year, c.semester, c.uva_id as courseid, e.title,
            e.id, e.date, e.open, e.close, pc.role from course c, exam e, person_course pc where e.course_id = c.id and pc.course_id = c.id and pc.person_id = $1 order by c.year desc;", [$this->user["id"]]);
        $all = $this->db->fetchAll($res);

        if (empty($all))
            return $exams;

        foreach ($all as $row) {
            if (!isset($exams[$row["year"]]))
                $exams[$row["year"]] = [];
            if (!isset($exams[$row["year"]][$row["courseid"]]))
                $exams[$row["year"]][$row["courseid"]] = [
                    "title" => $row["course"],
                    "role" => $row["role"],
                    "exams" => []
                ];

            $exams[$row["year"]][$row["courseid"]]["exams"][$row["id"]] = [
                "title" => $row["title"],
                "id" => $row["id"],
                "date" => $row["date"],
                "open" => $row["open"],
                "close" => $row["close"]
            ];

            $res2 = $this->db->query("select * from person_exam where exam_id = $1 and person_id = $2;", [$row["id"], $this->user["id"]]);
            $all2 = $this->db->fetchAll($res2);
            if (isset($all2[0]) && isset($all2[0]["date_taken"])) {
                $exams[$row["year"]][$row["courseid"]]["exams"][$row["id"]]["date_taken"] = $all2[0]["date_taken"];
            }
        }
        
        return $exams;
    }

    public function loadResults($examid = null) {
        $eid = $examid;
        if ($examid == null && !isset($this->input["e"]))
            die($this->showError("Unknown Exam"));

        if ($examid == null)
            $eid = $this->input["e"];

        $examInfo = [];
        $res = $this->db->query("select 
            e.id, e.date, e.open, e.close, e.title from course c, exam e, person_course pc 
            where e.course_id = c.id and pc.course_id = c.id and pc.person_id = $1 and
                pc.role in ('Instructor', 'Teaching Assistant', 'Secondary Instructor')
                and e.id = $2", 
            [$this->user["id"], $eid]);
        $all = $this->db->fetchAll($res);
        $allowed = false;
        foreach ($all as $row) {
            if ($row["id"] == $eid) {
                $allowed = true;
                $examInfo = $row;
                break;
            }
        }
        
        if (!$allowed)
            die($this->showError("You do not have permissions to view this exam"));
        
        $res = $this->db->query("select q.* from question q where q.exam_id = $1 order by q.ordering asc", [$eid]);
        $all = $this->db->fetchAll($res);
        $examInfo["questions"] = [];
        foreach ($all as $row) {
            $examInfo["questions"][$row["ordering"]] = [
                "id" => $row["id"],
                "text" => $row["text"],
                "code" => $row["code"],
                "correct" => $row["correct"],
                "rubric" => $row["rubric"],
                "score" => $row["score"]
            ];
        }

        $exams = [];
        $questions = [];
        $res = $this->db->query("select e.person_id, e.date_started, e.date_taken, e.code, u.uva_id, u.name, pc.role from person_exam e, person u, exam ex, person_course pc where e.exam_id = $1 and e.person_id = u.id and pc.person_id = u.id and ex.id = e.exam_id and pc.course_id = ex.course_id;", [$eid]);
        $all = $this->db->fetchAll($res);
        foreach ($all as $exam) {
            $exams[$exam["person_id"]] = [
                "uva_id" => $exam["uva_id"],
                "name" => $exam["name"],
                "role" => $exam["role"],
                "date_taken" => $exam["date_taken"],
                "date_started" => $exam["date_started"],
                "code" => $exam["code"],
                "questions" => []
            ];
        }
        
        $res = $this->db->query("select * from person_question where exam_id = $1
            order by question_id, person_id asc;", [$eid]);
        $all = $this->db->fetchAll($res);

        foreach ($all as $row) {
            if (!isset($questions[$row["question_id"]]))
                $questions[$row["question_id"]] = [
                    "answers" => []
                ];
            $questions[$row["question_id"]]["answers"][$row["person_id"]] = [
                "response" => $row["response"],
                "feedback" => $row["feedback"],
                "score" => $row["score"],
                "grader" => $row["grader"]
            ];

            $exams[$row["person_id"]]["questions"][$row["question_id"]] = [
                "response" => $row["response"],
                "feedback" => $row["feedback"],
                "score" => $row["score"],
                "grader" => $row["grader"]
            ];
        }

        foreach ($examInfo["questions"] as &$qinfo) {
            $total = 0;
            $graded = 0;
            foreach ($questions[$qinfo["id"]]["answers"] as $row) {
                if ($row["score"] != "")
                    $graded++;
                $total++;
            }
            $qinfo["total"] = $total;
            $qinfo["graded"] = $graded;
            $qinfo["percent"] = round(100*($graded/$total),0,PHP_ROUND_HALF_DOWN);
        }

        $recents = [];
        $res = $this->db->query("select * from person_question where exam_id = $1 and grader = $2
            order by grade_time desc;", [$eid, $this->user["id"]]);
        $all = $this->db->fetchAll($res);
        foreach ($all as $row) {
            if (!isset($recents[$row["question_id"]]))
                $recents[$row["question_id"]] = [];
            array_push($recents[$row["question_id"]], ["id" => $row["person_id"]]);
        }

        return ["info" => $examInfo, "exams" => $exams, "questions" => $questions, "recents" => $recents];

    }

    public function display($template) {
        $loader = new \Twig_Loader_Filesystem(\manager\Config::$TEMPLATE_DIR);
        $twig = new \Twig_Environment($loader, array(
            ));

        return $twig->render($template . ".html", array("data" => $this->dData, "user" => $this->user));
    
    }

    public function showError($str = "", $data = null) {
        if ($data != null) 
            $this->dData["output"] = json_encode($data, JSON_PRETTY_PRINT);
        if ($str != "")
            $this->dData["error"] = $str;
        return $this->display("error");
    }

    public function setInput($input) {
        $this->input = $input;
       // if ($input == null || empty($input))
         //   die($this->showError());

    }

    public function handleFileUpload($name) {
        if (
            !isset($_FILES[$name]['error']) ||
            is_array($_FILES[$name]['error'])
        ) {
            throw new \RuntimeException('Invalid parameters.');
        }

        // Check $_FILES[$name]['error'] value.
        switch ($_FILES[$name]['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                throw new \RuntimeException('No file sent.');
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new \RuntimeException('Exceeded filesize limit.');
            default:
                throw new \RuntimeException('Unknown errors.');
        }

        // You should also check filesize here.
        if ($_FILES[$name]['size'] > 100000000) {
            throw new \RuntimeException('Exceeded filesize limit.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        if (false === $ext = array_search(
            $finfo->file($_FILES[$name]['tmp_name']),
            array(
                'csv' => 'text/plain'
            ),
            true
        )) {
            echo $finfo->file($_FILES[$name]['tmp_name']);
            throw new \RuntimeException('Invalid file format.');
        }

        return $_FILES[$name]['tmp_name'];
    }

    public function downloadGrades() {
        $results = $this->loadResults();
        $info = $results["info"];
        //$dir = Config::$TEMP_DIR . "/".$results["info"]["title"];
        $zdir = $results["info"]["title"];
        $zip = new \ZipArchive();
        $zipname = Config::$TEMP_DIR . "/". $info["id"] .".zip";
        $zip->open($zipname, \ZipArchive::CREATE);
        $zip->addEmptyDir($zdir);

        //if (mkdir($dir) === false)
        //    die($this->showError("Could not create temp directory"));

        $gradefile = [];
        array_push($gradefile, [$info["title"], "Points"]); 
        array_push($gradefile, []); 
        array_push($gradefile, 
            ["Display ID","ID","Last Name","First Name","grade","Submission date","Late submission"]
        );

        foreach ($results["exams"] as $exam) {
            // only download student grades
            if ($exam["role"] !== "Student")
                continue;

            //$udir = "$dir/{$exam["name"]}({$exam["uva_id"]})";
            $uzdir = "$zdir/{$exam["name"]}({$exam["uva_id"]})";
            $zip->addEmptyDir($zdir);

            //if (mkdir($udir) === false)
            //    die($this->showError("Could not create temp directory"));

            $response = "";
            $comments = "";
            $i = 1;
            $score = 0;
            foreach ($info["questions"] as $q) {
                $response .= "<h3>Question $i</h3>\n";
                $response .= $q["text"] . "<br>\n";
                if (isset($exam["questions"][$q["id"]]) && isset($exam["questions"][$q["id"]]["response"]))
                    $response .= "<pre>".htmlspecialchars($exam["questions"][$q["id"]]["response"])."</pre>\n\n";
                else
                    $response .= "<pre>NO RESPONSE</pre>\n\n";
                $comments .= "Question $i\n-----------\n";
                if (isset($exam["questions"][$q["id"]]) && isset($exam["questions"][$q["id"]]["feedback"])
                        && isset($exam["questions"][$q["id"]]["score"])) {
                    $comments .= $exam["questions"][$q["id"]]["feedback"] . "\n";
                    $comments .= "Score: " . $exam["questions"][$q["id"]]["score"] ." / ".$q["score"]."\n\n";
                    $score += $exam["questions"][$q["id"]]["score"];
                } else {
                    $comments .= "Score: 0 / ".$q["score"]."\n\n";
                }
                
                $i++;
            }
            $comments .= "-------------------\nFinal Score: $score\n";
            array_push($gradefile, [
                $exam["uva_id"],
                $exam["uva_id"],
                "", // last name
                "", // first name
                round($score, 2), // grade
                "", // submission date
                "" // late submission
            ]);

            //file_put_contents("$udir/{$exam["name"]}({$exam["uva_id"]})_submissionText.html", $response);
            //file_put_contents("$udir/comments.txt", $comments);
            $zip->addFromString("$uzdir/{$exam["name"]}({$exam["uva_id"]})_submissionText.html", $response);
            $zip->addFromString("$uzdir/comments.txt", $comments);
        }

        // write the grades.csv file
        //$fp = fopen("$dir/grades.csv", 'w');
        //foreach ($gradefile as $fields) {
        //    fputcsv($fp, $fields);
        //}
        //fclose($fp);
        $gradefilecsv = "";
        foreach ($gradefile as $fields) {
            $gradefilecsv .= str_putcsv($fields) . "\n";
        }
        $zip->addFromString("$zdir/grades.csv", $gradefilecsv); 
        
        
        // close zip for downloading
        $zip->close();

        // show ZIP file
        header('Content-Type: application/zip');
        header('Content-disposition: attachment; filename=bulk_download.zip');
        header('Content-Length: ' . filesize($zipname));
        $zipfile = file_get_contents($zipname); 
        
        // remove the zip from the local filesystem
        unlink($zipname);

        // return the contents of the zipfile
        return $zipfile;
    }
}
