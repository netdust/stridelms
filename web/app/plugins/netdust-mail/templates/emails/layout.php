<?php
/**
 * Default Email Layout
 *
 * Variables available:
 * - $content - The parsed email body
 * - $subject - The email subject
 */
defined('ABSPATH') || exit;

$site_name = get_bloginfo('name');
$site_url = home_url();
$year = date('Y');
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr(get_locale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($subject); ?></title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            font-size: 16px;
            line-height: 1.6;
            color: #333333;
        }
        .wrapper {
            width: 100%;
            table-layout: fixed;
            background-color: #f4f4f4;
            padding: 40px 0;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .header {
            background-color: #4F46E5;
            color: #ffffff;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .content {
            padding: 40px 30px;
        }
        .content h1, .content h2, .content h3 {
            color: #1d2327;
            margin-top: 0;
        }
        .content a {
            color: #4F46E5;
        }
        .content .button {
            display: inline-block;
            background-color: #4F46E5;
            color: #ffffff !important;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 500;
            margin: 16px 0;
        }
        .content .button:hover {
            background-color: #4338CA;
        }
        .footer {
            background-color: #f0f0f1;
            padding: 20px 30px;
            text-align: center;
            font-size: 13px;
            color: #646970;
        }
        .footer a {
            color: #646970;
        }
        @media only screen and (max-width: 600px) {
            .content {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <div class="header">
                <h1><?php echo esc_html($site_name); ?></h1>
            </div>
            <div class="content">
                <?php echo $content; // Already sanitized by wp_kses_post in body field ?>
            </div>
            <div class="footer">
                <p>
                    &copy; <?php echo esc_html($year); ?> <?php echo esc_html($site_name); ?><br>
                    <a href="<?php echo esc_url($site_url); ?>"><?php echo esc_html($site_url); ?></a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
