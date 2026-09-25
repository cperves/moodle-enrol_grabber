<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace enrol_grabber;

use backup;
use backup_controller;
use restore_controller;
use restore_dbops;

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * systempay tests.
 *
 * @package    enrol_grabber
 * @category   test
 * @copyright Université de Strasbourg <www.unistra.fr>
 * @author  2023 Céline Pervès <cperves@unistra.fr>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_restore_test extends \advanced_testcase {
    private $course;
    private $newcourseid;
    private $grabberinstanceid;
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->create_enrolments();
    }

    public function test_backup_restore_with_enrolment_method_and_user(){
        global $USER, $CFG, $DB;
        $this->perform_backup_restore(array('enrolments' => backup::ENROL_WITHUSERS));
        $enrol = $DB->get_records('enrol', ['enrol' => 'grabber', 'courseid' => $this->newcourseid,
            'status' => ENROL_INSTANCE_ENABLED]);
        $this->assertNotEmpty($enrol);
        $sql = "select ue.id, ue.userid, e.enrol from {user_enrolments} ue
            join {enrol} e on ue.enrolid = e.id WHERE e.courseid = ?";
        $enrolments = $DB->get_records_sql($sql, [$this->newcourseid]);
        $this->assertEquals(1, count($enrolments));
    }

    public function test_backup_restore_with_enrolments_without_user(){
        global $USER, $CFG, $DB;
        $this->perform_backup_restore(array('enrolments' => backup::ENROL_ALWAYS, 'users' => false));
        $enrol = $DB->get_records('enrol', ['enrol' => 'grabber', 'courseid' => $this->newcourseid,
            'status' => ENROL_INSTANCE_ENABLED]);
        $this->assertNotEmpty($enrol);
        $sql = "select ue.id, ue.userid, e.enrol from {user_enrolments} ue
            join {enrol} e on ue.enrolid = e.id WHERE e.courseid = ?";
        $enrolments = $DB->get_records_sql($sql, [$this->newcourseid]);
        $this->assertEquals(0, count($enrolments));
    }

    protected function create_enrolments(){
        global $DB, $USER, $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();

        // Get the enrol plugin.
        $grabberpluginname = 'grabber';
        $grabberplugin = enrol_get_plugin($grabberpluginname);
        $manualpluginname = 'manual';
        $manualplugin = enrol_get_plugin($manualpluginname);
        $CFG->enrol_plugins_enabled = $grabberpluginname.','.$manualpluginname;
        // Create a course.
        $this->course = $generator->create_course();
        // Enable this enrol plugin for the course.
        $studentrole = $DB->get_record('role', array('shortname' => 'student'));
        $manualinstance = $DB->get_record('enrol', array('courseid'=>$this->course->id, 'enrol'=>'manual'));
        $grabberdata = array(
            'roleid' => $studentrole->id,
            'status' => ENROL_INSTANCE_ENABLED,
            'customint1' => $manualinstance->id,
            'customint2' => 1,//backup delete mode
            'customtext1' => $manualplugin->get_instance_name($manualinstance->id)
        );
        // Create a student.
        $student1 = $generator->create_user();
        // Enrol the student to the course.
        $manualplugin->enrol_user($manualinstance, $student1->id, $studentrole->id);
        $this->assertEquals(1, $DB->count_records('user_enrolments', array('enrolid'=> $manualinstance->id)));
        $this->grabberinstanceid = $grabberplugin->add_instance($this->course, $grabberdata);
        $this->assertEquals(0, $DB->count_records('user_enrolments', array('enrolid'=> $manualinstance->id)));
        $this->assertEquals(1, $DB->count_records('user_enrolments', array('enrolid'=> $this->grabberintanceid)));
    }

    /**
     * @param object|\stdClass $CFG
     * @param \moodle_database $DB
     * @param mixed $USER
     * @return array
     * @throws \base_plan_exception
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \restore_controller_exception
     */
    protected function perform_backup_restore(array $backupoptions) {
        global $DB, $USER, $CFG;
        // Turn off logging
        $CFG->backup_file_logger_level = backup::LOG_NONE;
        $bc = new backup_controller(backup::TYPE_1COURSE, $this->course->id,
            backup::FORMAT_MOODLE, backup::INTERACTIVE_NO, backup::MODE_SAMESITE,
            $USER->id);
        $backupid = $bc->get_backupid();
        $backupbasepath = $bc->get_plan()->get_basepath();
        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'];
        $bc->destroy();
        if (!file_exists($backupbasepath . "/moodle_backup.xml")) {
            $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $backupbasepath);
        }
        $this->newcourseid = restore_dbops::create_new_course($this->course->fullname . '_2',
            $this->course->shortname . '_2',
            $this->course->category);
        $rc = new restore_controller($backupid, $this->newcourseid,
            backup::INTERACTIVE_NO, backup::MODE_SAMESITE, $USER->id, backup::TARGET_NEW_COURSE);
        foreach($backupoptions as $option => $value) {
            $rc->get_plan()->get_setting($option)->set_value($value);
        }
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
    }
}
