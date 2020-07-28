<?php
/**
 * Main helper class 
 *
 * This classfile contains all the control structure of the system.
 *
 * @author Robbie Hott
 * @license https://opensource.org/licenses/Apache-2.0 Apache-2.0
 * @copyright 2019
 */
namespace manager\control;
use \manager\Config as Config;

/**
 * Helper class
 *
 * Provides all control methods
 */
class Helper {

    /**
     * @var Associative array of data used for the display
     */
    private $dData;

    /**
     * @var \manager\control\DatabaseConnector The connector to the database
     */
    private $db;

    /**
     * @var string[] User information
     */
    private $user;

    /**
     * @var string[] Input data from the user
     */
    private $input;

    private $displayMessage = null;

    private $displayError = null;

    /**
     * @var \Monolog\Logger The logger for this instance
     */
    private $logger;

    /**
     * Constructor
     *
     * Initializes logger, database connector, and reads the current user
     * information from the session.  There should be a session from Netbadge.
     */
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

    /**
     * Read User
     *
     * Reads user data from the database
     *
     * @param string $uid The username
     * @return string[]|null User information including id, name, and admin privileges or null if
     *                       the user does not exist.
     */
    public function readUser($uid) {
        $res = $this->db->query("select * from person where uva_id = $1", array($uid));
        $data = $this->db->fetchAll($res);

        if (isset($data[0]) && isset($data[0]["name"])) {
            return [
                "id" => $data[0]["id"],
                "uva_id" => $data[0]["uva_id"],
                "name" => $data[0]["name"],
                "admin" => $data[0]["admin"] == 't' ? true : false
            ];
        }
        return null;
    }

    /**
     * Show an exam
     *
     * Loads the display data with the current exam, if the user has privileges to take it.
     *
     * @return string HTML display data from the templating engine
     */
    public function showExam() {
        // load questions dies with error if student not allowed to take exam
        $this->dData = $this->loadQuestions();
        return $this->display("question"); 
    }

    /**
     * Show home page
     *
     * Shows the home page of the exam software.
     *
     * @return string HTML display data from the templating engine
     */
    public function showHome() {
        $this->dData = $this->loadCourses();
        return $this->display("home");
    }
    
    
    
    /**
     * Show Add Participant Form 
     *
     * Loads the display with the add-participant form if the user is an instructor
     * of the course.
     *
     * @return string HTML display data from the templating engine
     */
    public function showAddParticipant() {
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
            return $this->display("addparticipant");
        }
        die($this->showError("Not Authorized"));
    }





    private function getValidRoleFrom($inrole) {
        $role = "Student";
        if (!empty($inrole)) {
            if (in_array($inrole, \manager\Config::$VALID_ROLES))
                $role = $inrole;
        }
        return $role;
    }
    /**
     * Add participant to class 
     *
     * If the user is an instructor of the course, reads the input and adds the
     * participants to the course
     *
     * @return string HTML display data from the templating engine
     */
    public function addParticipant() {
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

            // input check
            if (!isset($this->input["participants"]) || empty($this->input["participants"]) || empty($this->input["role"])) {
                $this->setError("No Participants Given");
                die($this->showAddParticipant());
            }

            $role = $this->getValidRoleFrom($this->input["role"]);
            $count = 0;

            // parse the list and add participants
            $tmp = str_getcsv($this->input["participants"], "\n");
            foreach ($tmp as $row) {
                $participant = str_getcsv(trim($row));

                if (isset($participant[0]) && !empty($participant[0])) {
                    $uvaid = trim($participant[0]);
                    $name = "";
                    if (isset($participant[1]))
                        $name = trim($participant[1]);

                    // See if the person already exists from another class
                    $resP = $this->db->query("select id from person where uva_id = $1", [
                        $uvaid
                    ]);
                    $allP = $this->db->fetchAll($resP);

                    // If person doesn't exist in the person table, create them (non-administrator) 
                    if (!(isset($allP[0]) && isset($allP[0]["id"]))) {
                        $resP = $this->db->query("insert into person (uva_id, name) values ($1, $2) returning id;",
                            [
                                $uvaid,
                                $name
                            ]
                        );
                        $allP = $this->db->fetchAll($resP);
                        if (count($allP) != 1) {
                            die($this->showError("Database Error"));
                        }
                    }
                    $pid = $allP[0]["id"];

                    // Add the person to this course with the appropriate role
                    $resPC = $this->db->query("insert into person_course (course_id, person_id, role) values ($1, $2, $3);", [
                        $course["id"],
                        $pid,
                        $this->input["role"]
                    ]);
                    $count++;
                }

            }

            $this->setMessage("$count participants add as $role");

            return $this->showAddParticipant();

        }
        die($this->showError("Not Authorized"));
    }

    private function setError($message) {
        $this->displayError = $message;
    }

    private function setMessage($message) {
        $this->displayMessage = $message;
    }

    /**
     * Create Exam
     *
     * Loads the display with the create-exam form if the user is an instructor
     * of the course.
     *
     * @return string HTML display data from the templating engine
     */
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
            if (isset($this->input["exam"])) {
                $this->dData["examdata"] = $this->loadResults($this->input["exam"], null);
            }
            return $this->display("newexam");
        }
        die($this->showError("Not Authorized"));
    }

    /**
     * Create a new Exam
     *
     * Given exam data on input, this inserts the exam into the database.
     *
     * @return string HTML display data from the templating engine
     */
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
        $timeallowed = null;
        if (isset($this->input["timeallowed"]) && is_numeric($this->input["timeallowed"]) && $this->input["timeallowed"] > 0) {
            $timeallowed = $this->input["timeallowed"];
        }

        $timeenforced = 'f';
        if (isset($this->input["timeenforced"]) && $this->input["timeenforced"] == 't') {
            $timeenforced = 't';
        }

        // create the exam
        if (!isset($this->input["exam"]) || empty($this->input["exam"])) {
            $res = $this->db->query("insert into exam (course_id, title, instructions, time_allowed, time_enforced) values ($1, $2, $3, $4, $5) returning id;", array(
                $course["id"],
                $this->input["name"],
                $this->input["instructions"],
                $timeallowed,
                $timeenforced
            ));
        } else {
            $res = $this->db->query("update exam set (title, instructions, time_allowed, time_enforced) = ($3, $4, $5, $6) where course_id = $1 and id = $2 returning id;", array(
                $course["id"],
                $this->input["exam"],
                $this->input["name"],
                $this->input["instructions"],
                $timeallowed,
                $timeenforced
            ));
        }
        $tmp = $this->db->fetchAll($res);
        if (count($tmp) != 1) {
            die($this->showError("Database Error"));
        }
        $eid = $tmp[0]["id"];

        foreach ($this->input["question"] as $k => $q) {
            if (!isset($this->input["questionid"][$k]) || empty($this->input["questionid"][$k])) {
                $res = $this->db->query("insert into question (exam_id, ordering, text, code, correct, rubric, language, score) values ($1, $2, $3, $4, $5, $6, $7, $8);",
                    [
                        $eid,
                        $k, 
                        $q, 
                        isset($this->input["code"][$k]) ? $this->input["code"][$k] : "",
                        isset($this->input["answer"][$k]) ? $this->input["answer"][$k] : "",
                        isset($this->input["rubric"][$k]) ? $this->input["rubric"][$k] : "",
                        isset($this->input["language"][$k]) ? $this->input["language"][$k] : "java",
                        isset($this->input["score"][$k]) ? $this->input["score"][$k] : 0
                    ]);
            } else {
                $res = $this->db->query("update question set (text, code, correct, rubric, language, score) = ($1, $2, $3, $4, $5, $6) where id = $7;",
                    [
                        $q, 
                        isset($this->input["code"][$k]) ? $this->input["code"][$k] : "",
                        isset($this->input["answer"][$k]) ? $this->input["answer"][$k] : "",
                        isset($this->input["rubric"][$k]) ? $this->input["rubric"][$k] : "",
                        isset($this->input["language"][$k]) ? $this->input["language"][$k] : "java",
                        isset($this->input["score"][$k]) ? $this->input["score"][$k] : 0,
                        $this->input["questionid"][$k]
                    ]);
            }
        } 

        header("Location: ?");
    }

    /**
     * Check for Valid Exam
     *
     * Checks whether the exam ID given on input is an actual exam
     * and that the user has permission to modify it.
     * @return string HTML display data from the templating engine
     */
    private function checkValidExam() {
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

        if (!isset($this->input["e"]) || !is_numeric($this->input["e"])) {
            die($this->showError("No Exam Given"));
        }

        $eid = $this->input["e"];

        $res = $this->db->query("select 
            e.id, e.date, e.open, e.close, e.closed, e.title, e.instructions from course c, exam e, person_course pc 
            where e.course_id = c.id and pc.course_id = c.id and pc.person_id = $1 and
                pc.role in ('Instructor')
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
            die($this->showError("You do not have permissions to modify this exam (Instructors only!)"));

        return $eid;
    } 

    /**
     * Open Exam
     *
     * Sets the exam to accept submissions from students, then
     * redirects the user to the home page.
     */
    public function openExam() {
        $exam = $this->checkValidExam();

        $res = $this->db->query("update exam set closed = false where id = $1;",
            [$exam]);

        header("Location: ?");
    }

    /**
     * Close Exam
     *
     * Sets the exam to no longer accept submissions from students, then
     * redirects the user to the home page.
     */
    public function closeExam() {
        $exam = $this->checkValidExam();

        $res = $this->db->query("update exam set closed = true where id = $1;",
            [$exam]);

        header("Location: ?");
    }

    /**
     * Display New Course Page 
     *
     * Loads the new course page into the display if the user has admin privilege.
     *
     * @return string HTML display data from the templating engine
     */
    public function newCourse() {
        if (!$this->user["admin"])
            die($this->showError("Not Authorized"));
        return $this->display("newcourse");
    }


    /**
     * Create Course
     *
     * Given input, this method creates the course and stores it into the database.
     *
     * @return string HTML display data from the templating engine
     */
    public function createCourse() {
        if (!$this->user["admin"])
            die($this->showError("Not Authorized"));
        
        // Try to get the file first so that we don't have too many new courses in the DB
        $fn = $this->handleFileUpload("roster");
        

        // Create the course in the database 
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

        // Insert the instructor first!
        $resPC = $this->db->query("insert into person_course (course_id, person_id, role) values ($1, $2, $3);", [
            $cid,
            $this->user["id"],
            "Instructor"
        ]);


        // Insert students from the roster
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
                if ($this->user["uva_id"] == $line[1])
                    continue; // this is the instructor creating the course, ignore

                $resP = $this->db->query("select id from person where uva_id = $1", [
                    $line[1]
                ]);
                $allP = $this->db->fetchAll($resP);

                // If person doesn't exist, create them 
                if (!(isset($allP[0]) && isset($allP[0]["id"]))) {
                    $resP = $this->db->query("insert into person (uva_id, name) values ($1, $2) returning id;",
                        [
                            $line[1],
                            utf8_encode($line[0]) // some peoples names are in UTF8??
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

    /**
     * Save Exam
     *
     * Saves the student exam data given on input and then returns a JSON object
     * with the success or failure of the save action.
     *
     * @param string  JSON response
     */
    public function handleSaveExam() {
        $result = ["result" => "error", "error"=> "Unknown error occurred"];
        $exam = $this->loadQuestions();

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

        $left = "inf";
        if (isset($exam["info"]["date_started"]) && isset($exam["info"]["time_allowed"]) &&
                $exam["info"]["date_started"] != null && $exam["info"]["time_allowed"] != null) {
            $limit = $exam["info"]["time_allowed"];
            $started = strtotime($exam["info"]["date_started"]);
            $now = strtotime("now");

            $left = [
                "left" => (int) ceil($limit - (($now - $started)/60)),
                "started" => (int) $started / 60,
                "now" => (int) $now / 60,
                "limit" => $limit
            ];
        }
        
        $result = ["result" => "success", "time" => $left, "submission" => $this->input];
        return json_encode($result, JSON_PRETTY_PRINT);
    }

    /**
     * Submit Exam
     *
     * Submits the student's exam. Uses the handleSaveExam function, then verifies
     * that the save operation was successful.  It then logs the time the user completed
     * the exam and loads the display with a unique code for the user.
     *
     * @return string HTML display data from the templating engine
     */
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

    /**
     * Grade an Exam
     *
     * Given exam data on input, this loads the homepage of the exam grading section.
     * It shows all the questions of an exam and allows the grader to choose a question to grade.
     *
     * @return string HTML display data from the templating engine
     */
    public function gradeExam() {
        $data = $this->loadResults();
        // do grading
        $this->dData = $data;
        if (isset($this->input["m"])) {
            $m = $this->input["m"];
            if ($m == "done")
                $this->dData["message"] = "There are no more of that question to grade.";
            else if ($m == "checkin")
                $this->dData["message"] = "Ungraded questions checked in.";
        }

        return $this->display("gradehome");
    }

    /**
     * Exam Grading Stats
     *
     * Displays a stats page for the given exam: how much of each question of the exam is graded?
     *
     * @return string HTML display data from the templating engine
     */
    public function gradeExamStats() {
        $data = $this->loadResults();
        // do grading
        $this->dData = $data;
        
        return $this->display("gradestats");
    }

    /**
     * Plagiarism Check 
     *
     *
     * @return string HTML display data from the templating engine
     */
    public function checkSimilarities() {
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

        $all = [];
        // Select one at random and update to grab it as a grader
        $res = $this->db->query("select * from person_question where exam_id = $1 order by question_id",
            [$eid]);
        $all = $this->db->fetchAll($res);

        $similarity = [];
        $quest = [];
        foreach ($all as $r) {
            if (!isset($quest[$r["question_id"]]))
                $quest[$r["question_id"]] = [];
            $quest[$r["question_id"]][$r["person_id"]] = $r["response"];
        }
        $count = 0;
        foreach ($quest as $i => $q) {
            $similarity[$i] = [];
            foreach ($q as $id => $resp) {
                $similarity[$i][$id] = [];
                foreach($q as $id2 => $resp2) {
                    if ($id == $id2)
                        continue;
                    $t = similar_text($resp, $resp2, $per);
                    if ($per >= 99)
                        $similarity[$i][$id][$id2] = [
                            "percentage" => $per,
                            "orig" => $resp,
                            "other" => $resp2
                        ];
                    if ($count++ > 9000)
                        break;
                }
            }
        }

        $tmp = "";
        foreach ($similarity as $q => $ids) {

            $tmp .= "Question $q<br><table border='1'><tr><th>Percent</th><th>One</th><th>Two</th></tr>\n";
            foreach ($ids as $id1 => $ids2) {
                foreach ($ids2 as $id2 => $d) {
                    $tmp .= "<tr><td>{$d["percentage"]}</td><td width='40%'>$id1<br><pre>{$d["orig"]}</pre></td><td width='40%'>$id2<br><pre>{$d["other"]}</pre></td></tr>\n";
                }
            }
        }
        
        $tmp .= "</table>";
        //$this->dData = $data;
        return $tmp; //$this->display("grade_one");
    }

    /**
     * Grade One Question
     *
     * Checks that the user has permission to grade the given question, then shows the grading interface
     * for that question.
     *
     * @return string HTML display data from the templating engine
     */
    public function gradeOne() {
        $eid = $this->input["e"];
        
        $uid = null;
        if (isset($this->input["p"]))
            $uid = $this->input["p"];

        $examInfo = [];
        $res = $this->db->query("select 
            e.id, e.date, e.open, e.close, e.title, e.instructions from course c, exam e, person_course pc 
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

        $all = [];
        if ($uid == null) {
            // select one at random: This original query was not atomic.
            /*$res = $this->db->query("select pq.person_id, pq.question_id, pq.exam_id,
                pq.response, pq.feedback, pq.score as current_score, q.text, q.code, q.correct, q.rubric, q.score from person_question pq,
                question q where pq.score is null and pq.grader is null and 
                pq.question_id = q.id and pq.question_id = $1
                limit 1;", 
                [$this->input['q']]);
             */
            // Select one at random and update to grab it as a grader
            $res = $this->db->query("update person_question set grader = $2 where 
                (person_id, question_id, exam_id) = 
                (select pq.person_id, pq.question_id, pq.exam_id
                    from person_question pq, person_exam pe
                    where pq.score is null and pq.grader is null and 
                    pq.person_id = pe.person_id and pq.exam_id = pe.exam_id and
                    pe.date_taken is not null and pq.question_id = $1
                    limit 1 for update)
                returning *;",
                [$this->input['q'],
                $this->user["id"]]);
            $all = $this->db->fetchAll($res);
        } else {
            // select the specific one
            $res = $this->db->query("update person_question set grader = $3 where 
                (person_id, question_id, exam_id) = 
                (select pq.person_id, pq.question_id, pq.exam_id
                    from person_question pq, person_exam pe
                    where pq.person_id = $2 and 
                    pq.person_id = pe.person_id and pq.exam_id = pe.exam_id and
                    pe.date_taken is not null and pq.question_id = $1
                    limit 1 for update)
                returning *;",
                [$this->input['q'], $uid, $this->user["id"]]);
            $all = $this->db->fetchAll($res);
        }
        if (isset($all[0]) && isset($all[0]["question_id"])) {
            $data = $all[0];
            $data["flagged"] = $all[0]["flagged"] === 't' ? true : false;
            $data["current_score"] = $data["score"]; // fix the returning *
            unset($data["score"]);

            // get the person information
            $resPers = $this->db->query("select * from person where id = $1;",
                [$data["person_id"]]);
            $allPdata = $this->db->fetchAll($resPers);

            if (isset($allPdata[0])) {
                $data["person_userid"] = $allPdata[0]["uva_id"];
            }
            
            // get the question information
            $resQues = $this->db->query("select text, code, correct, rubric, score from question where id = $1;",
                [$this->input['q']]);
            $allQdata = $this->db->fetchAll($resQues);

            if (isset($allQdata[0]) && isset($allQdata[0]["text"])) {
                $data["text"] = $allQdata[0]["text"];
                $data["code"] = $allQdata[0]["code"];
                $data["correct"] = $allQdata[0]["correct"];
                $data["rubric"] = $allQdata[0]["rubric"];
                $data["score"] = $allQdata[0]["score"];
            } else {
                die("Something really bad happened");
            }

            /*
            // This was part of the original non-atomic version.
            $res = $this->db->query("update person_question set grader = $3 where 
                person_id = $1 and question_id = $2;", [
                $data["person_id"],
                $data["question_id"],
                $this->user["id"]
            ]);
             */
        } else {
            header("Location: ?c=grade&e=".$this->input['e']."&m=done");
        }
        if ($uid != null)
            $data["restricted"] = true;
        $this->dData = $data;
        return $this->display("grade_one");
    }

    /**
     * Check-in all ungraded questions
     *
     * In case a grader has one question checked out, this removes all grader information
     * for exam questions that do not have a grade.  Allows others to have access to grade
     * the exam questions through the interface.
     *
     * @return string HTML display data from the templating engine
     */
    public function checkinAllUngraded() {
        $eid = $this->input["e"];
        $res = $this->db->query("select 
            e.id, e.date, e.open, e.close, e.title from course c, exam e, person_course pc 
            where e.course_id = c.id and pc.course_id = c.id and pc.person_id = $1 and
                pc.role in ('Instructor')
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
            die($this->showError("You do not have permissions to grade this exam"));

        if (!isset($this->input['q']))
            die($this->showError("No Question"));

        $res = $this->db->query("update person_question pq set grader = null 
            where exam_id = $1 and question_id = $2 and grader is not null and grade_time is null;",  
            [$eid, $this->input['q']]);

        header("Location: ?c=grade&e=".$this->input['e']."&m=checkin");
    }

    /**
     * Cancel Grading for Question
     *
     * Cancels the grading and removes grader information for a question.  This allows a grader
     * to check-in the question for another grader.
     *
     * @return string HTML display data from the templating engine
     */
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

    /**
     * Save Grade Helper
     *
     * Saves the grade for the question on input.  This method
     * performs the actual saving of the grade.
     *
     * @return boolean true if save succeeded, false otherwise
     */
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

        $flagged = 'f';
        if (isset($this->input["flagged"]) && $this->input["flagged"] == "yes")
            $flagged = 't';

        $res = $this->db->query("update person_question
            set score = $4, feedback = $5, grade_time = now(), flagged = $6
            where person_id = $1 and question_id = $2 and grader = $3;", 
            [
                $this->input['student'],
                $this->input['question'],
                $this->user["id"],
                $score,
                $this->input['comments'],
                $flagged
            ]);

        return true;
    }

    /**
     * Save Grade and Next
     *
     * Saves the grade and displays the next ungraded question.
     *
     * @return string HTML display data from the templating engine
     */
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

    /**
     * Saves Grade and Stop Grading
     *
     * Saves the grade, then redirects the grader to the grading homepage
     * for the given exam.
     */   
    public function saveGrade() {
        $result = $this->saveGradeReal();

        // check result
        if ($result === false)
           die($this->showError("You were not allowed to grade that submission")); 
        
        // show the grading
        header("Location: ?c=grade&e=".$this->input["exam"]); 
        return true; //$this->gradeExam();

    }

    /**
     * Load Questions
     *
     * Loads all questions for a given exam (either by parameter or input).  Ensures that
     * the user has permission to load the exam questions.  Sets the timestamp if this was the
     * first time the user opened this exam.
     *
     * @param int $examid optional The exam to load
     * @param boolean $grading unused parameter
     * @return string[] Exam data 
     */
    private function loadQuestions($examid = null, $grading = false) {
        $exam = [];
        $eid = $examid;
        if ($examid == null && !isset($this->input["e"]))
            die($this->showError("Unknown Exam"));

        if ($examid == null)
            $eid = $this->input["e"];

        $res = $this->db->query("select 
            e.id, e.date, e.open, e.close, e.closed, e.time_allowed, e.time_enforced from course c, exam e, person_course pc 
            where e.course_id = c.id and pc.course_id = c.id and pc.person_id = $1 and e.id = $2;", [$this->user["id"], $eid]);
        $all = $this->db->fetchAll($res);
        $allowed = false;
        foreach ($all as $row) {
            if ($row["id"] == $eid && $row["closed"] != 't') {
                $allowed = true;
                $exam["info"] = $row;
                // by default they start with full time left
                $exam["info"]["time"] = [
                    "elapsed" => 0,
                    "left" => $row["time_allowed"],
                    "percleft" => "100"
                ]; 
                break;
            }
        }

        if (!$allowed)
            die($this->showError("You may not take this exam at this time"));

        // Check to ensure the student hasn't submitted already
        $res = $this->db->query("select e.exam_id, e.date_taken, e.date_started from person_exam e where e.exam_id = $1 and e.person_id = $2;", [$eid, $this->user["id"]]);
        $all = $this->db->fetchAll($res);
        if (isset($all[0])) {
            // The student has started the exam -- check conditions
            $info = $all[0];
            $exam["info"]["date_started"] = $info["date_started"];

            if ($info["date_taken"] != null)
                die($this->showError("You may not retake the same exam twice."));
            
            if ($exam["info"]["time_allowed"] != null) {
                // check to see time left (rewrite the time left)
                $exam["info"]["time"] = [];
                $started = strtotime($info["date_started"]);
                $now = strtotime("now");
                $mins = ($now - $started) / 60;
                
                $exam["info"]["time"]["elapsed"] = (int) $mins;
                $exam["info"]["time"]["left"] = (int) ceil($exam["info"]["time_allowed"] - $mins);
                $exam["info"]["time"]["percleft"] = ($exam["info"]["time_allowed"] - $mins) / $exam["info"]["time_allowed"] * 100;
            }
            
            // TODO: Check if they have taken more than the alotted time (Can't reopen)
            // if timer enforced AND timer has passed
            if ($exam["info"]["time_enforced"] == 't' && $exam["info"]["time_allowed"] != null) {
                if ($exam["info"]["time"]["left"] + 1 < 0) {
                    die($this->showError("You have exceeded the time allowed for this exam."));
                }
            }
        }


        
        $res = $this->db->query("select e.id as exam_id, e.title, e.instructions, e.open, e.close, e.date, q.* from exam e, question q where e.id = $1 and q.exam_id = e.id order by q.ordering asc", [$eid]);
        $all = $this->db->fetchAll($res);

        foreach ($all as $row) {
            $exam["id"] = $row["exam_id"];
            $exam["title"] = $row["title"];
            $exam["instructions"] = $row["instructions"];
            $exam["open"] = $row["open"];
            $exam["close"] = $row["close"];
            $exam["date"] = $row["date"];
            if (!isset($exam["questions"]))
                $exam["questions"] = [];
            $exam["questions"][$row["ordering"]] = [
                "id" => $row["id"],
                "text" => $row["text"],
                "code" => $row["code"],
                "language" => $row["language"]
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

    /** 
     * Load Courses
     *
     * Loads all courses and exams for the user given on input.
     *
     * @return string[] Exam and course data
     */
    private function loadCourses() {
        $exams = [];
        $res = $this->db->query("select c.title as course, c.year, c.semester, c.uva_id as courseid, c.id, pc.role 
            from course c, person_course pc 
            where pc.course_id = c.id and pc.person_id = $1 
            order by c.year desc, c.semester asc;", [$this->user["id"]]);
        $all = $this->db->fetchAll($res);

        foreach ($all as $row) {
            if (!isset($exams[$row["year"] . " - " . $row["semester"]]))
                $exams[$row["year"] . " - " . $row["semester"]] = [];
            if (!isset($exams[$row["year"] . " - " . $row["semester"]][$row["courseid"]]))
                $exams[$row["year"] . " - " . $row["semester"]][$row["courseid"]] = [
                    "title" => $row["course"],
                    "uva_id" => $row["courseid"],
                    "id" => $row["id"],
                    "role" => $row["role"],
                    "exams" => []
                ];
        }
        
        $res = $this->db->query("select c.title as course, c.year, c.semester, c.uva_id as courseid, e.title,
            e.id, e.date, e.open, e.close, e.closed, pc.role from course c, exam e, person_course pc where e.course_id = c.id and pc.course_id = c.id and pc.person_id = $1 order by c.year desc, c.semester asc, e.id asc;", [$this->user["id"]]);
        $all = $this->db->fetchAll($res);

        if (empty($all))
            return $exams;

        foreach ($all as $row) {
            if (!isset($exams[$row["year"] . " - " . $row["semester"]]))
                $exams[$row["year"] . " - " . $row["semester"]] = [];
            if (!isset($exams[$row["year"] . " - " . $row["semester"]][$row["courseid"]]))
                $exams[$row["year"] . " - " . $row["semester"]][$row["courseid"]] = [
                    "title" => $row["course"],
                    "role" => $row["role"],
                    "year" => $row["year"],
                    "semester" => $row["semester"],
                    "exams" => []
                ];

            $exams[$row["year"] . " - " . $row["semester"]][$row["courseid"]]["exams"][$row["id"]] = [
                "title" => $row["title"],
                "id" => $row["id"],
                "date" => $row["date"],
                "open" => $row["open"],
                "close" => $row["close"],
                "closed" => $row["closed"] == 't' ? true : false
            ];

            $res2 = $this->db->query("select * from person_exam where exam_id = $1 and person_id = $2;", [$row["id"], $this->user["id"]]);
            $all2 = $this->db->fetchAll($res2);
            if (isset($all2[0]) && isset($all2[0]["date_taken"])) {
                $exams[$row["year"] . " - " . $row["semester"]][$row["courseid"]]["exams"][$row["id"]]["date_taken"] = $all2[0]["date_taken"];
            }
        }
        
        return $exams;
    }

    public function isInstructor($userid, $examid) {
        $res = $this->db->query("select 
            e.id, e.date, e.open, e.close, e.title from course c, exam e, person_course pc 
            where e.course_id = c.id and pc.course_id = c.id and pc.person_id = $1 and
                pc.role in ('Instructor', 'Teaching Assistant', 'Secondary Instructor')
                and e.id = $2", 
            [$userid, $examid]);
        $all = $this->db->fetchAll($res);
        $allowed = false;
        foreach ($all as $row) {
            if ($row["id"] == $examid) {
                $allowed = true;
                $examInfo = $row;
                break;
            }
        }
        return $allowed;
    }

    /**
     * Load Results
     *
     * Loads all results for the given exam (either on input or parameter).
     *
     * @param int $examid optional The exam id to load
     * @param int $onlyid optional unused 
     * @return string[] Exam result data
     */
    public function loadResults($examid = null, $onlyid = null) {
        $eid = $examid;
        if ($examid == null && !isset($this->input["e"]))
            die($this->showError("Unknown Exam"));

        if ($examid == null)
            $eid = $this->input["e"];
        
        $res = $this->db->query("select 
            e.id, e.date, e.open, e.close, e.title, e.instructions, e.time_enforced, e.time_allowed, pc.role 
            from course c, exam e, person_course pc 
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
                $this->user["course_role"] = $row["role"];
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
                "language" => $row["language"],
                "score" => $row["score"]
            ];
        }

        $exams = [];
        $questions = [];
        $res = null;
        $lastperson = null;
        // if we're looking for an ID, only get that one
        if ($onlyid) {
            $res = $this->db->query("select e.person_id, e.date_started, e.date_taken, e.code, u.uva_id, u.name, pc.role from person_exam e, person u, exam ex, person_course pc where e.exam_id = $1 and e.person_id = u.id and pc.person_id = u.id and ex.id = e.exam_id and pc.course_id = ex.course_id and u.uva_id = $2;", [$eid, $onlyid]);
        } else {
            // else get everyone's exam
            $res = $this->db->query("select e.person_id, e.date_started, e.date_taken, e.code, u.uva_id, u.name, pc.role from person_exam e, person u, exam ex, person_course pc where e.exam_id = $1 and e.person_id = u.id and pc.person_id = u.id and ex.id = e.exam_id and pc.course_id = ex.course_id;", [$eid]);
        }
        $all = $this->db->fetchAll($res);
        foreach ($all as $exam) {
            $lastperson = $exam["person_id"];
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
        
        $res = null;
        // if we're looking for an ID, only get that one
        if ($onlyid && $lastperson) {
            $res = $this->db->query("select * from person_question where exam_id = $1 and person_id = $2
                order by question_id, person_id asc;", [$eid, $lastperson]);
        } else {
            // else get everyone's exam
            $res = $this->db->query("select * from person_question where exam_id = $1
                order by question_id, person_id asc;", [$eid]);
        }
        $all = $this->db->fetchAll($res);

        foreach ($all as $row) {
            if (!isset($questions[$row["question_id"]]))
                $questions[$row["question_id"]] = [
                    "answers" => []
                ];
            $questions[$row["question_id"]]["answers"][$row["person_id"]] = [
                "person_id" => $row["person_id"],
                "response" => $row["response"],
                "feedback" => $row["feedback"],
                "score" => $row["score"],
                "grader" => $row["grader"],
                "flagged" => $row["flagged"] === 't' ? true : false
            ];

            $exams[$row["person_id"]]["questions"][$row["question_id"]] = [
                "person_id" => $row["person_id"],
                "response" => $row["response"],
                "feedback" => $row["feedback"],
                "score" => $row["score"],
                "grader" => $row["grader"],
                "flagged" => $row["flagged"] === 't' ? true : false
            ];
        }

        foreach ($examInfo["questions"] as &$qinfo) {
            $total = 0;
            $graded = 0;
            if (isset($questions[$qinfo["id"]]["answers"])) { 
                foreach ($questions[$qinfo["id"]]["answers"] as $row) {
                    if ($row["score"] != "")
                        $graded++;
                    $total++;
                }
                $qinfo["total"] = $total;
                $qinfo["graded"] = $graded;
                $qinfo["percent"] = round(100*($graded/$total),0,PHP_ROUND_HALF_DOWN);
            } else {
                $qinfo["total"] = 0;
                $qinfo["graded"] = 0;
                $qinfo["percent"] = 0;
            }
        }
        // do not use qinfo again in this method, or call unset below first
        //unset($qinfo);

        $recents = [];
        $res = null;
        // if we're looking for an ID, only get that one
        if ($onlyid && $lastperson) {
            $res = $this->db->query("select * from person_question where exam_id = $1 and grader = $2 and person_id = $3
                order by grade_time desc;", [$eid, $this->user["id"], $lastperson]);
        } else {
            // else get everyone's exam
            $res = $this->db->query("select * from person_question where exam_id = $1 and grader = $2
                order by grade_time desc;", [$eid, $this->user["id"]]);
        }
        $all = $this->db->fetchAll($res);
        foreach ($all as $row) {
            if (!isset($recents[$row["question_id"]]))
                $recents[$row["question_id"]] = [];
            array_push($recents[$row["question_id"]], ["id" => $row["person_id"],
                "flagged" => $row["flagged"] === 't' ? true : false
            ]);
        }

        return ["info" => $examInfo, "exams" => $exams, "questions" => $questions, "recents" => $recents];
    }

    /**
     * Load and Render Display
     *
     * Loads the given template into the display and render the final
     * HTML using Twig.
     *
     * @param string $template Template name
     * @return string final HTML
     */
    public function display($template) {
        $loader = new \Twig_Loader_Filesystem(\manager\Config::$TEMPLATE_DIR);
        $twig = new \Twig_Environment($loader, array(
        ));

        $messages = ["message" => $this->displayMessage, "error" => $this->displayError];

        return $twig->render($template . ".html", array("data" => $this->dData, "user" => $this->user, "messages" => $messages));
    }

    /**
     * Show Error
     *
     * Loads an error into the display.
     *
     * @param string $str optional The error message to show
     * @param string[] $data optional Data to display in the error page
     * @return string HTML display data from the templating engine
     */
    public function showError($str = "", $data = null) {
        if ($data != null) 
            $this->dData["output"] = json_encode($data, JSON_PRETTY_PRINT);
        if ($str != "")
            $this->dData["error"] = $str;
        return $this->display("error");
    }

    /**
     * Set Input
     *
     * Sets the given array into the input field.
     *
     * @param string[] Input data
     */
    public function setInput($input) {
        $this->input = $input;
       // if ($input == null || empty($input))
         //   die($this->showError());

    }

    /**
     * Handle File Upload
     *
     * Handles the file upload process given the name of a file, ensuring
     * that the upload succeeded and is the correct type.
     *
     * @param string $name The name of the file being uploaded
     * @return string The temporary filename on this system
     */
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
            echo "Error: Invalid upload file format: " . $finfo->file($_FILES[$name]['tmp_name']);
            throw new \RuntimeException('Invalid file format.');
        }

        return $_FILES[$name]['tmp_name'];
    }

    public function showGrades($examid = null) {
        $eid = $examid;
        if ($examid == null && !isset($this->input["e"]))
            die($this->showError("Unknown Exam"));

        if ($examid == null)
            $eid = $this->input["e"];

        if (!$this->isInstructor($this->user["id"], $eid))
            die($this->showError("You do not have permissions to view this exam"));
        
        $results = $this->loadResults($eid);
        $info = $results["info"];

        $grades = []; 
        foreach ($results["exams"] as $exam) {
            // only download student grades
            if ($exam["role"] !== "Student")
                continue;

            $score = 0;
            foreach ($info["questions"] as $q) {
                $score += $exam["questions"][$q["id"]]["score"];
            }

            // calculate elapsed time
            $elapsed = "Still working";
            if (!empty($exam["date_taken"])) {
                $start = new \DateTime($exam["date_started"]);
                $finish = new \DateTime($exam["date_taken"]);
                $elapsed = $start->diff($finish)->format('%H:%I:%S');
            }
            array_push($grades, [
                "uva_id" => $exam["uva_id"],
                "name" => $exam["name"],
                "score" => $score,
                "date_taken" => $exam["date_taken"],
                "date_started" => $exam["date_started"],
                "elapsed" => $elapsed,
                "code" => $exam["code"],
            ]);
        }

        $this->dData = ["grades" => $grades, "info" => $info];
        return $this->display("grades");
    }


    public function showReport($examid = null) {
        $eid = $examid;
        if ($examid == null && !isset($this->input["e"]))
            die($this->showError("Unknown Exam"));

        if ($examid == null)
            $eid = $this->input["e"];

        if (!$this->isInstructor($this->user["id"], $eid))
            die($this->showError("You do not have permissions to view this exam"));

        $examInfo = []; 
        $res = $this->db->query("select 
            e.id, e.date, e.open, e.close, e.title, e.instructions from course c, exam e, person_course pc 
            where e.course_id = c.id and pc.course_id = c.id and pc.person_id = $1 and
                pc.role in ('Instructor', 'Teaching Assistant', 'Secondary Instructor')
                and e.id = $2", 
            [$this->user["id"], $eid]);
        $all = $this->db->fetchAll($res);
        foreach ($all as $row) {
            if ($row["id"] == $eid) {
                $allowed = true;
                $examInfo = $row;
                break;
            }
        }

        $res = $this->db->query("select p.uva_id, p.name from person p, person_course pc where p.id = pc.person_id and pc.course_id = (select course_id from exam where id = $1) and pc.role = 'Student' and p.id not in (select person_id from person_exam pe where pe.exam_id = $1) order by name;", 
            [$eid]);
        $missing = $this->db->fetchAll($res);
        
        $res = $this->db->query("select p.uva_id, p.name, pe.date_started from person p, person_exam pe where p.id = pe.person_id and pe.exam_id = $1 and pe.date_taken is null;", 
            [$eid]);
        $unsubmitted = $this->db->fetchAll($res);
        
        $res = $this->db->query("select p.uva_id, p.name, pe.code, pe.date_taken-pe.date_started as elapsed_time, pe.date_taken from person p, person_exam pe where pe.exam_id = $1 and p.id = pe.person_id order by date_taken-date_started asc;", 
            [$eid]);
        $timings = $this->db->fetchAll($res);

        
        $this->dData = ["info" => $examInfo, "missing" => $missing, "unsubmitted" => $unsubmitted, "timings" => $timings];
        return $this->display("report");


    }

    public function showStudentExam() {
        $uid = $this->input["u"];
        $results = $this->loadResults(null, $uid);

        //print_r($results);
        //$this->dData = $results;
        //return ["info" => $examInfo, "exams" => $exams, "questions" => $questions, "recents" => $recents];

        $this->dData = [
            "info" => $results["info"],
            "questions" => []
        ];
        foreach ($results["exams"] as $exam)
            $this->dData["exam"] = $exam;
        foreach ($results["info"]["questions"] as $q)
            $this->dData["questions"][$q["id"]] = $q;
        $this->dData["exam"]["score"] = 0;
        foreach ($this->dData["exam"]["questions"] as $q)
            $this->dData["exam"]["score"] += $q["score"];
        return $this->display("viewexam");
        
    }

    /**
     * Download Grades
     *
     * Packages up the grades and creates a ZIP file compatible with 
     * UVA Collab for upload.  This creates the grades file, then turns
     * the submissions into PDFs for the students to view their submissions.
     *
     * @param int $onlyid optional currently unused
     * @return Contents of the created zipfile
     */
    public function downloadGrades($onlyid = null) {
        $results = $this->loadResults(null, $onlyid);
        $info = $results["info"];
        
        if (!$this->isInstructor($this->user["id"], $this->input["e"]))
            die($this->showError("You do not have permissions to view this exam"));
        
        // other formats.  Collab is default.
        if (isset($this->input["format"])) {
            
            if ($this->input["format"] == "csv-summary") {
                $grades = "UVAID,Name,Score,DateStarted,DateFinished,ElapsedTime,Code\n"; 
                foreach ($results["exams"] as $exam) {
                    // only download student grades
                    if ($exam["role"] !== "Student")
                        continue;

                    $score = 0;
                    foreach ($info["questions"] as $q) {
                        $score += $exam["questions"][$q["id"]]["score"];
                    }

                    // calculate elapsed time
                    $elapsed = "Still working";
                    if (!empty($exam["date_taken"])) {
                        $start = new \DateTime($exam["date_started"]);
                        $finish = new \DateTime($exam["date_taken"]);
                        $elapsed = $start->diff($finish)->format('%H:%I:%S');
                    }
                    $grades .= str_putcsv([
                        $exam["uva_id"],
                        $exam["name"],
                        $score,
                        $exam["date_started"],
                        $exam["date_taken"],
                        $elapsed,
                        $exam["code"],
                    ])."\n";
                }
                header('Content-Type: text/csv');
                header('Content-disposition: attachment; filename=grade-summary.csv');
                return $grades;
            } else if ($this->input["format"] == "json-summary") {
                $grades = []; 
                foreach ($results["exams"] as $exam) {
                    // only download student grades
                    if ($exam["role"] !== "Student")
                        continue;

                    $score = 0;
                    foreach ($info["questions"] as $q) {
                        $score += $exam["questions"][$q["id"]]["score"];
                    }

                    // calculate elapsed time
                    $elapsed = "Still working";
                    if (!empty($exam["date_taken"])) {
                        $start = new \DateTime($exam["date_started"]);
                        $finish = new \DateTime($exam["date_taken"]);
                        $elapsed = $start->diff($finish)->format('%H:%I:%S');
                    }
                    array_push($grades, [
                        "uva_id" => $exam["uva_id"],
                        "name" => $exam["name"],
                        "score" => $score,
                        "date_taken" => $exam["date_taken"],
                        "date_started" => $exam["date_started"],
                        "elapsed" => $elapsed,
                        "code" => $exam["code"],
                    ]);
                }
                header('Content-Type: application/json');
                //header('Content-disposition: attachment; filename=grade-summary.csv');
                return json_encode($grades, JSON_PRETTY_PRINT);
            }
        }

        // Default to Collab format
        //$dir = Config::$TEMP_DIR . "/".$results["info"]["title"];
        $zdir = $results["info"]["title"];
        $zip = new \ZipArchive();
        // make the zip file unique
        $zipname = Config::$TEMP_DIR . "/". $info["id"] . "_" . time() . ".zip";
        $zip->open($zipname, \ZipArchive::CREATE);
        $zip->addEmptyDir($zdir);

        $tmpDir = \manager\Config::$TEMP_DIR."/".$info["id"]."_".time();
        if (mkdir($tmpDir) === false)
            die($this->showError("Could not create temp directory"));

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

            // run pandoc to take response from html to pdf
            $fullhtml = "<html><head><title>Exam Submission: {$exam["uva_id"]}</title></head><body>$response</body></html>";
            // pandoc -f html -t pdf (pipe fullhtml as input and capture output)
            $pdfDoc = null;

            // Open a new process to pandoc (convert to pdf)
            $tmpFile = $tmpDir . "/". $exam["uva_id"].".pdf";
            
            $descriptorspec = array(
                0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
                1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
                2 => array("pipe", "a")
            );
            $pipes = array();
            $process = proc_open("cd $tmpDir && pandoc -f html -o $tmpFile --pdf-engine=wkhtmltopdf 2>&1", $descriptorspec, $pipes);

            if (is_resource($process)) {
                // $pipes now looks like this:
                // 0 => writeable handle connected to child stdin
                // 1 => readable handle connected to child stdout

                fwrite($pipes[0], $fullhtml);
                fclose($pipes[0]);

                $pdfDoc = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);

                //echo $pdfDoc;

                // It is important that you close any pipes before calling
                // proc_close in order to avoid a deadlock
                $return_value = proc_close($process);

            }

            $fileCreated = false;
            if (is_file($tmpFile)) {
                $fileCreated = true;
                //$zip->addFile($tmpFile, "$uzdir/Submission attachment(s)/Submission.pdf");
                $zip->addFile($tmpFile, "$uzdir/Feedback Attachment(s)/Submission.pdf");
                //unlink($tmpFile);
            }

            /*
            if ($pdfDoc != null)
                $zip->addFromString("$uzdir/Submission attachment(s)/Submission.pdf", $pdfDoc);
            else
                echo "pandoc error: " . $return_value . "\n";
             */
            //file_put_contents("$udir/{$exam["name"]}({$exam["uva_id"]})_submissionText.html", $response);
            //file_put_contents("$udir/comments.txt", $comments);
            if ($fileCreated) 
                $zip->addFromString("$uzdir/{$exam["name"]}({$exam["uva_id"]})_submissionText.html", "<p>See attached PDF</p>");
            else
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

        // remove the temporary PDFs from the filesystem
        

        // return the contents of the zipfile
        return $zipfile;
    }
}
