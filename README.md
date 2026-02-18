# Mi Cuadrante - Control de Horas (WordPress Plugin)

Plugin para WordPress pensado para control personal de jornada laboral:

- Días trabajados
- Horas realizadas
- Horas exigidas por empresa
- Horas extra
- Vacaciones
- Días de asuntos propios
- Tipo de turno (normal, festivo, guardia, baja)
- Notas diarias

## Funcionalidades

- Panel en `WP Admin > Mi Cuadrante`
- Alta, edición y borrado de registros diarios
- Filtro por mes y año
- Resumen mensual con diferencia entre horas trabajadas y horas exigidas

## Instalación

1. Copia la carpeta `mi-cuadrante-control-horas` dentro de `wp-content/plugins/`.
2. En WordPress, ve a **Plugins**.
3. Activa **Mi Cuadrante - Control de Horas**.
4. Accede al menú **Mi Cuadrante** en el administrador.

## Uso recomendado

- Añade un registro por cada día laborable.
- Anota en “Notas” incidencias (por ejemplo, ampliaciones de jornada no previstas).
- Compara el resumen mensual con la nómina.

## Shortcodes

Puedes usar los siguientes shortcodes en páginas o entradas de WordPress:

- `[mcch_dashboard]`
  - Muestra el formulario de registro y el resumen del mes para el usuario logado.
  - Incluye filtro por mes y año y tabla de registros.
  - Si el usuario no ha iniciado sesión, muestra un mensaje amigable indicando que debe autenticarse.

- `[mcch_hours_summary]`
  - Muestra un resumen de horas del periodo indicado.
  - Atributo opcional: `period` con valores `month` (por defecto) o `week`.
  - Ejemplos:
    - `[mcch_hours_summary]`
    - `[mcch_hours_summary period="week"]`
  - Si el usuario no ha iniciado sesión, muestra un mensaje amigable indicando que debe autenticarse.

- `[mcch_company_schedule_form]`
  - Muestra el formulario de planificación oficial para el usuario logado.
  - Incluye filtro por mes y año y la tabla de planificación del periodo.
  - Permite guardar y eliminar planificación oficial usando las acciones del plugin.
  - Si el usuario no ha iniciado sesión, muestra un mensaje amigable indicando que debe autenticarse.


### Ejemplos de uso en páginas de WordPress

1. Crea una página nueva (por ejemplo: **Mi Cuadrante**).
2. Añade un bloque **Shortcode**.
3. Pega uno de estos códigos:
   - `[mcch_dashboard]`
   - `[mcch_hours_summary period="month"]`
   - `[mcch_hours_summary period="week"]`
   - `[mcch_company_schedule_form]`
4. Publica la página y comprueba el resultado con un usuario logado.

## Datos y privacidad

- Los datos se guardan en una tabla propia de WordPress: `{wp_prefix}mcch_entries`.
- Al desactivar el plugin no se eliminan datos.

## Implementación multiusuario y cuadrante oficial

Se añadió una guía técnica con SQL para Webmin y plan por fases en `IMPLEMENTACION_MULTIUSUARIO.md`.
