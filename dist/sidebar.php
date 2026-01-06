<div class="sidebar">
    <div class="sidebar-header">
        <h3><i class="fas fa-cogs"></i> لوحة التحكم</h3>
    </div>
    
    <nav class="sidebar-nav">
        <?php 
            // 1. جلب اسم الصفحة الحالية
            $currentPage = basename($_SERVER['PHP_SELF']); 
            
            // 2. تحديد المجموعات (لإبقاء القائمة نشطة عند دخول الصفحات الفرعية)
            $isPackageSection = in_array($currentPage, ['view_packages.php', 'edit_package.php']);
            $isBranchSection = in_array($currentPage, ['view_branches.php', 'edit_branch.php']);
            
            // مجموعة المنتجات (الجديدة)
            $isProductSection = in_array($currentPage, ['manage_products.php', 'add_product.php', 'edit_product.php']);
            
            // مجموعة التصنيفات (الجديدة)
            $isCatSection = in_array($currentPage, ['manage_categories.php', 'edit_category.php']);
            
            $isClientSection = in_array($currentPage, ['view_clients.php', 'add_client.php', 'edit_client.php']);
            $isStaffSection = in_array($currentPage, ['view_staff.php', 'add_staff.php', 'edit_staff.php']);
        ?>
        
        <a href="admin_dashboard.php" class="<?php echo ($currentPage == 'admin_dashboard.php') ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i> الرئيسية
        </a>
        
        <?php
        // التحقق من إعداد قسم الطلبات
        $orders_enabled = true;
        try {
            if (!isset($pdo)) {
                require_once 'db_connect.php';
            }
            if (isset($pdo)) {
                $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='orders_section_enabled' LIMIT 1");
                $st->execute();
                $val = $st->fetchColumn();
                $orders_enabled = ($val === false || $val === null || $val === '') ? true : ($val === '1');
            }
        } catch(Exception $e) {
            $orders_enabled = true;
        }
        if ($orders_enabled):
        ?>
        <a href="admin_orders.php" class="<?php echo ($currentPage == 'admin_orders.php') ? 'active' : ''; ?>">
            <i class="fas fa-clipboard-list"></i> إدارة الطلبات
        </a>
        <?php endif; ?>
        
        <hr style="border-color: #4a637c;">
        
        <a href="manage_categories.php" class="<?php echo ($isCatSection) ? 'active' : ''; ?>">
            <i class="fas fa-tags"></i> إدارة التصنيفات
        </a>
        
        <a href="manage_products.php" class="<?php echo ($isProductSection && $currentPage != 'add_product.php') ? 'active' : ''; ?>">
            <i class="fas fa-boxes"></i> قائمة المنتجات
        </a>
        
        <a href="add_product.php" class="<?php echo ($currentPage == 'add_product.php') ? 'active' : ''; ?>">
            <i class="fas fa-plus-circle"></i> إضافة منتج جديد
        </a>
        <a href="manage_options.php"><i class="fas fa-cubes"></i> إدارة الخيارات</a>
        <hr style="border-color: #4a637c;">
        <li>
  <a href="subscriptions_center.php">
    <i class="fas fa-id-card"></i>
    <span>إدارة الاشتراكات</span>
  </a>
</li>
        <a href="view_packages.php" class="<?php echo ($isPackageSection) ? 'active' : ''; ?>">
            <i class="fas fa-box-open"></i> باقات الاشتراك
        </a>
        <li>
    <a href="marketing_center.php">
        <i class="fas fa-bullhorn"></i>
        <span>مركز التسويق</span>
    </a>
</li>
        <hr style="border-color: #4a637c;">

        <a href="payment_settings.php" class="<?php echo ($currentPage == 'payment_settings.php') ? 'active' : ''; ?>">
            <i class="fas fa-credit-card"></i> إعدادات الدفع
        </a>
        <a href="view_branches.php" class="<?php echo ($isBranchSection) ? 'active' : ''; ?>">
            <i class="fas fa-store"></i> إدارة الفروع
        </a>
        
        <hr style="border-color: #4a637c;">
        
        <a href="view_clients.php" class="<?php echo ($isClientSection) ? 'active' : ''; ?>">
            <i class="fas fa-users"></i> العملاء
        </a> 
        
        <a href="view_staff.php" class="<?php echo ($isStaffSection) ? 'active' : ''; ?>">
            <i class="fas fa-user-shield"></i> الموظفين
        </a>
   
   <hr style="border-color: #4a637c;">
        
        <a href="system_settings.php" class="<?php echo ($currentPage == 'system_settings.php') ? 'active' : ''; ?>">
            <i class="fas fa-tools"></i> إعدادات النظام
        </a>
   
    </nav>
    
</div>