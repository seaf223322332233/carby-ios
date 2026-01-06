<?php
// ملف: delete_product.php

// 1. الاتصال والحماية
require_once 'auth_admin.php'; 
require_once 'db_connect.php'; 

// التحقق من وجود المعرف ID
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    
    $product_id = $_GET['id'];

    try {
        // خطوة 1: جلب اسم الصورة لحذفها من السيرفر
        $stmt = $pdo->prepare("SELECT image FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();

        if ($product) {
            // حذف الصورة إذا كانت موجودة
            if (!empty($product['image'])) {
                $image_path = 'uploads/' . $product['image'];
                if (file_exists($image_path)) {
                    unlink($image_path); // حذف الملف الفعلي
                }
            }

            // خطوة 2: حذف السجل من قاعدة البيانات
            // ملاحظة: سيتم حذف الخيارات (Options) تلقائياً بفضل خاصية CASCADE في قاعدة البيانات
            $delete_stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $delete_stmt->execute([$product_id]);

            // العودة لصفحة الإدارة مع رسالة نجاح
            header("Location: manage_products.php?status=deleted");
            exit;
        } else {
            // المنتج غير موجود أصلاً
            header("Location: manage_products.php?status=error");
            exit;
        }

    } catch (PDOException $e) {
        die("خطأ في الحذف: " . $e->getMessage());
    }

} else {
    // إذا لم يتم تمرير ID
    header("Location: manage_products.php");
    exit;
}
?>