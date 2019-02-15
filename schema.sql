CREATE SEQUENCE person_ids
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

CREATE SEQUENCE course_ids
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

CREATE SEQUENCE exam_ids
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

CREATE SEQUENCE question_ids
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

create table person (
    id integer DEFAULT nextval('person_ids'::regclass),
    uva_id text,
    name text
);

create table course (
    id integer DEFAULT nextval('course_ids'::regclass),
    uva_id text,
    semester text,
    year int,
    title text
);

create table exam (
    id integer DEFAULT nextval('exam_ids'::regclass),
    title text,
    course_id int,
    date text,
    open text,
    close text
);

create table question (
    id integer DEFAULT nextval('question_ids'::regclass),
    exam_id int,
    ordering int,
    text text,
    code text,
    correct text,
    rubric text,
    score text
);

create table person_course (
    course_id int,
    person_id int,
    role text,
    section text
);

create table person_exam (
    person_id int,
    exam_id int,
    date_taken int
);

create table person_question (
    person_id int,
    question_id int,
    exam_id int,
    response text,
    feedback text,
    score text
);

