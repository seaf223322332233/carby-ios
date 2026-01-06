<?php
// handle_edit_package.php
// Backend Processor for Editing Packages (With Allowed Weight Fix)
// =============================================================

require_once 'auth_admin.php'; 
require_once 'db_connect.php'; 

// Check if request is POST and form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_package'])) {

    // Validate ID
    if (!isset($_POST['id']) || empty($_POST['id'])) {
        header("Location: view_packages.php?error=missing_id");
        exit;
    }

    $id = (int)$_POST['id'];

    try {
        // Start Transaction
        $pdo->beginTransaction();

        // 1. Collect Basic Data
        $name = trim($_POST['name']);
        $price = (float)$_POST['price'];
        $meals_per_day = (int)$_POST['meals_per_day'];
        $description = trim($_POST['description']);
        $duration_days = (int)$_POST['duration_days'];
        
        // Checkbox handling (is_active)
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // 2. Weight Constraint Logic [FIXED HERE]
        $allowed_weight = 0; // Default open weight
        $fixed_weight_label = NULL;

        // Check if weight type is set to fixed
        if (isset($_POST['weight_type']) && $_POST['weight_type'] == 'fixed') {
            $val = (float)$_POST['fixed_weight_value'];
            if ($val > 0) {
                $allowed_weight = $val;          // Save number (e.g., 150)
                $fixed_weight_label = $val . 'g'; // Save text (e.g., 150g)
            }
        }

        // 3. Off Days Logic
        $off_days_str = "";
        if (isset($_POST['off_days']) && is_array($_POST['off_days'])) {
            $off_days_str = implode(',', $_POST['off_days']);
        }

        // 4. Image Upload Logic
        $image_sql_part = ""; 
        $params = [
            $name, 
            $description, 
            $price, 
            $meals_per_day, 
            $allowed_weight,      // New Numeric Field
            $fixed_weight_label,  // Text Label
            $duration_days, 
            $off_days_str, 
            $is_active
        ];

        // Check if a new image is uploaded
        if (isset($_FILES['package_image']) && $_FILES['package_image']['error'] == 0) {
            $target_dir = "uploads/packages/";
            // Create directory if not exists
            if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }
            
            $file_ext = strtolower(pathinfo($_FILES['package_image']['name'], PATHINFO_EXTENSION));
            $filename = time() . "_" . uniqid() . "." . $file_ext; // Unique filename
            
            if (move_uploaded_file($_FILES['package_image']['tmp_name'], $target_dir . $filename)) {
                $image_sql_part = ", image_url = ?";
                $params[] = $target_dir . $filename;
            }
        }
        
        // Add ID at the end for WHERE clause
        $params[] = $id;

        // 5. Update Packages Table
        $sql = "UPDATE packages SET 
                name=?, 
                description=?, 
                price=?, 
                meals_per_day=?, 
                allowed_weight=?,      -- Updated Column
                fixed_weight_label=?, 
                duration_days=?, 
                off_days=?, 
                is_active=? 
                $image_sql_part 
                WHERE id=?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // 6. Update Category Limits (Delete Old -> Insert New)
        $pdo->prepare("DELETE FROM package_category_limits WHERE package_id = ?")->execute([$id]);

        if (isset($_POST['cat_limits']) && is_array($_POST['cat_limits'])) {
            $stmt_limit = $pdo->prepare("INSERT INTO package_category_limits (package_id, category_id, allowed_count) VALUES (?, ?, ?)");
            
            foreach ($_POST['cat_limits'] as $cat_id => $limit) {
                $limit = (int)$limit;
                if ($limit > 0) { 
                    $stmt_limit->execute([$id, $cat_id, $limit]);
                }
            }
        }

        // 7. Update Option Category Limits (Delete Old -> Insert New)
        try {
            $checkTable = $pdo->query("SHOW TABLES LIKE 'package_option_category_limits'");
            if ($checkTable->rowCount() > 0) {
                $pdo->prepare("DELETE FROM package_option_category_limits WHERE package_id = ?")->execute([$id]);

                if (isset($_POST['optcat_limits']) && is_array($_POST['optcat_limits'])) {
                    $stmt_optcat = $pdo->prepare("INSERT INTO package_option_category_limits (package_id, option_category_id, allowed_count) VALUES (?, ?, ?)");
                    
                    foreach ($_POST['optcat_limits'] as $optcat_id => $limit) {
                        $limit = (int)$limit;
                        if ($limit > 0) { 
                            $stmt_optcat->execute([$id, $optcat_id, $limit]);
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            // جدول غير موجود، نتجاهل الخطأ
        }

        // Commit Transaction
        $pdo->commit();
        
        // Redirect with Success Message
        header("Location: edit_package.php?id=$id&success=update");
        exit;

    } catch (PDOException $e) {
        // Rollback on Error
        $pdo->rollBack();
        
        // Redirect back to Edit page with Error
        header("Location: edit_package.php?id=$id&error=" . urlencode($e->getMessage()));
        exit;
    }
} else {
    // Direct Access Prevention
    header("Location: view_packages.php");
    exit;
}