<?php
/*
Plugin Name: Display OAuth User Info with Sidebar
Description: Displays user details with "My Profile," "My Courses," and "Update Profile" menu
Version: 1.5
*/

// Enqueue styles
function knu_profile_enqueue_styles() {
    global $post;
    if( is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'show_oauth_user_info') ) {
        wp_enqueue_style('my-profile-page-style', plugins_url('assets/profile.css', __FILE__));
    }
}
add_action('wp_enqueue_scripts', 'knu_profile_enqueue_styles');

function get_token_data_from_cookie($token_name) {
    if (isset($_COOKIE[$token_name])) {
        return json_decode(base64_decode(explode('.', $_COOKIE[$token_name])[1]), true);
    }
    return null;
}

function knu_get_available_personas() {
    $access_token = isset($_COOKIE['access_token']) ? $_COOKIE['access_token'] : null;

    if (!$access_token) {
        return ['Error: No access token'];
    }

    $keycloak_url = 'https://accounts.rubiscape.com/realms/SSO/account/';
    $response = wp_remote_get($keycloak_url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/json',
        ],
    ]);

    if (is_wp_error($response)) {
        return ['Error: ' . $response->get_error_message()];
    }

    $body = wp_remote_retrieve_body($response);
    $user_data = json_decode($body, true);

    if (isset($user_data['attributes']['Persona']) && is_array($user_data['attributes']['Persona'])) {
        return $user_data['attributes']['Persona'];
    }

    return ['No personas available'];
}

function knu_get_user_courses($email) {
    // Custom encode email to match Postman's encoding
    $encoded_email = str_replace(['@', '.'], ['%40', '%2E'], $email);
    
    // LearnWorlds API endpoint for user courses
    $url = "https://learn.rubiscape.com/admin/api/v2/users/{$encoded_email}/courses";

    // API request to fetch user courses
    $response = wp_remote_get($url, [
        'headers' => [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer Sp6TfzDJw5A8z2bm4No8v1KCyoFdwtrLUTdfuob3',
            'Lw-Client' => '66c8390deda6647d6d06a8a6'
        ],
        'timeout' => 45,
        'sslverify' => true
    ]);

    if (is_wp_error($response)) {
        error_log('WordPress Error in LearnWorlds API: ' . $response->get_error_message());
        return ['error' => 'API request failed: ' . $response->get_error_message()];
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        error_log('LearnWorlds API Error: Non-200 response code: ' . $response_code);
        return ['error' => 'API returned status code: ' . $response_code];
    }

    $body = wp_remote_retrieve_body($response);
    $response_data = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('JSON Decode Error: ' . json_last_error_msg());
        return ['error' => 'Failed to parse API response'];
    }

    if (!isset($response_data['data']) || !is_array($response_data['data'])) {
        return ['error' => 'Invalid API response structure'];
    }

    $processed_courses = [];
    foreach ($response_data['data'] as $item) {
        if (isset($item['course'])) {
            // Get course progress from the completion data
            $progress = 0;
            if (isset($item['completion'])) {
                $progress = round($item['completion'] * 100);
            } elseif (isset($item['progress'])) {
                $progress = round($item['progress'] * 100);
            }

            // Build the course URL
            $course_url = '';
            if (isset($item['course']['url'])) {
                // If the URL is relative, make it absolute
                if (strpos($item['course']['url'], 'http') !== 0) {
                    $course_url = 'https://learn.rubiscape.com' . $item['course']['url'];
                } else {
                    $course_url = $item['course']['url'];
                }
            }
            
            $processed_courses[] = [
                'course_name' => $item['course']['title'],
                'status' => 'Enrolled',
                'description' => isset($item['course']['description']) ? $item['course']['description'] : '',
                'category' => !empty($item['course']['categories']) ? implode(', ', $item['course']['categories']) : '',
                'image_url' => isset($item['course']['courseImage']) ? $item['course']['courseImage'] : '',
                'course_url' => $course_url,
                'created' => isset($item['created']) ? date('Y-m-d', $item['created']) : '',
                'expires' => isset($item['expires']) ? ($item['expires'] ? date('Y-m-d', $item['expires']) : 'Never') : 'Never',
                'progress' => $progress,
                'tools' => isset($item['course']['hasToolKit']) ? $item['course']['hasToolKit'] : false,
                'has_rpt' => isset($item['course']['hasRPT']) ? $item['course']['hasRPT'] : false,
                'course_id' => isset($item['course']['id']) ? $item['course']['id'] : ''
            ];
        }
    }

    return $processed_courses;
}

function display_courses_section($courses) {
    ob_start();
    ?>
    <div id="coursesContent">
        <h2>My Courses</h2>
        <?php 
        if (isset($courses['error'])) {
            echo '<p class="error-message">Error: ' . htmlspecialchars($courses['error']) . '</p>';
            if (current_user_can('administrator')) {
                echo '<div class="admin-debug">';
                echo '<h4>Debug Information (Admin Only):</h4>';
                echo '<p>Please check the WordPress debug log for more details.</p>';
                echo '</div>';
            }
        } elseif (!empty($courses) && is_array($courses)) {
            echo '<div class="courses-grid">';
            foreach ($courses as $course) {
                // Ensure we have a valid course URL
                $course_url = !empty($course['course_url']) ? $course['course_url'] : 
                             (!empty($course['course_id']) ? "https://learn.rubiscape.com/course/" . $course['course_id'] : '#');
                ?>
                <div class="course-card">
                    <div class="course-header">
                        <?php if (!empty($course['image_url'])): ?>
                            <div class="course-image">
                                <img src="<?php echo esc_url($course['image_url']); ?>" 
                                     alt="<?php echo esc_attr($course['course_name']); ?>">
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="course-content">
                        <h3 class="course-title"><?php echo htmlspecialchars($course['course_name']); ?></h3>
                        <?php if (!empty($course['description'])): ?>
                            <p class="course-description"><?php echo htmlspecialchars($course['description']); ?></p>
                        <?php endif; ?>
                        
                        <div class="course-progress">
                            <div class="progress-text">
                                <span class="progress-label"><?php echo $course['progress']; ?>% COMPLETE</span>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar" style="width: <?php echo $course['progress']; ?>%"></div>
                            </div>
                        </div>
                        
                        <a href="<?php echo esc_url($course_url); ?>" class="continue-button" target="_blank">Continue</a>
                    </div>
                </div>
                <?php
            }
            echo '</div>';
        } else {
            echo '<p class="no-courses">No courses available.</p>';
        }
        ?>
    </div>
    <?php
    return ob_get_clean();
}

// Add new function to handle profile picture upload
function handle_profile_picture_upload() {
    if (!isset($_FILES['profile_picture'])) {
        return ['success' => false, 'message' => ''];
    }

    $current_user_id = get_current_user_id();
    $upload_overrides = array(
        'test_form' => false,
        'mimes' => array(
            'jpg|jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif'
        )
    );

    $file = wp_handle_upload($_FILES['profile_picture'], $upload_overrides);

    if (!isset($file['error'])) {
        $old_picture = get_user_meta($current_user_id, 'profile_picture', true);
        if (!empty($old_picture)) {
            $old_file_path = str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $old_picture);
            if (file_exists($old_file_path)) {
                unlink($old_file_path);
            }
        }
        update_user_meta($current_user_id, 'profile_picture', $file['url']);
        return ['success' => true, 'message' => "Profile picture updated successfully!"];
    }
    
    return ['success' => false, 'message' => "Error uploading file: " . $file['error']];
}


function get_user_id_from_email($email) {
    $encoded_email = rawurlencode($email);
    $url = "https://learn.rubiscape.com/admin/api/v2/users/{$encoded_email}";

    $response = wp_remote_get($url, [
        'headers' => [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer Sp6TfzDJw5A8z2bm4No8v1KCyoFdwtrLUTdfuob3',
            'Lw-Client' => '66c8390deda6647d6d06a8a6'
        ],
        'timeout' => 45,
        'sslverify' => true
    ]);

    if (is_wp_error($response)) {
        error_log('WordPress Error in LearnWorlds Users API: ' . $response->get_error_message());
        return null;
    }

    $body = wp_remote_retrieve_body($response);
    $user_data = json_decode($body, true);

    return isset($user_data['id']) ? $user_data['id'] : null;
}

function get_user_payments($email) {
    // First get the user ID
    $user_id = get_user_id_from_email($email);
    
    if (!$user_id) {
        return ['error' => 'Unable to find user ID'];
    }

    // LearnWorlds API endpoint for payments
    $url = "https://learn.rubiscape.com/admin/api/v2/payments";

    // API request to fetch payments
    $response = wp_remote_get($url, [
        'headers' => [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer Sp6TfzDJw5A8z2bm4No8v1KCyoFdwtrLUTdfuob3',
            'Lw-Client' => '66c8390deda6647d6d06a8a6'
        ],
        'timeout' => 45,
        'sslverify' => true
    ]);

    if (is_wp_error($response)) {
        error_log('WordPress Error in LearnWorlds Payments API: ' . $response->get_error_message());
        return ['error' => 'API request failed: ' . $response->get_error_message()];
    }

    $body = wp_remote_retrieve_body($response);
    $response_data = json_decode($body, true);

    if (!isset($response_data['data']) || !is_array($response_data['data'])) {
        return ['error' => 'Invalid API response structure'];
    }

    // Filter payments for the current user using user_id instead of email
    $user_payments = array_filter($response_data['data'], function($payment) use ($user_id) {
        return isset($payment['user_id']) && $payment['user_id'] === $user_id;
    });

    return array_values($user_payments); // Reset array keys
}

function refresh_zoho_token() {
    $refresh_url = 'https://accounts.zoho.in/oauth/v2/token';
    
    $params = array(
        'grant_type' => 'refresh_token',
        'client_id' => '1000.M45WQUD038EF6IKE6YSJ4B5B34T36J',
        'client_secret' => 'ce59c07482f2f48e1a790b2bcfcb9324a67a939993',
        'refresh_token' => '1000.55498028f11d519779fc74f9fa4d09e6.97db16f19273c3e1447e40beb6c48f42'
    );
    
    $response = wp_remote_post($refresh_url, array(
        'body' => $params,
        'timeout' => 45,
        'sslverify' => true
    ));

    if (is_wp_error($response)) {
        error_log('Error refreshing Zoho token: ' . $response->get_error_message());
        return null;
    }

    $body = wp_remote_retrieve_body($response);
    $token_data = json_decode($body, true);

    if (!isset($token_data['access_token'])) {
        error_log('Invalid token refresh response: ' . print_r($token_data, true));
        return null;
    }

    // Store the new access token in WordPress options with expiration time
    $token_data['expires_at'] = time() + ($token_data['expires_in'] - 300); // 5 minutes buffer
    update_option('zoho_token_data', $token_data);

    return $token_data['access_token'];
}

function get_valid_zoho_token() {
    $token_data = get_option('zoho_token_data');
    
    // If no token data exists or token is expired, refresh it
    if (!$token_data || !isset($token_data['expires_at']) || time() >= $token_data['expires_at']) {
        return refresh_zoho_token();
    }
    
    return $token_data['access_token'];
}

function get_zoho_subscriptions($email) {
    $url = 'https://www.zohoapis.in/billing/v1/subscriptions';
    
    // Debug log to check the email we're searching for
    error_log('Searching for subscriptions with email: ' . $email);
    
    // Get valid token
    $access_token = get_valid_zoho_token();
    if (!$access_token) {
        error_log('Failed to obtain valid Zoho access token');
        return ['error' => 'Authentication failed'];
    }
    
    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/json'
        ],
        'timeout' => 45,
        'sslverify' => true
    ]);

    if (is_wp_error($response)) {
        error_log('WordPress Error in Zoho API: ' . $response->get_error_message());
        return ['error' => 'API request failed: ' . $response->get_error_message()];
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code === 401) {
        // Token might be expired, try refreshing once
        error_log('Token expired, attempting refresh...');
        $access_token = refresh_zoho_token();
        if ($access_token) {
            // Retry the request with new token
            $response = wp_remote_get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json'
                ],
                'timeout' => 45,
                'sslverify' => true
            ]);
        }
    }

    $body = wp_remote_retrieve_body($response);
    $response_data = json_decode($body, true);
    
    // Debug log to check API response
    error_log('Zoho API Response: ' . print_r($response_data, true));

    if (!isset($response_data['subscriptions']) || !is_array($response_data['subscriptions'])) {
        error_log('Invalid API response structure or no subscriptions array');
        return ['error' => 'Invalid API response structure'];
    }

    // Filter subscriptions for the current user's email
    $user_subscriptions = array_filter($response_data['subscriptions'], function($subscription) use ($email) {
        // Check both email and customer_email fields
        $sub_email = isset($subscription['email']) ? $subscription['email'] : 
                    (isset($subscription['customer_email']) ? $subscription['customer_email'] : '');
        
        $matches = !empty($sub_email) && 
                  strtolower(trim($sub_email)) === strtolower(trim($email));
        
        // Debug log for email comparison
        error_log(sprintf(
            'Comparing emails - Subscription: %s, User: %s, Matches: %s',
            $sub_email,
            $email,
            $matches ? 'true' : 'false'
        ));
        
        return $matches;
    });

    $user_subscriptions = array_values($user_subscriptions);
    
    // Debug log for filtered subscriptions
    error_log('Filtered subscriptions: ' . print_r($user_subscriptions, true));
    
    // Return empty array if no subscriptions found
    if (empty($user_subscriptions)) {
        error_log('No subscriptions found for email: ' . $email);
    }
    
    return $user_subscriptions;
}
function display_payments_section($payments) {

    $current_user = wp_get_current_user();
    $email = $current_user->user_email;
    
    // Get Zoho subscriptions
    $subscriptions = get_zoho_subscriptions($email);
    ob_start();
    ?>
    <div id="paymentsContent">
       <h3>Rubiversity Subscriptions</h3>
        <?php if (isset($payments['error'])): ?>
            <p class="error-message"><?php echo htmlspecialchars($payments['error']); ?></p>
        <?php elseif (empty($payments)): ?>
            <p class="no-payments">No payment history available.</p>
        <?php else: ?>
	
            <div class="payments-table-wrapper">
                <table class="payments-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Course</th>
                            <th>Invoice</th>
                            <th>Original Price</th>
                            <th>Discount</th>
                            <th>Final Price</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?php echo date('Y-m-d', $payment['paid_at']); ?></td>
                                <td><?php echo htmlspecialchars($payment['product']['name']); ?></td>
                                <td><?php echo $payment['invoice'] ? htmlspecialchars($payment['invoice']) : '-'; ?></td>
                                <td><?php echo number_format($payment['product']['original_price'] / 100, 2); ?> USD</td>
                                <td><?php echo number_format($payment['discount'] / 100, 2); ?> USD</td>
                                <td><?php echo number_format($payment['price'] / 100, 2); ?> USD</td>
                                <td><?php echo $payment['refund_at'] ? 'Refunded' : 'Completed'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <!-- Zoho Subscriptions Section -->
        <h3>Platform Subscriptions</h3>
        <?php if (isset($subscriptions['error'])): ?>
            <p class="error-message"><?php echo htmlspecialchars($subscriptions['error']); ?></p>
        <?php elseif (empty($subscriptions)): ?>
            <p class="no-subscriptions">No subscription history available.</p>
        <?php else: ?>
            <div class="subscriptions-table-wrapper">
                <table class="subscriptions-table">
                    <thead>
                        <tr>
                            <th>Subscription Name</th>
                            <th>Plan</th>
                            <th>Status</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Amount</th>
                            <th>Next Billing</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subscriptions as $subscription): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($subscription['name']); ?></td>
                                <td><?php echo htmlspecialchars($subscription['plan_name']); ?></td>
                                <td><?php echo ucfirst(htmlspecialchars($subscription['status'])); ?></td>
                                <td><?php echo htmlspecialchars($subscription['current_term_starts_at']); ?></td>
                                <td><?php echo htmlspecialchars($subscription['current_term_ends_at']); ?></td>
                                <td>
                                    <?php 
                                    echo htmlspecialchars($subscription['currency_symbol']) . ' ' . 
                                         number_format($subscription['amount'], 2); 
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($subscription['next_billing_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}


function display_user_info() {
    ob_start();

    // Check if user is logged in
    if (!is_user_logged_in()) {
        $login_url = 'https://accounts.rubiscape.com/realms/SSO/protocol/openid-connect/auth?client_id=rubiscape&scope=openid&redirect_uri=https%3A%2F%2Fwww.rubiscape.com%3Fapp_name%3Dkeycloak&response_type=code&state=eyI2MTcwNzA2ZTYxNmQ2NSI6eyJWIjoiNmI2NTc5NjM2YzZmNjE2YiIsIkgiOiI5NDAwMzk0N2IxN2JiODVmNGI4YzkzMjkxNDE5YzQ3NCJ9LCI3MjY1NjQ2OTcyNjU2Mzc0NWY3NTcyNjkiOnsiViI6IjY4NzQ3NDcwNzMzYTJmMmY3Nzc3NzcyZTcyNzU2MjY5NzM2MzYxNzA2NTJlNjM2ZjZkMmY3NzcwMmQ2MTY0NmQ2OTZlMmYiLCJIIjoiMDc3NDJlMDc4MDUzYTUxOTlkNTdlNmFjOTllY2JlOTYifSwiNzM3NDYxNzQ2NTVmNmU2ZjZlNjM2NSI6eyJWIjoiNjUzNDY0NjEzMzYyMzc2NjYyNjI2MzY1MzIzMzM0MzU2NDM3MzczNzMyNjIzMDM2MzczNDYxMzMzMTM4NjQzNSIsIkgiOiJiNWVmYWFmMDM1NTg3MWQ5MGE0YmZlMmExODA1ZjdhNSJ9LCI3NTY5NjQiOnsiViI6IjQ0NDYzODU2NGI0YTRmMzU0NjQ0NDg1YTQxNTI0MjUyMzU1YTQ0NTMzMjU2MzU0YTM2MzY1NTMyNGU0NDUyIiwiSCI6IjhhNTc5ZDdjODRjOTg0ZDVhODBmNGM2YjhmMGQ0NjljIn19';
        
        return '<div class="login-required-message">
                    <h2>Please Log In</h2>
                    <p>You must be logged in to view your profile.</p>
                    <a href="' . esc_url($login_url) . '" class="login-button">Log In</a>
                </div>';
    }
    

    // Handle profile picture upload if form was submitted
    $upload_result = ['success' => false, 'message' => ''];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_picture'])) {
        $upload_result = handle_profile_picture_upload();
    }

    $personas = knu_get_available_personas();
    $payments = [];
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        $email = $user->user_email;
        $courses = knu_get_user_courses($email);
        $payments = get_user_payments($email);
        $zoho_subscriptions = get_zoho_subscriptions($email);
    }



    $profile_picture_url = '';
    if (is_user_logged_in()) {
        $profile_picture_url = get_user_meta(get_current_user_id(), 'profile_picture', true);
        if (empty($profile_picture_url)) {
            $profile_picture_url = plugins_url('assets/default-profile.png', __FILE__);
        }
    }
    ?>
     <div class="profile-wrapper">
        <div class="container">
            <div class="sidebar">
                <div id="profileItem" class="sidebar-item active" onclick="switchContent('profile')">My Profile</div>
                <div id="coursesItem" class="sidebar-item" onclick="switchContent('courses')">My Courses</div>
                <div id="paymentsItem" class="sidebar-item" onclick="switchContent('payments')">Billing & Payments</div>
            </div>
            <div class="content">
                <div id="profileContent" class="active">
                    <div class="user-info-container">
                        <div class="user-info">
                            <?php
                            $id_token_data = get_token_data_from_cookie('id_token');
                            if ($id_token_data) {
                                $full_name = $id_token_data['name'];
                                $name_parts = explode(' ', $full_name);
                                $first_name = $name_parts[0]; // First name

                                echo '<h2>Hello ' . htmlspecialchars($first_name) . '!</h2>';

                                if (!empty($profile_picture_url)) {
                                    echo '<img src="' . esc_url($profile_picture_url) . '" class="profile-picture" alt="Profile Picture" />';
                                }
                                echo '<p><strong>Name:</strong> ' . htmlspecialchars($id_token_data['name']) . '</p>';
                                echo '<p><strong>Email:</strong> ' . htmlspecialchars($id_token_data['email']) . '</p>';

                                if (!empty($personas) && is_array($personas)) {
                                    echo '<p><strong>Persona:</strong> ' . implode(', ', array_map('htmlspecialchars', $personas)) . '</p>';
                                } else {
                                    echo '<p>No personas available.</p>';
                                }
                            } else {
                                echo '<p class="error-message">No ID Token found. Please log in.</p>';
                            }
                            ?>
                        </div>
                        <div class="update-profile">
                            <h2>Update Profile Picture</h2>

                            <?php if (!empty($upload_result['message'])): ?>
                                <div class="message <?php echo $upload_result['success'] ? 'success' : 'error'; ?>">
                                    <?php echo esc_html($upload_result['message']); ?>
                                </div>
                            <?php endif; ?>

                            <img src="<?php echo esc_url($profile_picture_url); ?>" alt="Current Profile Picture" class="current-profile-picture" />

                            <form method="post" enctype="multipart/form-data" class="profile-upload-form">
                                <div class="file-input-wrapper">
                                    <label for="profile_picture">Choose a new profile picture:</label>
                                    <input type="file" name="profile_picture" id="profile_picture" accept="image/jpeg,image/png,image/gif" required>
                                </div>
                                <button type="submit" class="submit-button">Update Profile Picture</button>
                            </form>
                        </div>
                    </div>
                </div>

                <?php
                if (isset($courses)) {
                    echo '<div id="coursesContent">';
                    echo display_courses_section($courses);
                    echo '</div>';
                }
                ?>
                <div id="paymentsContent" class="content-section">
                    <?php echo display_payments_section($payments); ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function switchContent(contentName) {
            document.querySelectorAll('.sidebar-item').forEach(function(item) {
                item.classList.remove('active');
            });
            document.querySelectorAll('.content > div').forEach(function(content) {
                content.classList.remove('active');
            });
            document.getElementById(contentName + 'Item').classList.add('active');
            document.getElementById(contentName + 'Content').classList.add('active');
        }
    </script>
    <?php
    return ob_get_clean();
}

add_shortcode('show_oauth_user_info', 'display_user_info');

function remove_theme_wrappers($content) {
    if (has_shortcode($content, 'show_oauth_user_info')) {
        remove_filter('the_content', 'wpautop');
        remove_filter('the_content', 'wptexturize');
    }
    return $content;
}
add_filter('the_content', 'remove_theme_wrappers', 0);
