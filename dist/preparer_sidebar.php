<?php $cur_page = basename($_SERVER['PHP_SELF']); ?>
<div class="sidebar preparer-sidebar">
    <div class="sidebar-header">📦 التغليف</div>
    <nav class="sidebar-nav">
        <a href="preparer_dashboard.php" class="<?php echo ($cur_page=='preparer_dashboard.php')?'active':''; ?>">
            <i class="fas fa-box-open"></i> تغليف الاشتراكات
        </a>
        <a href="preparer_menu.php" class="<?php echo ($cur_page=='preparer_menu.php')?'active':''; ?>">
            <i class="fas fa-hamburger"></i> تغليف المنيو
        </a>
        <hr style="border-color: rgba(255,255,255,0.1);">
        <a href="logout.php"><i class="fas fa-sign-out-alt"></i> خروج</a>
    </nav>
</div>