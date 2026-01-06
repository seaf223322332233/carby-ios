<?php
// modern-sidebar.php
$current_page = basename($_SERVER['PHP_SELF']);
$h = date('H');
$greet = ($h < 12) ? 'صباح الخير ☀️' : 'مساء الخير 🌙';
?>

<div class="modern-sidebar">
    <!-- العلامة التجارية -->
    <div class="sidebar-brand">
        <div class="brand-logo">
            <i class="fas fa-utensils"></i>
        </div>
        <h2 class="brand-name">وجباتي</h2>
        <p class="brand-tagline">صحتك تهمنا</p>
    </div>
    
    <!-- القائمة الرئيسية -->
    <nav class="sidebar-menu">
        <a href="client_dashboard.php" 
           class="menu-item <?php echo $current_page == 'client_dashboard.php' ? 'active' : ''; ?>">
            <div class="menu-icon">
                <i class="fas fa-home"></i>
            </div>
            <span class="menu-text">الرئيسية</span>
            <div class="menu-indicator"></div>
        </a>
        
        <a href="client_package.php" 
           class="menu-item <?php echo $current_page == 'client_package.php' ? 'active' : ''; ?>">
            <div class="menu-icon">
                <i class="fas fa-calendar-alt"></i>
            </div>
            <span class="menu-text">إدارة الاشتراك</span>
            <div class="menu-indicator"></div>
        </a>
        
        <a href="menu.php" 
           class="menu-item <?php echo $current_page == 'menu.php' ? 'active' : ''; ?>">
            <div class="menu-icon">
                <i class="fas fa-hamburger"></i>
            </div>
            <span class="menu-text">المنيو</span>
            <div class="menu-indicator"></div>
        </a>
        
        <a href="my_orders.php" 
           class="menu-item <?php echo $current_page == 'my_orders.php' ? 'active' : ''; ?>">
            <div class="menu-icon">
                <i class="fas fa-shopping-bag"></i>
            </div>
            <span class="menu-text">طلباتي</span>
            <div class="menu-indicator"></div>
        </a>
        
        <a href="client_history.php" 
           class="menu-item <?php echo $current_page == 'client_history.php' ? 'active' : ''; ?>">
            <div class="menu-icon">
                <i class="fas fa-history"></i>
            </div>
            <span class="menu-text">السجل</span>
            <div class="menu-indicator"></div>
        </a>
        
        <a href="cart.php" 
           class="menu-item <?php echo $current_page == 'cart.php' ? 'active' : ''; ?>">
            <div class="menu-icon">
                <i class="fas fa-shopping-cart"></i>
            </div>
            <span class="menu-text">سلة المشتريات</span>
            <div class="menu-indicator"></div>
        </a>
    </nav>
    
    <!-- المستخدم والمعلومات -->
    <div class="sidebar-footer">
        <div class="user-profile">
            <div class="profile-avatar">
                <?php if(!empty($_SESSION['profile_pic'])): ?>
                    <img src="<?php echo htmlspecialchars($_SESSION['profile_pic']); ?>" alt="صورة المستخدم">
                <?php else: ?>
                    <i class="fas fa-user"></i>
                <?php endif; ?>
            </div>
            <div class="profile-info">
                <span class="profile-name"><?php echo htmlspecialchars(explode(' ', $_SESSION['name'])[0]); ?></span>
                <span class="profile-status"><?php echo $greet; ?></span>
            </div>
            <a href="logout.php" class="logout-btn" title="تسجيل الخروج">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </div>
</div>

<!-- شريط التنقل للهواتف -->
<nav class="mobile-nav">
    <a href="client_dashboard.php" 
       class="nav-item <?php echo $current_page == 'client_dashboard.php' ? 'active' : ''; ?>">
        <i class="fas fa-home"></i>
        <span class="nav-label">الرئيسية</span>
    </a>
    
    <a href="client_package.php" 
       class="nav-item <?php echo $current_page == 'client_package.php' ? 'active' : ''; ?>">
        <i class="fas fa-calendar-alt"></i>
        <span class="nav-label">باقتي</span>
    </a>
    
    <a href="menu.php" 
       class="nav-item center-btn <?php echo $current_page == 'menu.php' ? 'active' : ''; ?>">
        <div class="nav-fab">
            <i class="fas fa-plus"></i>
        </div>
    </a>
    
    <a href="my_orders.php" 
       class="nav-item <?php echo $current_page == 'my_orders.php' ? 'active' : ''; ?>">
        <i class="fas fa-shopping-bag"></i>
        <span class="nav-label">طلباتي</span>
    </a>
    
    <a href="client_history.php" 
       class="nav-item <?php echo $current_page == 'client_history.php' ? 'active' : ''; ?>">
        <i class="fas fa-history"></i>
        <span class="nav-label">السجل</span>
    </a>
</nav>