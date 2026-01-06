<?php $cur_page = basename($_SERVER['PHP_SELF']); ?>
<div class="sidebar chef-sidebar">
    <div class="sidebar-header"><i class="fas fa-utensils"></i> المطبخ</div>
    <nav class="sidebar-nav">
        <a href="chef_dashboard.php" class="<?php echo ($cur_page=='chef_dashboard.php')?'active':''; ?>">
            <i class="fas fa-calendar-alt"></i> طلبات الاشتراكات
        </a>
        <a href="chef_menu_orders.php" class="<?php echo ($cur_page=='chef_menu_orders.php')?'active':''; ?>">
            <i class="fas fa-hamburger"></i> طلبات المنيو
        </a>
        <hr style="border-color: rgba(255,255,255,0.1);">
        <a href="logout.php"><i class="fas fa-sign-out-alt"></i> خروج</a>
    </nav>
</div>