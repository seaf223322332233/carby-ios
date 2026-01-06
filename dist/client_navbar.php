<?php
$cur = basename($_SERVER['PHP_SELF']);
?>

<div class="sidebar">
    <div class="sidebar-header"><i class="fas fa-utensils"></i> تطبيق وجباتي</div>
    <nav class="sidebar-nav">
        <a href="client_dashboard.php" class="<?php echo ($cur=='client_dashboard.php')?'active':''; ?>">
            <i class="fas fa-home"></i> الرئيسية
        </a>
        <a href="client_package.php" class="<?php echo ($cur=='client_package.php')?'active':''; ?>">
            <i class="fas fa-calendar-alt"></i> إدارة اشتراكي
        </a>
        <a href="menu.php" class="<?php echo ($cur=='menu.php')?'active':''; ?>">
            <i class="fas fa-hamburger"></i> المنيو العام
        </a>
        <a href="my_orders.php" class="<?php echo ($cur=='my_orders.php')?'active':''; ?>">
            <i class="fas fa-shopping-bag"></i> طلباتي
        </a>
        <a href="client_history.php" class="<?php echo ($cur=='client_history.php')?'active':''; ?>">
            <i class="fas fa-history"></i> السجل
        </a>
        <hr style="border-color:rgba(255,255,255,0.1); margin:10px 20px;">
        <a href="logout.php" style="color:#ff6b6b;"><i class="fas fa-sign-out-alt"></i> خروج</a>
    </nav>
</div>

<nav class="mobile-nav">
    <a href="client_dashboard.php" class="nav-item <?php echo ($cur=='client_dashboard.php')?'active':''; ?>">
        <i class="fas fa-home"></i><span>الرئيسية</span>
    </a>
    <a href="client_package.php" class="nav-item <?php echo ($cur=='client_package.php')?'active':''; ?>">
        <i class="fas fa-calendar-alt"></i><span>باقتي</span>
    </a>
    
    <a href="menu.php" class="nav-item center-btn">
        <div class="nav-center"><i class="fas fa-plus"></i></div>
    </a>

    <a href="my_orders.php" class="nav-item <?php echo ($cur=='my_orders.php')?'active':''; ?>">
        <i class="fas fa-shopping-bag"></i><span>طلباتي</span>
    </a>
    <a href="client_history.php" class="nav-item <?php echo ($cur=='client_history.php')?'active':''; ?>">
        <i class="fas fa-history"></i><span>السجل</span>
    </a>
</nav>