<?php
/**
 * Plugin Name: تب‌های سفارشی پنل کاربری ووکامرس
 * Description: افزودن، حذف و مرتب‌سازی تب‌های دلخواه (مثل پشتیبانی/پشتوان) در My Account ووکامرس، کاملا از طریق پنل مدیریت بدون نیاز به کدنویسی.
 * Version: 1.0
 * Author: Karvanpardazesh
 * Author URI: https://karvanpardazesh.ir
 * Text Domain: custom-myaccount-tabs
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') or die('No script kiddies please!');

class Custom_MyAccount_Tabs_Plugin
{
    const OPTION_KEY = 'cmat_tabs_list';
    const NONCE_ACTION = 'cmat_save_tabs';

    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'register_admin_menu']);
        add_action('admin_init', [__CLASS__, 'handle_form_submit']);
        add_action('admin_init', [__CLASS__, 'handle_get_actions']);

        add_filter('woocommerce_account_menu_items', [__CLASS__, 'add_menu_items']);
        add_action('init', [__CLASS__, 'register_endpoints']);
        add_filter('woocommerce_get_query_vars', [__CLASS__, 'add_query_vars']);

        foreach (self::get_tabs() as $tab) {
            if (empty($tab['enabled'])) continue;
            $key = $tab['slug'];
            add_action("woocommerce_account_{$key}_endpoint", function () use ($tab) {
                echo '<div class="cmat-tab-content">';
                echo do_shortcode($tab['content']);
                echo '</div>';
            });
        }
    }

    /* ---------------------------------------------------------
     * Data handling
     * --------------------------------------------------------- */

    public static function get_tabs()
    {
        $tabs = get_option(self::OPTION_KEY, []);
        if (!is_array($tabs)) $tabs = [];

        // sort by order
        usort($tabs, function ($a, $b) {
            return ($a['order'] ?? 0) <=> ($b['order'] ?? 0);
        });

        return $tabs;
    }

    public static function save_tabs($tabs)
    {
        update_option(self::OPTION_KEY, $tabs);
    }

    public static function sanitize_slug($title, $existing_slugs = [])
    {
        $base = sanitize_title($title);
        $base = $base ?: 'tab';
        $slug = $base;
        $i = 2;
        while (in_array($slug, $existing_slugs)) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    /**
     * Force a pure ASCII (English) slug. Strips any non a-z0-9- characters
     * so Persian/Arabic text never ends up in the rewrite endpoint slug,
     * which previously caused broken/encoded URLs.
     */
    public static function sanitize_slug_ascii($raw)
    {
        $raw = strtolower(trim($raw));
        $raw = preg_replace('/[^a-z0-9\-]+/', '-', $raw);
        $raw = preg_replace('/-+/', '-', $raw);
        $raw = trim($raw, '-');
        return $raw;
    }

    /* ---------------------------------------------------------
     * Admin page
     * --------------------------------------------------------- */

    public static function register_admin_menu()
    {
        add_menu_page(
            'تب‌های پنل کاربری',
            'تب‌های پنل کاربری',
            'manage_options',
            'cmat-tabs',
            [__CLASS__, 'render_admin_page'],
            'dashicons-id-alt',
            58
        );
    }

    public static function handle_form_submit()
    {
        if (!isset($_POST['cmat_action'])) return;
        if (!current_user_can('manage_options')) return;
        if (!isset($_POST['cmat_nonce']) || !wp_verify_nonce($_POST['cmat_nonce'], self::NONCE_ACTION)) return;

        $action = sanitize_text_field($_POST['cmat_action']);
        $tabs = self::get_tabs();

        if ($action === 'add') {
            $title = sanitize_text_field($_POST['title'] ?? '');
            $content = wp_kses_post($_POST['content'] ?? '');
            $position_after = sanitize_text_field($_POST['position_after'] ?? '');
            $raw_slug = sanitize_text_field($_POST['slug'] ?? '');

            $existing_slugs = array_map(function ($t) {
                return $t['slug'];
            }, $tabs);
            $reserved = array_merge($existing_slugs, ['dashboard','orders','downloads','edit-address','edit-account','customer-logout']);

            // force ascii-only slug; if user left it empty or it has no latin chars, fallback to tab-N
            $clean_slug = self::sanitize_slug_ascii($raw_slug);
            if (empty($clean_slug)) {
                $n = 1;
                while (in_array('tab-' . $n, $reserved)) $n++;
                $clean_slug = 'tab-' . $n;
            }
            $final_slug = $clean_slug;
            $i = 2;
            while (in_array($final_slug, $reserved)) {
                $final_slug = $clean_slug . '-' . $i;
                $i++;
            }

            if ($title && $content) {
                $max_order = 0;
                foreach ($tabs as $t) {
                    $max_order = max($max_order, $t['order'] ?? 0);
                }

                $tabs[] = [
                    'slug' => $final_slug,
                    'title' => $title,
                    'content' => $content,
                    'position_after' => $position_after,
                    'enabled' => 1,
                    'order' => $max_order + 10,
                ];
                self::save_tabs($tabs);
                self::flush_rewrite_flag();
                add_settings_error('cmat', 'cmat_added', 'تب جدید با موفقیت اضافه شد. لطفا یک‌بار به تنظیمات > پیوندهای یکتا بروید و ذخیره را بزنید.', 'success');
            } else {
                add_settings_error('cmat', 'cmat_error', 'عنوان و محتوا (شورت‌کد) الزامی هستند.', 'error');
            }
        }

        if ($action === 'reorder') {
            $order_map = isset($_POST['order']) && is_array($_POST['order']) ? $_POST['order'] : [];
            foreach ($tabs as &$t) {
                if (isset($order_map[$t['slug']])) {
                    $t['order'] = intval($order_map[$t['slug']]);
                }
            }
            self::save_tabs($tabs);
            add_settings_error('cmat', 'cmat_reordered', 'ترتیب تب‌ها بروزرسانی شد.', 'success');
        }

        if ($action === 'edit') {
            $slug = sanitize_text_field($_POST['slug'] ?? '');
            foreach ($tabs as &$t) {
                if ($t['slug'] === $slug) {
                    $t['title'] = sanitize_text_field($_POST['title'] ?? $t['title']);
                    $t['content'] = wp_kses_post($_POST['content'] ?? $t['content']);
                    $t['position_after'] = sanitize_text_field($_POST['position_after'] ?? $t['position_after']);
                }
            }
            self::save_tabs($tabs);
            self::flush_rewrite_flag();
            add_settings_error('cmat', 'cmat_edited', 'تب بروزرسانی شد.', 'success');
        }

        wp_safe_redirect(admin_url('admin.php?page=cmat-tabs'));
        exit;
    }

    /**
     * Handles simple GET-link actions (toggle/delete) which are more reliable
     * across browsers/admin-themes than buttons bound to hidden forms via
     * the HTML5 form="" attribute.
     */
    public static function handle_get_actions()
    {
        if (!isset($_GET['page']) || $_GET['page'] !== 'cmat-tabs') return;
        if (!isset($_GET['cmat_get_action'], $_GET['slug'], $_GET['_wpnonce'])) return;
        if (!current_user_can('manage_options')) return;

        $action = sanitize_text_field($_GET['cmat_get_action']);
        $slug = sanitize_text_field($_GET['slug']);

        if (!wp_verify_nonce($_GET['_wpnonce'], 'cmat_row_action_' . $slug)) {
            wp_die('لینک نامعتبر است یا منقضی شده. لطفا صفحه را رفرش کنید و دوباره تلاش کنید.');
        }

        $tabs = self::get_tabs();

        if ($action === 'toggle') {
            foreach ($tabs as &$t) {
                if ($t['slug'] === $slug) {
                    $t['enabled'] = empty($t['enabled']) ? 1 : 0;
                }
            }
            self::save_tabs($tabs);
            self::flush_rewrite_flag();
        }

        if ($action === 'delete') {
            $tabs = array_values(array_filter($tabs, function ($t) use ($slug) {
                return $t['slug'] !== $slug;
            }));
            self::save_tabs($tabs);
            self::flush_rewrite_flag();
        }

        wp_safe_redirect(admin_url('admin.php?page=cmat-tabs'));
        exit;
    }

    public static function flush_rewrite_flag()
    {
        update_option('cmat_need_flush', 1);
    }

    public static function render_admin_page()
    {
        if (!current_user_can('manage_options')) return;

        if (get_option('cmat_need_flush')) {
            flush_rewrite_rules();
            delete_option('cmat_need_flush');
        }

        $tabs = self::get_tabs();
        settings_errors('cmat');

        $default_positions = [
            '' => '(انتهای لیست، قبل از خروج)',
            'dashboard' => 'بعد از داشبورد',
            'orders' => 'بعد از سفارشات',
            'downloads' => 'بعد از دانلودها',
            'edit-address' => 'بعد از آدرس‌ها',
            'edit-account' => 'بعد از جزئیات حساب',
        ];
        ?>
        <div class="wrap" dir="rtl" style="font-family: Tahoma, sans-serif;">
            <h1>مدیریت تب‌های پنل کاربری (My Account)</h1>
            <p>از این صفحه می‌توانید بدون نیاز به کدنویسی، تب‌های دلخواه (مثل پشتیبانی پشتوان، پیگیری سفارش و...) را به پنل کاربری ووکامرس اضافه، ویرایش، مرتب یا حذف کنید.</p>

            <h2>افزودن تب جدید</h2>
            <form method="post" style="background:#fff;border:1px solid #ccd0d4;padding:15px;max-width:700px;">
                <?php wp_nonce_field(self::NONCE_ACTION, 'cmat_nonce'); ?>
                <input type="hidden" name="cmat_action" value="add">
                <table class="form-table">
                    <tr>
                        <th><label for="title">عنوان تب (فارسی، فقط برای نمایش)</label></th>
                        <td><input type="text" name="title" id="title" class="regular-text" placeholder="مثلا: پشتیبانی" required></td>
                    </tr>
                    <tr>
                        <th><label for="slug">اسلاگ انگلیسی (برای آدرس URL)</label></th>
                        <td>
                            <input type="text" name="slug" id="slug" class="regular-text" placeholder="مثلا: support یا poshtvan" dir="ltr">
                            <p class="description">فقط حروف انگلیسی، عدد و خط تیره. اگر خالی بگذارید خودکار (tab-1, tab-2, ...) ساخته می‌شود. <strong>هرگز فارسی وارد نکنید</strong> چون باعث خراب شدن آدرس صفحه می‌شود.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="content">شورت‌کد یا محتوا</label></th>
                        <td>
                            <input type="text" name="content" id="content" class="regular-text" placeholder="مثلا: [mihanticket_list]" required>
                            <p class="description">می‌توانید هر شورت‌کدی از هر افزونه (پشتوان، پیگیری سفارش و...) را وارد کنید.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="position_after">محل قرارگیری</label></th>
                        <td>
                            <select name="position_after" id="position_after">
                                <?php foreach ($default_positions as $key => $label): ?>
                                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button('افزودن تب'); ?>
            </form>

            <h2>تب‌های موجود</h2>
            <?php if (empty($tabs)): ?>
                <p>هنوز هیچ تبی اضافه نشده است.</p>
            <?php else: ?>
                <form method="post">
                    <?php wp_nonce_field(self::NONCE_ACTION, 'cmat_nonce'); ?>
                    <input type="hidden" name="cmat_action" value="reorder">
                    <table class="widefat striped" style="max-width:900px;">
                        <thead>
                        <tr>
                            <th style="width:80px;">ترتیب</th>
                            <th>عنوان</th>
                            <th>اسلاگ (URL)</th>
                            <th>شورت‌کد</th>
                            <th>محل قرارگیری</th>
                            <th>وضعیت</th>
                            <th>عملیات</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tabs as $tab): ?>
                            <tr>
                                <td>
                                    <input type="number" name="order[<?php echo esc_attr($tab['slug']); ?>]" value="<?php echo esc_attr($tab['order'] ?? 0); ?>" style="width:60px;">
                                </td>
                                <td><?php echo esc_html($tab['title']); ?></td>
                                <td dir="ltr"><code><?php echo esc_html($tab['slug']); ?></code></td>
                                <td><code><?php echo esc_html($tab['content']); ?></code></td>
                                <td><?php echo esc_html($default_positions[$tab['position_after']] ?? $tab['position_after']); ?></td>
                                <td><?php echo !empty($tab['enabled']) ? '✅ فعال' : '❌ غیرفعال'; ?></td>
                                <td>
                                    <?php
                                    $toggle_url = wp_nonce_url(
                                        admin_url('admin.php?page=cmat-tabs&cmat_get_action=toggle&slug=' . urlencode($tab['slug'])),
                                        'cmat_row_action_' . $tab['slug']
                                    );
                                    $delete_url = wp_nonce_url(
                                        admin_url('admin.php?page=cmat-tabs&cmat_get_action=delete&slug=' . urlencode($tab['slug'])),
                                        'cmat_row_action_' . $tab['slug']
                                    );
                                    ?>
                                    <a href="<?php echo esc_url($toggle_url); ?>" class="button button-small">
                                        <?php echo !empty($tab['enabled']) ? 'غیرفعال‌سازی' : 'فعال‌سازی'; ?>
                                    </a>
                                    <a href="<?php echo esc_url($delete_url); ?>" class="button button-small" onclick="return confirm('حذف شود؟');">حذف</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php submit_button('ذخیره ترتیب'); ?>
                </form>
            <?php endif; ?>

            <p style="margin-top:20px;color:#666;">
                ⚠️ بعد از هر بار افزودن یا حذف تب، یک‌بار به <strong>تنظیمات → پیوندهای یکتا (Permalinks)</strong> بروید و دکمه ذخیره را بزنید تا تغییرات کامل اعمال شود.
            </p>
        </div>
        <?php
    }

    /* ---------------------------------------------------------
     * WooCommerce hooks
     * --------------------------------------------------------- */

    public static function add_menu_items($items)
    {
        $tabs = self::get_tabs();
        $new_items = [];

        foreach ($items as $key => $label) {
            $new_items[$key] = $label;

            foreach ($tabs as $tab) {
                if (empty($tab['enabled'])) continue;
                if (($tab['position_after'] ?? '') === $key) {
                    $new_items[$tab['slug']] = $tab['title'];
                }
            }
        }

        // tabs with no valid position_after match -> add before logout
        foreach ($tabs as $tab) {
            if (empty($tab['enabled'])) continue;
            if (!isset($new_items[$tab['slug']])) {
                $logout = $new_items['customer-logout'] ?? null;
                unset($new_items['customer-logout']);
                $new_items[$tab['slug']] = $tab['title'];
                if ($logout) {
                    $new_items['customer-logout'] = $logout;
                }
            }
        }

        return $new_items;
    }

    public static function register_endpoints()
    {
        foreach (self::get_tabs() as $tab) {
            if (empty($tab['enabled'])) continue;
            add_rewrite_endpoint($tab['slug'], EP_ROOT | EP_PAGES);
        }
    }

    public static function add_query_vars($vars)
    {
        foreach (self::get_tabs() as $tab) {
            if (empty($tab['enabled'])) continue;
            $vars[$tab['slug']] = $tab['slug'];
        }
        return $vars;
    }
}

add_action('plugins_loaded', ['Custom_MyAccount_Tabs_Plugin', 'init']);

register_activation_hook(__FILE__, function () {
    update_option('cmat_need_flush', 1);
    delete_option(Custom_MyAccount_Tabs_Plugin::OPTION_KEY); // اطمینان از شروع تمیز و بدون تب پیش‌فرض
});