<?php
/**
 * Plugin Name: Barbaarintasan User Import
 * Description: Import users from Barbaarintasan Academy app JSON export into WordPress + Tutor LMS.
 *              Preserves bcrypt passwords via legacy_bcrypt usermeta for seamless first login.
 * Version: 1.0.0
 * Author: Barbaarintasan Academy
 *
 * USAGE:
 * 1. Upload the JSON export file from app admin (/api/admin/export-users-wp)
 * 2. Go to WordPress Admin > Tools > BSA User Import
 * 3. Upload the JSON file and click Import
 *
 * REQUIREMENTS:
 * - Tutor LMS plugin must be active for course enrollment import
 * - barbaarintasan-legacy-auth.php plugin must be active for password migration
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'bsa_import_admin_menu');

function bsa_import_admin_menu() {
    add_management_page(
        'BSA User Import',
        'BSA User Import',
        'manage_options',
        'bsa-user-import',
        'bsa_import_admin_page'
    );
}

function bsa_import_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }

    $results = null;
    if (isset($_POST['bsa_import_submit']) && check_admin_referer('bsa_import_nonce')) {
        if (!empty($_FILES['bsa_json_file']['tmp_name'])) {
            $json_content = file_get_contents($_FILES['bsa_json_file']['tmp_name']);
            $data = json_decode($json_content, true);
            if ($data && isset($data['users'])) {
                $results = bsa_process_import($data);
            } else {
                $results = ['error' => 'Invalid JSON file format. Expected "users" array.'];
            }
        } else {
            $results = ['error' => 'Please upload a JSON file.'];
        }
    }

    ?>
    <div class="wrap">
        <h1>Barbaarintasan User Import</h1>
        <p>Import users from Barbaarintasan Academy app into WordPress + Tutor LMS.</p>
        <p><strong>Requirements:</strong></p>
        <ul style="list-style: disc; margin-left: 20px;">
            <li>"Barbaarintasan Legacy Auth" plugin must be active</li>
            <li>Tutor LMS must be active for course enrollment</li>
        </ul>

        <?php if ($results): ?>
            <?php if (isset($results['error'])): ?>
                <div class="notice notice-error"><p><?php echo esc_html($results['error']); ?></p></div>
            <?php else: ?>
                <div class="notice notice-success">
                    <p><strong>Import Complete!</strong></p>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <li>Total users in file: <?php echo $results['total']; ?></li>
                        <li>Successfully imported: <?php echo $results['imported']; ?></li>
                        <li>Skipped (already exist): <?php echo $results['skipped']; ?></li>
                        <li>Errors: <?php echo $results['errors']; ?></li>
                        <li>Enrollments created: <?php echo $results['enrollments_created']; ?></li>
                    </ul>
                </div>
                <?php if (!empty($results['error_details'])): ?>
                    <div class="notice notice-warning">
                        <p><strong>Error Details:</strong></p>
                        <ul style="list-style: disc; margin-left: 20px;">
                            <?php foreach ($results['error_details'] as $err): ?>
                                <li><?php echo esc_html($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" style="margin-top: 20px;">
            <?php wp_nonce_field('bsa_import_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="bsa_json_file">JSON Export File</label></th>
                    <td>
                        <input type="file" name="bsa_json_file" id="bsa_json_file" accept=".json" required />
                        <p class="description">Upload the JSON file exported from App Admin > Export Users for WordPress</p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="bsa_import_submit" class="button-primary" value="Import Users" />
            </p>
        </form>
    </div>
    <?php
}

function bsa_process_import($data) {
    $results = [
        'total' => count($data['users']),
        'imported' => 0,
        'skipped' => 0,
        'errors' => 0,
        'enrollments_created' => 0,
        'error_details' => [],
    ];

    $course_mapping = bsa_get_tutor_course_mapping();

    foreach ($data['users'] as $app_user) {
        $email = sanitize_email($app_user['email']);
        if (empty($email)) {
            $results['errors']++;
            $results['error_details'][] = "User '{$app_user['name']}' has no email - skipped";
            continue;
        }

        $existing_user = get_user_by('email', $email);
        if ($existing_user) {
            $wp_user_id = $existing_user->ID;

            update_user_meta($wp_user_id, 'bsa_app_id', $app_user['id']);
            if (!empty($app_user['phone'])) {
                update_user_meta($wp_user_id, 'bsa_phone', $app_user['phone']);
            }

            $results['skipped']++;
        } else {
            $username = sanitize_user($email, true);
            $temp_password = wp_generate_password(32, true, true);

            $wp_user_id = wp_insert_user([
                'user_login' => $username,
                'user_email' => $email,
                'user_pass' => $temp_password,
                'display_name' => sanitize_text_field($app_user['name']),
                'first_name' => sanitize_text_field(explode(' ', $app_user['name'])[0]),
                'last_name' => sanitize_text_field(implode(' ', array_slice(explode(' ', $app_user['name']), 1))),
                'role' => 'subscriber',
            ]);

            if (is_wp_error($wp_user_id)) {
                $results['errors']++;
                $results['error_details'][] = "Failed to create user '{$email}': " . $wp_user_id->get_error_message();
                continue;
            }

            if (!empty($app_user['passwordHash'])) {
                update_user_meta($wp_user_id, 'legacy_bcrypt', $app_user['passwordHash']);
            }

            update_user_meta($wp_user_id, 'bsa_app_id', $app_user['id']);
            if (!empty($app_user['phone'])) {
                update_user_meta($wp_user_id, 'bsa_phone', $app_user['phone']);
            }
            if (!empty($app_user['country'])) {
                update_user_meta($wp_user_id, 'bsa_country', $app_user['country']);
            }
            if (!empty($app_user['city'])) {
                update_user_meta($wp_user_id, 'bsa_city', $app_user['city']);
            }

            if (function_exists('tutor_utils')) {
                $wp_user = new WP_User($wp_user_id);
                $wp_user->add_role('tutor_student');
            }

            $results['imported']++;
        }

        if (!empty($app_user['enrollments']) && !empty($course_mapping)) {
            foreach ($app_user['enrollments'] as $enrollment) {
                $app_course_id = $enrollment['courseId'];
                if (isset($course_mapping[$app_course_id])) {
                    $tutor_course_id = $course_mapping[$app_course_id];
                    $enrolled = bsa_enroll_user_tutor($wp_user_id, $tutor_course_id);
                    if ($enrolled) {
                        $results['enrollments_created']++;
                    }
                }
            }
        }
    }

    return $results;
}

function bsa_get_tutor_course_mapping() {
    $mapping = [];

    if (!function_exists('tutor_utils')) {
        return $mapping;
    }

    $courses = get_posts([
        'post_type' => tutor()->course_post_type,
        'post_status' => 'publish',
        'numberposts' => -1,
    ]);

    foreach ($courses as $course) {
        $bsa_id = get_post_meta($course->ID, 'bsa_course_id', true);
        if (!empty($bsa_id)) {
            $mapping[$bsa_id] = $course->ID;
        }

        $slug = $course->post_name;
        $mapping[$slug] = $course->ID;
    }

    return $mapping;
}

function bsa_enroll_user_tutor($user_id, $course_id) {
    if (!function_exists('tutor_utils')) {
        return false;
    }

    $is_enrolled = tutor_utils()->is_enrolled($course_id, $user_id);
    if ($is_enrolled) {
        return false;
    }

    $enrollment_data = [
        'post_type' => 'tutor_enrolled',
        'post_title' => 'BSA Import Enrollment',
        'post_status' => 'completed',
        'post_author' => $user_id,
        'post_parent' => $course_id,
    ];

    $enroll_id = wp_insert_post($enrollment_data);

    if ($enroll_id && !is_wp_error($enroll_id)) {
        $existing = get_user_meta($user_id, '_tutor_enrolled_courses_ids', false);
        $existing_ids = [];
        if (!empty($existing)) {
            foreach ($existing as $val) {
                $existing_ids[] = intval($val);
            }
        }
        if (!in_array(intval($course_id), $existing_ids)) {
            add_user_meta($user_id, '_tutor_enrolled_courses_ids', $course_id);
        }
        return true;
    }

    return false;
}
