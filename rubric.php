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
 * Exports an Excel spreadsheet of the component grades in a rubric-graded assignment.
 *
 * @package    report_advancedgrading
 * @copyright  2021 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '../../../config.php');
require_once(__DIR__ . '/../../report/advancedgrading/locallib.php');
require_once(__DIR__ . '/../../lib/excellib.class.php');

require_once $CFG->dirroot . '/grade/lib.php';

$dload = optional_param("dload", '', PARAM_BOOL);

$courseid  = required_param('id', PARAM_INT); // Course ID.
$data['courseid'] = $courseid;
$data['modid'] = required_param('modid', PARAM_INT); // CM I

global $PAGE;

$PAGE->requires->js_call_amd('report_advancedgrading/table_sort', 'init');
$PAGE->set_url(new moodle_url('/report/advancedgrading/index.php', $data));

$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
require_login($course);

$modinfo = get_fast_modinfo($courseid);
$assign = $modinfo->get_cm($data['modid']);

$modcontext = context_module::instance($assign->id);
require_capability('mod/assign:grade', $modcontext);

$context = context_course::instance($course->id);

$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$renderer = $PAGE->get_renderer('core_user');

$PAGE->set_title('Rubric Report');
$PAGE->set_heading('Report Name');

// Profile fields.
$profileconfig = trim(get_config('report_advancedgrading', 'profilefields'));
$data['profilefields'] = empty($profileconfig) ? [] : explode(',', $profileconfig);

$gdef = get_grading_definition($assign->instance);

$cm = get_coursemodule_from_instance('assign', $assign->instance, $course->id);

$criteria = get_criteria('gradingform_rubric_criteria', (int) $gdef->definitionid);


$data = header_fields($data, $criteria, $course, $assign, $gdef);
$dbrecords = rubric_get_data($assign->id);
$data = user_fields($data, $dbrecords);
$data = add_groups($data, $courseid);
$data = get_grades($data, $dbrecords);

$data['definition'] = get_grading_definition($cm->instance);
$data['dodload'] = true;
$data['studentspan'] = count($data['profilefields']);

$form = $OUTPUT->render_from_template('report_advancedgrading/rubric/header_form', $data);
$table = $OUTPUT->render_from_template('report_advancedgrading/rubric/header', $data);

$rows = get_rows($data);

$table .= $rows;
$table .= '   </tbody> </table> </div>';
if ($dload) {
    download($table);
    echo $OUTPUT->header();
} else {
    $html = $form . $table;
    $PAGE->set_pagelayout('standard');
    echo $OUTPUT->header();
    echo $OUTPUT->container($html, 'advancedgrading-main');
}
echo $OUTPUT->footer();

function download($spreadsheet) {
    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Html();
    $spreadsheet = $reader->loadFromString($spreadsheet);

    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    hout('rubric');
    $writer->save('php://output');
    exit();
}

function get_rows(array $data): string {
    $row = '';
    $criterion = $data['criterion'];
    if ($data['students']) {
        foreach ($data['students'] as $student) {
            $row .= '<tr>';
            foreach ($data['profilefields'] as $field) {
                $row .= '<td>' . $student[$field] . '</td>';
            }
            foreach (array_keys($criterion) as $crikey) {
                $row .= '<td>' . number_format($student['grades'][$crikey]['score'], 2) . '</td>';
                $row .= '<td>' . $student['grades'][$crikey]['feedback'] . '</td>';
            }
            $row .= '<td>' . number_format($student['gradeinfo']['grade'], 2) . '</td>';
            $row .= '<td>' . $student['gradeinfo']['grader'] . '</td>';
            $row .= '<td>' . \userdate($student['gradeinfo']['timegraded'], "% %d %b %Y %I:%M %p") . '</td>';
            $row .= '</tr>';
        }
    }
    return $row;
}
function hout($filename) {

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '.xlsx"');
    return true;
    $filename = preg_replace('/\.xlsx?$/i', '', $filename);

    $mimetype = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    $filename = $filename . '.xlsx';

    if (is_https()) { // HTTPS sites - watch out for IE! KB812935 and KB316431.
        header('Cache-Control: max-age=10');
        header('Expires: ' . gmdate('D, d M Y H:i:s', 0) . ' GMT');
        header('Pragma: ');
    } else { // normal http - prevent caching at all cost
        header('Cache-Control: private, must-revalidate, pre-check=0, post-check=0, max-age=0');
        header('Expires: ' . gmdate('D, d M Y H:i:s', 0) . ' GMT');
        header('Pragma: no-cache');
    }

    if (core_useragent::is_ie() || core_useragent::is_edge()) {
        $filename = rawurlencode($filename);
    } else {
        $filename = s($filename);
    }

    header('Content-Type: ' . $mimetype);
    header('Content-Disposition: attachment;filename="' . $filename . '"');
}


function user_fields($data, $dbrecords) {
    foreach ($dbrecords as $grade) {
        $student['userid'] = $grade->userid;
        foreach ($data['profilefields'] as $key => $field) {
            if ($field == 'groups') {
                continue;
            }
            $student[$field] = $grade->$field;
        }
        $data['students'][$grade->userid] = $student;
        $data['criterion'][$grade->criterionid] = $grade->description;
    }
    return $data;
}

function get_grades($data, $dbrecords){
    foreach ($dbrecords as $grade) {
        $g[$grade->userid][$grade->criterionid] = [
            'userid' => $grade->userid,
            'score' => $grade->score,
            'feedback' => $grade->remark
        ];
        $gi = [
            'grader' => $grade->grader,
            'timegraded' => $grade->modified,
            'grade' => $grade->grade
        ];

        foreach ($data['students'] as $student) {
            if ($student['userid'] == $grade->userid) {
                $data['students'][$grade->userid]['grades'] = $g[$grade->userid];
                $data['students'][$grade->userid]['gradeinfo'] = $gi;
            }
        }
    }
    return $data;
}

function add_groups($data, $courseid) {
    $groups = report_advancedgrading_get_user_groups($courseid);

    foreach ($data['students'] as $userid => $student) {
        $data['students'][$userid]['groups'] = implode(" ", $groups[$userid]);
    }
    return $data;
}
