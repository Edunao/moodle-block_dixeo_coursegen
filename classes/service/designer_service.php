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

use block_dixeo_designer\cancellation\cancellation_context;
use block_dixeo_designer\cancellation\cancellation_policy_resolver;
use block_dixeo_designer\local\dixeo_capability;
use block_dixeo_designer\service\cache\prepare_progress_cache;
use block_dixeo_designer\service\remote\dixeo_remote_adapter;
use local_dixeo\service\course_image_writer;
use local_dixeo\service\image_poll_manager;
use local_dixeo\service\pluginfile_image_helper;
use block_dixeo_designer\service\structure\repository as structure_repository;
use block_dixeo_designer\service\submission\file_service as submission_file_service;
use block_dixeo_designer\service\submission\service as submission_service;
use block_dixeo_designer\workflow_constants;
use local_dixeo\service\image_generation_policy;
use local_dixeo\service\image_generation_service;

/**
 * Designer workflow: start generation, poll status, finalize or cancel.
 *
 * Uses block repositories/services and local_dixeo API only (no persistence interface).
 *
 * @package    block_dixeo_designer
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class designer_service {

    /** @var submission_service */
    private submission_service $submissions;

    /** @var submission_file_service */
    private submission_file_service $files;

    /** @var structure_repository */
    private structure_repository $structures;

    /** @var designer_course_creation_service */
    private designer_course_creation_service $coursecreation;

    /** @var dixeo_remote_adapter */
    private dixeo_remote_adapter $remoteapi;

    /** @var \local_dixeo\service\job_service|null */
    private $jobservice;

    /** @var \local_dixeo\service\file_sync_service|null */
    private $filesyncservice;

    /** @var image_generation_service|null */
    private ?image_generation_service $imageservice = null;

    /**
     * Constructor with optional dependencies for unit tests and workflow orchestration.
     *
     * @param submission_service|null $submissions
     * @param submission_file_service|null $files
     * @param structure_repository|null $structures
     * @param designer_course_creation_service|null $coursecreation
     * @param dixeo_remote_adapter|null $remoteapi Remote structure-generation API adapter.
     * @param \local_dixeo\service\job_service|null $jobservice
     * @param \local_dixeo\service\file_sync_service|null $filesyncservice
     */
    public function __construct(
        ?submission_service $submissions = null,
        ?submission_file_service $files = null,
        ?structure_repository $structures = null,
        ?designer_course_creation_service $coursecreation = null,
        ?dixeo_remote_adapter $remoteapi = null,
        ?\local_dixeo\service\job_service $jobservice = null,
        ?\local_dixeo\service\file_sync_service $filesyncservice = null
    ) {
        $this->submissions = $submissions ?? new submission_service();
        $this->files = $files ?? new submission_file_service();
        $this->structures = $structures ?? new structure_repository();
        $this->coursecreation = $coursecreation ?? new designer_course_creation_service();
        $this->remoteapi = $remoteapi ?? new dixeo_remote_adapter();
        $this->jobservice = $jobservice;
        $this->filesyncservice = $filesyncservice;
    }

    /**
     * Start async generation: create draft course, sync files, submit structure job.
     *
     * @param string $jobid
     * @param int $userid
     * @param string $description
     * @param string|null $templateid
     * @return object { courseid: int, remotejobid: string }
     */
    public function start_generation(string $jobid, int $userid, string $description, ?string $templateid): object {
        // New generation attempt must not inherit stale finalize cancellation state.
        $finalizecache = \cache::make('block_dixeo_designer', 'finalize_progress');
        $finalizecache->delete($jobid);

        $submission = $this->submissions->save_submission($jobid, $userid, $description, $templateid);
        $this->submissions->mark_status($submission, workflow_constants::SUBMISSION_STATUS_GENERATING_STRUCTURE);

        $course = $this->coursecreation->create_draft_course($userid);
        $this->submissions->set_draft_and_remote_job($submission, (int) $course->id, null);

        try {
            $this->files->copy_files_to_course_resources((int) $submission->id, (int) $course->id, $userid);
            $this->coursecreation->enable_draft_file_sync_and_wait((int) $course->id, $userid);
            $this->sync_submission_files_to_remote((int) $submission->id, $jobid, (int) $course->id);

            $instructions = trim($description);
            if ($instructions === '') {
                $instructions = get_string('designer_default_file_prompt', 'block_dixeo_designer');
            }

            $op = $this->remoteapi->submit_course_structure_generation(
                $instructions,
                $templateid,
                (int) $course->id
            );
            $this->submissions->set_draft_and_remote_job($submission, (int) $course->id, $op->jobid);

            return (object) [
                'courseid' => (int) $course->id,
                'remotejobid' => $op->jobid,
            ];
        } catch (\Throwable $e) {
            $this->coursecreation->delete_draft_course((int) $course->id);
            $this->submissions->clear_course($submission);
            throw $e;
        }
    }

    /**
     * Prepare generation: create draft course, copy submission files into it, and trigger async file sync.
     * Remote structure generation is submitted only after file sync has started so the client can poll progress.
     *
     * @param string $jobid
     * @param int $userid
     * @param string $description
     * @param string|null $templateid
     * @return object { courseid: int, noop?: bool }
     */
    public function prepare_generation(string $jobid, int $userid, string $description, ?string $templateid): object {
        $this->cancel_existing_jobs_for_regeneration($jobid);

        $existing = $this->submissions->get_submission($jobid);
        $trimmeddescription = trim($description);
        $existinghasfiles = $existing
            && (int) $existing->userid === (int) $userid
            && !empty($existing->id)
            && count($this->files->get_files((int) $existing->id)) > 0;
        if ($trimmeddescription === '' && !$existinghasfiles) {
            throw new \moodle_exception('invalidinput', 'block_dixeo_designer');
        }

        $existingCourseId = ($existing && (int) $existing->userid === (int) $userid && !empty($existing->courseid))
            ? (int) $existing->courseid
            : null;

        $oldPrompt = $existing->prompt ?? null;
        $oldTemplateId = $existing->templateid ?? null;

        $submission = $this->submissions->save_submission($jobid, $userid, $trimmeddescription, $templateid);
        $this->submissions->begin_sync_phase($submission);

        $newPrompt = $submission->prompt ?? null;
        $newTemplateId = $submission->templateid ?? null;

        // Compute a deterministic manifest hash of the submission files.
        // Used to decide if we can reuse the existing draft course/vector store
        // without forcing a re-copy + re-sync.
        $submissionFiles = $this->files->get_files((int) $submission->id);
        $submissionFilesHash = $this->compute_file_manifest_hash($submissionFiles);

        prepare_progress_cache::purge($jobid);

        if ($existingCourseId !== null) {
            global $DB;
            if (!$DB->record_exists('course', ['id' => $existingCourseId])) {
                // Corrupted/missing draft course reference: force clean draft recreation.
                $existingCourseId = null;
            }
        }

        if ($existingCourseId !== null) {
            $courseAiRepo = new \local_dixeo\repository\course_ai_repository();
            $courseAi = $courseAiRepo->get_by_courseid($existingCourseId);

            $storedFileHash = $courseAi->filehash ?? null;
            $fileManifestUnchanged = $storedFileHash !== null && hash_equals((string) $storedFileHash, $submissionFilesHash);

            $syncStatus = $courseAi->syncstatus ?? null;
            $fileSyncReady = in_array((string) $syncStatus, ['synchronized', 'none'], true);

            // Reuse the existing draft course when the vector-store input is unchanged.
            if ($fileManifestUnchanged && $fileSyncReady) {
                $this->submissions->set_draft_course_pending_sync($submission, $existingCourseId);

                $promptTemplateUnchanged = ($oldPrompt === $newPrompt) && ($oldTemplateId === $newTemplateId);
                $structureExists = $this->structures->get_latest_structure($jobid) !== null;

                // Fast-path: identical prompt/template/files + structure already saved => no remote calls.
                if ($promptTemplateUnchanged && $structureExists) {
                    $this->submissions->mark_status($submission, workflow_constants::SUBMISSION_STATUS_NOOP_GENERATION);
                    return (object) [
                        'courseid' => (int) $existingCourseId,
                        'noop' => true,
                    ];
                }

                return (object) [
                    'courseid' => (int) $existingCourseId,
                    'noop' => false,
                ];
            }
        }

        // Fallback to the current behavior (new draft course) when we cannot safely reuse.
        prepare_progress_cache::begin($jobid, !empty($submissionFiles), count($submissionFiles));

        $course = $this->coursecreation->create_draft_course($userid);
        $this->submissions->set_draft_course_pending_sync($submission, (int) $course->id);

        try {
            // Allow concurrent get_filesync_status polls while files are copied and trigger_sync runs.
            \core\session\manager::write_close();

            $this->files->copy_files_to_course_resources((int) $submission->id, (int) $course->id, $userid,
                function (int $copied, int $total) use ($jobid): void {
                    prepare_progress_cache::set_copied($jobid, $copied);
                }
            );
            $this->coursecreation->enable_draft_file_sync((int) $course->id, $userid);
            prepare_progress_cache::purge($jobid);

            return (object) [
                'courseid' => (int) $course->id,
                'noop' => false,
            ];
        } catch (\Throwable $e) {
            prepare_progress_cache::purge($jobid);
            $this->coursecreation->delete_draft_course((int) $course->id);
            $this->submissions->clear_course($submission);
            throw $e;
        }
    }

    /**
     * Poll file sync status for the draft course associated with this job.
     *
     * @param string $jobid
     * @param int $userid
     * @return object { status, progresspercent, filestotal, filescompleted, errormessage, lastsynccompleted,
     *                  hassubmissionfiles, moodleprepareactive, moodlepreparepercent }
     */
    public function get_filesync_status(string $jobid, int $userid): object {
        $submission = $this->submissions->get_submission($jobid);
        if (!$submission || (int) $submission->userid !== $userid) {
            return $this->filesync_status_empty(false);
        }

        $hasfiles = $this->submission_has_source_files((int) $submission->id);
        $prep = prepare_progress_cache::get($jobid);

        if (($submission->status ?? '') !== workflow_constants::SUBMISSION_STATUS_SYNCING_FILES) {
            return $this->filesync_status_preparing($hasfiles, $prep);
        }

        if ($prep && !empty($prep['active'])) {
            return $this->filesync_status_preparing($hasfiles, $prep);
        }

        if (empty($submission->courseid)) {
            return $this->filesync_status_preparing($hasfiles, $prep);
        }

        $remote = $this->remoteapi->get_file_sync_progress((int) $submission->courseid);
        return $this->filesync_status_merge_prepare_fields($hasfiles, $prep, $remote);
    }

    /**
     * @param int $submissionid
     * @return bool
     */
    private function submission_has_source_files(int $submissionid): bool {
        return count($this->files->get_files($submissionid)) > 0;
    }

    /**
     * @param bool $hasfiles
     * @return object
     */
    private function filesync_status_empty(bool $hasfiles): object {
        return (object) [
            'status' => 'preparing',
            'progresspercent' => null,
            'filestotal' => null,
            'filescompleted' => null,
            'uploadbytes' => null,
            'uploadbytestotal' => null,
            'errormessage' => null,
            'lastsynccompleted' => null,
            'hassubmissionfiles' => $hasfiles,
            'moodleprepareactive' => false,
            'moodlepreparepercent' => null,
        ];
    }

    /**
     * @param bool $hasfiles
     * @param array|null $prep
     * @return object
     */
    private function filesync_status_preparing(bool $hasfiles, ?array $prep): object {
        [$moodleactive, $moodlepct] = $this->filesync_moodle_prepare_state($hasfiles, $prep);
        return (object) [
            'status' => 'preparing',
            'progresspercent' => null,
            'filestotal' => null,
            'filescompleted' => null,
            'uploadbytes' => null,
            'uploadbytestotal' => null,
            'errormessage' => null,
            'lastsynccompleted' => null,
            'hassubmissionfiles' => $hasfiles,
            'moodleprepareactive' => $moodleactive,
            'moodlepreparepercent' => $moodlepct,
        ];
    }

    /**
     * @param bool $hasfiles
     * @param array|null $prep
     * @param object $remote
     * @return object
     */
    private function filesync_status_merge_prepare_fields(bool $hasfiles, ?array $prep, object $remote): object {
        [$moodleactive, $moodlepct] = $this->filesync_moodle_prepare_state($hasfiles, $prep);
        $remote->hassubmissionfiles = $hasfiles;
        $remote->moodleprepareactive = $moodleactive;
        $remote->moodlepreparepercent = $moodlepct;
        return $remote;
    }

    /**
     * Moodle copy phase: active while cache says files remain to be copied into the draft course.
     *
     * @param bool $hasfiles
     * @param array|null $prep
     * @return array{0:bool,1:float|null} [active, percent 0–100 or null]
     */
    private function filesync_moodle_prepare_state(bool $hasfiles, ?array $prep): array {
        if (!$hasfiles || !$prep || empty($prep['active'])) {
            return [false, null];
        }
        $total = (int) ($prep['moodle_total'] ?? 0);
        $copied = (int) ($prep['moodle_copied'] ?? 0);
        if ($total <= 0 || $copied >= $total) {
            return [false, null];
        }
        return [true, 100.0 * $copied / $total];
    }

    /**
     * Submit the remote structure generation job after file sync has started/completed.
     *
     * @param string $jobid
     * @param int $userid
     * @return object { remotejobid: string, courseid: int }
     */
    public function submit_structure_generation(string $jobid, int $userid): object {
        $submission = $this->submissions->get_submission($jobid);
        if (!$submission || (int) $submission->userid !== $userid || empty($submission->courseid)) {
            throw new \moodle_exception('invalidinput', 'block_dixeo_designer');
        }

        // No-op regenerate fast-path:
        // If prepare_generation detected identical submission inputs, skip any remote
        // structure job submission and let get_structure_status return the saved structure.
        if (!empty($submission->status) && $submission->status === workflow_constants::SUBMISSION_STATUS_NOOP_GENERATION) {
            $this->submissions->mark_status($submission, workflow_constants::SUBMISSION_STATUS_NOOP_COMPLETED);
            return (object) [
                'remotejobid' => '',
                'courseid' => (int) $submission->courseid,
            ];
        }

        if (($submission->status ?? '') !== workflow_constants::SUBMISSION_STATUS_SYNCING_FILES) {
            throw new \moodle_exception('invalidinput', 'block_dixeo_designer');
        }

        $instructions = trim((string) ($submission->prompt ?? ''));
        if ($instructions === '') {
            $instructions = get_string('designer_default_file_prompt', 'block_dixeo_designer');
        }
        if (\core_text::strlen($instructions) < workflow_constants::MIN_INSTRUCTIONS_LEN) {
            // The remote API requires instructions >= 20 characters.
            // If the user-provided prompt/description is too short, fall back to
            // the default file-based prompt to avoid remote validation errors.
            $defaultprompt = get_string('designer_default_file_prompt', 'block_dixeo_designer');
            $instructions = trim($instructions . ' ' . $defaultprompt);
        }

        $op = $this->remoteapi->submit_course_structure_generation(
            $instructions,
            $submission->templateid ?? null,
            (int) $submission->courseid
        );

        $this->submissions->set_draft_and_remote_job($submission, (int) $submission->courseid, $op->jobid);
        $this->submissions->mark_status($submission, workflow_constants::SUBMISSION_STATUS_GENERATING_STRUCTURE);

        return (object) [
            'remotejobid' => $op->jobid,
            'courseid' => (int) $submission->courseid,
        ];
    }

    /**
     * Get the status of the remote structure generation job.
     *
     * @param string $jobid
     * @param int $userid
     * @return object { status, progress, completed, failed, result, error }
     */
    public function get_structure_status(string $jobid, int $userid): object {
        $submission = $this->submissions->get_submission($jobid);
        if ($submission && (int) $submission->userid === $userid && ($submission->status ?? '') === workflow_constants::SUBMISSION_STATUS_NOOP_COMPLETED) {
            $structureJson = $this->structures->get_latest_structure($jobid);
            if ($structureJson !== null) {
                $decoded = json_decode($structureJson, true);
                $result = is_array($decoded) ? $decoded : null;

                return (object) [
                    'status' => 'completed',
                    'progress' => 100,
                    'completed' => true,
                    'failed' => false,
                    'result' => $result,
                    'error' => null,
                ];
            }
        }

        if (!$submission || (int) $submission->userid !== $userid || empty($submission->remotejobid)) {
            return (object) [
                'status' => 'unknown',
                'progress' => 0,
                'completed' => false,
                'failed' => false,
                'result' => null,
                'error' => null,
            ];
        }

        if (!empty($submission->courseid)) {
            dixeo_capability::require_generate_for_course((int) $submission->courseid);
        }

        $jobstatus = $this->remoteapi->get_job_status($submission->remotejobid);
        $result = $jobstatus->result;
        if (is_string($result)) {
            $decoded = json_decode($result, true);
            $result = is_array($decoded) ? $decoded : null;
        }

        return (object) [
            'status' => $jobstatus->status,
            'progress' => $jobstatus->progress,
            'completed' => $jobstatus->is_completed(),
            'failed' => $jobstatus->is_failed(),
            'result' => $jobstatus->is_completed() ? $result : null,
            'error' => $jobstatus->errormessage,
        ];
    }

    /**
     * Finalize the draft course after structure is ready.
     *
     * @param string $jobid
     * @param int $userid
     * @param bool $createcourse
     * @return \stdClass|null
     */
    public function finalize_course(string $jobid, int $userid, bool $createcourse, string $finalizemode = ''): ?\stdClass {
        $submission = $this->submissions->get_submission($jobid);
        if (!$submission || (int) $submission->userid !== $userid) {
            return null;
        }

        if ($createcourse && $finalizemode !== '') {
            $cache = \cache::make('block_dixeo_designer', 'finalize_progress');
            $existing = $cache->get($jobid);
            $merged = is_array($existing) ? array_merge($existing, [
                'generation_mode' => $finalizemode,
                'cancelled' => false,
                'phase' => '',
                'section_index' => 0,
                'section_total' => 0,
                'module_index' => 0,
                'module_total' => 0,
                'courseid' => 0,
                'coursename' => '',
                'current_fill_jobid' => '',
                'active_jobids' => [],
            ]) : [
                'generation_mode' => $finalizemode,
                'cancelled' => false,
                'phase' => '',
                'section_index' => 0,
                'section_total' => 0,
                'module_index' => 0,
                'module_total' => 0,
                'courseid' => 0,
                'coursename' => '',
                'current_fill_jobid' => '',
                'active_jobids' => [],
            ];
            $cache->set($jobid, $merged);
        }

        $structureJson = $this->structures->get_latest_structure($jobid);
        if ($structureJson !== null) {
            $result = json_decode($structureJson, true);
            $result = is_array($result) ? $result : [];
        } else {
            $cache = \cache::make('block_dixeo_designer', 'finalize_progress');
            $progress = $cache->get($jobid);
            if (is_array($progress) && !empty($progress['cancelled'])) {
                return null;
            }
            if (!empty($submission->courseid)) {
                dixeo_capability::require_generate_for_course((int) $submission->courseid);
            }
            $jobstatus = $this->remoteapi->get_job_status($submission->remotejobid);
            if (!$jobstatus->is_completed() || empty($jobstatus->result)) {
                return null;
            }
            $result = $jobstatus->result;
            if (is_string($result)) {
                $decoded = json_decode($result, true);
                $result = is_array($decoded) ? $decoded : [];
            }
            $this->structures->save_structure($jobid, $userid, $submission->prompt ?? '', $result);
        }

        if ($createcourse) {
            $structurevalidator = new \local_dixeo\service\designer_structure_finalize_validation_service();
            $structurevalidationerrors = $structurevalidator->validate($result);
            if ($structurevalidationerrors !== []) {
                throw new \moodle_exception(
                    'designerstructurevalidate_failed',
                    'local_dixeo',
                    '',
                    (object) ['details' => implode("\n\n", $structurevalidationerrors)]
                );
            }
        }

        if (!$createcourse) {
            return null;
        }

        // Re-fetch: user may have cancelled; submission state may have changed concurrently.
        global $DB;
        $submission = $this->submissions->get_submission($jobid);
        if (!$submission || (int) $submission->userid !== $userid) {
            return null;
        }

        $draftcourseid = !empty($submission->courseid) ? (int) $submission->courseid : 0;
        $draftcourseexists = $draftcourseid > 0 && $DB->record_exists('course', ['id' => $draftcourseid]);

        // Self-heal: when structure exists but draft is missing/corrupted, recreate draft and continue.
        if (!$draftcourseexists) {
            $draftcourse = $this->coursecreation->create_draft_course($userid);
            $draftcourseid = (int) $draftcourse->id;
            $this->submissions->set_draft_and_remote_job($submission, $draftcourseid, $submission->remotejobid ?? null);

            // Self-heal preflight must recreate the same prerequisites as generation:
            // copy uploaded files into course resources, wait for sync readiness,
            // then sync files to remote vector store before module filling.
            $this->files->copy_files_to_course_resources((int) $submission->id, $draftcourseid, $userid);
            $this->coursecreation->enable_draft_file_sync_and_wait($draftcourseid, $userid);
            $this->sync_submission_files_to_remote((int) $submission->id, $jobid, $draftcourseid);
        }

        $this->queue_finalize_course_image_tracking($jobid, $userid, $draftcourseid, $finalizemode, $result);

        $course = $this->coursecreation->finalize_draft_course(
            $draftcourseid,
            $result,
            $userid,
            $jobid
        );

        // Defensive guard: if course finalization did not produce a course,
        // do not attach/delete the submission.
        if (!$course || empty($course->id)) {
            return null;
        }

        // Defensive safeguard: finalized courses must never keep the draft idnumber marker.
        if (strpos((string) ($course->idnumber ?? ''), designer_course_creation_service::IDNUMBER_DRAFT_PREFIX) === 0) {
            $DB->set_field('course', 'idnumber', '', ['id' => (int) $course->id]);
            $course->idnumber = '';
        }

        $this->submissions->attach_course($submission, (int) $course->id);

        // After a successful generation, delete the submission so revisiting
        // the designer with the same id results in a clean designer.
        $this->submissions->delete_submission($jobid, $userid);
        $this->structures->delete_by_jobid($jobid);

        return $course;
    }

    /**
     * Cancel the current draft workflow and clean submission state.
     *
     * Policy is resolved via {@see cancellation_policy_resolver} from context (saved structure,
     * footer/hard delete, quick mode). Execution order: cancel remote jobs, course cleanup
     * (delete course, or strip generated modules and restore draft metadata), disable file sync,
     * structure rows, submission row or draft reset. See docs/cancellation-decision-matrix.yml.
     * The finalize-progress cache keeps a cancelled flag so in-flight finalize
     * requests can observe cancellation and stop safely.
     *
     * @param string $jobid
     * @param int $userid
     * @param bool $deletestructure Force deleting saved structure versions.
     * @return bool
     */
    public function cancel_draft(string $jobid, int $userid, bool $deletestructure = false): bool {
        $submission = $this->submissions->get_submission($jobid);
        if (!$submission || (int) $submission->userid !== $userid) {
            return false;
        }

        $courseid = !empty($submission->courseid) ? (int) $submission->courseid : null;
        $remotejobid = !empty($submission->remotejobid) ? $submission->remotejobid : null;
        $structurerec = $this->structures->get_by_jobid($jobid);
        $lateststructure = $this->structures->get_latest_structure($jobid);
        $hasstructure = $structurerec !== null
            || ($lateststructure !== null && $lateststructure !== '');

        $cache = \cache::make('block_dixeo_designer', 'finalize_progress');
        $progressdata = $cache->get($jobid);
        $generationmode = is_array($progressdata) && !empty($progressdata['generation_mode'])
            ? (string) $progressdata['generation_mode']
            : '';
        $currentfilljobid = null;
        $trackedjobids = [];
        if (is_array($progressdata) && !empty($progressdata['current_fill_jobid'])) {
            $currentfilljobid = $progressdata['current_fill_jobid'];
        }
        if (is_array($progressdata) && !empty($progressdata['active_jobids']) && is_array($progressdata['active_jobids'])) {
            $trackedjobids = $progressdata['active_jobids'];
        }

        $jobstocancel = [];
        if ($currentfilljobid !== null && $currentfilljobid !== '') {
            $jobstocancel[] = $currentfilljobid;
        }
        if ($remotejobid !== null && $remotejobid !== '') {
            $jobstocancel[] = $remotejobid;
        }
        if ($structurerec && !empty($structurerec->imagejobid)) {
            $jobstocancel[] = (string) $structurerec->imagejobid;
        }
        foreach ($trackedjobids as $trackedjobid) {
            if (is_string($trackedjobid) && $trackedjobid !== '') {
                $jobstocancel[] = $trackedjobid;
            }
        }
        $jobstocancel = array_values(array_unique($jobstocancel));

        $ctx = new cancellation_context($hasstructure, $deletestructure, $generationmode);
        $plan = cancellation_policy_resolver::resolve($ctx);

        $mergedprogress = array_merge(is_array($progressdata) ? $progressdata : [], ['cancelled' => true]);
        // Always reset finalize counters on cancel to avoid stale "module X/Y" from a previous run.
        $mergedprogress['phase'] = '';
        $mergedprogress['section_index'] = 0;
        $mergedprogress['section_total'] = 0;
        $mergedprogress['module_index'] = 0;
        $mergedprogress['module_total'] = 0;
        $mergedprogress['courseid'] = 0;
        $mergedprogress['coursename'] = '';
        $mergedprogress['current_fill_jobid'] = '';
        $mergedprogress['active_jobids'] = [];
        $cache->set($jobid, $mergedprogress);

        if (!empty($jobstocancel) && $this->jobservice !== null) {
            foreach ($jobstocancel as $jobidtocancel) {
                try {
                    $this->jobservice->cancel_job($jobidtocancel);
                } catch (\Throwable $e) {
                    debugging('cancel_draft: failed to cancel job ' . $jobidtocancel . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }

        if ($structurerec) {
            $this->structures->set_image_state($jobid, null, 'cancelled', null);
        }

        if ($courseid !== null) {
            image_poll_manager::delete_queued_poll_tasks($courseid);
        }

        if ($plan->delete_draft_course && $courseid !== null) {
            $this->coursecreation->delete_draft_course($courseid, true);
        } else if ($plan->delete_generated_modules_only && $courseid !== null) {
            $this->coursecreation->delete_generated_content_modules_preserving_uploads($courseid);
            if ($plan->restore_draft_course_metadata) {
                $this->coursecreation->restore_draft_course_metadata_after_cancel($courseid);
            }
        }

        if ($plan->disable_file_sync && $courseid !== null && $this->filesyncservice !== null) {
            try {
                $this->filesyncservice->disable_sync($courseid, $userid, $plan->remove_files_on_disable_sync);
            } catch (\Throwable $e) {
                debugging('cancel_draft: failed to disable file sync: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        if ($plan->delete_structure_rows) {
            $this->structures->delete_by_jobid($jobid);
        }
        if ($plan->delete_submission_row) {
            $this->submissions->delete_submission($jobid, $userid);
        } else if ($plan->reset_submission_to_draft) {
            if ($plan->delete_draft_course) {
                $submission->courseid = null;
            }
            $submission->remotejobid = null;
            $this->submissions->mark_status($submission, workflow_constants::SUBMISSION_STATUS_DRAFT);
        }

        return true;
    }

    /**
     * Push local uploaded files into the remote vector store so structure generation can use the same inputs.
     *
     * @param int $submissionid
     * @param string $jobid
     * @return void
     */
    private function sync_submission_files_to_remote(int $submissionid, string $jobid, int $courseid): void {
        $files = $this->files->get_files($submissionid);
        $this->remoteapi->sync_files_to_remote($jobid, $files, $courseid);
    }

    /**
     * Compute SHA-256 hash of the submission files manifest.
     * Mirrors local_dixeo file_sync_service::compute_file_manifest_hash().
     *
     * @param \stored_file[] $files
     * @return string
     */
    private function compute_file_manifest_hash(array $files): string {
        $entries = [];

        foreach ($files as $file) {
            $entries[] = $file->get_contenthash() . '|' . $file->get_filename();
        }

        sort($entries);

        return hash('sha256', implode("\n", $entries));
    }

    /**
     * Start async image generation for the stored structure/job.
     *
     * @param string $jobid
     * @param int $userid
     * @return array{started: bool, status: string, image: ?string, error: ?string}
     */
    public function start_structure_image_generation(string $jobid, int $userid): array {
        $submission = $this->submissions->get_submission($jobid);
        if (!$submission || (int) $submission->userid !== $userid || empty($submission->courseid)) {
            throw new \moodle_exception('invalidinput', 'block_dixeo_designer');
        }
        $structure = $this->structures->get_by_jobid($jobid);
        if (!$structure) {
            throw new \moodle_exception('structurenotfound', 'block_dixeo_designer');
        }

        if (image_generation_policy::is_enabled(
            image_generation_policy::ENTITY_COURSE,
            image_generation_policy::ACTION_GENERATE
        )) {
            $this->cancel_image_job_if_running($structure->imagejobid ?? null);
        }

        $decoded = json_decode((string) $structure->structure, true);
        [$payloadtitle, $payloadsummary] = is_array($decoded)
            ? $this->resolve_image_payload_from_structure_result($decoded)
            : [null, null];

        $operation = $this->get_image_service()->submit_course_image_job(
            (int) $submission->courseid,
            image_generation_service::DEFAULT_SIZE,
            image_generation_service::DEFAULT_QUALITY,
            $payloadtitle,
            $payloadsummary
        );
        $this->structures->set_image_state($jobid, (string) $operation->jobid, 'pending', null);
        image_poll_manager::delete_queued_poll_tasks((int) $submission->courseid);

        return [
            'started' => true,
            'status' => 'pending',
            'image' => $this->extract_structure_image($structure->structure),
            'error' => null,
        ];
    }

    /**
     * Start async image edit job using current structure image as base.
     *
     * @param string $jobid
     * @param int $userid
     * @param string $instructions
     * @return array{started: bool, status: string, image: ?string, error: ?string}
     */
    public function start_structure_image_edit(string $jobid, int $userid, string $instructions): array {
        $instructions = trim($instructions);
        if ($instructions === '') {
            throw new \moodle_exception('invalidinput', 'block_dixeo_designer');
        }
        $submission = $this->submissions->get_submission($jobid);
        if (!$submission || (int) $submission->userid !== $userid || empty($submission->courseid)) {
            throw new \moodle_exception('invalidinput', 'block_dixeo_designer');
        }
        $structure = $this->structures->get_by_jobid($jobid);
        if (!$structure) {
            throw new \moodle_exception('structurenotfound', 'block_dixeo_designer');
        }

        $currentimage = $this->extract_structure_image($structure->structure);
        if (!$currentimage) {
            throw new \moodle_exception('invalidinput', 'block_dixeo_designer');
        }

        if (image_generation_policy::is_enabled(
            image_generation_policy::ENTITY_COURSE,
            image_generation_policy::ACTION_EDIT
        )) {
            $this->cancel_image_job_if_running($structure->imagejobid ?? null);
        }

        $imagesbase64 = [pluginfile_image_helper::image_url_to_base64($currentimage)];

        $operation = $this->get_image_service()->submit_course_image_edit_job(
            (int) $submission->courseid,
            $imagesbase64,
            $instructions,
            image_generation_service::DEFAULT_SIZE,
            image_generation_service::DEFAULT_QUALITY
        );
        $this->structures->set_image_state($jobid, (string) $operation->jobid, 'pending', null);
        image_poll_manager::delete_queued_poll_tasks((int) $submission->courseid);

        return [
            'started' => true,
            'status' => 'pending',
            'image' => $currentimage,
            'error' => null,
        ];
    }

    /**
     * Poll image generation/edit status and persist final image URL when complete.
     *
     * @param string $jobid
     * @param int $userid
     * @return array{status: string, completed: bool, failed: bool, image: ?string, error: ?string}
     */
    public function get_structure_image_status(string $jobid, int $userid): array {
        $submission = $this->submissions->get_submission($jobid);
        if (!$submission || (int) $submission->userid !== $userid) {
            throw new \moodle_exception('invalidinput', 'block_dixeo_designer');
        }
        $structure = $this->structures->get_by_jobid($jobid);
        if (!$structure) {
            throw new \moodle_exception('structurenotfound', 'block_dixeo_designer');
        }

        $image = $this->extract_structure_image($structure->structure);
        $status = (string) ($structure->imagestatus ?? '');
        $imagejobid = (string) ($structure->imagejobid ?? '');

        if ($imagejobid === '') {
            // Auto-start when structure has no image yet (first visit after generation).
            if (!$image && !empty($submission->courseid)
                    && image_generation_policy::is_enabled(
                        image_generation_policy::ENTITY_COURSE,
                        image_generation_policy::ACTION_GENERATE
                    )) {
                $this->start_structure_image_generation($jobid, $userid);
                return [
                    'status' => 'pending',
                    'completed' => false,
                    'failed' => false,
                    'image' => null,
                    'error' => null,
                ];
            }
            return [
                'status' => $status !== '' ? $status : ($image ? 'completed' : 'idle'),
                'completed' => (bool) $image,
                'failed' => $status === 'failed',
                'image' => $image,
                'error' => $structure->imageerror ?? null,
            ];
        }

        $jobstatus = $this->get_job_service()->get_job_status($imagejobid);
        if ($jobstatus->is_completed()) {
            $imageurl = $this->persist_generated_image($jobid, (array) ($jobstatus->result ?? []), (int) $userid);
            $this->structures->set_image_state($jobid, null, 'completed', null);
            return [
                'status' => 'completed',
                'completed' => true,
                'failed' => false,
                'image' => $imageurl,
                'error' => null,
            ];
        }

        if ($jobstatus->is_failed()) {
            $error = (string) ($jobstatus->errormessage ?? get_string('designer_image_generate_unavailable', 'block_dixeo_designer'));
            $this->structures->set_image_state($jobid, null, 'failed', $error);
            return [
                'status' => 'failed',
                'completed' => false,
                'failed' => true,
                'image' => $image,
                'error' => $error,
            ];
        }

        $mappedstatus = $jobstatus->is_processing() ? 'processing' : 'pending';
        $this->structures->set_image_state($jobid, $imagejobid, $mappedstatus, null);
        return [
            'status' => $mappedstatus,
            'completed' => false,
            'failed' => false,
            'image' => $image,
            'error' => null,
        ];
    }

    /**
     * Best-effort cancellation of stale jobs when user starts regeneration.
     *
     * @param string $jobid
     * @return void
     */
    private function cancel_existing_jobs_for_regeneration(string $jobid): void {
        $submission = $this->submissions->get_submission($jobid);
        if ($submission && !empty($submission->remotejobid) && $this->jobservice !== null) {
            try {
                $this->jobservice->cancel_job((string) $submission->remotejobid);
            } catch (\Throwable $e) {
                // Ignore cancellation failure for already completed jobs.
            }
        }

        $structure = $this->structures->get_by_jobid($jobid);
        if ($structure && !empty($structure->imagejobid)) {
            $this->cancel_image_job_if_running((string) $structure->imagejobid);
            $this->structures->set_image_state($jobid, null, 'cancelled', null);
        }

        if ($submission && !empty($submission->courseid)) {
            image_poll_manager::delete_queued_poll_tasks((int) $submission->courseid);
        }
    }

    /**
     * Queue background polling for course image jobs across finalize (structure row is removed after success).
     *
     * @param string $jobid
     * @param int $userid
     * @param int $draftcourseid
     * @param string $finalizemode
     * @param array $structureresult Structure payload (same shape as finalize_course $result); used for quick-mode image title/summary overrides.
     * @return void
     */
    private function queue_finalize_course_image_tracking(
        string $jobid,
        int $userid,
        int $draftcourseid,
        string $finalizemode,
        array $structureresult = []
    ): void {
        if ($draftcourseid < 1) {
            return;
        }

        $struct = $this->structures->get_by_jobid($jobid);
        $mode = trim($finalizemode);

        if ($mode === 'quick') {
            if (!image_generation_policy::is_enabled(
                image_generation_policy::ENTITY_COURSE,
                image_generation_policy::ACTION_GENERATE
            )) {
                return;
            }
            if ($struct && !empty($struct->imagejobid)) {
                $this->cancel_image_job_if_running((string) $struct->imagejobid);
            }
            try {
                [$payloadtitle, $payloadsummary] = $this->resolve_image_payload_from_structure_result($structureresult);
                $operation = $this->get_image_service()->submit_course_image_job(
                    $draftcourseid,
                    image_generation_service::DEFAULT_SIZE,
                    image_generation_service::DEFAULT_QUALITY,
                    $payloadtitle,
                    $payloadsummary
                );
                $imagejobid = (string) $operation->jobid;
                if ($struct) {
                    $this->structures->set_image_state($jobid, $imagejobid, 'pending', null);
                }
                image_poll_manager::queue_poll_task($draftcourseid, $imagejobid, $userid);
            } catch (\Throwable $e) {
                debugging('designer_service: quick finalize image submit failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            return;
        }

        if (!$struct) {
            return;
        }

        $imagejobid = trim((string) ($struct->imagejobid ?? ''));
        $status = (string) ($struct->imagestatus ?? '');

        // In-flight remote image job: poll until complete; the adhoc task applies bytes from the API result.
        if ($imagejobid !== '' && !in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            image_poll_manager::queue_poll_task($draftcourseid, $imagejobid, $userid);
            return;
        }

        // Two-step flow: after the UI persists the JPEG to pluginfile, imagejobid is empty but the
        // structure holds the URL — copy those bytes onto the draft course overview.
        $structureimage = $this->extract_structure_image($struct->structure);
        if ($structureimage === null || $structureimage === '') {
            return;
        }
        try {
            $this->try_apply_designer_saved_image_to_course($draftcourseid, $structureimage, $userid);
        } catch (\Throwable $e) {
            debugging('designer_service: apply persisted structure image to course failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * @param string|null $imagejobid
     * @return void
     */
    private function cancel_image_job_if_running(?string $imagejobid): void {
        if (!$imagejobid || $this->jobservice === null) {
            return;
        }
        try {
            $this->jobservice->cancel_job($imagejobid);
        } catch (\Throwable $e) {
            // Ignore cancellation failures.
        }
    }

    /**
     * @return \local_dixeo\service\job_service
     */
    private function get_job_service(): \local_dixeo\service\job_service {
        if ($this->jobservice instanceof \local_dixeo\service\job_service) {
            return $this->jobservice;
        }
        return \local_dixeo\external\service_factory::get_job_service();
    }

    /**
     * @return image_generation_service
     */
    private function get_image_service(): image_generation_service {
        if ($this->imageservice === null) {
            $this->imageservice = new image_generation_service($this->get_job_service());
        }
        return $this->imageservice;
    }

    /**
     * Title and summary for image API from designer structure (aligned with finalize_draft_course mapping).
     *
     * When the inner payload is missing or invalid, returns [null, null] so the image service uses DB fields.
     *
     * @param array $result Decoded structure root (may include course_structure).
     * @return array{0: ?string, 1: ?string} Override title and summary; null pair means use course row.
     */
    private function resolve_image_payload_from_structure_result(array $result): array {
        $data = $result['course_structure'] ?? $result;
        if (!is_array($data)) {
            return [null, null];
        }

        $title = isset($data['title']) && is_string($data['title']) ? trim($data['title']) : '';
        if ($title === '') {
            $title = get_string('blocktitle', 'block_dixeo_designer');
        }

        $summary = $data['summary'] ?? '';
        $summary = is_string($summary) ? $summary : '';

        return [$title, $summary];
    }

    /**
     * @param string $structurejson
     * @return string|null
     */
    private function extract_structure_image(string $structurejson): ?string {
        $decoded = json_decode($structurejson, true);
        if (!is_array($decoded)) {
            return null;
        }
        if (!empty($decoded['course_structure']['image']) && is_string($decoded['course_structure']['image'])) {
            return $decoded['course_structure']['image'];
        }
        if (!empty($decoded['image']) && is_string($decoded['image'])) {
            return $decoded['image'];
        }
        return null;
    }

    /**
     * Copy a designer-generated pluginfile image onto the course overview file area.
     *
     * @param int $courseid
     * @param string $imageurl
     * @param int $userid
     * @return void
     */
    private function try_apply_designer_saved_image_to_course(int $courseid, string $imageurl, int $userid): void {
        $file = pluginfile_image_helper::get_stored_file_from_pluginfile_url($imageurl);
        if (!$file) {
            return;
        }
        if ($file->get_component() !== 'block_dixeo_designer' || $file->get_filearea() !== 'generated_images') {
            return;
        }
        $binary = $file->get_content();
        if ($binary === '') {
            return;
        }
        course_image_writer::apply_image_binary_to_course_overview($courseid, $binary, $userid);
    }

    /**
     * Persist generated base64 image and return pluginfile URL.
     *
     * @param string $jobid
     * @param array $result
     * @param int $userid
     * @return string
     */
    private function persist_generated_image(string $jobid, array $result, int $userid): string {
        $binary = course_image_writer::extract_image_binary_from_result($result);
        if ($binary === '') {
            throw new \moodle_exception('designer_image_generate_unavailable', 'block_dixeo_designer');
        }

        $context = \context_system::instance();
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'block_dixeo_designer', 'generated_images', $userid);
        // Unique filename so the pluginfile URL changes each generation (avoids stale browser cache).
        $safejob = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $jobid);
        $filename = sprintf(
            'course-image-%s-%d-%03d.jpg',
            $safejob,
            time(),
            random_int(0, 999)
        );
        $file = $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'block_dixeo_designer',
            'filearea' => 'generated_images',
            'itemid' => $userid,
            'filepath' => '/',
            'filename' => $filename,
        ], $binary);

        $url = \moodle_url::make_pluginfile_url(
            $context->id,
            'block_dixeo_designer',
            'generated_images',
            $userid,
            '/',
            $filename
        )->out(false);

        $this->structures->set_structure_image($jobid, $url);

        return $url;
    }
}
