<?php
// handle_product.php - محرك المنتجات (ADD/EDIT/DELETE) + ربط الخيارات المصنفة (متوافق مع product_options الحالي)
// =========================================================================================

require_once 'db_connect.php';
if (file_exists('auth_admin.php')) require_once 'auth_admin.php';

header('Content-Type: text/html; charset=utf-8');

// ------------------------------
// إعدادات رفع الصور
// ------------------------------
const UPLOAD_DIR   = 'uploads/';
const MAX_IMAGE_MB = 6;

// ------------------------------
// Helpers
// ------------------------------
function ensureUploadsDir(): void {
    if (!file_exists(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
        chmod(UPLOAD_DIR, 0755);
    }
}

function uploadImage(array $file): ?string {
    ensureUploadsDir();

    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return null;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;

    $maxBytes = MAX_IMAGE_MB * 1024 * 1024;
    if (!empty($file['size']) && (int)$file['size'] > $maxBytes) return null;

    // تحقق أنها صورة
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) return null;

    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $valid = ['jpg','jpeg','png','gif','webp'];
    if (!in_array($ext, $valid, true)) return null;

    $newName = uniqid('meal_', true) . '.' . $ext;
    $target  = UPLOAD_DIR . $newName;

    return move_uploaded_file($file['tmp_name'], $target) ? $newName : null;
}

function deleteImageIfExists(?string $filename): void {
    if (!$filename) return;
    $path = UPLOAD_DIR . $filename;
    if (file_exists($path)) @unlink($path);
}

function normalizePrice($val): ?float {
    if (!isset($val)) return null;
    $val = trim((string)$val);
    if ($val === '') return null;
    $val = str_replace(',', '.', $val);
    return is_numeric($val) ? (float)$val : null;
}

function normalizeQty($val, $fallback = 1): string {
    // جدولك quantity نوعه varchar، فنرجع string دائمًا
    if (!isset($val)) return (string)$fallback;
    $val = trim((string)$val);
    if ($val === '' || !is_numeric($val)) return (string)$fallback;
    $q = (int)$val;
    if ($q <= 0) $q = (int)$fallback;
    return (string)$q;
}

/**
 * استخراج أول Size صالح (price/weight/calories) لحفظه في products للعرض السريع
 */
function pickFirstValidSize(array $sizes): array {
    foreach ($sizes as $s) {
        $w = isset($s['weight']) ? (float)$s['weight'] : 0;
        $p = isset($s['price'])  ? (float)$s['price']  : 0;
        if ($w > 0 && $p > 0) {
            return [
                'price'    => $p,
                'weight'   => $w,
                'calories' => (float)($s['calories'] ?? 0),
            ];
        }
    }
    return ['price' => 0, 'weight' => 0, 'calories' => 0];
}

/**
 * يدعم:
 * - الجديد: linked_options[id][checked] + linked_options[id][price_override]
 * - القديم: linked_options[id][checked] + linked_options[id][qty] + linked_options[id][price]
 */
function parseLinkedOptions(array $linked_options): array {
    $out = [];
    foreach ($linked_options as $gid => $data) {
        $gid = (int)$gid;
        if ($gid <= 0 || !is_array($data)) continue;

        $checked = isset($data['checked']) && (int)$data['checked'] === 1;
        if (!$checked) continue;

        // quantity (افتراضي 1)
        $qty = '1';
        if (isset($data['qty']))      $qty = normalizeQty($data['qty'], 1);
        if (isset($data['quantity'])) $qty = normalizeQty($data['quantity'], (int)$qty);

        // price override (الجديد أو القديم)
        $priceOverride = null;
        if (array_key_exists('price_override', $data)) {
            $priceOverride = normalizePrice($data['price_override']);
        } elseif (array_key_exists('price', $data)) {
            $priceOverride = normalizePrice($data['price']);
        }

        $out[] = [
            'global_option_id' => $gid,
            'quantity'         => $qty,
            'price_override'   => $priceOverride, // NULL أو رقم
        ];
    }
    return $out;
}

// ------------------------------
// ROUTER
// ------------------------------
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ====================================================
// 1) ADD
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add') {
    try {
        $pdo->beginTransaction();

        $name    = trim($_POST['name'] ?? '');
        $cat_id  = (int)($_POST['category_id'] ?? 0);
        $desc    = (string)($_POST['description'] ?? '');
        $barcode = trim((string)($_POST['barcode'] ?? ''));
        // معالجة stock_qty: -1 = لا محدود، 0 = غير متوفر، > 0 = الكمية المتاحة
        $stock_raw = $_POST['stock_qty'] ?? '0';
        $stock = ($stock_raw === '-1' || $stock_raw === -1) ? -1 : (int)$stock_raw;

        if ($name === '' || $cat_id <= 0) {
            throw new Exception("بيانات المنتج غير مكتملة (الاسم/التصنيف).");
        }

        $sizes = (isset($_POST['sizes']) && is_array($_POST['sizes'])) ? $_POST['sizes'] : [];
        $firstSize = pickFirstValidSize($sizes);

        // رفع الصورة
        $imagePath = '';
        if (isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $img = uploadImage($_FILES['image']);
            if ($img) $imagePath = $img;
        }

        // إدخال المنتج
        $stmt = $pdo->prepare("
            INSERT INTO products (name, description, category_id, image, price, weight, calories, barcode, stock_qty)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $name,
            $desc,
            $cat_id,
            $imagePath,
            (float)$firstSize['price'],
            (float)$firstSize['weight'],
            (float)$firstSize['calories'],
            $barcode,
            $stock
        ]);

        $product_id = (int)$pdo->lastInsertId();

        // إدخال الأحجام
        if (!empty($sizes)) {
            $stmtSize = $pdo->prepare("
                INSERT INTO product_sizes (product_id, size_name, weight, unit, price, calories, protein, carbs, fats)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($sizes as $s) {
                $weight = isset($s['weight']) ? (float)$s['weight'] : 0;
                $price  = isset($s['price'])  ? (float)$s['price']  : 0;
                if ($weight <= 0 || $price <= 0) continue;

                $stmtSize->execute([
                    $product_id,
                    trim((string)($s['size_name'] ?? 'افتراضي')) ?: 'افتراضي',
                    $weight,
                    (string)($s['unit'] ?? 'gram'),
                    $price,
                    (float)($s['calories'] ?? 0),
                    (float)($s['protein'] ?? 0),
                    (float)($s['carbs'] ?? 0),
                    (float)($s['fats'] ?? 0),
                ]);
            }
        }

        // ربط الخيارات (product_options)
        if (!empty($_POST['linked_options']) && is_array($_POST['linked_options'])) {
            $rows = parseLinkedOptions($_POST['linked_options']);
            if (!empty($rows)) {

                // ✅ متوافق مع جدولك: price + price_override + quantity(varchar)
                $stmtOpt = $pdo->prepare("
                    INSERT INTO product_options (product_id, global_option_id, quantity, price, price_override, is_active)
                    VALUES (?, ?, ?, ?, ?, 1)
                ");

                foreach ($rows as $r) {
                    $override = $r['price_override']; // NULL أو رقم
                    $stmtOpt->execute([
                        $product_id,
                        (int)$r['global_option_id'],
                        (string)$r['quantity'],
                        $override,        // نخزنها أيضًا في price للتوافق مع أي صفحة تقرأ price
                        $override,        // العمود الرسمي للـ override
                    ]);
                }
            }
        }

        $pdo->commit();
        header("Location: manage_products.php?success=added");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        die("حدث خطأ أثناء الحفظ: " . $e->getMessage());
    }
}

// ====================================================
// 2) DELETE
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];

    try {
        $pdo->beginTransaction();

        $stmtImg = $pdo->prepare("SELECT image FROM products WHERE id = ?");
        $stmtImg->execute([$id]);
        $img = $stmtImg->fetchColumn();

        $pdo->prepare("DELETE FROM product_sizes WHERE product_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM product_options WHERE product_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);

        $pdo->commit();

        deleteImageIfExists($img ?: null);

        header("Location: manage_products.php?success=deleted");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        die("خطأ في الحذف: " . $e->getMessage());
    }
}

// ====================================================
// 3) EDIT
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit') {
    try {
        $pdo->beginTransaction();

        $id      = (int)($_POST['product_id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $cat_id  = (int)($_POST['category_id'] ?? 0);
        $desc    = (string)($_POST['description'] ?? '');
        $barcode = trim((string)($_POST['barcode'] ?? ''));
        // معالجة stock_qty: -1 = لا محدود، 0 = غير متوفر، > 0 = الكمية المتاحة
        $stock_raw = $_POST['stock_qty'] ?? '0';
        $stock = ($stock_raw === '-1' || $stock_raw === -1) ? -1 : (int)$stock_raw;

        if ($id <= 0 || $name === '' || $cat_id <= 0) {
            throw new Exception("بيانات التعديل غير مكتملة (id/الاسم/التصنيف).");
        }

        // الصورة القديمة من DB
        $stmtOld = $pdo->prepare("SELECT image FROM products WHERE id = ?");
        $stmtOld->execute([$id]);
        $oldImageFromDb = (string)($stmtOld->fetchColumn() ?? '');

        // الصورة الجديدة (إن وجدت)
        $imagePath    = $oldImageFromDb;
        $uploadedNew  = false;
        $newImageName = null;

        if (isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && !empty($_FILES['image']['name'])) {
            $newImageName = uploadImage($_FILES['image']);
            if ($newImageName) {
                $imagePath   = $newImageName;
                $uploadedNew = true;
            }
        }

        $sizes = (isset($_POST['sizes']) && is_array($_POST['sizes'])) ? $_POST['sizes'] : [];
        $firstSize = pickFirstValidSize($sizes);

        // تحديث المنتج
        $stmt = $pdo->prepare("
            UPDATE products SET
                name = ?, description = ?, category_id = ?, image = ?,
                barcode = ?, stock_qty = ?,
                price = ?, weight = ?, calories = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $name,
            $desc,
            $cat_id,
            $imagePath,
            $barcode,
            $stock,
            (float)$firstSize['price'],
            (float)$firstSize['weight'],
            (float)$firstSize['calories'],
            $id
        ]);

        // تحديث الأحجام
        $pdo->prepare("DELETE FROM product_sizes WHERE product_id = ?")->execute([$id]);

        if (!empty($sizes)) {
            $stmtSize = $pdo->prepare("
                INSERT INTO product_sizes (product_id, size_name, weight, unit, price, calories, protein, carbs, fats)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($sizes as $s) {
                $weight = isset($s['weight']) ? (float)$s['weight'] : 0;
                $price  = isset($s['price'])  ? (float)$s['price']  : 0;
                if ($weight <= 0 || $price <= 0) continue;

                $stmtSize->execute([
                    $id,
                    trim((string)($s['size_name'] ?? 'افتراضي')) ?: 'افتراضي',
                    $weight,
                    (string)($s['unit'] ?? 'gram'),
                    $price,
                    (float)($s['calories'] ?? 0),
                    (float)($s['protein'] ?? 0),
                    (float)($s['carbs'] ?? 0),
                    (float)($s['fats'] ?? 0),
                ]);
            }
        }

        // تحديث روابط الخيارات
        $pdo->prepare("DELETE FROM product_options WHERE product_id = ?")->execute([$id]);

        if (!empty($_POST['linked_options']) && is_array($_POST['linked_options'])) {
            $rows = parseLinkedOptions($_POST['linked_options']);
            if (!empty($rows)) {

                $stmtOpt = $pdo->prepare("
                    INSERT INTO product_options (product_id, global_option_id, quantity, price, price_override, is_active)
                    VALUES (?, ?, ?, ?, ?, 1)
                ");

                foreach ($rows as $r) {
                    $override = $r['price_override'];
                    $stmtOpt->execute([
                        $id,
                        (int)$r['global_option_id'],
                        (string)$r['quantity'],
                        $override,
                        $override
                    ]);
                }
            }
        }

        $pdo->commit();

        // بعد نجاح الحفظ: لو رفعنا صورة جديدة نحذف القديمة
        if ($uploadedNew && $oldImageFromDb && $oldImageFromDb !== $newImageName) {
            deleteImageIfExists($oldImageFromDb);
        }

        header("Location: manage_products.php?success=edited");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        die("حدث خطأ أثناء التعديل: " . $e->getMessage());
    }
}

// أي وصول مباشر
header("Location: manage_products.php");
exit;