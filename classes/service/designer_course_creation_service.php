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
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

namespace block_dixeo_designer\service;

defined('MOODLE_INTERNAL') || die();

use block_dixeo_designer\local\dixeo_capability;
use block_dixeo_designer\workflow_constants;
use local_dixeo\service\image\job_orchestrator;
use local_dixeo\service\image\policy;
use local_dixeo\service\image\structure\structure_target;

/**
 * Creates and finalizes Moodle courses for the designer workflow (block-owned).
 *
 * Draft courses use idnumber prefix dixeo_draft_*. Uses local_dixeo for file sync and module generation API only.
 *
 * @package    block_dixeo_designer
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class designer_course_creation_service {

    /** @var string idnumber prefix for draft courses (cleanup matches this). */
    public const IDNUMBER_DRAFT_PREFIX = 'dixeo_draft_';

    /**
     * Create an empty draft course for structure generation.
     *
     * @param int $userid
     * @return \stdClass
     */
    public function create_draft_course(int $userid): \stdClass {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');

        $categoryid = $this->resolve_category_id();
        $idnumber = self::IDNUMBER_DRAFT_PREFIX . gmdate('Ymd_His');
        $shortname = 'draft-' . gmdate('Ymd-His');
        $defaultformat = get_config('moodlecourse', 'format') ?: 'topics';

        $candidate = $shortname;
        $suffix = 1;
        while ($DB->record_exists('course', ['shortname' => $candidate])) {
            $candidate = $shortname . '-' . $suffix++;
        }
        $shortname = $candidate;

        $fullname = $this->get_draft_course_name();

        $coursedata = (object) [
            'category' => $categoryid,
            'fullname' => $fullname,
            'shortname' => $shortname,
            'idnumber' => $idnumber,
            'summary' => '',
            'summaryformat' => FORMAT_HTML,
            'format' => $defaultformat,
            'lang' => '',
            'newsitems' => 0,
            'visible' => 1,
            'enablecompletion' => 1,
            'startdate' => time(),
            'numsections' => 1,
        ];

        $course = \create_course($coursedata);
        $this->enrol_user((int) $course->id, $userid);

        return $course;
    }

    /**
     * Delete a draft course by id (only if idnumber matches dixeo_draft_*).
     *
     * @param int $courseid
     * @return bool
     */
    public function delete_draft_course(int $courseid, bool $force = false): bool {
        global $CFG, $DB;

        $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
        if (!$course) {
            return false;
        }
        if (!$force && strpos($course->idnumber ?? '', self::IDNUMBER_DRAFT_PREFIX) !== 0) {
            return true;
        }
        if ((int) $course->id === SITEID) {
            return false;
        }

        require_once($CFG->dirroot . '/course/lib.php');
        return \delete_course($course, false);
    }

    /**
     * Finalize a draft course after structure is ready: rename, sections, materialize modules.
     *
     * @param int $courseid
     * @param array $result Structure API result (course_structure.title, course_structure.sections, etc.)
     * @param int $userid
     * @param string|null $jobid Optional job ID for progress reporting (module X of Y).
     * @return \stdClass|null Final course record, or null if the draft was removed (e.g. user cancelled) or missing.
     */
    public function finalize_draft_course(int $courseid, array $result, int $userid, ?string $jobid = null): ?\stdClass {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');

        if (!$DB->record_exists('course', ['id' => $courseid])) {
            return null;
        }

        dixeo_capability::require_generate_and_manage_activities($courseid);

        // API may return either:
        // - a wrapper: { course_structure: { title, sections, ... } }
        // - or the unwrapped course_structure itself (what the designer stores).
        $data = $result['course_structure'] ?? $result;
        $sections = $data['sections'] ?? [];
        $title = $data['title'] ?? get_string('blocktitle', 'block_dixeo_designer');
        $sectiontotal = count($sections);
        $moduletotal = 0;
        foreach (array_values($sections) as $sd) {
            $moduletotal += count($sd['modules'] ?? []);
        }
        $defaultformat = get_config('moodlecourse', 'format') ?: 'topics';

        if ($jobid !== null && $jobid !== '') {
            // UI expects generating content to start at 1/total (not 0/total).
            $this->set_finalize_progress($jobid, [
                'phase' => workflow_constants::FINALIZE_PHASE_GENERATING_CONTENT,
                'module_index' => $moduletotal > 0 ? 1 : 0,
                'module_total' => $moduletotal,
                'section_index' => 0,
                'section_total' => 0,
            ]);
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
        if (!$course) {
            return null;
        }
        $course->fullname = $title;
        $course->shortname = $this->generate_unique_shortname($title);
        // Finalized courses must not keep the draft marker, or scheduled cleanup can delete them.
        $course->idnumber = '';
        $course->summary = $data['summary'] ?? '';
        $course->summaryformat = FORMAT_HTML;
        $course->format = $defaultformat;
        $course->numsections = $sectiontotal;
        $DB->update_record('course', $course);

        foreach (array_values($sections) as $index => $sectiondata) {
            $sectionnumber = $index + 1;
            course_create_sections_if_missing($courseid, [$sectionnumber]);

            $section = $DB->get_record('course_sections', [
                'course' => $courseid,
                'section' => $sectionnumber,
            ], '*', IGNORE_MISSING);
            if (!$section) {
                return null;
            }
            $section->name = $sectiondata['title'] ?? '';
            $section->summary = $sectiondata['summary'] ?? '';
            $section->summaryformat = FORMAT_HTML;
            $DB->update_record('course_sections', $section);
        }

        $this->materialize_structure_modules($courseid, $sections, $jobid, $moduletotal);

        if ($this->is_finalize_cancelled($jobid)) {
            return null;
        }

        $this->queue_section_images_after_finalize($jobid, $courseid, $userid, $sectiontotal);

        if ($this->is_finalize_cancelled($jobid)) {
            return null;
        }

        $completionsync = new \local_dixeo\service\course_completion_sync_service();
        $completionsync->sync_activity_criteria_from_modules($courseid);

        if ($this->is_finalize_cancelled($jobid)) {
            return null;
        }

        $certtrailing = false;
        $placed = false;
        if ((bool) get_config('block_dixeo_designer', 'certificate_generation')) {
            $templateid = (int) get_config('block_dixeo_designer', 'certificate_template');
            $certlocation = (string) (get_config('block_dixeo_designer', 'certificate_location') ?: 'last');
            if (!in_array($certlocation, ['summary', 'last'], true)) {
                $certlocation = 'last';
            }
            if ($templateid > 0) {
                $certservice = new \local_dixeo\service\course_certificate_service();
                $placed = $certservice->try_add_coursecertificate_activity(
                    $courseid,
                    true,
                    $templateid,
                    $certlocation,
                    get_string('certificate_name', 'block_dixeo_designer'),
                    get_string('certificate_section', 'block_dixeo_designer'),
                    get_string('certificate_section_intro', 'block_dixeo_designer')
                );
                $certtrailing = ($placed === 'last');
            }
        }

        if ($this->is_finalize_cancelled($jobid)) {
            return null;
        }

        if ($placed === 'last') {
            $this->queue_section_image_jobs_for_section_numbers(
                $jobid,
                $courseid,
                $userid,
                [$sectiontotal + 1]
            );
        }

        $this->apply_lti_publication_if_enabled($courseid);

        if ($this->is_finalize_cancelled($jobid)) {
            return null;
        }

        $this->apply_self_enrol_if_enabled($courseid);

        if ($this->is_finalize_cancelled($jobid)) {
            return null;
        }

        $resourcestargetsection = $sectiontotal + 1 + ($certtrailing ? 1 : 0);
        $fileService = new submission\file_service();
        $resourcesrelocated = $fileService->relocate_designer_upload_resources_after_finalize(
            $courseid,
            $sectiontotal,
            $resourcestargetsection
        );

        if ($this->is_finalize_cancelled($jobid)) {
            return null;
        }

        if ($resourcesrelocated) {
            $this->queue_section_image_jobs_for_section_numbers(
                $jobid,
                $courseid,
                $userid,
                [$resourcestargetsection]
            );
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
        if (!$course) {
            return null;
        }
        if ($jobid !== null && $jobid !== '') {
            $this->set_finalize_progress($jobid, ['phase' => workflow_constants::FINALIZE_PHASE_FINALIZING]);
            $this->set_finalize_progress($jobid, [
                'phase' => workflow_constants::FINALIZE_PHASE_DONE,
                'courseid' => (int) $course->id,
                'coursename' => $course->fullname,
            ]);
        }

        $this->mark_course_as_recently_viewed((int) $course->id, $userid);

        return $course;
    }

    /**
     * Stores finalize phase for polling by `get_finalize_progress` while modules are materialized.
     *
     * @param string $jobid
     * @param array $data phase, module_index?, module_total?, section_index?, section_total?, courseid?, coursename?
     * @return void
     */
    private function set_finalize_progress(string $jobid, array $data): void {
        $cache = \cache::make('block_dixeo_designer', 'finalize_progress');
        $existing = $cache->get($jobid);
        if (is_array($existing) && !empty($existing['cancelled'])) {
            return;
        }
        $cache->set($jobid, $data);
    }

    /**
     * Merge data into finalize progress cache (preserves current_fill_jobid when updating module progress).
     *
     * @param string $jobid
     * @param array $data Keys to merge (phase, module_index, module_total, section_index?, section_total?, current_fill_jobid?, cancelled?)
     * @return void
     */
    private function merge_finalize_progress(string $jobid, array $data): void {
        $cache = \cache::make('block_dixeo_designer', 'finalize_progress');
        $existing = $cache->get($jobid);
        if (is_array($existing) && !empty($existing['cancelled'])) {
            return;
        }
        $merged = is_array($existing) ? array_merge($existing, $data) : $data;
        $cache->set($jobid, $merged);
    }

    /**
     * Whether the user requested cancel during finalize (so the materialize loop should exit).
     *
     * @param string|null $jobid
     * @return bool
     */
    private function is_finalize_cancelled(?string $jobid): bool {
        if ($jobid === null || $jobid === '') {
            return false;
        }
        $cache = \cache::make('block_dixeo_designer', 'finalize_progress');
        $data = $cache->get($jobid);
        return is_array($data) && !empty($data['cancelled']);
    }

    /**
     * True when Dixeo format section images are allowed to be queued after finalize (all of):
     * format_dixeo installed, site default new-course format dixeo, course format dixeo,
     * and local_dixeo policy allows section image generation.
     *
     * @param int $courseid
     * @return bool
     */
    private function section_images_after_finalize_allowed(int $courseid): bool {
        global $DB;

        if (!\local_dixeo\service\plugin_installation_service::is_component_installed('format_dixeo')) {
            return false;
        }

        $defaultformat = get_config('moodlecourse', 'format') ?: 'topics';
        if ($defaultformat !== 'dixeo') {
            return false;
        }

        $courseformat = $DB->get_field('course', 'format', ['id' => $courseid], IGNORE_MISSING);
        if ((string) $courseformat !== 'dixeo') {
            return false;
        }

        if (!policy::is_enabled(
            policy::ENTITY_SECTION,
            policy::ACTION_GENERATE
        )) {
            return false;
        }

        return true;
    }

    /**
     * Queue async section image jobs for designer content sections (1..N) after modules exist.
     *
     * @param string|null $jobid
     * @param int $courseid
     * @param int $userid
     * @param int $sectiontotal Number of structure sections (course section numbers 1..N).
     * @return void
     */
    private function queue_section_images_after_finalize(
        ?string $jobid,
        int $courseid,
        int $userid,
        int $sectiontotal
    ): void {
        if ($sectiontotal < 1) {
            return;
        }
        $this->queue_section_image_jobs_for_section_numbers(
            $jobid,
            $courseid,
            $userid,
            range(1, $sectiontotal)
        );
    }

    /**
     * Queue async section image jobs for the given Moodle section numbers (course_sections.section).
     *
     * Used for structure sections (1..N), trailing certificate section, and Resources section.
     * No-ops when {@see self::section_images_after_finalize_allowed()} is false or numbers are empty.
     *
     * @param string|null $jobid
     * @param int $courseid
     * @param int $userid
     * @param int[] $sectionnumbers Section indices (e.g. 1, 2, or trailing Resources index).
     * @return void
     */
    private function queue_section_image_jobs_for_section_numbers(
        ?string $jobid,
        int $courseid,
        int $userid,
        array $sectionnumbers
    ): void {
        global $DB;

        $sectionnumbers = array_values(array_unique(array_map('intval', $sectionnumbers)));
        sort($sectionnumbers, SORT_NUMERIC);
        if ($sectionnumbers === [] || !$this->section_images_after_finalize_allowed($courseid)) {
            return;
        }

        $imageservice = new \local_dixeo\service\image_generation_service(
            \local_dixeo\external\service_factory::get_job_service()
        );

        foreach ($sectionnumbers as $sectionnumber) {
            if ($sectionnumber < 1) {
                continue;
            }
            if ($this->is_finalize_cancelled($jobid)) {
                return;
            }

            $section = $DB->get_record('course_sections', [
                'course' => $courseid,
                'section' => $sectionnumber,
            ], 'id', IGNORE_MISSING);
            if (!$section) {
                continue;
            }

            try {
                $operation = $imageservice->submit_section_image_job((int) $section->id);
                $remotejobid = trim((string) ($operation->jobid ?? ''));
                if ($remotejobid === '') {
                    continue;
                }
                $target = structure_target::format_section($courseid, (int) $section->id);
                job_orchestrator::submit_and_queue($target, $remotejobid, $userid);
            } catch (\Throwable $e) {
                debugging(
                    'designer_course_creation_service: section image job failed course=' . $courseid .
                    ' sectionnum=' . $sectionnumber . ': ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }
    }

    /**
     * Add enrol_lti instance when block setting "LTI publication" is enabled.
     *
     * @param int $courseid
     * @return void
     */
    private function apply_lti_publication_if_enabled(int $courseid): void {
        if (!(bool) get_config('block_dixeo_designer', 'lti_publication_enabled')) {
            return;
        }

        $maxraw = get_config('block_dixeo_designer', 'lti_maxenrolled');
        $maxenrolled = ($maxraw !== false && $maxraw !== '') ? (int) $maxraw : 0;
        $membersyncraw = get_config('block_dixeo_designer', 'lti_membersync');
        $membersync = ($membersyncraw !== false && $membersyncraw !== '') ? (int) $membersyncraw : 0;
        $membersyncmoderaw = get_config('block_dixeo_designer', 'lti_membersyncmode');
        $membersyncmode = ($membersyncmoderaw !== false && $membersyncmoderaw !== '')
            ? (int) $membersyncmoderaw
            : \enrol_lti\helper::MEMBER_SYNC_ENROL_AND_UNENROL;

        $ltiservice = new \local_dixeo\service\designer_lti_enrol_service();
        $ltiservice->add_lti_enrol_instance($courseid, [
            'maxenrolled' => $maxenrolled,
            'membersync' => $membersync,
            'membersyncmode' => $membersyncmode,
        ]);
    }

    /**
     * Enable and configure enrol_self when block setting "Configure self enrolment" is enabled.
     *
     * @param int $courseid
     * @return void
     */
    private function apply_self_enrol_if_enabled(int $courseid): void {
        if (!(bool) get_config('block_dixeo_designer', 'self_enrol_configure')) {
            return;
        }

        $generatekey = (bool) get_config('block_dixeo_designer', 'self_enrol_generate_key');
        $service = new \local_dixeo\service\designer_self_enrol_service();
        $service->configure_for_course($courseid, $generatekey);
    }

    /**
     * Ensures initial file sync completes before module materialization (avoids empty module inputs).
     *
     * Caller must copy submission files into the course before calling this.
     *
     * @param int $courseid
     * @param int $userid
     * @return void
     */
    public function enable_draft_file_sync_and_wait(int $courseid, int $userid): void {
        dixeo_capability::require_generate_for_course($courseid);
        $filesync = \local_dixeo\external\service_factory::get_file_sync_service();
        $filesync->enable_sync($courseid, $userid);
        $filesync->trigger_sync($courseid);
        $this->wait_for_initial_file_sync($filesync, $courseid);
    }

    /**
     * Enables async file sync so the UI can poll progress during remote structure generation.
     *
     * Caller must copy submission files into the course before calling this.
     *
     * @param int $courseid
     * @param int $userid
     * @return void
     */
    public function enable_draft_file_sync(int $courseid, int $userid): void {
        dixeo_capability::require_generate_for_course($courseid);
        $filesync = \local_dixeo\external\service_factory::get_file_sync_service();
        $filesync->enable_sync($courseid, $userid);
        $filesync->trigger_sync($courseid);
    }

    /**
     * Delete draft courses older than the given seconds (by startdate).
     *
     * @param int $olderthanseconds
     * @return int Number of courses deleted.
     */
    public function cleanup_draft_courses_older_than(int $olderthanseconds): int {
        global $DB;

        $prefix = self::IDNUMBER_DRAFT_PREFIX;
        $olderthan = time() - $olderthanseconds;

        $courses = $DB->get_recordset_sql(
            "SELECT id, idnumber FROM {course}
             WHERE " . $DB->sql_like('idnumber', ':prefix', false, false) . "
             AND startdate < :olderthan",
            ['prefix' => $prefix . '%', 'olderthan' => $olderthan]
        );

        $deleted = 0;
        foreach ($courses as $course) {
            if ($this->delete_draft_course((int) $course->id)) {
                $deleted++;
            }
        }
        $courses->close();

        return $deleted;
    }

    private function get_draft_course_name(): string {
        return get_string('designer_draft_course_name', 'block_dixeo_designer');
    }

    private function resolve_category_id(): int {
        $categoryname = get_config('block_dixeo_designer', 'categoryname');
        if (empty($categoryname)) {
            $categoryname = get_string('default_categoryname', 'block_dixeo_designer');
        }

        global $DB;

        $existingid = $DB->get_field('course_categories', 'id', ['name' => $categoryname, 'parent' => 0]);
        if ($existingid) {
            return (int) $existingid;
        }

        $created = \core_course_category::create([
            'name' => $categoryname,
            'parent' => 0,
        ]);

        return (int) $created->id;
    }

    private function generate_unique_shortname(string $basename): string {
        global $DB;

        $shortname = trim(preg_replace('/\s+/', '-', \core_text::strtolower($basename)), '-');
        $shortname = clean_param($shortname, PARAM_ALPHANUMEXT);
        if ($shortname === '') {
            $shortname = 'dixeo-course';
        }

        $candidate = $shortname;
        $suffix = 1;
        while ($DB->record_exists('course', ['shortname' => $candidate])) {
            $candidate = $shortname . '-' . $suffix++;
        }

        return $candidate;
    }

    private function enrol_user(int $courseid, int $userid): void {
        global $CFG;

        $enrol = enrol_get_plugin('manual');
        if (!$enrol) {
            return;
        }

        foreach (enrol_get_instances($courseid, false) as $instance) {
            if ($instance->enrol !== 'manual') {
                continue;
            }

            $enrol->enrol_user($instance, $userid, $CFG->creatornewroleid);
        }
    }

    /**
     * Mark the course as recently viewed for the creator (My courses / last access).
     *
     * @param int $courseid
     * @param int $userid
     * @return void
     */
    private function mark_course_as_recently_viewed(int $courseid, int $userid): void {
        global $DB;

        $now = time();
        $existing = $DB->get_record('user_lastaccess', [
            'courseid' => $courseid,
            'userid' => $userid,
        ], 'id', IGNORE_MISSING);

        if ($existing) {
            $DB->set_field('user_lastaccess', 'timeaccess', $now, ['id' => $existing->id]);
            return;
        }

        $lastaccess = (object) [
            'courseid' => $courseid,
            'userid' => $userid,
            'timeaccess' => $now,
        ];
        $DB->insert_record('user_lastaccess', $lastaccess);
    }

    private function wait_for_initial_file_sync(\local_dixeo\service\file_sync_service $filesync, int $courseid): void {
        $status = $filesync->get_status($courseid);
        if ($status->filestotal === 0) {
            return;
        }

        $deadline = time() + 120;
        while (time() < $deadline) {
            $status = $filesync->poll_status($courseid);
            if ($status->status === 'synchronized' || $status->status === 'none') {
                return;
            }
            if ($status->status === 'error') {
                throw new \moodle_exception('designer_filesyncfailed', 'block_dixeo_designer', '', $status->errormessage);
            }
            sleep(2);
        }

        throw new \moodle_exception('designer_filesynctimeout', 'block_dixeo_designer');
    }

    /**
     * @param int $courseid
     * @param array $sections
     * @param string|null $jobid For progress reporting (module X of Y across all sections).
     * @param int $moduletotal Total modules to materialize.
     */
    private function materialize_structure_modules(int $courseid, array $sections, ?string $jobid, int $moduletotal): void {
        $moduleservice = \local_dixeo\external\service_factory::get_module_generation_service();
        $jobservice = \local_dixeo\external\service_factory::get_job_service();

        $moduleordinal = 0;
        foreach (array_values($sections) as $sectionindex => $sectiondata) {
            if ($this->is_finalize_cancelled($jobid)) {
                return;
            }
            $sectionnumber = $sectionindex + 1;
            foreach (($sectiondata['modules'] ?? []) as $module) {
                if ($this->is_finalize_cancelled($jobid)) {
                    return;
                }
                $moduleordinal++;
                if ($jobid !== null && $jobid !== '' && $moduletotal > 0) {
                    $this->merge_finalize_progress($jobid, [
                        'phase' => workflow_constants::FINALIZE_PHASE_GENERATING_CONTENT,
                        'module_index' => $moduleordinal,
                        'module_total' => $moduletotal,
                    ]);
                }
                $modulename = $module['type'] ?? 'page';
                $title = trim((string) ($module['title'] ?? ''));
                $summary = trim((string) ($module['summary'] ?? ''));
                $instructions = $this->build_module_instructions($module);

                $this->fill_single_module_from_structure(
                    $jobid,
                    $moduleservice,
                    $jobservice,
                    $modulename,
                    $instructions,
                    $courseid,
                    $sectionnumber,
                    $title,
                    $summary
                );
            }
        }
    }

    /**
     * Whether the modulegen block is installed, not deleted, enabled, and loadable (optional queue logging).
     */
    private function is_modulegen_queue_available(): bool {
        if (!\local_dixeo\service\plugin_installation_service::is_component_installed('block_dixeo_modulegen')) {
            return false;
        }
        $pm = \core_plugin_manager::instance();
        $info = $pm->get_plugin_info('block_dixeo_modulegen');
        if ($info === null || empty($info->rootdir)) {
            return false;
        }
        $status = $info->get_status();
        if ($status === \core_plugin_manager::PLUGIN_STATUS_MISSING
                || $status === \core_plugin_manager::PLUGIN_STATUS_DELETE) {
            return false;
        }
        if ($info->is_enabled() === false) {
            return false;
        }
        return class_exists(\block_dixeo_modulegen\queue_service::class);
    }

    /**
     * Log fill outcome to modulegen queue when that plugin is available (no hard dependency).
     *
     * @param array{success: bool, cmid: int, error: string, fill_jobid: string, cancelled: bool} $out
     */
    private function maybe_log_fill_to_modulegen_queue(
        int $courseid,
        string $modulename,
        string $instructions,
        int $sectionnumber,
        ?int $beforemod,
        string $structuretitle,
        string $summary,
        array $out
    ): void {
        if (!$this->is_modulegen_queue_available()) {
            return;
        }
        if (!empty($out['success']) && !empty($out['cmid'])) {
            \block_dixeo_modulegen\queue_service::log_fill_completed(
                $courseid,
                $modulename,
                $instructions,
                $sectionnumber,
                $beforemod,
                (int) $out['cmid'],
                $structuretitle,
                $summary,
                (string) ($out['fill_jobid'] ?? '')
            );
        } else if (!empty($out['error'])) {
            \block_dixeo_modulegen\queue_service::log_fill_failed(
                $courseid,
                $modulename,
                $instructions,
                $sectionnumber,
                $beforemod,
                $structuretitle,
                $summary,
                (string) ($out['fill_jobid'] ?? ''),
                (string) $out['error']
            );
        }
    }

    /**
     * Run fill_module → wait → create module; used by finalize only.
     *
     * @return array{success: bool, cmid: int, error: string, fill_jobid: string, cancelled: bool}
     */
    private function run_structure_fill_attempt(
        ?string $jobid,
        \local_dixeo\service\module_generation_service $moduleservice,
        \local_dixeo\service\job_service $jobservice,
        string $modulename,
        string $instructions,
        int $courseid,
        int $sectionnumber,
        string $title,
        string $summary
    ): array {
        $filljobid = '';

        try {
            $operation = $moduleservice->submit_fill_job_for_course(
                $modulename,
                $instructions,
                $courseid,
                $sectionnumber,
                $title,
                $summary
            );
            $filljobid = (string) ($operation->jobid ?? '');

            if ($jobid !== null && $jobid !== '') {
                $activejobids = [];
                $cache = \cache::make('block_dixeo_designer', 'finalize_progress');
                $existing = $cache->get($jobid);
                if (is_array($existing) && !empty($existing['active_jobids']) && is_array($existing['active_jobids'])) {
                    $activejobids = $existing['active_jobids'];
                }
                if ($filljobid !== '') {
                    $activejobids[] = $filljobid;
                }
                $activejobids = array_values(array_unique($activejobids));
                $this->merge_finalize_progress($jobid, [
                    'current_fill_jobid' => $filljobid,
                    'active_jobids' => $activejobids,
                ]);
            }

            if ($this->is_finalize_cancelled($jobid)) {
                return [
                    'success' => false,
                    'cmid' => 0,
                    'error' => '',
                    'fill_jobid' => $filljobid,
                    'cancelled' => true,
                ];
            }

            $waitResult = $jobservice->wait_for_job($operation->jobid, 'fill_module');
            if ($this->is_finalize_cancelled($jobid)) {
                return [
                    'success' => false,
                    'cmid' => 0,
                    'error' => '',
                    'fill_jobid' => $filljobid,
                    'cancelled' => true,
                ];
            }
            if (!$waitResult->is_completed()) {
                $msg = 'Dixeo designer module fill did not complete in time. ' .
                    'module=' . $modulename .
                    ', section=' . $sectionnumber .
                    ', title=' . ($title !== '' ? $title : '(empty title)') .
                    ', jobid=' . $filljobid;
                debugging($msg, DEBUG_DEVELOPER);
                return [
                    'success' => false,
                    'cmid' => 0,
                    'error' => $msg,
                    'fill_jobid' => $filljobid,
                    'cancelled' => false,
                ];
            }

            $result = \local_dixeo\external\create_module_from_job::execute(
                $operation->jobid,
                $courseid,
                $sectionnumber,
                null,
                $title,
                format_text($summary, FORMAT_PLAIN)
            );

            if (empty($result['success'])) {
                $errmsg = !empty($result['errormessage'])
                    ? $result['errormessage']
                    : get_string('designer_unknown_error', 'block_dixeo_designer');
                $msg = 'Dixeo designer module fill returned unsuccessful result. ' .
                    'module=' . $modulename .
                    ', section=' . $sectionnumber .
                    ', title=' . ($title !== '' ? $title : '(empty title)') .
                    ', error=' . $errmsg .
                    ', jobid=' . $filljobid;
                debugging($msg, DEBUG_DEVELOPER);
                return [
                    'success' => false,
                    'cmid' => 0,
                    'error' => (string) $errmsg,
                    'fill_jobid' => $filljobid,
                    'cancelled' => false,
                ];
            }

            return [
                'success' => true,
                'cmid' => (int) ($result['cmid'] ?? 0),
                'error' => '',
                'fill_jobid' => $filljobid,
                'cancelled' => false,
            ];
        } catch (\Throwable $e) {
            $msg = 'Dixeo designer module fill failed (skipping). ' .
                'module=' . $modulename .
                ', section=' . $sectionnumber .
                ', title=' . ($title !== '' ? $title : '(empty title)') .
                ', error=' . $e->getMessage();
            debugging($msg, DEBUG_DEVELOPER);
            return [
                'success' => false,
                'cmid' => 0,
                'error' => $e->getMessage(),
                'fill_jobid' => $filljobid,
                'cancelled' => false,
            ];
        }
    }

    /**
     * Fill a single module from the course structure.
     *
     * Failure is non-fatal. When block_dixeo_modulegen is present and enabled, logs completed/failed rows.
     *
     * @param string|null $jobid Designer job id (for progress/cancel tracking).
     */
    private function fill_single_module_from_structure(
        ?string $jobid,
        \local_dixeo\service\module_generation_service $moduleservice,
        \local_dixeo\service\job_service $jobservice,
        string $modulename,
        string $instructions,
        int $courseid,
        int $sectionnumber,
        string $title,
        string $summary
    ): void {
        $out = $this->run_structure_fill_attempt(
            $jobid,
            $moduleservice,
            $jobservice,
            $modulename,
            $instructions,
            $courseid,
            $sectionnumber,
            $title,
            $summary
        );
        if (!empty($out['cancelled'])) {
            return;
        }
        $this->maybe_log_fill_to_modulegen_queue(
            $courseid,
            $modulename,
            $instructions,
            $sectionnumber,
            null,
            $title,
            $summary,
            $out
        );
    }

    /**
     * AI fill instructions payload (module summary is passed separately to the fill API).
     *
     * @param array $module Structure module row.
     */
    private function build_module_instructions(array $module): string {
        return trim((string) ($module['instructions'] ?? ''));
    }

    /**
     * After finalize, restore draft-like course metadata so the course can be reused for a new fill.
     *
     * Mirrors {@see create_draft_course()} naming/summary defaults; assigns a new draft idnumber and unique shortname.
     *
     * @param int $courseid
     * @return bool False if the course row is missing.
     */
    public function restore_draft_course_metadata_after_cancel(int $courseid): bool {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');

        $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
        if (!$course) {
            return false;
        }

        $categoryid = $this->resolve_category_id();
        $idnumber = self::IDNUMBER_DRAFT_PREFIX . gmdate('Ymd_His');
        $basename = 'draft-' . gmdate('Ymd-His');
        $candidate = $basename;
        $suffix = 1;
        while ($DB->record_exists('course', ['shortname' => $candidate])) {
            $existingid = $DB->get_field('course', 'id', ['shortname' => $candidate]);
            if ($existingid && (int) $existingid === $courseid) {
                break;
            }
            $candidate = $basename . '-' . $suffix++;
        }

        $defaultformat = get_config('moodlecourse', 'format') ?: 'topics';

        $course->fullname = $this->get_draft_course_name();
        $course->shortname = $candidate;
        $course->idnumber = $idnumber;
        $course->summary = '';
        $course->summaryformat = FORMAT_HTML;
        $course->format = $defaultformat;
        $course->numsections = 1;
        $course->category = $categoryid;
        $course->enablecompletion = 1;

        update_course($course);
        rebuild_course_cache($courseid, true);

        return true;
    }

    /**
     * Remove activity modules created during finalize; keep submission file resources (tagged idnumber).
     *
     * @param int $courseid
     */
    public function delete_generated_content_modules_preserving_uploads(int $courseid): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');

        $uploadtag = submission\file_service::CM_IDNUMBER_DESIGNER_UPLOAD;
        $cms = $DB->get_records('course_modules', ['course' => $courseid]);
        foreach ($cms as $cm) {
            if (($cm->idnumber ?? '') === $uploadtag) {
                continue;
            }
            try {
                course_delete_module((int) $cm->id, false);
            } catch (\Throwable $e) {
                debugging(
                    'delete_generated_content_modules_preserving_uploads: failed cm ' . $cm->id . ': ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }

        rebuild_course_cache($courseid, true);
    }
}
