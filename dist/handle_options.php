<?php
// handle_options.php - متوافق مع edit_option.php الجديد (Nutrition per Tier + Auto-serving + Stay on Edit)
// ================================================================================================

require_once 'auth_admin.php';
require_once 'db_connect.php';

function redirect_to_manage($q = ""): void {
    header("Location: manage_options.php" . ($q ? ("?$q") : ""));
    exit;
}

function redirect_to_edit(int $id, int $saved = 0): void {
    header("Location: edit_option.php?id=" . $id . ($saved ? "&saved=1" : ""));
    exit;
}

function clean_text($v): string { return trim((string)$v); }
function clean_float($v): float {
    if ($v === null) return 0.0;
    $v = trim((string)$v);
    if ($v === '') return 0.0;
    $v = str_replace(',', '.', $v);
    return is_numeric($v) ? (float)$v : 0.0;
}
function is_auto_serving_unit(string $unit): bool { return $unit === 'gram'; }

// =========================
// POST Actions
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    // -------- toggle active --------
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) redirect_to_manage("error=bad_id");

        $pdo->prepare("UPDATE global_options SET is_active = IF(is_active=1,0,1) WHERE id=?")
            ->execute([$id]);

        redirect_to_manage("success=toggled");
    }

    // -------- add / edit --------
    if ($action === 'add' || $action === 'edit') {

        $id = (int)($_POST['id'] ?? 0);

        $name = clean_text($_POST['name'] ?? '');
        $unit = clean_text($_POST['unit'] ?? '');
        $category_id = (int)($_POST['category_id'] ?? 0);
        if ($category_id <= 0) $category_id = null;

        if ($name === '' || $unit === '') {
            // في edit نرجعه لنفس الصفحة
            if ($action === 'edit' && $id > 0) redirect_to_edit($id, 0);
            redirect_to_manage("error=missing");
        }

        // (اختياري) قيم عامة للتوافق/القديم
        $calories = clean_float($_POST['calories'] ?? 0);
        $protein  = clean_float($_POST['protein'] ?? 0);
        $carbs    = clean_float($_POST['carbs'] ?? 0);
        $fat      = clean_float($_POST['fat'] ?? 0);

        $autoServing = is_auto_serving_unit($unit);

        // tiers arrays (الجديد + القديم)
        $weights = $_POST['tiers_weight'] ?? [];
        $prices  = $_POST['tiers_price'] ?? [];
        $servs   = $_POST['tiers_serving'] ?? [];

        // nutrition per tier (الجديد)
        $tCal = $_POST['tiers_calories'] ?? [];
        $tPro = $_POST['tiers_protein']  ?? [];
        $tCar = $_POST['tiers_carbs']    ?? [];
        $tFat = $_POST['tiers_fat']      ?? [];

        $tiers = [];

        if (is_array($weights)) {
            $count = count($weights);

            for ($i = 0; $i < $count; $i++) {

                $threshold = clean_float($weights[$i] ?? 0);
                $price     = clean_float($prices[$i]  ?? 0);

                if ($threshold <= 0) continue;

                // serving:
                // gram => auto (null)
                // غير ذلك => من input
                $serving = $autoServing ? null : clean_float($servs[$i] ?? 0);

                // nutrition (لكل شريحة)
                // لو الصفحة القديمة ما ترسلها => تكون 0 تلقائياً
                $nutrition = [
                    'calories' => clean_float($tCal[$i] ?? 0),
                    'protein'  => clean_float($tPro[$i] ?? 0),
                    'carbs'    => clean_float($tCar[$i] ?? 0),
                    'fat'      => clean_float($tFat[$i] ?? 0),
                ];

                $tiers[] = [
                    'threshold'    => $threshold,
                    'price'        => $price,
                    'serving'      => $serving,
                    'auto_serving' => $autoServing,
                    'nutrition'    => $nutrition,
                ];
            }
        }

        // ترتيب tiers تصاعدي (أفضلية)
        usort($tiers, fn($a, $b) => ($a['threshold'] <=> $b['threshold']));

        $pricing_config = json_encode(['tiers' => $tiers], JSON_UNESCAPED_UNICODE);

        // ---------- ADD ----------
        if ($action === 'add') {
            $stmt = $pdo->prepare("
                INSERT INTO global_options
                    (name, category_id, unit, is_active, calories, protein, carbs, fat, pricing_config, created_at)
                VALUES
                    (?, ?, ?, 1, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$name, $category_id, $unit, $calories, $protein, $carbs, $fat, $pricing_config]);

            redirect_to_manage("success=added");
        }

        // ---------- EDIT ----------
        if ($action === 'edit') {
            if ($id <= 0) redirect_to_manage("error=bad_id");

            $stmt = $pdo->prepare("
                UPDATE global_options SET
                    name=?,
                    category_id=?,
                    unit=?,
                    calories=?,
                    protein=?,
                    carbs=?,
                    fat=?,
                    pricing_config=?
                WHERE id=?
            ");
            $stmt->execute([$name, $category_id, $unit, $calories, $protein, $carbs, $fat, $pricing_config, $id]);

            // مهم: نبقى في صفحة التعديل + تظهر Toast عبر saved=1
            redirect_to_edit($id, 1);
        }
    }
}

// =========================
// GET delete (تعطيل فقط)
// =========================
if (isset($_GET['action']) && $_GET['action'] === 'delete') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare("UPDATE global_options SET is_active=0 WHERE id=?")->execute([$id]);
    }
    redirect_to_manage("success=disabled");
}

redirect_to_manage();