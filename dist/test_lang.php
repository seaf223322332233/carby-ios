<?php
/**
 * ملف اختبار زر اللغة
 * استخدم هذا الملف لاختبار زر اللغة
 */

// يجب استدعاء lang_handler.php قبل أي header() أو output
require_once 'lang_handler.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>اختبار زر اللغة</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 50px;
            background: #f5f5f5;
        }
        .lang-switcher {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 12px;
            background: rgba(108, 92, 231, 0.1);
            border: 1px solid rgba(108, 92, 231, 0.2);
            color: #6c5ce7;
            text-decoration: none;
            font-weight: 900;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        .lang-switcher:hover {
            background: rgba(108, 92, 231, 0.2);
            transform: translateY(-2px);
        }
        .info {
            margin-top: 20px;
            padding: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <h1>اختبار زر اللغة</h1>
    
    <div>
        <?php echo langSwitcher(); ?>
    </div>
    
    <div class="info">
        <h2>معلومات الجلسة:</h2>
        <p><strong>اللغة الحالية:</strong> <?php echo $currentLang ?? 'ar'; ?></p>
        <p><strong>Session ID:</strong> <?php echo session_id(); ?></p>
        <p><strong>$_SESSION['lang']:</strong> <?php echo $_SESSION['lang'] ?? 'غير محدد'; ?></p>
        <p><strong>$_GET['lang']:</strong> <?php echo $_GET['lang'] ?? 'غير موجود'; ?></p>
    </div>
    
    <div class="info" style="margin-top: 20px;">
        <h2>اختبار الروابط:</h2>
        <p><a href="?lang=ar">التبديل إلى العربية</a></p>
        <p><a href="?lang=en">Switch to English</a></p>
    </div>
</body>
</html>

