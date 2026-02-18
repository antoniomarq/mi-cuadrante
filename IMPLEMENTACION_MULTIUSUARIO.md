# Implementación en 3 pasos (adaptada al código actual)

Este documento está preparado **tras revisar primero la base de código actual**:

- El plugin actual usa una sola tabla: `wp_mcch_entries` (sin `user_id`).
- La vista principal está en `WP Admin > Mi Cuadrante`.
- El resumen mensual se calcula solo con los registros cargados en esa tabla.

> Estado actual: es un control "global" (no segmentado por usuario), por lo que para hacerlo visible por usuario registrado hay que introducir capa multiusuario y cuadrante oficial.

---

## Paso 1) Multiusuario real en registros diarios

Objetivo: que cada registro de horas pertenezca a un usuario (`user_id`) y que cada empleado vea solo sus datos (salvo admin/supervisor).

### SQL (Webmin > Tools > SQL Query)

> Si tu prefijo no es `wp_`, reemplázalo en todos los comandos.

```sql
-- 1. Añadir columna user_id a la tabla actual
ALTER TABLE `wp_mcch_entries`
  ADD COLUMN `user_id` BIGINT UNSIGNED NULL AFTER `id`;

-- 2. Rellenar user_id para históricos (asignar admin ID=1 temporalmente)
UPDATE `wp_mcch_entries`
SET `user_id` = 1
WHERE `user_id` IS NULL;

-- 3. Hacerla obligatoria e indexar
ALTER TABLE `wp_mcch_entries`
  MODIFY COLUMN `user_id` BIGINT UNSIGNED NOT NULL,
  ADD KEY `idx_user_date` (`user_id`, `work_date`),
  ADD KEY `idx_user_turn` (`user_id`, `turn_type`);
```

### Tareas de desarrollo (código)

1. Al guardar registro, persistir `user_id = get_current_user_id()` (o usuario seleccionado por RRHH/admin).
2. En listados y resumen, filtrar por `user_id` además de mes/año.
3. Añadir capacidades:
   - Empleado: ver/editar solo lo suyo.
   - Supervisor/Admin: ver equipo o todos.
4. Proteger edición/borrado para impedir acceso a registros de otros usuarios sin permiso.

---

## Paso 2) Cuadrante oficial de empresa (planificación)

Objetivo: almacenar las horas planificadas oficiales por usuario y fecha para comparar contra horas reales.

### SQL (crear tablas)

```sql
-- Tabla de cuadrante oficial por usuario y día
CREATE TABLE IF NOT EXISTS `wp_mcch_official_schedule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `work_date` DATE NOT NULL,
  `planned_minutes` INT NOT NULL DEFAULT 0,
  `shift_name` VARCHAR(120) NOT NULL DEFAULT '',
  `turn_type` VARCHAR(30) NOT NULL DEFAULT 'normal',
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_date` (`user_id`,`work_date`),
  KEY `idx_work_date` (`work_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla de configuración legal y contractual por usuario
CREATE TABLE IF NOT EXISTS `wp_mcch_legal_limits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `weekly_limit_minutes` INT NOT NULL DEFAULT 2400,
  `monthly_limit_minutes` INT NOT NULL DEFAULT 9600,
  `daily_limit_minutes` INT NOT NULL DEFAULT 540,
  `max_extra_year_minutes` INT NOT NULL DEFAULT 4800,
  `effective_from` DATE NOT NULL,
  `effective_to` DATE NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_effective` (`user_id`, `effective_from`, `effective_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Tareas de desarrollo (código)

1. Nueva pantalla admin “Cuadrante oficial” (CRUD por usuario/fecha).
2. Importación por CSV (opcional pero recomendada para RRHH).
3. Al calcular resumen semanal/mensual, tomar planificado desde `official_schedule`.
4. Resolver conflictos: si no hay planificado en una fecha, usar fallback (0 o valor por contrato).

---

## Paso 3) Control de exceso/defecto legal (semanal y mensual)

Objetivo: comparar real vs oficial y generar alertas por exceso/defecto y límites legales.

### SQL (tabla de acumulados/alertas)

```sql
-- Resumen por usuario y periodo para acelerar paneles
CREATE TABLE IF NOT EXISTS `wp_mcch_period_balance` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `period_type` ENUM('week','month') NOT NULL,
  `period_key` VARCHAR(20) NOT NULL,
  `worked_minutes` INT NOT NULL DEFAULT 0,
  `planned_minutes` INT NOT NULL DEFAULT 0,
  `difference_minutes` INT NOT NULL DEFAULT 0,
  `extra_minutes` INT NOT NULL DEFAULT 0,
  `status` ENUM('ok','warning','exceeded') NOT NULL DEFAULT 'ok',
  `calculated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_period` (`user_id`,`period_type`,`period_key`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Alertas generadas
CREATE TABLE IF NOT EXISTS `wp_mcch_alerts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `alert_type` VARCHAR(40) NOT NULL,
  `period_type` ENUM('day','week','month') NOT NULL,
  `period_key` VARCHAR(20) NOT NULL,
  `message` VARCHAR(255) NOT NULL,
  `severity` ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_alert_type` (`alert_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Tareas de desarrollo (código)

1. Servicio de cálculo:
   - diario: horas netas reales,
   - semanal/mensual: acumulados y diferencia vs planificado.
2. Regla de límites:
   - leer límites de `legal_limits` por usuario y vigencia,
   - marcar `warning/exceeded`.
3. Mostrar alertas en dashboard de usuario y panel RRHH.
4. Programar recalculo (WP-Cron) nocturno + recalculo puntual al guardar registro.

---

## Orden recomendado de ejecución (operativo)

1. **Backup** de DB completa.
2. Ejecutar SQL del Paso 1.
3. Ajustar código multiusuario y desplegar.
4. Ejecutar SQL del Paso 2.
5. Implementar pantalla de cuadrante oficial y validarla.
6. Ejecutar SQL del Paso 3.
7. Activar cálculos/alertas y probar con 2-3 usuarios reales.

---

## Checklist de verificación mínima

- Un empleado A no puede ver registros de B.
- RRHH/Admin sí puede ver por usuario/equipo.
- Para una semana, `worked_minutes` y `planned_minutes` cuadran con datos diarios.
- Si supera límite semanal/mensual, aparece alerta en panel.
- Exportación mensual por usuario coincide con resumen mostrado en pantalla.
