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

## Datos y privacidad

- Los datos se guardan en una tabla propia de WordPress: `{wp_prefix}mcch_entries`.
- Al desactivar el plugin no se eliminan datos.
