<?php

namespace FluentComments\App\Hooks\Handlers;

class ShortcodeHandler
{
    public function register()
    {
        add_shortcode('fluent_comments', array($this, 'handleShortcode'));
        add_action('comment_form', function () {
            return;
            global $post;
            if (is_admin() || !is_singular() || !comments_open($post)) {
                return;
            }
            ?>
            <script>
                window.flc_post_id = <?php echo $post->ID; ?>;
            </script>
            <?php
            $this->initAssets();
        });

        add_filter('comments_template', function ($file) {
            return FLUENT_COMMENTS_PLUGIN_PATH . 'app/Views/comments.php';
        });

        add_action('wp_enqueue_scripts', function () {
            if (is_admin() || !is_singular()) {
                return;
            }

            wp_enqueue_style('fluent_comments', FLUENT_COMMENTS_PLUGIN_URL . 'dist/css/app.css', [], time(), 'all');

            if (comments_open()) {
                wp_enqueue_script('fluent_comments', FLUENT_COMMENTS_PLUGIN_URL . 'dist/js/native-comments.js', [], FLUENT_COMMENTS_VERSION, true);

                wp_localize_script('fluent_comments', 'fluentCommentPublic', [
                    'ajaxurl' => admin_url('admin-ajax.php')
                ]);
            }

        });

        add_action('wp_ajax_fluent_comment_post', [$this, 'handleAjaxComment']);
        add_action('wp_ajax_nopriv_fluent_comment_post', [$this, 'handleAjaxComment']);

        add_action('wp_ajax_fluent_comment_comment_token', [$this, 'handleAjaxCommentToken']);
        add_action('wp_ajax_nopriv_fluent_comment_comment_token', [$this, 'handleAjaxCommentToken']);

        add_filter('pre_comment_approved', [$this, 'checkForSecurityToken'], 10, 2);
    }

    public function handleAjaxComment()
    {
        $postId = (int)$_REQUEST['comment_post_ID'];

        $post = get_post($postId);

        if (!$post || !comments_open($post)) {
            wp_send_json([
                'message' => 'Sorry, this post does not allow new comments'
            ], 423);
        }

        $comment = wp_handle_comment_submission(wp_unslash($_REQUEST));

        if (is_wp_error($comment)) {
            wp_send_json([
                'message' => $comment->get_error_message()
            ], 423);
        }

        wp_send_json([
            'comment_id'      => $comment->comment_ID,
            'comment_preview' => $this->commentPreview($comment)
        ], 200);
    }

    public function checkForSecurityToken($approved, $commendData)
    {
        if(is_wp_error($approved)) {
            return $approved;
        }

        if(current_user_can('moderate_comments')) {
            return $approved;
        }

        if(empty($_REQUEST['_fluent_comment_s_token'])) {
            return new \WP_Error('fluent_comment_s_token', 'Invalid Security Token');
        }

        $token = $this->encryptDecrypt(sanitize_text_field($_REQUEST['_fluent_comment_s_token']), 'decrypt');

        if (!$token) {
            return new \WP_Error('fluent_comment_s_token', 'Invalid Security Token');
        }

        $tokenParts = explode('|', $token);

        if (count($tokenParts) !== 2) {
            return new \WP_Error('fluent_comment_s_token', 'Invalid Security Token');
        }

        $timeStamp = $tokenParts[0];
        $tokenPostId = $tokenParts[1];

        if (time() - $timeStamp > 300) {
            return new \WP_Error('fluent_comment_s_token', 'Token expired');
        }

        if ($tokenPostId != $commendData['comment_post_ID']) {
            return new \WP_Error('fluent_comment_s_token', 'Invalid post id on security token');
        }

        return $approved;

    }

    public function handleShortcode()
    {
        $postId = get_the_ID();
        return $this->render($postId);
    }

    public function render($postId)
    {
        $this->initAssets();
        return '<div data-post_id="' . $postId . '" class="fluent_dynamic_comments" ><h3 style="text-align: center;">Loading..</h3></div>';
    }

    public function handleAjaxCommentToken()
    {
        $postId = (int)$_REQUEST['comment_post_ID'];

        if (!$postId) {
            wp_send_json([
                'message' => 'Invalid post id'
            ], 423);
        }

        $token = time() . '|' . $postId;
        wp_send_json([
            'token' => $this->encryptDecrypt($token)
        ], 200);
    }

    private function initAssets()
    {
        static $loaded;

        if ($loaded) {
            return;
        }

        $loaded = true;

        wp_enqueue_script('fluent_comments', FLUENT_COMMENTS_PLUGIN_URL . 'dist/js/app.js', [], FLUENT_COMMENTS_VERSION, true);

        $vars = [
            'slug'        => 'fluent-comments',
            'nonce'       => wp_create_nonce('fluent-comments'),
            'rest'        => [
                'base_url'  => esc_url_raw(rest_url()),
                'url'       => rest_url('fluent-comments'),
                'nonce'     => wp_create_nonce('wp_rest'),
                'namespace' => 'fluent-comments',
                'version'   => '1'
            ],
            'i18n'        => [
                'Dashboard' => __('Dashboard', 'fluent-comments'),
                'Docs'      => __('Docs', 'fluent-comments'),
            ],
            'user_avatar' => 'https://secure.gravatar.com/avatar/?s=96&d=mm&r=g'
        ];

        if (get_current_user_id()) {
            $currentUser = wp_get_current_user();
            $vars['me'] = [
                'id'        => $currentUser->ID,
                'full_name' => trim($currentUser->first_name . ' ' . $currentUser->last_name),
                'email'     => $currentUser->user_email,
                'avatar'    => get_avatar_url($currentUser->user_email)
            ];
            $vars['user_avatar'] = $vars['me']['avatar'];
        }

        wp_localize_script('fluent_comments', 'fluentCommentVars', $vars);
    }

    private function commentPreview($comment)
    {
        ob_start();

        $avatar = get_avatar($comment, 64);
        $comment_author = get_comment_author($comment);
        ?>
        <div id="comment-<?php echo (int)$comment->comment_ID; ?>" class="flc_comment fls_new_comment">
            <article class="flc_body">
                <div class="flc_avatar">
                    <div class="flc_comment_author">
                        <?php echo wp_kses_post($avatar); ?>
                    </div>
                </div>
                <div class="flc_comment__details">
                    <div class="crayons-card">
                        <div class="comment__header">
                            <b class="fn"><?php echo esc_html($comment_author); ?></b>
                        </div>
                        <div class="flc_comment-content">
                            <?php
                            echo wp_kses_post(wpautop(apply_filters('get_comment_text', $comment->comment_content, $comment)));
                            if ('0' === $comment->comment_approved) {
                                ?>
                                <p class="comment-awaiting-moderation"><?php _e('Your comment is awaiting moderation.', 'fluent-comments'); ?></p>
                                <?php
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </article>
        </div>
        <?php
        return ob_get_clean();
    }

    private function encryptDecrypt($string, $action = 'encrypt')
    {
        // you may change these values to your own
        $secret_key = 'my_simple_secret_key';
        $secret_iv = 'my_simple_secret_iv';

        $output = false;
        $encrypt_method = "AES-256-CBC";
        $key = hash('sha256', $secret_key);
        $iv = substr(hash('sha256', $secret_iv), 0, 16);

        if ($action == 'encrypt') {
            $output = openssl_encrypt($string, $encrypt_method, $key, 0, $iv);
            $output = base64_encode($output);
        } else if ($action == 'decrypt') {
            $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);
        }

        return $output;
    }
}
