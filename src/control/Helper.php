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
use phpseclib\Crypt\RSA;
use phpseclib\Net\SSH2;
use phpseclib\Net\SFTP;
use \Monolog\Handler\StreamHandler;


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
     * @var \Monolog\Logger The save logger for this instance
     */
    private $saveLogger;

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

        if (\manager\Config::$SAVE_LOG === true) {
            $savelog = new StreamHandler(\manager\Config::$SAVE_LOG_FILE, \Monolog\Logger::DEBUG);

            // the default date format is "Y-m-d\TH:i:sP"
            $dateFormat = "Y-m-d\TH:i:sP";
            $output = "%datetime%\t%context%\n";
            // finally, create a formatter
            $formatter = new \Monolog\Formatter\LineFormatter($output, $dateFormat);
            $savelog->setFormatter($formatter);
            $this->saveLogger = new \Monolog\Logger('SaveLog');
            $this->saveLogger->pushHandler($savelog);
        }


        $this->dData = array();
        $this->db = new \manager\control\DatabaseConnector();
        if (!isset($_SERVER["uid"])) {
            die("This is not the server you are looking for"); // update soon
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
        return $this->display("takeexam"); 
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
     * Show course page
     *
     * Shows the course page 
     *
     * @return string HTML display data from the templating engine
     */
    public function showCourse() {
        $data = $this->loadCourses();
        $cid = $this->input["course"];
        $course = null;
        $allowed = false;
        foreach ($data as $y) {
            foreach ($y as $c) {
                if ($c["id"] == $cid) {
                    $course = $c;
                }
            }
        }
        if ($course != null) {
            $this->dData = $course;
            return $this->display("course");
        }
        die($this->showError("You do not have permission to view this course"));
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

    
    /**
     * Show Add Accommodation Form 
     *
     * Loads the display with the add-accommodation form if the user is an instructor
     * of the course.
     *
     * @return string HTML display data from the templating engine
     */
    public function showAddAccommodation() {
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
            return $this->display("accommodation");
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
    
    /**
     * Add Accommodation 
     *
     * If the user is an instructor of the course, reads the input and adds the
     * accommodation to the student in the course
     *
     * @return string HTML display data from the templating engine
     */
    public function addAccommodation() {
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
            if (!isset($this->input["userid"]) || empty($this->input["userid"]) || !isset($this->input["time"]) || empty($this->input["time"])) {
                $this->setError("No Participants Given");
                die($this->showAddAccommodation());
            }
            if (!is_numeric($this->input["time"])) {
                $this->setError("Timescale must be numeric");
                die($this->showAddAccommodation());
            }

            $studentid = $this->input["userid"];
            $time = $this->input["time"];
            
            // See if the person already exists from another class
            $resP = $this->db->query("select p.id from person p, person_course pc where p.uva_id = $1 and p.id = pc.person_id and pc.course_id = $2", [
                $studentid, $course["id"]
            ]);
            $allP = $this->db->fetchAll($resP);

            // If person doesn't exist in the course, return error
            if (!(isset($allP[0]) && isset($allP[0]["id"]))) {
                $this->setError("Student was not found");
                die($this->showAddAccommodation());
            }
            
            $pid = $allP[0]["id"];
            $resPC = $this->db->query("update person_course set time_scale = $3 where course_id = $1 and person_id = $2;", [
                $course["id"],
                $pid,
                $time
            ]);

            $this->setMessage("Accommodation updated to $time for $studentid");

            return $this->showAddAccommodation();

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
     * Preview an Exam
     *
     *
     * @return string HTML display data from the templating engine
     */
    public function previewExam() {
        $data = $this->loadCourses();
        $cid = $this->input["course"];
        $course = null;
        $allowed = false;
        foreach ($data as $y) {
            foreach ($y as $c) {
                if ($c["id"] == $cid && ($c["role"] == "Instructor" || $c["role"] == "Secondary Instructor" || $c["role"] == "Teaching Assistant")) {
                    $allowed = true;
                    $course = $c;
                }
            }
        }
        if ($allowed) {
            $this->dData = $course;
            if (isset($this->input["exam"])) {
                $examdata = $this->loadResults($this->input["exam"], null);
                foreach ($examdata["info"]["questions"] as $k => $v) {
                    if ($v["language"] == "multiplechoice") {
                        $examdata["info"]["questions"][$k]["options"] = $this->parseOptions($v["code"]);
                        $examdata["info"]["questions"][$k]["code"] = "";
                    }
                }
                $this->dData = $examdata["info"];
            }
            return $this->display("previewexam");
        }
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

        $timermethod = 'bar-down';
        if (isset($this->input["timermethod"])) {
            $timermethod = $this->input["timermethod"];
        }

        // convert open/close time
        $open = null;
        $close = null;
        if (isset($this->input["opentime"]) && !empty($this->input["opentime"]) &&
            isset($this->input["opendate"]) && !empty($this->input["opendate"])) {

            $open = strtotime($this->input["opendate"] . " " .
                $this->input["opentime"]);

        }        
        if (isset($this->input["closetime"]) && !empty($this->input["closetime"]) &&
            isset($this->input["closedate"]) && !empty($this->input["closedate"])) {

            $close = strtotime($this->input["closedate"] . " " .
                $this->input["closetime"]);

        }        
        // make sure to add the time check to taking the exam, too, but NOT saving exam


        // create the exam
        if (!isset($this->input["exam"]) || empty($this->input["exam"])) {
            $res = $this->db->query("insert into exam (course_id, title, instructions, time_allowed, time_enforced, timer_method, open, close) values ($1, $2, $3, $4, $5, $6, $7, $8) returning id;", array(
                $course["id"],
                $this->input["name"],
                $this->input["instructions"],
                $timeallowed,
                $timeenforced,
                $timermethod,
                $open,
                $close
            ));
        } else {
            $res = $this->db->query("update exam set (title, instructions, time_allowed, time_enforced, timer_method, open, close) = ($3, $4, $5, $6, $7, $8, $9) where course_id = $1 and id = $2 returning id;", array(
                $course["id"],
                $this->input["exam"],
                $this->input["name"],
                $this->input["instructions"],
                $timeallowed,
                $timeenforced,
                $timermethod,
                $open,
                $close
            ));
        }
        $tmp = $this->db->fetchAll($res);
        if (count($tmp) != 1) {
            die($this->showError("Database Error"));
        }
        $eid = $tmp[0]["id"];

        foreach ($this->input["question"] as $k => $q) {
            if (!isset($this->input["questionid"][$k]) || empty($this->input["questionid"][$k])) {
                $res = $this->db->query("insert into question (exam_id, ordering, text, code, correct, rubric, language, score, unit_tests) values ($1, $2, $3, $4, $5, $6, $7, $8, $9);",
                    [
                        $eid,
                        $k, 
                        $q, 
                        isset($this->input["code"][$k]) ? $this->input["code"][$k] : "",
                        isset($this->input["answer"][$k]) ? $this->input["answer"][$k] : "",
                        isset($this->input["rubric"][$k]) ? $this->input["rubric"][$k] : "",
                        isset($this->input["language"][$k]) ? $this->input["language"][$k] : "java",
                        isset($this->input["score"][$k]) ? $this->input["score"][$k] : 0,
                        isset($this->input["autograder"][$k]) ? $this->input["autograder"][$k] : ""
                    ]);
            } else {
                $res = $this->db->query("update question set (text, code, correct, rubric, language, score, unit_tests) = ($1, $2, $3, $4, $5, $6, $7) where id = $8;",
                    [
                        $q, 
                        isset($this->input["code"][$k]) ? $this->input["code"][$k] : "",
                        isset($this->input["answer"][$k]) ? $this->input["answer"][$k] : "",
                        isset($this->input["rubric"][$k]) ? $this->input["rubric"][$k] : "",
                        isset($this->input["language"][$k]) ? $this->input["language"][$k] : "java",
                        isset($this->input["score"][$k]) ? $this->input["score"][$k] : 0,
                        isset($this->input["autograder"][$k]) ? $this->input["autograder"][$k] : "",
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

        if ($this->saveLogger != null) {
            $logMessage = ["user" => $this->user["id"], "qs" => $this->input["q"], "responses" => $this->input["response"]];
            if (isset($this->input["timermethod"]))
                $logMessage["timer"] = $this->input["timermethod"];
            if (isset($this->input["timerhidden"]))
                $logMessage["timer_hidden"] = $this->input["timerhidden"];
            $this->saveLogger->addNotice("", $logMessage);
        }

        foreach ($this->input["q"] as $k => $q) {
            $res = $this->db->query("select pq.response from person_question pq where pq.person_id = $1
                and pq.question_id = $2 and pq.exam_id = $3;", [$this->user["id"], $q, $this->input["e"]]);
            $all = $this->db->fetchAll($res);

            if (!isset($this->input["response"][$k]))
                $this->input["response"][$k] = "";

            // If student already has written a partial response, then replace
            if (isset($all[0]) && isset($all[0]["response"])) {
                $res = $this->db->query("update person_question set response = $4 where person_id = $1 and question_id = $2 and exam_id = $3;",
                    [$this->user["id"], $q, $this->input["e"], $this->input["response"][$k]]);
            } else {
                $res = $this->db->query("insert into person_question (person_id, question_id, exam_id, response) values ($1, $2, $3, $4);",
                    [$this->user["id"], $q, $this->input["e"], $this->input["response"][$k]]);
            }
        }

        if ($exam["info"]["timer_method"] == 'choice' && isset($this->input["timermethod"]) && !empty($this->input["timermethod"])) {
            $res = $this->db->query("update person_exam set timer_method = $3 where person_id = $1 and exam_id = $2;",
                [$this->user["id"], $this->input["e"], $this->input["timermethod"]]);
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
        $res = $this->db->query("update person_question set submitted = true where person_id = $1 and exam_id = $2;",
            [$this->user["id"], $this->input["e"]]);

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
            else if ($m == "submitall")
                $this->dData["message"] = "Any unsubmitted assessments have been submitted.";
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
                    from person_question pq
                    where pq.grader is null and pq.grade_time is null and 
                    pq.submitted = true and pq.question_id = $1
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
                    from person_question pq
                    where pq.person_id = $2 and 
                    pq.submitted = true and pq.question_id = $1
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
     * Submit all un-submitted assessments 
     *
     * Submit all exams for students who have not completed their assessment.  This ensures
     * that they can all be appropriately graded.
     *
     * @return string HTML display data from the templating engine
     */
    public function submitAll() {
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

        $res = $this->db->query("update person_question pq set submitted = 't' 
            where exam_id = $1 and not submitted;",  
            [$eid]);
        
        $res = $this->db->query("update person_exam set date_taken = now() 
            where exam_id = $1 and date_taken is null;",  
            [$eid]);


        header("Location: ?c=grade&e=".$this->input['e']."&m=submitall");
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
            set grader = null, feedback = null 
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
     * Parse options from text
     */
    private function parseOptions($text) {
        $options = [];
        $lines = explode("\n", $text);
        $current = "";
        foreach ($lines as $line) {
            $key = substr($line, 0, 1);
            $value = substr($line, 3);
            $options[$key] = $value;
        }
        return $options;
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
            e.id, e.date, e.open, e.close, e.closed, e.time_allowed, e.time_enforced, e.timer_method, pc.time_scale from course c, exam e, person_course pc 
            where e.course_id = c.id and pc.course_id = c.id and pc.person_id = $1 and e.id = $2;", [$this->user["id"], $eid]);
        $all = $this->db->fetchAll($res);
        $allowed = false;
        foreach ($all as $row) {
            if ($row["id"] == $eid && $row["closed"] != 't') {
                // allow opening only if no time set or if it's between open/close timem
                if (($row["open"] == "" && $row["close"] == "") ||
                    ($row["open"] == null && $row["close"] == null) ||
                    ($row["open"] != "" && $row["open"] != null && $row["close"] != "" && $row["close"] != null
                        && $row["open"] <= time() && $row["close"] >= time())) {
                    $allowed = true; 
                }
                $exam["info"] = $row;
                // scale up the time based on the scale in the person_course link
                if ($exam["info"]["time_allowed"] != null) {
                    $exam["info"]["time_allowed"] = (int) ($exam["info"]["time_allowed"] * $exam["info"]["time_scale"]); 
                }
                
                // by default they start with full time left
                $exam["info"]["time"] = [
                    "elapsed" => 0,
                    "left" => $exam["info"]["time_allowed"],
                    "percleft" => "100"
                ];
                break;
            }
        }

        if (!$allowed)
            die($this->showError("You may not take this assessment at this time"));

        // Check to ensure the student hasn't submitted already
        $res = $this->db->query("select e.exam_id, e.date_taken, e.date_started, e.timer_method from person_exam e where e.exam_id = $1 and e.person_id = $2;", [$eid, $this->user["id"]]);
        $all = $this->db->fetchAll($res);
        $mins = 0;
        if (isset($all[0])) {
            // The student has started the exam -- check conditions
            $info = $all[0];
            $exam["info"]["date_started"] = $info["date_started"];
            if (($exam["info"]["timer_method"] == "choice" || $exam["info"]["timer_method"] == "study") && $info["timer_method"] != null)
                $exam["info"]["timer_method"] = $info["timer_method"];

            /*if (($exam["info"]["timer_method"] == "study") && $info["timer_method"] == null) {
                $studyOptions = ["stoplight", "grayshades"];
                $studyKey = array_rand($studyOptions, 1);
                $exam["info"]["timer_method"] = $studyOptions[$studyKey];
                $resStudy = $this->db->query("update person_exam set timer_method = $3 where person_id = $1 and exam_id = $2;",
                    [$this->user["id"], $eid, $exam["info"]["timer_method"]]);

                }*/
            
            if ($exam["info"]["time_allowed"] != null) {
                // check to see time left (rewrite the time left)
                $exam["info"]["time"] = [];
                $started = strtotime($info["date_started"]);
                $now = strtotime("now");
                $mins = ($now - $started) / 60;
            }
            if ($this->user["id"] == 1) {
                $this->logger->addDebug("Test", [$exam]);
                $this->logger->addDebug("Ifno", [$info]);
            }

            if ($info["date_taken"] != null)
                die($this->showError("You may not retake the same assessmment twice."));
        } else {
            // RESEARCH STUDY
            // If there is an ongoing research study, make the choice 
            // study if this is the first ime the exam was opened
            if ($exam["info"]["timer_method"] == "study") {
                $studyOptions = ["green-down", "text-down"];
                $studyKey = array_rand($studyOptions, 1);
                $exam["info"]["timer_method"] = $studyOptions[$studyKey];
                $resStudy = $this->db->query("update person_exam set timer_method = $3 where person_id = $1 and exam_id = $2;",
                    [$this->user["id"], $eid, $exam["info"]["timer_method"]]);
                if ($this->user["id"] == 1) {
                    $this->logger->addDebug("Test2", [$exam, $eid, $this->user["id"]]);
                }

            }
            // time allowed if first time opened
            if ($exam["info"]["time_allowed"] != null) {
                // check to see time left (rewrite the time left)
                $exam["info"]["time"] = [];
                $mins = 0;
            }
        }
            
        if ($exam["info"]["time_allowed"] != null) {
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
                "score" => $row["score"],
                "language" => $row["language"]
            ];

            if ($row["language"] == "multiplechoice") {
                $exam["questions"][$row["ordering"]]["options"] = $this->parseOptions($row["code"]);
                $exam["questions"][$row["ordering"]]["code"] = "";
            }

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

        // update the timer if needed
        if ($exam["info"]["timer_method"] != null) {
            $resStudy = $this->db->query("update person_exam set timer_method = $3 where person_id = $1 and exam_id = $2;",
                [$this->user["id"], $eid, $exam["info"]["timer_method"]]);
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
        
        $res = $this->db->query("select c.title as course, c.year, c.semester, c.uva_id as courseid, e.title, e.time_allowed,
            e.id, e.date, e.open, e.close, e.closed, pc.role, pc.time_scale from course c, exam e, person_course pc where e.course_id = c.id and pc.course_id = c.id and pc.person_id = $1 order by c.year desc, c.semester asc, e.id asc;", [$this->user["id"]]);
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
                "time_allowed" => $row["time_allowed"] ? (int) ($row["time_allowed"] * $row["time_scale"]) : $row["time_allowed"],
                "closed" => $row["closed"] == 't' ? true : false,
                "available" => false
            ];

            // Make timezone dependent to EST/EDT
            $curtime = strtotime(date("Y-m-d H:i:s"));

            if ($row["closed"] != 't' && (($row["open"] == "" && $row["close"] == "") ||
                ($row["open"] == null && $row["close"] == null) ||
                ($row["open"] != "" && $row["open"] != null && $row["close"] != "" && $row["close"] != null
                    && $row["open"] <= $curtime && $row["close"] >= $curtime))) {
                $exams[$row["year"] . " - " . $row["semester"]][$row["courseid"]]["exams"][$row["id"]]["available"] = true;  
            }

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
            e.id, e.date, e.open, e.close, e.title, e.instructions, e.time_enforced, e.time_allowed, e.timer_method, pc.role 
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
                "unit_tests" => $row["unit_tests"],
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
            $res = $this->db->query("select e.person_id, e.date_started, e.date_taken, e.code, u.uva_id, u.name, pc.role from person_exam e, person u, exam ex, person_course pc where e.exam_id = $1 and e.person_id = u.id and pc.person_id = u.id and ex.id = e.exam_id and pc.course_id = ex.course_id and pc.role = 'Student';", [$eid]);
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
            $res = $this->db->query("select pq.* from person_question pq, exam e, person_course pc where pq.exam_id = $1 and pq.person_id = pc.person_id 
                and e.id = pq.exam_id and e.course_id = pc.course_id and pc.role = 'Student'
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
                "graded" => $row["graded"] === 't' ? true : false,
                "grade_time" => $row["grade_time"],
                "auto_grader" => $row["auto_grader"],
                "flagged" => $row["flagged"] === 't' ? true : false
            ];

            $exams[$row["person_id"]]["questions"][$row["question_id"]] = [
                "person_id" => $row["person_id"],
                "response" => $row["response"],
                "feedback" => $row["feedback"],
                "score" => $row["score"],
                "grader" => $row["grader"],
                "auto_grader" => $row["auto_grader"],
                "flagged" => $row["flagged"] === 't' ? true : false
            ];
        }

        $overall_total = 0;
        $overall_graded = 0;
        foreach ($examInfo["questions"] as &$qinfo) {
            $total = 0;
            $graded = 0;
            if (isset($questions[$qinfo["id"]]["answers"])) { 
                foreach ($questions[$qinfo["id"]]["answers"] as $row) {
                    if ($row["grade_time"] != "" || $row["graded"])
                        $graded++;
                    $total++;
                }
                $qinfo["total"] = $total;
                $qinfo["graded"] = $graded;
                $qinfo["percent"] = round(100*($graded/$total),0,PHP_ROUND_HALF_DOWN);
                $overall_total += $total;
                $overall_graded += $graded;
            } else {
                $qinfo["total"] = 0;
                $qinfo["graded"] = 0;
                $qinfo["percent"] = 0;
            }
        }
        // do not use qinfo again in this method, or call unset below first
        //unset($qinfo);

        $examInfo["grading_progress"] = [
            "total" => $overall_total,
            "graded" => $overall_graded,
            "percent" => round(100*($overall_graded/$overall_total),0,PHP_ROUND_HALF_DOWN)
        ];

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

    public function studentOwnExamView() {
        // need a non-instructor load results function to make this work
        $results = $this->loadResults(null, $this->user["uva_id"]);

        //print_r($results);
        //$this->dData = $results;
        //return ["info" => $examInfo, "exams" => $exams, "questions" => $questions, "recents" => $recents];

        $this->dData = [
            "info" => $results["info"],
            "questions" => []
        ];
        foreach ($results["exams"] as $exam)
            $this->dData["exam"] = $exam;
        foreach ($results["info"]["questions"] as $q) {
            if ($q["language"] == "multiplechoice")
                $q["options"] = $this->parseOptions($q["code"]);
            $this->dData["questions"][$q["id"]] = $q;
        }
        $this->dData["exam"]["score"] = 0;
        foreach ($this->dData["exam"]["questions"] as $q)
            $this->dData["exam"]["score"] += $q["score"];
        return $this->display("viewexam");
        
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
        foreach ($results["info"]["questions"] as $q) {
            if ($q["language"] == "multiplechoice")
                $q["options"] = $this->parseOptions($q["code"]);
            $this->dData["questions"][$q["id"]] = $q;
        }
        $this->dData["exam"]["score"] = 0;
        foreach ($this->dData["exam"]["questions"] as $q)
            $this->dData["exam"]["score"] += $q["score"];
        return $this->display("viewexam");
        
    }
    
    /**
     * Run Multiple Choice Autograder 
     *
     * Runs MC over any questions for the current exam. 
     *
     * @param int $onlyid Only autograde for this student.  If null, run for all students 
     * @return Output for display 
     */
    public function runMCAutograde($onlyid = null) {
        $uid = $onlyid;
        if (isset($this->input["u"]) && !empty($this->input["u"]))
            $uid = $this->input["u"];
        $results = $this->loadResults(null, $uid);
        $info = $results["info"];
        
        if (!$this->isInstructor($this->user["id"], $this->input["e"]))
            die($this->showError("You do not have permissions to view this exam"));

        $progress = "Running autograder\n\n";
        $this->logger->addDebug("Running Tests", []);

        foreach ($info["questions"] as $question) {

            // check if should run tests
            if ($question["language"] != "multiplechoice")
                continue; // we should not run if there are no tests

            $progress .=  "Running autograder for the following question:\n----------------------------------------\n";
            $progress .=  $question["text"]."\n\n";
             
            foreach ($results["questions"][$question["id"]]["answers"] as $pid => $answer) {
                $score = 0;
                //print_r($answer);

                //print_r($question);
                if ($answer["response"] == $question["correct"]) {
                    $score = $question["score"];
                }
                $progress .= "Score was $score\n"; 
                $res = $this->db->query("update person_question
                    set score = $3
                    where person_id = $1 and question_id = $2;", 
                    [
                        $pid,
                        $question["id"],
                        $score
                    ]);
            } 
        }
        
        $progress .= "Finished running autograder\n";


        $this->dData = [
            'info' => $info,
            'progress' => $progress
        ];

        return $this->display("autograder");

    }    

    /**
     * Run JUnit over Class 
     *
     * Runs JUnit over any questions for the current exam that have defined unit tests.
     * Currently only uses JUnit 4.
     *
     * @param int $onlyid Only run JUnit for this student.  If null, run for all students 
     * @return Output for display 
     */
    public function runJUnitClass($onlyid = null) {
        $uid = $onlyid;
        if (isset($this->input["u"]) && !empty($this->input["u"]))
            $uid = $this->input["u"];
        $results = $this->loadResults(null, $uid);
        $info = $results["info"];
        
        if (!$this->isInstructor($this->user["id"], $this->input["e"]))
            die($this->showError("You do not have permissions to view this exam"));

        $progress = "Connecting to the tester\n\n";

        // Connect to the tester machine 
        $key = new RSA();
        $key->loadKey(file_get_contents(\manager\Config::$TEST_MACHINE_RSA));

        // Domain can be an IP too
        $ssh = new SSH2(\manager\Config::$TEST_MACHINE);
        if (!$ssh->login(\manager\Config::$TEST_MACHINE_USER, $key)) {
            exit('SSH Login Failed');
        }
        $sftp = new SFTP(\manager\Config::$TEST_MACHINE);
        if (!$sftp->login(\manager\Config::$TEST_MACHINE_USER, $key)) {
            exit('SFTP Login Failed');
        }

        // Change directory command to issue on the server 
        $cdCmd = "cd " . \manager\Config::$TEST_MACHINE_DIR;
        $tmpDir = $info["id"]."_".time();
        $startup = $ssh->exec("$cdCmd && mkdir $tmpDir && mkdir $tmpDir/individual\n");
        $cdCmd = $cdCmd . $tmpDir; 

        $progress .= "Running tests\n\n";
        $this->logger->addDebug("Running Tests", []);

        foreach ($info["questions"] as $question) {

            // check if should run tests
            if (empty($question["unit_tests"]))
                continue; // we should not run if there are no tests


            $progress .=  "Running tests for the following question:\n----------------------------------------\n\n";
            $this->logger->addDebug("Testing question", [$question["text"]]);

            $progress .=  $question["text"]."\n\n";

            // do the setup for this question
            $unitTests = $question["unit_tests"];
            list($junk, $junk2) = explode("public class ", $unitTests);
            list($unitTestClassName, $junk) = explode(" ", $junk2);
            $unitTestName = $unitTestClassName . ".java";
            $scaffold = $question["code"];
            list($junk, $junk2) = explode("public class ", $scaffold);
            list($scaffoldName, $junk) = explode(" ", $junk2);
            $scaffoldName = $scaffoldName . ".java";

            // The following line could be used to supplement student code
            //$getset = file_get_contents(\manager\Config::$TEMP_DIR."/getset.java");
            
            $sftp->put(\manager\Config::$TEST_MACHINE_DIR.$tmpDir."/".$unitTestName, $unitTests);
            
            foreach ($results["questions"][$question["id"]]["answers"] as $pid => $answer) {
                $progress .= "Running for {$pid}\n===========================================\n";

                // The following could update the response to add supplemental class material
                //list($pre, $post) = explode("//Constructor", $answer["response"]);
                //$updated = $pre . "\n" . $getset . "\n" . $post;
                
                $updated = $answer["response"];
                $progress .= $updated . "\n\n";

                // Copy the student code and unit tests to a directory unique to this student
                $setupOut = $ssh->exec("$cdCmd && mkdir individual/$pid\n");
                $sftp->put(\manager\Config::$TEST_MACHINE_DIR.$tmpDir."/individual/$pid/".$unitTestName, $unitTests);
                $sftp->put(\manager\Config::$TEST_MACHINE_DIR.$tmpDir."/individual/$pid/".$scaffoldName, $updated);

                // Go to the student directory and compile and run code and JUnit tests
                $setupOut .= $ssh->exec("$cdCmd && cd individual/$pid && javac -cp .:../../../lib/junit.jar:../../../lib/hamcrest.jar $scaffoldName $unitTestName 2>&1\n");
                $junitOut = $ssh->exec("$cdCmd && cd individual/$pid && java -cp .:../../../lib/junit.jar:../../../lib/hamcrest.jar org.junit.runner.JUnitCore $unitTestClassName 2>&1\n");

                // Make output more friendly
                $junitOutClean = str_replace("FAILURES!!!","", $junitOut);

                // Grab counts of tests and failures
                // This MIGHT NOT report all the tests if there are some failures (some tests might not run)
                preg_match('/Tests run:.*([0-9]+),.*Failures:.*([0-9]+)/', $junitOut, $matches);
                preg_match('/There [wares]+ ([0-9]+) failure.*:/', $junitOut, $matches2);
                // 100% success outputs only an OK line with tests passed
                preg_match('/OK \(([0-9]+) tests\)*/', $junitOut, $matches3);

                // Calculate Score
                $score = 0;
                if (isset($matches3[1])) {
                    // We got an OK line, so all tests passed
                    $score = 1.0;
                } else if ($matches[2] == $matches2[1]) {
                    $testsRun = $matches[1];
                    $failuresRun = $matches[2];
                    $passedRun = $testsRun - $failuresRun;
                    $score = ($passedRun / (float) $testsRun);
                }
                $weightedScore = round($score * (float) $question["score"],2);
                $percentScore = round($score * 100, 2);


                // Use JUnit Output as comments unless it's empty, then include setup
                $comments = $setupOut . $junitOutClean . "\n--------------\nAutograder Score: $percentScore%";
                // Write output to the test system for debugging purposes
                $sftp->put(\manager\Config::$TEST_MACHINE_DIR.$tmpDir."/individual/$pid/output.txt", $comments);

                $progress .= $comments."\n\n";
                $res = $this->db->query("update person_question
                    set score = $3, auto_grader = $4
                    where person_id = $1 and question_id = $2;", 
                    [
                        $pid,
                        $question["id"],
                        $weightedScore,
                        $comments
                    ]);
                
            }

            $progress .= "Cleaning up test\n\n";

            // clean up this test (commented out for debugging)
            $cleanupOut = $ssh->exec("$cdCmd && rm *.class *.java\n");
        }

        // Clean up ignored for debugging purposes
        $cleanupOut = $ssh->exec("$cdCmd && cd .. && rmdir $tmpDir\n");
        
        $progress .= "Finished running autograder tests\n";


        $this->dData = [
            'info' => $info,
            'progress' => $progress
        ];

        return $this->display("autograder");

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
            } else if ($this->input["format"] == "text-zip") {
                $zip = new \ZipArchive();
                // make the zip file unique
                $zipname = Config::$TEMP_DIR . "/". $info["id"] . "_" . time() . ".zip";
                $zip->open($zipname, \ZipArchive::CREATE);
                foreach ($results["exams"] as $exam) {
                    // only download student grades
                    if ($exam["role"] !== "Student")
                        continue;

                    //$uzdir = "$zdir/{$exam["name"]}({$exam["uva_id"]})";

                    $response = "";
                    $comments = "";
                    $i = 1;
                    $score = 0;
                    $body = "";
                    foreach ($info["questions"] as $q) {
                        $response = "Question $i\n-------------\n";
                        $response .= "Question:\n".$q["text"] . "\n\nResponse:\n";
                        if (isset($exam["questions"][$q["id"]]) && isset($exam["questions"][$q["id"]]["response"]))
                            $response .= $exam["questions"][$q["id"]]["response"]."\n\n";
                        else
                            $response .= "NO RESPONSE\n\n";
                        $body .= "$response";
                        $comments .= "Question $i\n-----------\n";
                        $curcomments = "";
                        if (isset($exam["questions"][$q["id"]]) 
                            && isset($exam["questions"][$q["id"]]["score"])) {

                            if (isset($exam["questions"][$q["id"]]["auto_grader"]))
                                $curcomments .= "Autograder Results:\n" . $exam["questions"][$q["id"]]["auto_grader"] . "\n-----\n";

                            if (isset($exam["questions"][$q["id"]]["feedback"]))
                                $curcomments .= "Grader Feedback:\n" . $exam["questions"][$q["id"]]["feedback"] . "\n";
                            $curcomments .= "Score: " . $exam["questions"][$q["id"]]["score"] ." / ".$q["score"]."\n";
                            $body .= "$curcomments\n";
                            $comments .= $curcomments;
                            $score += $exam["questions"][$q["id"]]["score"];
                        } else {
                            $comments .= "Score: 0 / ".$q["score"]."\n\n";
                        }

                        $i++;
                    }
                    $comments .= "-------------------\nFinal Score: $score\n";
                    $zip->addFromString("{$exam["uva_id"]}.txt", $body);
                }
                // close zip for downloading
                $zip->close();

                // show ZIP file
                header('Content-Type: application/zip');
                header('Content-disposition: attachment; filename=exam_submissions.zip');
                header('Content-Length: ' . filesize($zipname));
                $zipfile = file_get_contents($zipname); 

                // remove the zip from the local filesystem
                unlink($zipname);
                return $zipfile;
            } else if ($this->input["format"] == "jplag-zip") {
                $zip = new \ZipArchive();
                // make the zip file unique
                $zipname = Config::$TEMP_DIR . "/". $info["id"] . "_" . time() . ".zip";
                $zip->open($zipname, \ZipArchive::CREATE);
                foreach ($results["exams"] as $exam) {
                    // only download student grades
                    if ($exam["role"] !== "Student")
                        continue;

                    //$uzdir = "$zdir/{$exam["name"]}({$exam["uva_id"]})";
                    $zip->addEmptyDir("{$exam["uva_id"]}");

                    $response = "";
                    $comments = "";
                    $i = 1;
                    $score = 0;
                    $body = "";
                    foreach ($info["questions"] as $q) {
                        if ($i > 2) 
                            break;
                        if (isset($exam["questions"][$q["id"]]) && isset($exam["questions"][$q["id"]]["response"]))
                            $zip->addFromString("{$exam["uva_id"]}/$i.java", $exam["questions"][$q["id"]]["response"]);
                        $i++;
                    }
                }
                // close zip for downloading
                $zip->close();

                // show ZIP file
                header('Content-Type: application/zip');
                header('Content-disposition: attachment; filename=exam_submissions_jplag.zip');
                header('Content-Length: ' . filesize($zipname));
                $zipfile = file_get_contents($zipname); 

                // remove the zip from the local filesystem
                unlink($zipname);
                return $zipfile;
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
            $comments = "See attached PDF for details\n\n";
            $i = 1;
            $score = 0;
            $body = "";
            foreach ($info["questions"] as $q) {
                $response = "<h3>Question $i</h3>\n";
                $response .= $q["text"] . "<br>\n";
                if (isset($exam["questions"][$q["id"]]) && isset($exam["questions"][$q["id"]]["response"])) {
                    if ($q["language"] == "multiplechoice") {
                        $response .= "<p>".nl2br(htmlspecialchars($q["code"]))."</p>";
                        $response .= "<p><b>Response:</b> ".$exam["questions"][$q["id"]]["response"]."</p>\n\n";
                    } else {
                        $response .= "<pre>".htmlspecialchars($exam["questions"][$q["id"]]["response"])."</pre>\n\n";
                    }
                } else
                    $response .= "<pre>NO RESPONSE</pre>\n\n";
                $body .= "$response";
                $comments .= "Question $i\n-----------\n";
                $curcomments = "";
                if (isset($exam["questions"][$q["id"]]) 
                    && isset($exam["questions"][$q["id"]]["score"])) {
                    
                    if ($q["language"] == "multiplechoice")
                        $curcomments .= "Correct Response: " . $q["correct"] . "\n\n";

                    if (isset($exam["questions"][$q["id"]]["auto_grader"]))
                        $curcomments .= "Autograder Results:\n" . $exam["questions"][$q["id"]]["auto_grader"] . "\n\n-----\n\n";
                    
                    if (isset($exam["questions"][$q["id"]]["feedback"]))
                        $curcomments .= "Grader Feedback:\n" . $exam["questions"][$q["id"]]["feedback"] . "\n\n";
                    $scorecomments = "Score: " . $exam["questions"][$q["id"]]["score"] ." / ".$q["score"]."\n\n";
                    $curcomments .= $scorecomments;
                    $body .= "<br><pre style='color: #000077; margin: 20px; border: 1px solid #000077; padding: 10px;'>$curcomments</pre>\n\n";
                    $comments .= $scorecomments;
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
            $fullhtml = "<html><head><title>Exam Submission: {$exam["uva_id"]}</title></head><body>$body</body></html>";
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
            //$process = proc_open("cd $tmpDir && pandoc -f html -o $tmpFile -t html 2>&1", $descriptorspec, $pipes);
            $process = proc_open("cd $tmpDir && pandoc2 -f html -o $tmpFile --pdf-engine=wkhtmltopdf 2>&1", $descriptorspec, $pipes);
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
                $zip->addFromString("$uzdir/{$exam["name"]}({$exam["uva_id"]})_submissionText.html", $fullhtml);

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
    
    /**
     * Load Student Results
     *
     * Loads all results for the given exam for a particular student 
     *
     * @param int $examid optional The exam id to load
     * @param int $onlyid optional unused 
     * @return string[] Exam result data
     */
    public function loadResultsForStudent($examid = null) {
        $onlyid = $this->user["uva_id"];
        $eid = $examid;
        if ($examid == null && !isset($this->input["e"]))
            die($this->showError("Unknown Exam"));

        if ($examid == null)
            $eid = $this->input["e"];
        
        $res = $this->db->query("select 
            e.id, e.date, e.open, e.close, e.title, e.instructions, e.time_enforced, e.time_allowed, e.timer_method, pc.role 
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
                "unit_tests" => $row["unit_tests"],
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
            $res = $this->db->query("select e.person_id, e.date_started, e.date_taken, e.code, u.uva_id, u.name, pc.role from person_exam e, person u, exam ex, person_course pc where e.exam_id = $1 and e.person_id = u.id and pc.person_id = u.id and ex.id = e.exam_id and pc.course_id = ex.course_id and pc.role = 'Student';", [$eid]);
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
            $res = $this->db->query("select pq.* from person_question pq, exam e, person_course pc where pq.exam_id = $1 and pq.person_id = pc.person_id 
                and e.id = pq.exam_id and e.course_id = pc.course_id and pc.role = 'Student'
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
                "grade_time" => $row["grade_time"],
                "auto_grader" => $row["auto_grader"],
                "flagged" => $row["flagged"] === 't' ? true : false
            ];

            $exams[$row["person_id"]]["questions"][$row["question_id"]] = [
                "person_id" => $row["person_id"],
                "response" => $row["response"],
                "feedback" => $row["feedback"],
                "score" => $row["score"],
                "grader" => $row["grader"],
                "auto_grader" => $row["auto_grader"],
                "flagged" => $row["flagged"] === 't' ? true : false
            ];
        }

        foreach ($examInfo["questions"] as &$qinfo) {
            $total = 0;
            $graded = 0;
            if (isset($questions[$qinfo["id"]]["answers"])) { 
                foreach ($questions[$qinfo["id"]]["answers"] as $row) {
                    if ($row["grade_time"] != "")
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
}
