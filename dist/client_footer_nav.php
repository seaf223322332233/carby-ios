<style>
/* ===============================
   Bottom Nav (Unified with dashboard theme)
   Works with: body[data-theme="dark"]
================================ */

/* Default (Light) */
:root{
  --nav-bg: rgba(255,255,255,.92);
  --nav-border: rgba(0,0,0,.06);
  --nav-shadow: 0 -12px 30px rgba(0,0,0,.12);

  --nav-text: #9aa0a6;
  --nav-icon: #b0b6bd;

  --nav-active: var(--primary);
  --nav-active-glow: rgba(142,68,173,.28);

  --fab-bg: linear-gradient(135deg, var(--primary), #6d28d9);
  --fab-border: #fff;
  --fab-shadow: 0 16px 40px rgba(142,68,173,.35);
}

/* Dark (MATCH your dashboard: body[data-theme="dark"]) */
body[data-theme="dark"]{
  --nav-bg: color-mix(in srgb, var(--surface) 86%, transparent);
  --nav-border: rgba(255,255,255,.08);
  --nav-shadow: 0 -16px 45px rgba(0,0,0,.65);

  --nav-text: color-mix(in srgb, var(--text-sub) 88%, #ffffff 12%);
  --nav-icon: color-mix(in srgb, var(--text-sub) 75%, #ffffff 25%);

  --nav-active: #b084f7;
  --nav-active-glow: rgba(176,132,247,.28);

  --fab-bg: linear-gradient(135deg, #8e44ad, #4c1d95);
  --fab-border: color-mix(in srgb, var(--surface) 92%, #000 8%);
  --fab-shadow: 0 18px 55px rgba(0,0,0,.55);
}

.bottom-nav{
  position: fixed;
  left: 0; right: 0; bottom: 0;
  width: 100%;
  display: flex;
  justify-content: space-around;
  padding: 12px 10px 10px;
  background: var(--nav-bg);
  border-top: 1px solid var(--nav-border);
  box-shadow: var(--nav-shadow);
  border-top-left-radius: 26px;
  border-top-right-radius: 26px;
  z-index: 1000;

  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
}

.nav-item{
  flex: 1;
  text-align: center;
  text-decoration: none;
  color: var(--nav-text);
  font-size: .75rem;
  position: relative;
  transition: .25s ease;
}

.nav-item i{
  display: block;
  font-size: 1.45rem;
  margin-bottom: 4px;
  color: var(--nav-icon);
  transition: .25s ease;
}

/* Active (premium underline glow) */
.nav-item.active{
  color: var(--nav-active);
  font-weight: 700;
}

.nav-item.active i{
  color: var(--nav-active);
  transform: translateY(-4px) scale(1.06);
}

.nav-item.active::after{
  content:"";
  position:absolute;
  left: 50%;
  bottom: -6px;
  width: 28px;
  height: 4px;
  transform: translateX(-50%);
  border-radius: 999px;
  background: var(--nav-active);
  box-shadow: 0 0 20px var(--nav-active-glow);
}

/* Center Floating */
.nav-item.special{
  transform: translateY(-28px);
}

.nav-item.special .nav-circle{
  width: 62px; height: 62px;
  border-radius: 50%;
  margin: 0 auto;
  display:flex;
  align-items:center;
  justify-content:center;
  background: var(--fab-bg);
  border: 4px solid var(--fab-border);
  box-shadow: var(--fab-shadow);
  transition: .25s ease;
}

.nav-item.special .nav-circle i{
  color: #fff;
  font-size: 1.65rem;
}

.nav-item.special:active .nav-circle{
  transform: scale(.96);
}
</style>

<?php
// client_footer_nav.php (Premium + Dark Sync + Menu Toggle)
// =======================================================

require_once 'db_connect.php';

$cur = basename($_SERVER['PHP_SELF']);

// ✅ استعلام واحد فقط للإعدادات
$settings = [];
try {
  $settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
  $settings = [];
}

// ✅ حالة المنيو (افتراضي مفعل)
$menu_enabled = ($settings['menu_enabled'] ?? '1') === '1';
?>

<div class="bottom-nav">
    <a href="client_dashboard.php" class="nav-item <?php echo ($cur == 'client_dashboard.php') ? 'active' : ''; ?>">
        <i class="fas fa-home"></i> الرئيسية
    </a>

    <a href="client_browse_packages.php" class="nav-item <?php echo ($cur == 'client_browse_packages.php' || $cur == 'client_package_details.php') ? 'active' : ''; ?>">
        <i class="fas fa-box-open"></i> الباقات
    </a>

    <!-- ✅ المنيو يظهر فقط إذا كان مفعل -->
    <?php if($menu_enabled): ?>
    <a href="menu.php" class="nav-item <?php echo ($cur == 'menu.php') ? 'active' : ''; ?>">
      <i class="fas fa-hamburger"></i> المنيو
    </a>
    <?php endif; ?>

    <a href="client_select_meals.php" class="nav-item special">
        <div class="nav-circle"><i class="fas fa-utensils"></i></div>
        <span style="display:block; margin-top:5px; color:var(--primary); font-weight:bold;">وجباتي</span>
    </a>

    <a href="my_orders.php" class="nav-item <?php echo ($cur == 'my_orders.php') ? 'active' : ''; ?>">
        <i class="fas fa-receipt"></i> طلباتي
    </a>

    <a href="client_profile.php" class="nav-item <?php echo ($cur == 'client_profile.php') ? 'active' : ''; ?>">
        <i class="fas fa-user-circle"></i> حسابي
    </a>
</div>