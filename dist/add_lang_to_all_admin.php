<?php
/**
 * ملف مساعد لإضافة زر اللغة إلى جميع صفحات الأدمن
 * استخدم هذا الملف كمرجع لإضافة زر اللغة يدوياً
 */

// في بداية كل صفحة أدمن، أضف:
require_once 'lang_handler.php';

// في header أو top-bar، أضف:
<?php echo langSwitcher(); ?>

// مثال في top-bar:
<div style="display:flex; gap:10px; align-items:center;">
    <?php echo langSwitcher(); ?>
    <a href="logout.php" class="logout-link">...</a>
</div>

