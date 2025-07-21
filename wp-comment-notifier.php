<?php
/*
Plugin Name: WP-comment-notifier
Description: 在有新评论时，通过企业微信和 Telegram 发送通知，可自定义通知内容
Version: 1.30
Author: Sy-心情如歌 
*/

if (!defined('ABSPATH')) exit;

// 默认通知字段选项
define('WECHAT_COMMENT_NOTIFIER_DEFAULT_FIELDS', [
    'post_title'    => '文章标题',
    'comment_author' => '评论人',
    'comment_time'  => '时间',
    'comment_ip'    => 'IP地址',
    'comment_content' => '内容',
    'comment_link'  => '链接'
]);

// 设置页面注册
add_action('admin_menu', function () {
    add_options_page(
        '评论通知设置',
        'WP-comment-notifier推送设置',
        'manage_options',
        'wechat-comment-notifier',
        'wechat_comment_notifier_settings_page'
    );
});

// 设置页面内容
function wechat_comment_notifier_settings_page() {
    ?>
    <div class="wrap">
        <h1>通知推送设置（企业微信 + Telegram）</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('wechat_comment_notifier_options');
            do_settings_sections('wechat_comment_notifier');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// 注册设置项
add_action('admin_init', function () {
    // Webhook URL 设置
    register_setting('wechat_comment_notifier_options', 'wechat_comment_notifier_webhook');
    register_setting('wechat_comment_notifier_options', 'wechat_comment_notifier_fields');

    // Telegram 设置项
    register_setting('wechat_comment_notifier_options', 'telegram_bot_token');
    register_setting('wechat_comment_notifier_options', 'telegram_chat_id');

    // Webhook 设置部分
    add_settings_section(
        'wechat_section',
        '通知服务设置',
        null,
        'wechat_comment_notifier'
    );

    add_settings_field(
        'wechat_comment_notifier_webhook',
        '企业微信机器人 Webhook URL',
        function () {
            $value = esc_attr(get_option('wechat_comment_notifier_webhook'));
            echo "<input type='text' name='wechat_comment_notifier_webhook' value='$value' style='width: 100%;'>";
        },
        'wechat_comment_notifier',
        'wechat_section'
    );

    add_settings_field(
        'telegram_bot_token',
        'Telegram Bot Token',
        function () {
            $value = esc_attr(get_option('telegram_bot_token'));
            echo "<input type='text' name='telegram_bot_token' value='$value' style='width: 100%;'>";
        },
        'wechat_comment_notifier',
        'wechat_section'
    );

    add_settings_field(
        'telegram_chat_id',
        'Telegram Chat ID',
        function () {
            $value = esc_attr(get_option('telegram_chat_id'));
            echo "<input type='text' name='telegram_chat_id' value='$value' style='width: 100%;'>";
        },
        'wechat_comment_notifier',
        'wechat_section'
    );

    // 通知内容设置部分
    add_settings_section(
        'content_section',
        '通知内容设置',
        function () {
            echo '<p>选择要在通知中包含的字段：</p>';
        },
        'wechat_comment_notifier'
    );

    add_settings_field(
        'wechat_comment_notifier_fields',
        '选择通知字段',
        'wechat_comment_notifier_fields_callback',
        'wechat_comment_notifier',
        'content_section'
    );
});

// 通知字段选择回调
function wechat_comment_notifier_fields_callback() {
    $saved_fields = get_option('wechat_comment_notifier_fields', array_keys(WECHAT_COMMENT_NOTIFIER_DEFAULT_FIELDS));

    foreach (WECHAT_COMMENT_NOTIFIER_DEFAULT_FIELDS as $field => $label) {
        $checked = in_array($field, $saved_fields) ? 'checked' : '';
        echo "<label><input type='checkbox' name='wechat_comment_notifier_fields[]' value='$field' $checked> $label</label><br>";
    }
}

// 获取IP的地理位置信息
function get_ip_location($ip) {
    if (empty($ip) || $ip == '127.0.0.1') {
        return '本地';
    }

    $api_url = "http://ip-api.com/json/{$ip}?fields=status,country,regionName,city&lang=zh-CN";
    $response = wp_remote_get($api_url, ['timeout' => 2]);

    if (is_wp_error($response)) {
        return '未知';
    }

    $data = json_decode($response['body'], true);
    if ($data['status'] == 'success') {
        $location = $data['country'];
        if (!empty($data['regionName'])) {
            $location .= " " . $data['regionName'];
        }
        return $location;
    }

    return '未知';
}

// 发送通知
add_action('comment_post', function ($comment_ID, $comment_approved) {
    if ($comment_approved != 1 && $comment_approved != 0) return;

    $comment = get_comment($comment_ID);
    $post = get_post($comment->comment_post_ID);

    // 获取选中的通知字段
    $enabled_fields = get_option('wechat_comment_notifier_fields', array_keys(WECHAT_COMMENT_NOTIFIER_DEFAULT_FIELDS));

    // 构建通知内容
    $content = "📢 博客有新评论：\n";

    if (in_array('post_title', $enabled_fields)) {
        $content .= "文章：《" . $post->post_title . "》\n";
    }

    if (in_array('comment_author', $enabled_fields)) {
        $content .= "评论人：" . $comment->comment_author . "\n";
    }

    if (in_array('comment_time', $enabled_fields)) {
        $content .= "时间：" . get_comment_date('Y-m-d H:i:s', $comment_ID) . "\n";
    }

    if (in_array('comment_ip', $enabled_fields)) {
        $ip_location = get_ip_location($comment->comment_author_IP);
        $content .= "IP地址：" . $comment->comment_author_IP . "（来自 " . $ip_location . "）\n";
    }

    if (in_array('comment_content', $enabled_fields)) {
        $content .= "内容：" . $comment->comment_content . "\n";
    }

    if (in_array('comment_link', $enabled_fields)) {
        $content .= "链接：" . get_comment_link($comment_ID) . "\n";
    }

    // 发送企业微信通知
    $webhook_url = get_option('wechat_comment_notifier_webhook');
    if ($webhook_url) {
        $payload = json_encode([
            "msgtype" => "text",
            "text" => ["content" => $content]
        ], JSON_UNESCAPED_UNICODE);

        wp_remote_post($webhook_url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $payload,
            'timeout' => 5,
        ]);
    }

    // 发送 Telegram 通知
    $bot_token = get_option('telegram_bot_token');
    $chat_id   = get_option('telegram_chat_id');
    if ($bot_token && $chat_id) {
        $telegram_api = "https://api.telegram.org/bot{$bot_token}/sendMessage";

        $telegram_payload = [
            'chat_id' => $chat_id,
            'text' => $content,
            'parse_mode' => 'Markdown'
        ];

        wp_remote_post($telegram_api, [
            'body' => $telegram_payload,
            'timeout' => 5,
        ]);
    }
}, 10, 2);
