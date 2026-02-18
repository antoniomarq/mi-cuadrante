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

final class MCCH_Legal_Calculator
{
    private wpdb $wpdb;
    private string $entries_table;
    private string $schedule_table;
    private string $period_balance_table;
    private string $alerts_table;

    public function __construct(wpdb $wpdb, string $entries_table, string $schedule_table, string $period_balance_table, string $alerts_table)
    {
        $this->wpdb = $wpdb;
        $this->entries_table = $entries_table;
        $this->schedule_table = $schedule_table;
        $this->period_balance_table = $period_balance_table;
        $this->alerts_table = $alerts_table;
    }

    public function recalculate_for_user_and_date(int $user_id, string $work_date, int $fallback_minutes = 0): void
    {
        if ($user_id <= 0 || $work_date === '') {
            return;
        }

        $week = $this->build_week_period($work_date);
        $month = $this->build_month_period($work_date);

        $this->upsert_period_balance($user_id, 'week', $week['period_key'], $week['start'], $week['end'], $fallback_minutes);
        $this->upsert_period_balance($user_id, 'month', $month['period_key'], $month['start'], $month['end'], $fallback_minutes);
    }

    private function upsert_period_balance(int $user_id, string $period_type, string $period_key, string $start, string $end, int $fallback_minutes): void
    {
        $worked_minutes = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COALESCE(SUM(worked_minutes), 0) FROM {$this->entries_table} WHERE user_id = %d AND work_date BETWEEN %s AND %s",
                $user_id,
                $start,
                $end
            )
        );

        $schedule_rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT work_date, planned_minutes FROM {$this->schedule_table} WHERE user_id = %d AND work_date BETWEEN %s AND %s",
                $user_id,
                $start,
                $end
            ),
            ARRAY_A
        );

        $planned_map = [];
        $planned_minutes = 0;

        if (is_array($schedule_rows)) {
            foreach ($schedule_rows as $row) {
                $date_key = (string) ($row['work_date'] ?? '');
                if ($date_key === '') {
                    continue;
                }
                $planned_map[$date_key] = (int) ($row['planned_minutes'] ?? 0);
                $planned_minutes += (int) ($row['planned_minutes'] ?? 0);
            }
        }

        if ($fallback_minutes > 0) {
            $period_dates = $this->list_dates_between($start, $end);
            foreach ($period_dates as $period_date) {
                if (!array_key_exists($period_date, $planned_map)) {
                    $planned_minutes += $fallback_minutes;
                }
            }
        }

        $difference_minutes = $worked_minutes - $planned_minutes;
        $status = $this->resolve_status($difference_minutes);

        $this->wpdb->replace(
            $this->period_balance_table,
            [
                'user_id' => $user_id,
                'period_type' => $period_type,
                'period_key' => $period_key,
                'worked_minutes' => $worked_minutes,
                'planned_minutes' => $planned_minutes,
                'difference_minutes' => $difference_minutes,
                'extra_minutes' => max(0, $difference_minutes),
                'status' => $status,
                'calculated_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s']
        );

        $this->wpdb->delete(
            $this->alerts_table,
            ['user_id' => $user_id, 'period_type' => $period_type, 'period_key' => $period_key],
            ['%d', '%s', '%s']
        );

        if ($status !== 'ok') {
            $severity = $status === 'exceeded' ? 'critical' : 'warning';
            $this->wpdb->insert(
                $this->alerts_table,
                [
                    'user_id' => $user_id,
                    'alert_type' => 'balance_' . $period_type,
                    'period_type' => $period_type,
                    'period_key' => $period_key,
                    'message' => sprintf('Desviación %s (%s).', strtoupper($period_type), $this->format_minutes($difference_minutes)),
                    'severity' => $severity,
                ],
                ['%d', '%s', '%s', '%s', '%s', '%s']
            );
        }
    }

    private function build_week_period(string $date): array
    {
        $ts = strtotime($date);
        $monday = wp_date('Y-m-d', strtotime('monday this week', $ts));
        $sunday = wp_date('Y-m-d', strtotime('sunday this week', $ts));

        return [
            'period_key' => wp_date('o-\\WW', $ts),
            'start' => $monday,
            'end' => $sunday,
        ];
    }

    private function build_month_period(string $date): array
    {
        $ts = strtotime($date);
        $start = wp_date('Y-m-01', $ts);
        $end = wp_date('Y-m-t', $ts);

        return [
            'period_key' => wp_date('Y-m', $ts),
            'start' => $start,
            'end' => $end,
        ];
    }

    private function list_dates_between(string $start, string $end): array
    {
        $start_dt = DateTimeImmutable::createFromFormat('Y-m-d', $start);
        $end_dt = DateTimeImmutable::createFromFormat('Y-m-d', $end);

        if (!$start_dt || !$end_dt || $start_dt > $end_dt) {
            return [];
        }

        $dates = [];
        $cursor = $start_dt;

        while ($cursor <= $end_dt) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        return $dates;
    }

    private function resolve_status(int $difference_minutes): string
    {
        $delta = abs($difference_minutes);

        if ($delta >= 300) {
            return 'exceeded';
        }

        if ($delta >= 120) {
            return 'warning';
        }

        return 'ok';
    }

    private function format_minutes(int $minutes): string
    {
        $sign = $minutes >= 0 ? '+' : '-';
        $minutes = abs($minutes);
        return sprintf('%s%02d:%02d', $sign, intdiv($minutes, 60), $minutes % 60);
    }
}

final class Mi_Cuadrante_Control_Horas
{
    private const DB_VERSION = '1.3.0';
    private const OPTION_DB_VERSION = 'mcch_db_version';
    private const OPTION_CAP = 'mcch_manage_cap';
    private const OPTION_MIGRATION_USER_ID = 'mcch_migration_user_id';
    private const OPTION_SCHEDULE_FALLBACK_MODE = 'mcch_schedule_fallback_mode';
    private const OPTION_SCHEDULE_FALLBACK_MINUTES = 'mcch_schedule_fallback_minutes';
    private const NONCE_ACTION_SAVE = 'mcch_save_entry';
    private const NONCE_ACTION_DELETE = 'mcch_delete_entry';
    private const NONCE_ACTION_SAVE_SCHEDULE = 'mcch_save_schedule';
    private const NONCE_ACTION_DELETE_SCHEDULE = 'mcch_delete_schedule';
    private const NONCE_ACTION_RECALCULATE_NOW = 'mcch_recalculate_now';
    private const CRON_HOOK_RECALCULATE_OPEN_PERIODS = 'mcch_recalculate_open_periods_event';
    private const USER_META_LAST_PERIOD_CALCULATIONS = 'mcch_last_period_calculations';

    private static ?Mi_Cuadrante_Control_Horas $instance = null;
    private ?MCCH_Legal_Calculator $legal_calculator = null;

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
        add_action('init', [$this, 'register_shortcodes']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_mcch_save_entry', [$this, 'handle_save_entry']);
        add_action('admin_post_mcch_delete_entry', [$this, 'handle_delete_entry']);
        add_action('admin_post_mcch_save_schedule', [$this, 'handle_save_schedule']);
        add_action('admin_post_mcch_delete_schedule', [$this, 'handle_delete_schedule']);
        add_action('admin_post_mcch_save_schedule_fallback', [$this, 'handle_save_schedule_fallback']);
        add_action('admin_post_mcch_recalculate_now', [$this, 'handle_recalculate_now']);
        add_action(self::CRON_HOOK_RECALCULATE_OPEN_PERIODS, [$this, 'handle_scheduled_recalculation']);

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

        if (!wp_next_scheduled(self::CRON_HOOK_RECALCULATE_OPEN_PERIODS)) {
            wp_schedule_event(self::next_nightly_timestamp(), 'daily', self::CRON_HOOK_RECALCULATE_OPEN_PERIODS);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK_RECALCULATE_OPEN_PERIODS);
    }

    private static function next_nightly_timestamp(): int
    {
        $ts = strtotime('tomorrow 03:00:00');

        if (!is_int($ts) || $ts <= time()) {
            return time() + DAY_IN_SECONDS;
        }

        return $ts;

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

        $this->maybe_migrate_user_id();

        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
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

        add_submenu_page(
            'mcch-dashboard',
            __('Cuadrante oficial', 'mi-cuadrante-control-horas'),
            __('Cuadrante oficial', 'mi-cuadrante-control-horas'),
            $capability,
            'mcch-official-schedule',
            [$this, 'render_official_schedule_page']
        );

        add_submenu_page(
            'mcch-dashboard',
            __('Vista RRHH', 'mi-cuadrante-control-horas'),
            __('Vista RRHH', 'mi-cuadrante-control-horas'),
            $capability,
            'mcch-hr-overview',
            [$this, 'render_hr_overview_page']
        );

        add_submenu_page(
            'mcch-dashboard',
            __('Configuración', 'mi-cuadrante-control-horas'),
            __('Configuración', 'mi-cuadrante-control-horas'),
            $capability,
            'mcch-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_shortcodes(): void
    {
        add_shortcode('mcch_dashboard', [$this, 'shortcode_dashboard']);
        add_shortcode('mcch_hours_summary', [$this, 'shortcode_hours_summary']);
    }

    public function enqueue_assets(string $hook): void
    {
        if (!in_array($hook, ['toplevel_page_mcch-dashboard', 'mi-cuadrante_page_mcch-official-schedule', 'mi-cuadrante_page_mcch-settings'], true)) {
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
            $entry_user_id = $this->get_entry_user_id($entry_id);

            if ($entry_user_id <= 0 || !$this->can_manage_entry($entry_user_id)) {
                $this->log_unauthorized_attempt('save_entry', $entry_id, $entry_user_id);
                $this->redirect_with_notice('error', __('No tienes permisos para realizar esta acción.', 'mi-cuadrante-control-horas'));
            }

            $data['user_id'] = $entry_user_id;
            $result = $wpdb->update(
                $table,
                $data,
                ['id' => $entry_id, 'user_id' => $entry_user_id],
                ['%d', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%s'],
                ['%d', '%d']
            );
        } else {
            $result = $wpdb->insert(
                $table,
                $data,
                ['%d', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%s']
            );
        }

        if ($result === false) {
            $this->redirect_with_notice('error', __('No se pudo guardar el registro.', 'mi-cuadrante-control-horas'));
        }

        $this->recalculate_period_balance((int) $data['user_id'], (string) $data['work_date'], 'save_entry');
        $this->recalculate_open_periods_for_user((int) $data['user_id'], 'save_entry_open_periods');

        $this->redirect_with_notice('success', __('Registro guardado correctamente.', 'mi-cuadrante-control-horas'), $data['user_id']);
    }

    public function handle_delete_entry(): void
    {
        $this->assert_capability();
        check_admin_referer(self::NONCE_ACTION_DELETE);

        $entry_id = isset($_POST['entry_id']) ? absint($_POST['entry_id']) : 0;
        $target_user_id = $this->resolve_target_user_id($_POST);

        if ($entry_id <= 0) {
            $this->redirect_with_notice('error', __('Registro inválido.', 'mi-cuadrante-control-horas'));
        }

        global $wpdb;
        $entry_user_id = $this->get_entry_user_id($entry_id);

        if ($entry_user_id <= 0 || !$this->can_manage_entry($entry_user_id)) {
            $this->log_unauthorized_attempt('delete_entry', $entry_id, $entry_user_id);
            $this->redirect_with_notice('error', __('No tienes permisos para realizar esta acción.', 'mi-cuadrante-control-horas'), $target_user_id);
        }

        $where = ['id' => $entry_id, 'user_id' => $entry_user_id];

        if (!$this->can_manage_all_entries()) {
            $where['user_id'] = $target_user_id;
        }

        $reference_date = (string) $wpdb->get_var($wpdb->prepare("SELECT work_date FROM {$this->table_name()} WHERE id = %d", $entry_id));
        $result = $wpdb->delete($this->table_name(), $where, ['%d', '%d']);

        if ($result === false) {
            $this->redirect_with_notice('error', __('No se pudo eliminar el registro.', 'mi-cuadrante-control-horas'));
        }

        $reference_date = $reference_date !== '' ? $reference_date : wp_date('Y-m-d');
        $this->recalculate_period_balance((int) $entry_user_id, $reference_date, 'delete_entry');
        $this->recalculate_open_periods_for_user((int) $entry_user_id, 'delete_entry_open_periods');

        $this->redirect_with_notice('success', __('Registro eliminado.', 'mi-cuadrante-control-horas'), $target_user_id);
    }

    public function handle_save_schedule(): void
    {
        $this->assert_capability();
        check_admin_referer(self::NONCE_ACTION_SAVE_SCHEDULE);

        $schedule_id = isset($_POST['schedule_id']) ? absint($_POST['schedule_id']) : 0;
        $data = $this->sanitize_schedule_data($_POST);

        if (empty($data['work_date'])) {
            $this->redirect_with_notice('error', __('La fecha es obligatoria.', 'mi-cuadrante-control-horas'), $data['user_id'], 'mcch-official-schedule');
        }

        global $wpdb;

        if ($schedule_id > 0) {
            $result = $wpdb->update(
                $this->official_schedule_table_name(),
                $data,
                ['id' => $schedule_id, 'user_id' => $data['user_id']],
                ['%d', '%s', '%d', '%s', '%s', '%s'],
                ['%d', '%d']
            );
        } else {
            $result = $wpdb->replace(
                $this->official_schedule_table_name(),
                $data,
                ['%d', '%s', '%d', '%s', '%s', '%s']
            );
        }

        if ($result === false) {
            $this->redirect_with_notice('error', __('No se pudo guardar la planificación oficial.', 'mi-cuadrante-control-horas'), $data['user_id'], 'mcch-official-schedule');
        }

        $this->recalculate_period_balance((int) $data['user_id'], (string) $data['work_date'], 'save_schedule');

        $this->redirect_with_notice('success', __('Planificación oficial guardada correctamente.', 'mi-cuadrante-control-horas'), $data['user_id'], 'mcch-official-schedule');
    }

    public function handle_delete_schedule(): void
    {
        $this->assert_capability();
        check_admin_referer(self::NONCE_ACTION_DELETE_SCHEDULE);

        $schedule_id = isset($_POST['schedule_id']) ? absint($_POST['schedule_id']) : 0;
        $target_user_id = $this->resolve_target_user_id($_POST);

        if ($schedule_id <= 0) {
            $this->redirect_with_notice('error', __('Registro inválido.', 'mi-cuadrante-control-horas'), $target_user_id, 'mcch-official-schedule');
        }

        global $wpdb;
        $reference_date = (string) $wpdb->get_var($wpdb->prepare("SELECT work_date FROM {$this->official_schedule_table_name()} WHERE id = %d", $schedule_id));
        $result = $wpdb->delete($this->official_schedule_table_name(), ['id' => $schedule_id, 'user_id' => $target_user_id], ['%d', '%d']);

        if ($result === false) {
            $this->redirect_with_notice('error', __('No se pudo eliminar la planificación oficial.', 'mi-cuadrante-control-horas'), $target_user_id, 'mcch-official-schedule');
        }

        $reference_date = $reference_date !== '' ? $reference_date : wp_date('Y-m-d');
        $this->recalculate_period_balance($target_user_id, $reference_date, 'delete_schedule');

        $this->redirect_with_notice('success', __('Planificación oficial eliminada.', 'mi-cuadrante-control-horas'), $target_user_id, 'mcch-official-schedule');
    }

    public function handle_save_schedule_fallback(): void
    {
        $this->assert_capability();
        check_admin_referer(self::NONCE_ACTION_SAVE_SCHEDULE);

        if (!$this->can_manage_all_entries()) {
            $this->redirect_with_notice('error', __('No tienes permisos para configurar el fallback.', 'mi-cuadrante-control-horas'), null, 'mcch-official-schedule');
        }

        $mode = isset($_POST['fallback_mode']) ? sanitize_key($_POST['fallback_mode']) : 'zero';
        if (!in_array($mode, ['zero', 'contract'], true)) {
            $mode = 'zero';
        }

        $minutes = isset($_POST['fallback_minutes']) ? $this->time_to_minutes(sanitize_text_field((string) $_POST['fallback_minutes'])) : 0;

        update_option(self::OPTION_SCHEDULE_FALLBACK_MODE, $mode);
        update_option(self::OPTION_SCHEDULE_FALLBACK_MINUTES, $minutes);

        $this->redirect_with_notice('success', __('Fallback de cuadrante oficial actualizado.', 'mi-cuadrante-control-horas'), null, 'mcch-official-schedule');
    }

    public function handle_recalculate_now(): void
    {
        $this->assert_capability();
        check_admin_referer(self::NONCE_ACTION_RECALCULATE_NOW);

        if (!$this->can_manage_all_entries()) {
            $this->redirect_with_notice('error', __('No tienes permisos para recalcular todos los periodos.', 'mi-cuadrante-control-horas'), null, 'mcch-hr-overview');
        }

        $processed = $this->recalculate_open_periods_for_all_active_users('manual_admin');
        $this->redirect_with_notice(
            'success',
            sprintf(
                /* translators: %d: number of users recalculated */
                __('Recalculado completado para %d usuarios activos.', 'mi-cuadrante-control-horas'),
                $processed
            ),
            null,
            'mcch-hr-overview'
        );
    }

    public function handle_scheduled_recalculation(): void
    {
        $this->recalculate_open_periods_for_all_active_users('cron_daily');
    }

    public function render_admin_page(): void
    {
        $this->assert_capability();

        $period = $this->resolve_selected_month_year();
        $target_user_id = $this->resolve_target_user_id($_GET);
        $entries = $this->get_entries_by_month($period['month'], $period['year'], $target_user_id);
        $summary = $this->calculate_summary($entries);
        $period_balance = $this->get_period_balance_overview($target_user_id, $period['month'], $period['year']);
        $edit_entry = $this->get_edit_entry($target_user_id);

        ?>
        <div class="wrap mcch-wrap">
            <h1><?php esc_html_e('Mi Cuadrante - Control Personal', 'mi-cuadrante-control-horas'); ?></h1>

            <?php $this->render_notice(); ?>
            <?php $this->render_dashboard_content($period['month'], $period['year'], $entries, $summary, $period_balance, $edit_entry, true, $target_user_id); ?>
        </div>
        <?php
    }

    public function render_official_schedule_page(): void
    {
        $this->assert_capability();

        $period = $this->resolve_selected_month_year();
        $target_user_id = $this->resolve_target_user_id($_GET);
        $entries = $this->get_schedule_by_month($period['month'], $period['year'], $target_user_id);
        $edit_entry = $this->get_edit_schedule($target_user_id);

        ?>
        <div class="wrap mcch-wrap">
            <h1><?php esc_html_e('Cuadrante oficial', 'mi-cuadrante-control-horas'); ?></h1>

            <?php $this->render_notice(); ?>

            <div class="mcch-grid">
                <section class="mcch-card">
                    <h2><?php echo $edit_entry ? esc_html__('Editar planificación', 'mi-cuadrante-control-horas') : esc_html__('Nueva planificación', 'mi-cuadrante-control-horas'); ?></h2>
                    <?php $this->render_schedule_form($edit_entry, $target_user_id); ?>
                </section>

                <section class="mcch-card">
                    <h2><?php esc_html_e('Filtros y fallback', 'mi-cuadrante-control-horas'); ?></h2>
                    <?php $this->render_month_filter($period['month'], $period['year'], true, $target_user_id, 'mcch-official-schedule'); ?>
                    <?php $this->render_fallback_form(); ?>
                    <?php $this->render_fallback_info(); ?>
                </section>
            </div>

            <section class="mcch-card">
                <h2><?php esc_html_e('Planificación del mes', 'mi-cuadrante-control-horas'); ?></h2>
                <?php $this->render_schedule_table($entries, $target_user_id); ?>
            </section>
        </div>
        <?php
    }


    public function render_hr_overview_page(): void
    {
        $this->assert_capability();

        $period = $this->resolve_selected_month_year();
        $target_user_id = $this->resolve_target_user_id($_GET);
        $team_filter = isset($_GET['team']) ? sanitize_key((string) $_GET['team']) : '';
        $rows = $this->get_hr_period_rows($period['month'], $period['year'], $target_user_id, $team_filter);
        ?>
        <div class="wrap mcch-wrap">
            <h1><?php esc_html_e('Vista RRHH / Admin', 'mi-cuadrante-control-horas'); ?></h1>
            <section class="mcch-card">
                <?php $this->render_month_filter($period['month'], $period['year'], true, $target_user_id, 'mcch-hr-overview'); ?>
                <?php $this->render_recalculate_now_form(); ?>
                <form method="get" class="mcch-filter">
                    <input type="hidden" name="page" value="mcch-hr-overview" />
                    <input type="hidden" name="month" value="<?php echo esc_attr((string) $period['month']); ?>" />
                    <input type="hidden" name="year" value="<?php echo esc_attr((string) $period['year']); ?>" />
                    <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $target_user_id); ?>" />
                    <label>
                        <?php esc_html_e('Equipo (rol)', 'mi-cuadrante-control-horas'); ?>
                        <select name="team">
                            <option value=""><?php esc_html_e('Todos', 'mi-cuadrante-control-horas'); ?></option>
                            <?php foreach ($this->get_selectable_roles() as $role_key => $role_label) : ?>
                                <option value="<?php echo esc_attr($role_key); ?>" <?php selected($team_filter, $role_key); ?>><?php echo esc_html($role_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" class="button"><?php esc_html_e('Aplicar equipo', 'mi-cuadrante-control-horas'); ?></button>
                </form>
            </section>
            <section class="mcch-card">
                <?php $this->render_hr_table($rows); ?>
            </section>
        </div>
        <?php
    }

    public function render_settings_page(): void
    {
        $this->assert_capability();
        $shortcodes = $this->get_shortcode_definitions();
        ?>
        <div class="wrap mcch-wrap">
            <h1><?php esc_html_e('Configuración', 'mi-cuadrante-control-horas'); ?></h1>

            <div class="card" style="max-width: none; margin-top: 1rem;">
                <h2><?php esc_html_e('Shortcodes disponibles', 'mi-cuadrante-control-horas'); ?></h2>
                <p><?php esc_html_e('Puedes copiar estos shortcodes y pegarlos en páginas o entradas de WordPress.', 'mi-cuadrante-control-horas'); ?></p>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Shortcode', 'mi-cuadrante-control-horas'); ?></th>
                            <th><?php esc_html_e('Función', 'mi-cuadrante-control-horas'); ?></th>
                            <th><?php esc_html_e('Ejemplo', 'mi-cuadrante-control-horas'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shortcodes as $shortcode): ?>
                            <tr>
                                <td><code><?php echo esc_html($shortcode['tag']); ?></code></td>
                                <td><?php echo esc_html($shortcode['description']); ?></td>
                                <td><code><?php echo esc_html($shortcode['example']); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    public function shortcode_dashboard(array $atts = []): string
    {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Debes iniciar sesión para ver tu cuadrante de horas.', 'mi-cuadrante-control-horas') . '</p>';
        }

        $period = $this->resolve_selected_month_year();
        $target_user_id = $this->resolve_target_user_id($_GET);
        $entries = $this->get_entries_by_month($period['month'], $period['year'], $target_user_id);
        $summary = $this->calculate_summary($entries);
        $period_balance = $this->get_period_balance_overview($target_user_id, $period['month'], $period['year']);

        ob_start();
        ?>
        <div class="mcch-wrap mcch-shortcode-dashboard">
            <?php $this->render_dashboard_content($period['month'], $period['year'], $entries, $summary, $period_balance, null, false, $target_user_id); ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    public function shortcode_hours_summary(array $atts = []): string
    {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Debes iniciar sesión para consultar el resumen de horas.', 'mi-cuadrante-control-horas') . '</p>';
        }

        $atts = shortcode_atts(
            [
                'period' => 'month',
            ],
            $atts,
            'mcch_hours_summary'
        );

        $period = is_string($atts['period']) ? sanitize_key($atts['period']) : 'month';
        $target_user_id = $this->resolve_target_user_id($_GET);
        $entries = $period === 'week' ? $this->get_entries_by_week($target_user_id) : $this->get_entries_by_month((int) wp_date('n'), (int) wp_date('Y'), $target_user_id);
        $summary = $this->calculate_summary($entries);

        ob_start();
        ?>
        <div class="mcch-wrap mcch-shortcode-summary">
            <h3>
                <?php
                echo esc_html(
                    $period === 'week'
                        ? __('Resumen semanal', 'mi-cuadrante-control-horas')
                        : __('Resumen mensual', 'mi-cuadrante-control-horas')
                );
                ?>
            </h3>
            <?php $this->render_summary($summary, []); ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private function get_shortcode_definitions(): array
    {
        return [
            [
                'tag' => '[mcch_dashboard]',
                'description' => __('Muestra el panel completo con formulario de jornada, filtros y resumen mensual del usuario.', 'mi-cuadrante-control-horas'),
                'example' => '[mcch_dashboard]',
            ],
            [
                'tag' => '[mcch_hours_summary]',
                'description' => __('Muestra un resumen rápido de horas. Permite usar atributos month, year y user_id.', 'mi-cuadrante-control-horas'),
                'example' => '[mcch_hours_summary month="3" year="2026" user_id="12"]',
            ],
        ];
    }

    private function render_dashboard_content(
        int $current_month,
        int $current_year,
        array $entries,
        array $summary,
        array $period_balance,
        ?array $edit_entry,
        bool $is_admin,
        int $target_user_id
    ): void {
        ?>
        <div class="mcch-grid">
            <section class="mcch-card">
                <h2><?php echo $edit_entry ? esc_html__('Editar registro', 'mi-cuadrante-control-horas') : esc_html__('Nuevo registro', 'mi-cuadrante-control-horas'); ?></h2>
                <?php $this->render_entry_form($edit_entry, $target_user_id); ?>
            </section>

            <section class="mcch-card">
                <h2><?php esc_html_e('Resumen mensual', 'mi-cuadrante-control-horas'); ?></h2>
                <?php $this->render_month_filter($current_month, $current_year, $is_admin, $target_user_id, 'mcch-dashboard'); ?>
                <?php $this->render_summary($summary, $period_balance); ?>
            </section>
        </div>

        <section class="mcch-card">
            <h2><?php esc_html_e('Registros del mes', 'mi-cuadrante-control-horas'); ?></h2>
            <?php $this->render_entries_table($entries, $is_admin, $target_user_id); ?>
        </section>
        <?php
    }

    private function render_entry_form(?array $entry = null, int $target_user_id = 0): void
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
            <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $target_user_id); ?>" />
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

    private function render_schedule_form(?array $entry = null, int $target_user_id = 0): void
    {
        $default = [
            'id' => 0,
            'work_date' => wp_date('Y-m-d'),
            'planned_minutes' => 0,
            'shift_name' => '',
            'turn_type' => 'normal',
            'notes' => '',
        ];

        $entry = wp_parse_args($entry ?? [], $default);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mcch-form">
            <input type="hidden" name="action" value="mcch_save_schedule" />
            <input type="hidden" name="schedule_id" value="<?php echo esc_attr((string) $entry['id']); ?>" />
            <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $target_user_id); ?>" />
            <?php wp_nonce_field(self::NONCE_ACTION_SAVE_SCHEDULE); ?>

            <label>
                <?php esc_html_e('Fecha', 'mi-cuadrante-control-horas'); ?>
                <input type="date" name="work_date" value="<?php echo esc_attr($entry['work_date']); ?>" required />
            </label>

            <label>
                <?php esc_html_e('Turno oficial', 'mi-cuadrante-control-horas'); ?>
                <input type="text" name="shift_name" value="<?php echo esc_attr($entry['shift_name']); ?>" placeholder="Mañana / Tarde / Noche" />
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
                <?php esc_html_e('Minutos planificados', 'mi-cuadrante-control-horas'); ?>
                <input type="time" name="planned_time" value="<?php echo esc_attr($this->minutes_to_time((int) $entry['planned_minutes'])); ?>" required />
            </label>

            <label>
                <?php esc_html_e('Notas', 'mi-cuadrante-control-horas'); ?>
                <textarea name="notes" rows="4"><?php echo esc_textarea($entry['notes']); ?></textarea>
            </label>

            <button type="submit" class="button button-primary">
                <?php echo $entry['id'] ? esc_html__('Actualizar planificación', 'mi-cuadrante-control-horas') : esc_html__('Guardar planificación', 'mi-cuadrante-control-horas'); ?>
            </button>
        </form>
        <?php
    }

    private function render_month_filter(int $month, int $year, bool $is_admin = true, int $target_user_id = 0, string $page = 'mcch-dashboard'): void
    {
        ?>
        <form method="get" class="mcch-filter">
            <?php if ($is_admin) : ?>
                <input type="hidden" name="page" value="<?php echo esc_attr($page); ?>" />
            <?php endif; ?>
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
            <?php if ($this->can_manage_all_entries()) : ?>
                <label>
                    <?php esc_html_e('Empleado', 'mi-cuadrante-control-horas'); ?>
                    <select name="user_id">
                        <?php foreach ($this->get_selectable_users() as $user) : ?>
                            <option value="<?php echo esc_attr((string) $user['ID']); ?>" <?php selected($target_user_id, (int) $user['ID']); ?>>
                                <?php echo esc_html($user['display_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php else : ?>
                <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $target_user_id); ?>" />
            <?php endif; ?>
            <button type="submit" class="button"><?php esc_html_e('Filtrar', 'mi-cuadrante-control-horas'); ?></button>
        </form>
        <?php
    }

    private function render_fallback_info(): void
    {
        $fallback_mode = $this->get_schedule_fallback_mode();
        $fallback_minutes = $this->get_schedule_fallback_minutes();
        ?>
        <p>
            <?php
            if ($fallback_mode === 'contract') {
                printf(
                    esc_html__('Fallback actual cuando no hay cuadrante oficial: valor configurable por contrato (%s).', 'mi-cuadrante-control-horas'),
                    esc_html($this->minutes_to_human($fallback_minutes))
                );
            } else {
                esc_html_e('Fallback actual cuando no hay cuadrante oficial: 0 minutos.', 'mi-cuadrante-control-horas');
            }
            ?>
        </p>
        <?php
    }

    private function render_fallback_form(): void
    {
        if (!$this->can_manage_all_entries()) {
            return;
        }

        $mode = $this->get_schedule_fallback_mode();
        $minutes = $this->get_schedule_fallback_minutes();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mcch-form">
            <input type="hidden" name="action" value="mcch_save_schedule_fallback" />
            <?php wp_nonce_field(self::NONCE_ACTION_SAVE_SCHEDULE); ?>
            <label>
                <?php esc_html_e('Modo fallback (sin planificación oficial)', 'mi-cuadrante-control-horas'); ?>
                <select name="fallback_mode">
                    <option value="zero" <?php selected($mode, 'zero'); ?>><?php esc_html_e('0 minutos', 'mi-cuadrante-control-horas'); ?></option>
                    <option value="contract" <?php selected($mode, 'contract'); ?>><?php esc_html_e('Valor fijo configurable', 'mi-cuadrante-control-horas'); ?></option>
                </select>
            </label>
            <label>
                <?php esc_html_e('Minutos fallback (valor fijo)', 'mi-cuadrante-control-horas'); ?>
                <input type="time" name="fallback_minutes" value="<?php echo esc_attr($this->minutes_to_time($minutes)); ?>" />
            </label>
            <button type="submit" class="button">
                <?php esc_html_e('Guardar fallback', 'mi-cuadrante-control-horas'); ?>
            </button>
        </form>
        <?php
    }

    private function render_recalculate_now_form(): void
    {
        if (!$this->can_manage_all_entries()) {
            return;
        }
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mcch-form">
            <input type="hidden" name="action" value="mcch_recalculate_now" />
            <?php wp_nonce_field(self::NONCE_ACTION_RECALCULATE_NOW); ?>
            <button type="submit" class="button button-secondary">
                <?php esc_html_e('Recalcular ahora', 'mi-cuadrante-control-horas'); ?>
            </button>
        </form>
        <?php
    }

    private function render_summary(array $summary, array $period_balance = []): void
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
            <?php if (!empty($period_balance['week'])) : ?>
                <li><strong><?php esc_html_e('Estado semanal ISO', 'mi-cuadrante-control-horas'); ?>:</strong> <span class="mcch-status mcch-status-<?php echo esc_attr($period_balance['week']['status']); ?>"><?php echo esc_html(strtoupper((string) $period_balance['week']['status'])); ?></span> (<?php echo esc_html($this->minutes_to_human((int) $period_balance['week']['difference_minutes'], true)); ?>)</li>
            <?php endif; ?>
            <?php if (!empty($period_balance['month'])) : ?>
                <li><strong><?php esc_html_e('Estado mensual', 'mi-cuadrante-control-horas'); ?>:</strong> <span class="mcch-status mcch-status-<?php echo esc_attr($period_balance['month']['status']); ?>"><?php echo esc_html(strtoupper((string) $period_balance['month']['status'])); ?></span> (<?php echo esc_html($this->minutes_to_human((int) $period_balance['month']['difference_minutes'], true)); ?>)</li>
            <?php endif; ?>
            <li><strong><?php esc_html_e('Diferencia acumulada', 'mi-cuadrante-control-horas'); ?>:</strong> <?php echo esc_html($this->minutes_to_human((int) ($period_balance['accumulated_difference_minutes'] ?? $summary['difference_minutes']), true)); ?></li>
        </ul>
        <?php
    }

    private function render_entries_table(array $entries, bool $show_actions = true, int $target_user_id = 0): void
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
                    <?php if ($show_actions) : ?>
                        <th><?php esc_html_e('Acciones', 'mi-cuadrante-control-horas'); ?></th>
                    <?php endif; ?>
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
                        <?php if ($show_actions) : ?>
                            <td>
                                <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'mcch-dashboard', 'edit' => (int) $entry['id'], 'user_id' => $target_user_id], admin_url('admin.php'))); ?>">
                                    <?php esc_html_e('Editar', 'mi-cuadrante-control-horas'); ?>
                                </a>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mcch-inline-form">
                                    <input type="hidden" name="action" value="mcch_delete_entry" />
                                    <input type="hidden" name="entry_id" value="<?php echo esc_attr((string) $entry['id']); ?>" />
                                    <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $target_user_id); ?>" />
                                    <?php wp_nonce_field(self::NONCE_ACTION_DELETE); ?>
                                    <button type="submit" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js(__('¿Eliminar este registro?', 'mi-cuadrante-control-horas')); ?>');">
                                        <?php esc_html_e('Eliminar', 'mi-cuadrante-control-horas'); ?>
                                    </button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_schedule_table(array $entries, int $target_user_id): void
    {
        if (empty($entries)) {
            echo '<p>' . esc_html__('No hay planificación oficial para este periodo.', 'mi-cuadrante-control-horas') . '</p>';
            return;
        }
        ?>
        <div class="mcch-table-wrapper">
            <table class="widefat striped">
                <thead>
                <tr>
                    <th><?php esc_html_e('Fecha', 'mi-cuadrante-control-horas'); ?></th>
                    <th><?php esc_html_e('Turno oficial', 'mi-cuadrante-control-horas'); ?></th>
                    <th><?php esc_html_e('Tipo', 'mi-cuadrante-control-horas'); ?></th>
                    <th><?php esc_html_e('Planificadas', 'mi-cuadrante-control-horas'); ?></th>
                    <th><?php esc_html_e('Notas', 'mi-cuadrante-control-horas'); ?></th>
                    <th><?php esc_html_e('Acciones', 'mi-cuadrante-control-horas'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $entry) : ?>
                    <tr>
                        <td><?php echo esc_html($entry['work_date']); ?></td>
                        <td><?php echo esc_html($entry['shift_name']); ?></td>
                        <td><?php echo esc_html(ucfirst($entry['turn_type'])); ?></td>
                        <td><?php echo esc_html($this->minutes_to_human((int) $entry['planned_minutes'])); ?></td>
                        <td><?php echo esc_html($entry['notes']); ?></td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'mcch-official-schedule', 'edit_schedule' => (int) $entry['id'], 'user_id' => $target_user_id], admin_url('admin.php'))); ?>">
                                <?php esc_html_e('Editar', 'mi-cuadrante-control-horas'); ?>
                            </a>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mcch-inline-form">
                                <input type="hidden" name="action" value="mcch_delete_schedule" />
                                <input type="hidden" name="schedule_id" value="<?php echo esc_attr((string) $entry['id']); ?>" />
                                <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $target_user_id); ?>" />
                                <?php wp_nonce_field(self::NONCE_ACTION_DELETE_SCHEDULE); ?>
                                <button type="submit" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js(__('¿Eliminar esta planificación oficial?', 'mi-cuadrante-control-horas')); ?>');">
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
        $target_user_id = $this->resolve_target_user_id($source);

        return [
            'user_id' => $target_user_id,
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

    private function sanitize_schedule_data(array $source): array
    {
        return [
            'user_id' => $this->resolve_target_user_id($source),
            'work_date' => isset($source['work_date']) ? sanitize_text_field($source['work_date']) : '',
            'planned_minutes' => $this->time_to_minutes($source['planned_time'] ?? '00:00'),
            'shift_name' => isset($source['shift_name']) ? sanitize_text_field($source['shift_name']) : '',
            'turn_type' => isset($source['turn_type']) ? sanitize_key($source['turn_type']) : 'normal',
            'notes' => isset($source['notes']) ? sanitize_textarea_field($source['notes']) : '',
        ];
    }


    private function legal_calculator(): MCCH_Legal_Calculator
    {
        if ($this->legal_calculator instanceof MCCH_Legal_Calculator) {
            return $this->legal_calculator;
        }

        global $wpdb;
        $this->legal_calculator = new MCCH_Legal_Calculator(
            $wpdb,
            $this->table_name(),
            $this->official_schedule_table_name(),
            $this->period_balance_table_name(),
            $this->alerts_table_name()
        );

        return $this->legal_calculator;
    }

    private function recalculate_period_balance(int $user_id, string $work_date, string $trigger = 'on_demand'): void
    {
        if ($user_id <= 0 || $work_date === '') {
            return;
        }

        $this->legal_calculator()->recalculate_for_user_and_date($user_id, $work_date, $this->resolve_fallback_minutes());
        $this->store_last_calculation_marks($user_id, $work_date, $trigger);
    }

    private function recalculate_open_periods_for_user(int $user_id, string $trigger): void
    {
        if ($user_id <= 0) {
            return;
        }

        $this->recalculate_period_balance($user_id, wp_date('Y-m-d'), $trigger);
    }

    private function recalculate_open_periods_for_all_active_users(string $trigger): int
    {
        $user_ids = $this->get_active_user_ids();

        foreach ($user_ids as $user_id) {
            $this->recalculate_open_periods_for_user($user_id, $trigger);
        }

        return count($user_ids);
    }

    private function get_active_user_ids(): array
    {
        $users = get_users(
            [
                'fields' => ['ID'],
                'orderby' => 'ID',
                'order' => 'ASC',
            ]
        );

        $ids = [];

        foreach ($users as $user) {
            $ids[] = (int) $user->ID;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function store_last_calculation_marks(int $user_id, string $work_date, string $trigger): void
    {
        $ts = strtotime($work_date);

        if ($user_id <= 0 || $work_date === '' || !is_int($ts)) {
            return;
        }

        $week_key = wp_date('o-\\WW', $ts);
        $month_key = wp_date('Y-m', $ts);
        $marks = get_user_meta($user_id, self::USER_META_LAST_PERIOD_CALCULATIONS, true);

        if (!is_array($marks)) {
            $marks = [];
        }

        $mark = [
            'timestamp' => current_time('mysql'),
            'trigger' => $trigger,
        ];

        $marks['week:' . $week_key] = $mark;
        $marks['month:' . $month_key] = $mark;

        if (count($marks) > 50) {
            $marks = array_slice($marks, -50, null, true);
        }

        update_user_meta($user_id, self::USER_META_LAST_PERIOD_CALCULATIONS, $marks);
    }

    private function create_tables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $this->table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
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
            KEY user_work_date (user_id, work_date),
            KEY work_date (work_date)
        ) {$charset};";

        dbDelta($sql);

        $official_schedule = $this->official_schedule_table_name();
        $official_schedule_sql = "CREATE TABLE {$official_schedule} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            work_date DATE NOT NULL,
            planned_minutes INT NOT NULL DEFAULT 0,
            shift_name VARCHAR(120) NOT NULL DEFAULT '',
            turn_type VARCHAR(30) NOT NULL DEFAULT 'normal',
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_date (user_id, work_date),
            KEY idx_work_date (work_date)
        ) {$charset};";

        dbDelta($official_schedule_sql);

        if (!get_option(self::OPTION_SCHEDULE_FALLBACK_MODE)) {
            update_option(self::OPTION_SCHEDULE_FALLBACK_MODE, 'zero');
        }

        if (!get_option(self::OPTION_SCHEDULE_FALLBACK_MINUTES)) {
            update_option(self::OPTION_SCHEDULE_FALLBACK_MINUTES, 480);
        }

        $period_balance_sql = "CREATE TABLE {$this->period_balance_table_name()} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            period_type ENUM('week','month') NOT NULL,
            period_key VARCHAR(20) NOT NULL,
            worked_minutes INT NOT NULL DEFAULT 0,
            planned_minutes INT NOT NULL DEFAULT 0,
            difference_minutes INT NOT NULL DEFAULT 0,
            extra_minutes INT NOT NULL DEFAULT 0,
            status ENUM('ok','warning','exceeded') NOT NULL DEFAULT 'ok',
            calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_period (user_id, period_type, period_key),
            KEY idx_status (status)
        ) {$charset};";

        dbDelta($period_balance_sql);

        $alerts_sql = "CREATE TABLE {$this->alerts_table_name()} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            alert_type VARCHAR(40) NOT NULL,
            period_type ENUM('day','week','month') NOT NULL,
            period_key VARCHAR(20) NOT NULL,
            message VARCHAR(255) NOT NULL,
            severity ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_user_created (user_id, created_at),
            KEY idx_alert_type (alert_type)
        ) {$charset};";

        dbDelta($alerts_sql);

        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }

    private function table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'mcch_entries';
    }

    private function official_schedule_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'mcch_official_schedule';
    }

    private function period_balance_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'mcch_period_balance';
    }

    private function alerts_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'mcch_alerts';
    }

    private function get_entries_by_month(int $month, int $year, int $target_user_id): array
    {
        global $wpdb;

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = wp_date('Y-m-t', strtotime($start));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name()} WHERE user_id = %d AND work_date BETWEEN %s AND %s ORDER BY work_date DESC, id DESC",
                $target_user_id,
                $start,
                $end
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    private function get_schedule_by_month(int $month, int $year, int $target_user_id): array
    {
        global $wpdb;

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = wp_date('Y-m-t', strtotime($start));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->official_schedule_table_name()} WHERE user_id = %d AND work_date BETWEEN %s AND %s ORDER BY work_date DESC, id DESC",
                $target_user_id,
                $start,
                $end
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    private function get_entries_by_week(int $target_user_id): array
    {
        global $wpdb;

        $start = wp_date('Y-m-d', strtotime('monday this week'));
        $end = wp_date('Y-m-d', strtotime('sunday this week'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name()} WHERE user_id = %d AND work_date BETWEEN %s AND %s ORDER BY work_date DESC, id DESC",
                $target_user_id,
                $start,
                $end
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    private function resolve_selected_month_year(): array
    {
        $current_month = isset($_GET['month']) ? absint($_GET['month']) : (int) wp_date('n');
        $current_year = isset($_GET['year']) ? absint($_GET['year']) : (int) wp_date('Y');

        if ($current_month < 1 || $current_month > 12) {
            $current_month = (int) wp_date('n');
        }

        if ($current_year < 2000 || $current_year > 2100) {
            $current_year = (int) wp_date('Y');
        }

        return [
            'month' => $current_month,
            'year' => $current_year,
        ];
    }

    private function get_edit_entry(int $target_user_id): ?array
    {
        if (!isset($_GET['edit'])) {
            return null;
        }

        $entry_id = absint($_GET['edit']);

        if ($entry_id <= 0) {
            return null;
        }

        global $wpdb;

        if ($this->can_manage_all_entries()) {
            $entry = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$this->table_name()} WHERE id = %d", $entry_id),
                ARRAY_A
            );

            return is_array($entry) ? $entry : null;
        }

        $entry = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table_name()} WHERE id = %d AND user_id = %d", $entry_id, $target_user_id),
            ARRAY_A
        );

        return is_array($entry) ? $entry : null;
    }

    private function get_edit_schedule(int $target_user_id): ?array
    {
        if (!isset($_GET['edit_schedule'])) {
            return null;
        }

        $schedule_id = absint($_GET['edit_schedule']);

        if ($schedule_id <= 0) {
            return null;
        }

        global $wpdb;
        $entry = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->official_schedule_table_name()} WHERE id = %d AND user_id = %d", $schedule_id, $target_user_id),
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

        $expected_by_date = $this->get_expected_minutes_for_entries($entries);

        foreach ($entries as $entry) {
            $summary['worked_minutes'] += (int) ($entry['worked_minutes'] ?? 0);
            $date_key = isset($entry['work_date']) ? (string) $entry['work_date'] : '';
            $summary['expected_minutes'] += $expected_by_date[$date_key] ?? (int) ($entry['expected_minutes'] ?? 0);
            $summary['extra_minutes'] += (int) ($entry['extra_minutes'] ?? 0);
            $summary['vacation_days'] += (int) ($entry['vacation_day'] ?? 0);
            $summary['personal_days'] += (int) ($entry['personal_day'] ?? 0);
        }

        $summary['difference_minutes'] = $summary['worked_minutes'] - $summary['expected_minutes'];

        return $summary;
    }

    private function get_expected_minutes_for_entries(array $entries): array
    {
        if (empty($entries)) {
            return [];
        }

        $user_id = isset($entries[0]['user_id']) ? (int) $entries[0]['user_id'] : 0;
        if ($user_id <= 0) {
            return [];
        }

        $dates = [];
        foreach ($entries as $entry) {
            if (!empty($entry['work_date'])) {
                $dates[] = sanitize_text_field((string) $entry['work_date']);
            }
        }

        $dates = array_values(array_unique($dates));
        if (empty($dates)) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(', ', array_fill(0, count($dates), '%s'));
        $query = "SELECT work_date, planned_minutes FROM {$this->official_schedule_table_name()} WHERE user_id = %d AND work_date IN ({$placeholders})";
        $params = array_merge([$user_id], $dates);
        $rows = $wpdb->get_results($wpdb->prepare($query, ...$params), ARRAY_A);

        $official = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $official[(string) $row['work_date']] = (int) $row['planned_minutes'];
            }
        }

        $fallback = $this->resolve_fallback_minutes();
        $result = [];

        foreach ($entries as $entry) {
            $date = isset($entry['work_date']) ? (string) $entry['work_date'] : '';
            if ($date === '') {
                continue;
            }

            if (array_key_exists($date, $official)) {
                $result[$date] = $official[$date];
                continue;
            }

            $result[$date] = $fallback;
        }

        return $result;
    }

    private function get_schedule_fallback_mode(): string
    {
        $mode = get_option(self::OPTION_SCHEDULE_FALLBACK_MODE, 'zero');
        return $mode === 'contract' ? 'contract' : 'zero';
    }

    private function get_schedule_fallback_minutes(): int
    {
        return max(0, absint((string) get_option(self::OPTION_SCHEDULE_FALLBACK_MINUTES, '480')));
    }

    private function resolve_fallback_minutes(): int
    {
        if ($this->get_schedule_fallback_mode() === 'contract') {
            return $this->get_schedule_fallback_minutes();
        }

        return 0;
    }

    private function get_period_balance_overview(int $user_id, int $month, int $year): array
    {
        $month_start = sprintf('%04d-%02d-01', $year, $month);
        $month_end = wp_date('Y-m-t', strtotime($month_start));
        $month_key = wp_date('Y-m', strtotime($month_start));
        $week_key = wp_date('o-\WW', strtotime($month_start));

        global $wpdb;

        $month_balance = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->period_balance_table_name()} WHERE user_id = %d AND period_type = 'month' AND period_key = %s", $user_id, $month_key),
            ARRAY_A
        );

        $week_balance = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->period_balance_table_name()} WHERE user_id = %d AND period_type = 'week' AND period_key = %s", $user_id, $week_key),
            ARRAY_A
        );

        $accumulated = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(difference_minutes), 0) FROM {$this->period_balance_table_name()} WHERE user_id = %d AND period_type = 'month' AND period_key BETWEEN %s AND %s",
                $user_id,
                substr($month_start, 0, 7),
                substr($month_end, 0, 7)
            )
        );

        return [
            'week' => is_array($week_balance) ? $week_balance : [],
            'month' => is_array($month_balance) ? $month_balance : [],
            'accumulated_difference_minutes' => $accumulated,
        ];
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

    private function redirect_with_notice(string $type, string $message, ?int $target_user_id = null, string $page = 'mcch-dashboard'): void
    {
        $resolved_user_id = $target_user_id ?? $this->resolve_target_user_id($_REQUEST);

        $url = add_query_arg(
            [
                'page' => $page,
                'mcch_notice' => $type,
                'mcch_message' => rawurlencode($message),
                'user_id' => $resolved_user_id,
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
        if (!is_user_logged_in()) {
            wp_die(esc_html__('Debes iniciar sesión para acceder.', 'mi-cuadrante-control-horas'));
        }

        if (!current_user_can($this->get_capability()) && !current_user_can('read')) {
            wp_die(esc_html__('No tienes permisos suficientes para acceder.', 'mi-cuadrante-control-horas'));
        }
    }

    private function can_manage_all_entries(): bool
    {
        return current_user_can($this->get_capability()) || current_user_can('manage_options');
    }

    private function can_manage_entry(int $entry_user_id): bool
    {
        if ($entry_user_id <= 0) {
            return false;
        }

        if ($this->can_manage_all_entries()) {
            return true;
        }

        return get_current_user_id() === $entry_user_id;
    }

    private function get_entry_user_id(int $entry_id): int
    {
        if ($entry_id <= 0) {
            return 0;
        }

        global $wpdb;

        $entry_user_id = $wpdb->get_var(
            $wpdb->prepare("SELECT user_id FROM {$this->table_name()} WHERE id = %d", $entry_id)
        );

        return absint($entry_user_id);
    }

    private function log_unauthorized_attempt(string $action, int $entry_id, int $entry_user_id = 0): void
    {
        error_log(
            sprintf(
                '[MCCH] Unauthorized attempt: %s',
                wp_json_encode(
                    [
                        'action' => sanitize_key($action),
                        'entry_id' => absint($entry_id),
                        'entry_user_id' => absint($entry_user_id),
                        'current_user_id' => get_current_user_id(),
                        'ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
                    ]
                )
            )
        );
    }

    private function resolve_target_user_id(array $source): int
    {
        $current_user_id = get_current_user_id();

        if ($current_user_id <= 0) {
            return 0;
        }

        if (!$this->can_manage_all_entries()) {
            return $current_user_id;
        }

        $requested_user_id = isset($source['user_id']) ? absint($source['user_id']) : 0;

        if ($requested_user_id > 0) {
            return $requested_user_id;
        }

        return $current_user_id;
    }


    private function get_selectable_roles(): array
    {
        global $wp_roles;

        if (!isset($wp_roles->roles) || !is_array($wp_roles->roles)) {
            return [];
        }

        $roles = [];
        foreach ($wp_roles->roles as $role_key => $role_config) {
            $roles[(string) $role_key] = isset($role_config['name']) ? (string) $role_config['name'] : (string) $role_key;
        }

        return $roles;
    }

    private function get_hr_period_rows(int $month, int $year, int $target_user_id, string $team_filter = ''): array
    {
        global $wpdb;

        $month_key = sprintf('%04d-%02d', $year, $month);
        $sql = "SELECT pb.user_id, pb.status, pb.difference_minutes, pb.worked_minutes, pb.planned_minutes, u.display_name
            FROM {$this->period_balance_table_name()} pb
            INNER JOIN {$wpdb->users} u ON u.ID = pb.user_id
            WHERE pb.period_type = 'month' AND pb.period_key = %s";
        $params = [$month_key];

        if ($target_user_id > 0) {
            $sql .= ' AND pb.user_id = %d';
            $params[] = $target_user_id;
        }

        $sql .= ' ORDER BY u.display_name ASC';
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        if ($team_filter === '') {
            return $rows;
        }

        $filtered = [];
        foreach ($rows as $row) {
            $user = get_user_by('id', (int) $row['user_id']);
            $roles = $user instanceof WP_User ? $user->roles : [];
            if (in_array($team_filter, $roles, true)) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    private function render_hr_table(array $rows): void
    {
        if (empty($rows)) {
            echo '<p>' . esc_html__('No hay datos de balance para los filtros seleccionados.', 'mi-cuadrante-control-horas') . '</p>';
            return;
        }
        ?>
        <div class="mcch-table-wrapper">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Empleado', 'mi-cuadrante-control-horas'); ?></th>
                        <th><?php esc_html_e('Trabajadas', 'mi-cuadrante-control-horas'); ?></th>
                        <th><?php esc_html_e('Planificadas', 'mi-cuadrante-control-horas'); ?></th>
                        <th><?php esc_html_e('Diferencia', 'mi-cuadrante-control-horas'); ?></th>
                        <th><?php esc_html_e('Estado', 'mi-cuadrante-control-horas'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $row['display_name']); ?></td>
                        <td><?php echo esc_html($this->minutes_to_human((int) $row['worked_minutes'])); ?></td>
                        <td><?php echo esc_html($this->minutes_to_human((int) $row['planned_minutes'])); ?></td>
                        <td><?php echo esc_html($this->minutes_to_human((int) $row['difference_minutes'], true)); ?></td>
                        <td><span class="mcch-status mcch-status-<?php echo esc_attr((string) $row['status']); ?>"><?php echo esc_html(strtoupper((string) $row['status'])); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function get_selectable_users(): array
    {
        $users = get_users(
            [
                'fields' => ['ID', 'display_name'],
                'orderby' => 'display_name',
                'order' => 'ASC',
            ]
        );

        $result = [];

        foreach ($users as $user) {
            $result[] = [
                'ID' => (int) $user->ID,
                'display_name' => (string) $user->display_name,
            ];
        }

        return $result;
    }

    private function maybe_migrate_user_id(): void
    {
        global $wpdb;

        $table = $this->table_name();
        $column = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'user_id'), ARRAY_A);

        if (!is_array($column)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id");
            $column = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'user_id'), ARRAY_A);
        }

        $fallback_user_id = $this->get_migration_fallback_user_id();
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET user_id = %d WHERE user_id IS NULL OR user_id = 0", $fallback_user_id));

        if (is_array($column) && isset($column['Null']) && strtoupper((string) $column['Null']) === 'YES') {
            $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN user_id BIGINT UNSIGNED NOT NULL");
        }

        $index_exists = $wpdb->get_var("SHOW INDEX FROM {$table} WHERE Key_name = 'user_work_date'");

        if (!$index_exists) {
            $wpdb->query("ALTER TABLE {$table} ADD KEY user_work_date (user_id, work_date)");
        }
    }

    private function get_migration_fallback_user_id(): int
    {
        $option_user = absint((string) get_option(self::OPTION_MIGRATION_USER_ID, '0'));

        if ($option_user > 0) {
            return $option_user;
        }

        $admin_user = get_users(
            [
                'role' => 'administrator',
                'orderby' => 'ID',
                'order' => 'ASC',
                'number' => 1,
                'fields' => ['ID'],
            ]
        );

        if (is_array($admin_user) && !empty($admin_user[0]->ID)) {
            return (int) $admin_user[0]->ID;
        }

        return max(1, get_current_user_id());
    }
}

register_activation_hook(__FILE__, ['Mi_Cuadrante_Control_Horas', 'activate']);
register_deactivation_hook(__FILE__, ['Mi_Cuadrante_Control_Horas', 'deactivate']);

Mi_Cuadrante_Control_Horas::instance();
