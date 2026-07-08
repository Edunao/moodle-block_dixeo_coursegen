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
 * External API for saving course design structure.
 *
 * @package    block_dixeo_designer
 * @author     Josemaria Bolanos <admin@mako.digital>
 * @copyright  2026 Dixeo (contact@dixeo.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_dixeo_designer\external\course;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_dixeo\service\image\policy;

/**
 * External API class for saving course design structure.
 *
 * @package    block_dixeo_designer
 * @copyright  2026 Dixeo (contact@dixeo.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class save_structure extends external_api {

    /**
     * Web service parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function save_structure_parameters(): external_function_parameters {
        return new external_function_parameters([
            'job_id' => new external_value(PARAM_TEXT, 'Job ID', VALUE_REQUIRED),
            'structure' => new external_value(PARAM_RAW, 'JSON structure', VALUE_REQUIRED),
        ]);
    }

    /**
     * Save structure (single row per job; upsert).
     * Used when persisting the editor JSON (e.g. before finalize, or when landing from the generator).
     *
     * @param string $job_id The job identifier
     * @param string $structure JSON structure data
     * @return array Save result
     */
    public static function save_structure(string $job_id, string $structure): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::save_structure_parameters(), [
            'job_id' => $job_id,
            'structure' => $structure,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);

        require_login();

        // Validate JSON.
        $decoded = json_decode($params['structure'], true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('invalidjson', 'block_dixeo_designer');
        }

        $existing = $DB->get_record('block_dixeo_designer_structure', ['jobid' => $params['job_id']], '*', IGNORE_MISSING);

        if ($existing) {
            // Check user owns this structure (or is a site administrator).
            if ($existing->userid != $USER->id && !is_siteadmin()) {
                throw new \moodle_exception('nopermissions', 'error');
            }
            $DB->set_field('block_dixeo_designer_structure', 'structure', $params['structure'], ['id' => $existing->id]);
            $DB->set_field('block_dixeo_designer_structure', 'timecreated', time(), ['id' => $existing->id]);
            return ['success' => true];
        }

        // No record yet (e.g. designer opened before any structure saved); insert one.
        $DB->insert_record('block_dixeo_designer_structure', (object) [
            'jobid' => $params['job_id'],
            'userid' => $USER->id,
            'description' => '',
            'structure' => $params['structure'],
            'timecreated' => time(),
        ]);

        if (policy::is_enabled(
            policy::ENTITY_COURSE,
            policy::ACTION_GENERATE
        )) {
            $service = \block_dixeo_designer\service\designer_service_factory::get_designer_service();
            $service->start_structure_image_generation($params['job_id'], (int) $USER->id);
        }

        return ['success' => true];
    }

    /**
     * Web service return value definitions.
     *
     * @return external_single_structure
     */
    public static function save_structure_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
        ]);
    }
}
