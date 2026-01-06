<?php
require_once 'db_connect.php'; // تأكد من مسار ملف الاتصال

try {
    echo "<h3>--- أعمدة جدول الباقات (packages) ---</h3>";
    $q1 = $pdo->query("DESCRIBE packages");
    while($row = $q1->fetch(PDO::FETCH_ASSOC)) { 
        echo "<b>" . $row['Field'] . "</b> - النوع: " . $row['Type'] . "<br>"; 
    }

    echo "<h3>--- أعمدة جدول الاختيارات (daily_selections) ---</h3>";
    $q2 = $pdo->query("DESCRIBE daily_selections");
    while($row = $q2->fetch(PDO::FETCH_ASSOC)) { 
        echo "<b>" . $row['Field'] . "</b> - النوع: " . $row['Type'] . "<br>"; 
    }
} catch (Exception $e) { 
    echo "خطأ في الفحص: " . $e->getMessage(); 
}
?>