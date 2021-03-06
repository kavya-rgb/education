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

/**
 * A scheduled task.
 *
 * @package    assignfeedback_editpdf
 * @copyright  2016 Damyon Wiese
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace assignfeedback_editpdf\task;

use core\task\scheduled_task;
use assignfeedback_editpdf\document_services;
use assignfeedback_editpdf\combined_document;
use context_module;
use assign;

/**
 * Simple task to convert submissions to pdf in the background.
 * @copyright  2016 Damyon Wiese
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class convert_submissions extends scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('preparesubmissionsforannotation', 'assignfeedback_editpdf');
    }

    /**
     * Do the job.
     * Throw exceptions on errors (the job will be retried).
     */
    public function execute() {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        // Conversion speed varies significantly and mostly depends on the documents content.
        // We don't want the task to get stuck forever trying to process the whole queue in one go,
        // so fetch 100 records only to make sure the task will be working for reasonable time.
        // With the task's default schedule, 100 records per run means the task is capable to process
        // 9600 conversions per day (100 * 4 * 24).
        $records = $DB->get_records('assignfeedback_editpdf_queue', [], '', '*', 0, 100);

        $assignmentcache = array();

        $conversionattemptlimit = !empty($CFG->conversionattemptlimit) ? $CFG->conversionattemptlimit : 3;
        foreach ($records as $record) {
            $submissionid = $record->submissionid;
            $submission = $DB->get_record('assign_submission', array('id' => $submissionid), '*', IGNORE_MISSING);
            if (!$submission || $record->attemptedconversions >= $conversionattemptlimit) {
                // Submission no longer exists; or we've exceeded the conversion attempt limit.
                $DB->delete_records('assignfeedback_editpdf_queue', array('id' => $record->id));
                continue;
            }

            // Record that we're attempting the conversion ahead of time.
            // We can't do this afterwards as its possible for the conversion process to crash the script entirely.
            $DB->set_field('assignfeedback_editpdf_queue', 'attemptedconversions',
                    $record->attemptedconversions + 1, ['id' => $record->id]);

            $assignmentid = $submission->assignment;
            $attemptnumber = $record->submissionattempt;

            if (empty($assignmentcache[$assignmentid])) {
                $cm = get_coursemodule_from_instance('assign', $assignmentid, 0, false, MUST_EXIST);
                $context = context_module::instance($cm->id);

                $assignment = new assign($context, null, null);
                $assignmentcache[$assignmentid] = $assignment;
            } else {
                $assignment = $assignmentcache[$assignmentid];
            }

            $users = array();
            if ($submission->userid) {
                array_push($users, $submission->userid);
            } else {
                $members = $assignment->get_submission_group_members($submission->groupid, true);

                foreach ($members as $member) {
                    array_push($users, $member->id);
                }
            }

            mtrace('Convert ' . count($users) . ' submission attempt(s) for assignment ' . $assignmentid);
            $conversionrequirespolling = false;

            foreach ($users as $userid) {
                try {
                    $combineddocument = document_services::get_combined_pdf_for_attempt($assignment, $userid, $attemptnumber);
                    switch ($combineddocument->get_status()) {
                        case combined_document::STATUS_READY:
                        case combined_document::STATUS_READY_PARTIAL:
                        case combined_document::STATUS_PENDING_INPUT:
                            // The document has not been converted yet or is somehow still ready.
                            $conversionrequirespolling = true;
                            continue 2;
                    }
                    document_services::get_page_images_for_attempt(
                            $assignment,
                            $userid,
                            $attemptnumber,
                            false
                        );
                    document_services::get_page_images_for_attempt(
                            $assignment,
                            $userid,
                            $attemptnumber,
                            true
                        );
                } catch (\moodle_exception $e) {
                    mtrace('Conversion failed with error:' . $e->errorcode);
                }
            }

            // Remove from queue.
            if (!$conversionrequirespolling) {
                $DB->delete_records('assignfeedback_editpdf_queue', array('id' => $record->id));
            }

        }
    }

}
