<?php
/**
 * Plugin Name: Mi Cuadrante - Control de Horas
 * Description: Plugin personal para registrar jornadas, horas extra, vacaciones y comparar horas reales con las exigidas por la empresa.
 * Version: 1.0.0
 * Author: Mi Cuadrante
 * Text Domain: mi-cuadrante-control-horas
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Mi_Cuadrante_Control_Horas
{
    private const DB_VERSION = '1.0.0';
    private const OPTION_DB_VERSION = 'mcch_db_version';
    private const OPTION_CAP = 'mcch_manage_cap';
    private const NONCE_ACTION_SAVE = 'mcch_save_entry';
    private const NONCE_ACTION_DELETE = 'mcch_delete_entry';

    private static ?Mi_Cuadrante_Control_Horas $instance = null;

    public static function instance(): Mi_Cuadrante_Control_Horas
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_mcch_save_entry', [$this, 'handle_save_entry']);
        add_action('admin_post_mcch_delete_entry', [$this, 'handle_delete_entry']);

        if (is_admin()) {
            add_action('admin_init', [$this, 'maybe_upgrade_db']);
        }
    }

    public static function activate(): void
    {
        self::instance()->create_tables();

        if (!get_option(self::OPTION_CAP)) {
            update_option(self::OPTION_CAP, 'manage_options');
        }
    }

    public static function deactivate(): void
    {
        // Intencionadamente vacío: mantenemos datos para histórico personal.
    }

    public function load_textdomain(): void
    {
        load_plugin_textdomain('mi-cuadrante-control-horas', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function maybe_upgrade_db(): void
    {
        $installed = get_option(self::OPTION_DB_VERSION);

        if ($installed !== self::DB_VERSION) {
            $this->create_tables();
        }
    }

    public function register_admin_menu(): void
    {
        $capability = $this->get_capability();

        add_menu_page(
            __('Mi Cuadrante', 'mi-cuadrante-control-horas'),
            __('Mi Cuadrante', 'mi-cuadrante-control-horas'),
            $capability,
            'mcch-dashboard',
            [$this, 'render_admin_page'],
            'dashicons-calendar-alt',
            56
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_mcch-dashboard') {
            return;
        }

        wp_enqueue_style(
            'mcch-admin-style',
            plugins_url('assets/css/admin.css', __FILE__),
            [],
            self::DB_VERSION
        );

        wp_enqueue_script(
            'mcch-admin-script',
            plugins_url('assets/js/admin.js', __FILE__),
            [],
            self::DB_VERSION,
            true
        );
    }

    public function handle_save_entry(): void
    {
        $this->assert_capability();
        check_admin_referer(self::NONCE_ACTION_SAVE);

        $entry_id = isset($_POST['entry_id']) ? absint($_POST['entry_id']) : 0;
        $data = $this->sanitize_entry_data($_POST);

        if (empty($data['work_date'])) {
            $this->redirect_with_notice('error', __('La fecha es obligatoria.', 'mi-cuadrante-control-horas'));
        }

        global $wpdb;
        $table = $this->table_name();

        if ($entry_id > 0) {
            $result = $wpdb->update(
                $table,
                $data,
                ['id' => $entry_id],
                ['%s', '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%s'],
                ['%d']
            );
        } else {
            $result = $wpdb->insert(
                $table,
                $data,
                ['%s', '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%s']
            );
        }

        if ($result === false) {
            $this->redirect_with_notice('error', __('No se pudo guardar el registro.', 'mi-cuadrante-control-horas'));
        }

        $this->redirect_with_notice('success', __('Registro guardado correctamente.', 'mi-cuadrante-control-horas'));
    }

    public function handle_delete_entry(): void
    {
        $this->assert_capability();
        check_admin_referer(self::NONCE_ACTION_DELETE);

        $entry_id = isset($_POST['entry_id']) ? absint($_POST['entry_id']) : 0;

        if ($entry_id <= 0) {
            $this->redirect_with_notice('error', __('Registro inválido.', 'mi-cuadrante-control-horas'));
        }

        global $wpdb;

        $result = $wpdb->delete($this->table_name(), ['id' => $entry_id], ['%d']);

        if ($result === false) {
            $this->redirect_with_notice('error', __('No se pudo eliminar el registro.', 'mi-cuadrante-control-horas'));
        }

        $this->redirect_with_notice('success', __('Registro eliminado.', 'mi-cuadrante-control-horas'));
    }

    public function render_admin_page(): void
    {
        $this->assert_capability();

        $current_month = isset($_GET['month']) ? absint($_GET['month']) : (int) wp_date('n');
        $current_year = isset($_GET['year']) ? absint($_GET['year']) : (int) wp_date('Y');

        if ($current_month < 1 || $current_month > 12) {
            $current_month = (int) wp_date('n');
        }

        if ($current_year < 2000 || $current_year > 2100) {
            $current_year = (int) wp_date('Y');
        }

        $entries = $this->get_entries_by_month($current_month, $current_year);
        $summary = $this->calculate_summary($entries);
        $edit_entry = $this->get_edit_entry();

        ?>
        <div class="wrap mcch-wrap">
            <h1><?php esc_html_e('Mi Cuadrante - Control Personal', 'mi-cuadrante-control-horas'); ?></h1>

            <?php $this->render_notice(); ?>

            <div class="mcch-grid">
                <section class="mcch-card">
                    <h2><?php echo $edit_entry ? esc_html__('Editar registro', 'mi-cuadrante-control-horas') : esc_html__('Nuevo registro', 'mi-cuadrante-control-horas'); ?></h2>
                    <?php $this->render_entry_form($edit_entry); ?>
                </section>

                <section class="mcch-card">
                    <h2><?php esc_html_e('Resumen mensual', 'mi-cuadrante-control-horas'); ?></h2>
                    <?php $this->render_month_filter($current_month, $current_year); ?>
                    <?php $this->render_summary($summary); ?>
                </section>
            </div>

            <section class="mcch-card">
                <h2><?php esc_html_e('Registros del mes', 'mi-cuadrante-control-horas'); ?></h2>
                <?php $this->render_entries_table($entries); ?>
            </section>
        </div>
        <?php
    }

    private function render_entry_form(?array $entry = null): void
    {
        $default = [
            'id' => 0,
            'work_date' => wp_date('Y-m-d'),
            'shift' => '',
            'worked_minutes' => 0,
            'expected_minutes' => 0,
            'extra_minutes' => 0,
            'vacation_day' => 0,
            'personal_day' => 0,
            'notes' => '',
            'turn_type' => 'normal',
        ];

        $entry = wp_parse_args($entry ?? [], $default);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mcch-form">
            <input type="hidden" name="action" value="mcch_save_entry" />
            <input type="hidden" name="entry_id" value="<?php echo esc_attr((string) $entry['id']); ?>" />
            <?php wp_nonce_field(self::NONCE_ACTION_SAVE); ?>

            <label>
                <?php esc_html_e('Fecha', 'mi-cuadrante-control-horas'); ?>
                <input type="date" name="work_date" value="<?php echo esc_attr($entry['work_date']); ?>" required />
            </label>

            <label>
                <?php esc_html_e('Turno', 'mi-cuadrante-control-horas'); ?>
                <input type="text" name="shift" value="<?php echo esc_attr($entry['shift']); ?>" placeholder="Mañana / Tarde / Noche" />
            </label>

            <label>
                <?php esc_html_e('Tipo de día', 'mi-cuadrante-control-horas'); ?>
                <select name="turn_type">
                    <?php
                    $types = [
                        'normal' => __('Normal', 'mi-cuadrante-control-horas'),
                        'festivo' => __('Festivo', 'mi-cuadrante-control-horas'),
                        'guardia' => __('Guardia', 'mi-cuadrante-control-horas'),
                        'baja' => __('Baja médica', 'mi-cuadrante-control-horas'),
                    ];

                    foreach ($types as $value => $label) {
                        printf(
                            '<option value="%1$s" %2$s>%3$s</option>',
                            esc_attr($value),
                            selected($entry['turn_type'], $value, false),
                            esc_html($label)
                        );
                    }
                    ?>
                </select>
            </label>

            <label>
                <?php esc_html_e('Horas trabajadas', 'mi-cuadrante-control-horas'); ?>
                <input type="time" name="worked_time" value="<?php echo esc_attr($this->minutes_to_time((int) $entry['worked_minutes'])); ?>" required />
            </label>

            <label>
                <?php esc_html_e('Horas exigidas por empresa', 'mi-cuadrante-control-horas'); ?>
                <input type="time" name="expected_time" value="<?php echo esc_attr($this->minutes_to_time((int) $entry['expected_minutes'])); ?>" required />
            </label>

            <label>
                <?php esc_html_e('Horas extra', 'mi-cuadrante-control-horas'); ?>
                <input type="time" name="extra_time" value="<?php echo esc_attr($this->minutes_to_time((int) $entry['extra_minutes'])); ?>" />
            </label>

            <label class="mcch-checkbox">
                <input type="checkbox" name="vacation_day" value="1" <?php checked((int) $entry['vacation_day'], 1); ?> />
                <?php esc_html_e('Día de vacaciones', 'mi-cuadrante-control-horas'); ?>
            </label>

            <label class="mcch-checkbox">
                <input type="checkbox" name="personal_day" value="1" <?php checked((int) $entry['personal_day'], 1); ?> />
                <?php esc_html_e('Día de asuntos propios', 'mi-cuadrante-control-horas'); ?>
            </label>

            <label>
                <?php esc_html_e('Notas', 'mi-cuadrante-control-horas'); ?>
                <textarea name="notes" rows="4" placeholder="Ej. Se pidió quedarme 1h más para cierre."><?php echo esc_textarea($entry['notes']); ?></textarea>
            </label>

            <button type="submit" class="button button-primary">
                <?php echo $entry['id'] ? esc_html__('Actualizar registro', 'mi-cuadrante-control-horas') : esc_html__('Guardar registro', 'mi-cuadrante-control-horas'); ?>
            </button>
        </form>
        <?php
    }

    private function render_month_filter(int $month, int $year): void
    {
        ?>
        <form method="get" class="mcch-filter">
            <input type="hidden" name="page" value="mcch-dashboard" />
            <label>
                <?php esc_html_e('Mes', 'mi-cuadrante-control-horas'); ?>
                <select name="month">
                    <?php for ($m = 1; $m <= 12; $m++) : ?>
                        <option value="<?php echo esc_attr((string) $m); ?>" <?php selected($month, $m); ?>>
                            <?php echo esc_html(wp_date('F', mktime(0, 0, 0, $m, 1))); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </label>
            <label>
                <?php esc_html_e('Año', 'mi-cuadrante-control-horas'); ?>
                <input type="number" name="year" min="2000" max="2100" value="<?php echo esc_attr((string) $year); ?>" />
            </label>
            <button type="submit" class="button"><?php esc_html_e('Filtrar', 'mi-cuadrante-control-horas'); ?></button>
        </form>
        <?php
    }

    private function render_summary(array $summary): void
    {
        $balance_class = $summary['difference_minutes'] >= 0 ? 'positive' : 'negative';
        ?>
        <ul class="mcch-summary">
            <li><strong><?php esc_html_e('Horas trabajadas', 'mi-cuadrante-control-horas'); ?>:</strong> <?php echo esc_html($this->minutes_to_human($summary['worked_minutes'])); ?></li>
            <li><strong><?php esc_html_e('Horas exigidas', 'mi-cuadrante-control-horas'); ?>:</strong> <?php echo esc_html($this->minutes_to_human($summary['expected_minutes'])); ?></li>
            <li><strong><?php esc_html_e('Horas extra registradas', 'mi-cuadrante-control-horas'); ?>:</strong> <?php echo esc_html($this->minutes_to_human($summary['extra_minutes'])); ?></li>
            <li><strong><?php esc_html_e('Vacaciones', 'mi-cuadrante-control-horas'); ?>:</strong> <?php echo esc_html((string) $summary['vacation_days']); ?></li>
            <li><strong><?php esc_html_e('Asuntos propios', 'mi-cuadrante-control-horas'); ?>:</strong> <?php echo esc_html((string) $summary['personal_days']); ?></li>
            <li class="mcch-balance <?php echo esc_attr($balance_class); ?>">
                <strong><?php esc_html_e('Diferencia (trabajadas - exigidas)', 'mi-cuadrante-control-horas'); ?>:</strong>
                <?php echo esc_html($this->minutes_to_human($summary['difference_minutes'], true)); ?>
            </li>
        </ul>
        <?php
    }

    private function render_entries_table(array $entries): void
    {
        if (empty($entries)) {
            echo '<p>' . esc_html__('No hay registros para este periodo.', 'mi-cuadrante-control-horas') . '</p>';
            return;
        }
        ?>
        <div class="mcch-table-wrapper">
            <table class="widefat striped">
                <thead>
                <tr>
                    <th><?php esc_html_e('Fecha', 'mi-cuadrante-control-horas'); ?></th>
                    <th><?php esc_html_e('Turno', 'mi-cuadrante-control-horas'); ?></th>
                    <th><?php esc_html_e('Tipo', 'mi-cuadrante-control-horas'); ?></th>
                    <th><?php esc_html_e('Trabajadas', 'mi-cuadrante-control-horas'); ?></th>
                    <th><?php esc_html_e('Exigidas', 'mi-cuadrante-control-horas'); ?></th>
                    <th><?php esc_html_e('Extra', 'mi-cuadrante-control-horas'); ?></th>
                    <th><?php esc_html_e('Vacaciones', 'mi-cuadrante-control-horas'); ?></th>
                    <th><?php esc_html_e('Asuntos propios', 'mi-cuadrante-control-horas'); ?></th>
                    <th><?php esc_html_e('Notas', 'mi-cuadrante-control-horas'); ?></th>
                    <th><?php esc_html_e('Acciones', 'mi-cuadrante-control-horas'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $entry) : ?>
                    <tr>
                        <td><?php echo esc_html($entry['work_date']); ?></td>
                        <td><?php echo esc_html($entry['shift']); ?></td>
                        <td><?php echo esc_html(ucfirst($entry['turn_type'])); ?></td>
                        <td><?php echo esc_html($this->minutes_to_human((int) $entry['worked_minutes'])); ?></td>
                        <td><?php echo esc_html($this->minutes_to_human((int) $entry['expected_minutes'])); ?></td>
                        <td><?php echo esc_html($this->minutes_to_human((int) $entry['extra_minutes'])); ?></td>
                        <td><?php echo (int) $entry['vacation_day'] === 1 ? '✔' : '—'; ?></td>
                        <td><?php echo (int) $entry['personal_day'] === 1 ? '✔' : '—'; ?></td>
                        <td><?php echo esc_html($entry['notes']); ?></td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'mcch-dashboard', 'edit' => (int) $entry['id']], admin_url('admin.php'))); ?>">
                                <?php esc_html_e('Editar', 'mi-cuadrante-control-horas'); ?>
                            </a>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mcch-inline-form">
                                <input type="hidden" name="action" value="mcch_delete_entry" />
                                <input type="hidden" name="entry_id" value="<?php echo esc_attr((string) $entry['id']); ?>" />
                                <?php wp_nonce_field(self::NONCE_ACTION_DELETE); ?>
                                <button type="submit" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js(__('¿Eliminar este registro?', 'mi-cuadrante-control-horas')); ?>');">
                                    <?php esc_html_e('Eliminar', 'mi-cuadrante-control-horas'); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function sanitize_entry_data(array $source): array
    {
        return [
            'work_date' => isset($source['work_date']) ? sanitize_text_field($source['work_date']) : '',
            'shift' => isset($source['shift']) ? sanitize_text_field($source['shift']) : '',
            'worked_minutes' => $this->time_to_minutes($source['worked_time'] ?? '00:00'),
            'extra_minutes' => $this->time_to_minutes($source['extra_time'] ?? '00:00'),
            'vacation_day' => isset($source['vacation_day']) ? 1 : 0,
            'personal_day' => isset($source['personal_day']) ? 1 : 0,
            'notes' => isset($source['notes']) ? sanitize_textarea_field($source['notes']) : '',
            'expected_minutes' => $this->time_to_minutes($source['expected_time'] ?? '00:00'),
            'turn_type' => isset($source['turn_type']) ? sanitize_key($source['turn_type']) : 'normal',
        ];
    }

    private function create_tables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $this->table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            work_date DATE NOT NULL,
            shift VARCHAR(120) NOT NULL DEFAULT '',
            worked_minutes INT NOT NULL DEFAULT 0,
            extra_minutes INT NOT NULL DEFAULT 0,
            vacation_day TINYINT(1) NOT NULL DEFAULT 0,
            personal_day TINYINT(1) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            expected_minutes INT NOT NULL DEFAULT 0,
            turn_type VARCHAR(30) NOT NULL DEFAULT 'normal',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY work_date (work_date)
        ) {$charset};";

        dbDelta($sql);

        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }

    private function table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'mcch_entries';
    }

    private function get_entries_by_month(int $month, int $year): array
    {
        global $wpdb;

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = wp_date('Y-m-t', strtotime($start));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name()} WHERE work_date BETWEEN %s AND %s ORDER BY work_date DESC, id DESC",
                $start,
                $end
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    private function get_edit_entry(): ?array
    {
        if (!isset($_GET['edit'])) {
            return null;
        }

        $entry_id = absint($_GET['edit']);

        if ($entry_id <= 0) {
            return null;
        }

        global $wpdb;

        $entry = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table_name()} WHERE id = %d", $entry_id),
            ARRAY_A
        );

        return is_array($entry) ? $entry : null;
    }

    private function calculate_summary(array $entries): array
    {
        $summary = [
            'worked_minutes' => 0,
            'expected_minutes' => 0,
            'extra_minutes' => 0,
            'vacation_days' => 0,
            'personal_days' => 0,
            'difference_minutes' => 0,
        ];

        foreach ($entries as $entry) {
            $summary['worked_minutes'] += (int) ($entry['worked_minutes'] ?? 0);
            $summary['expected_minutes'] += (int) ($entry['expected_minutes'] ?? 0);
            $summary['extra_minutes'] += (int) ($entry['extra_minutes'] ?? 0);
            $summary['vacation_days'] += (int) ($entry['vacation_day'] ?? 0);
            $summary['personal_days'] += (int) ($entry['personal_day'] ?? 0);
        }

        $summary['difference_minutes'] = $summary['worked_minutes'] - $summary['expected_minutes'];

        return $summary;
    }

    private function minutes_to_human(int $minutes, bool $signed = false): string
    {
        $sign = '';
        if ($signed && $minutes !== 0) {
            $sign = $minutes > 0 ? '+' : '-';
        }

        $minutes = abs($minutes);
        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        return sprintf('%s%02d:%02d h', $sign, $hours, $remaining);
    }

    private function minutes_to_time(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        return sprintf('%02d:%02d', min($hours, 23), $remaining);
    }

    private function time_to_minutes(string $value): int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches)) {
            return 0;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];

        if ($minutes < 0 || $minutes > 59 || $hours < 0) {
            return 0;
        }

        return ($hours * 60) + $minutes;
    }

    private function render_notice(): void
    {
        if (!isset($_GET['mcch_notice'], $_GET['mcch_message'])) {
            return;
        }

        $type = sanitize_key($_GET['mcch_notice']);
        $message = sanitize_text_field(wp_unslash($_GET['mcch_message']));

        if (!in_array($type, ['success', 'error'], true)) {
            return;
        }

        $class = $type === 'success' ? 'notice notice-success' : 'notice notice-error';

        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
    }

    private function redirect_with_notice(string $type, string $message): void
    {
        $url = add_query_arg(
            [
                'page' => 'mcch-dashboard',
                'mcch_notice' => $type,
                'mcch_message' => rawurlencode($message),
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }

    private function get_capability(): string
    {
        $capability = get_option(self::OPTION_CAP, 'manage_options');

        return is_string($capability) && $capability !== '' ? $capability : 'manage_options';
    }

    private function assert_capability(): void
    {
        if (!current_user_can($this->get_capability())) {
            wp_die(esc_html__('No tienes permisos suficientes para acceder.', 'mi-cuadrante-control-horas'));
        }
    }
}

register_activation_hook(__FILE__, ['Mi_Cuadrante_Control_Horas', 'activate']);
register_deactivation_hook(__FILE__, ['Mi_Cuadrante_Control_Horas', 'deactivate']);

Mi_Cuadrante_Control_Horas::instance();
