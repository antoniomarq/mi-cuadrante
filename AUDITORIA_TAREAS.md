# Auditoría rápida y tareas propuestas

## Hallazgos clave

1. **Error tipográfico en el README**: en la sección de funcionalidades se usa "borrado" junto a "alta, edición", mientras que en el resto del documento se prioriza lenguaje más neutro y consistente de acciones CRUD. Conviene unificar a "eliminación" para mantener consistencia terminológica.
2. **Fallo potencial en mensajes flash**: `redirect_with_notice()` aplica `rawurlencode()` antes de pasar `mcch_message` a `add_query_arg()`, lo que puede provocar doble codificación del texto en la URL y que el mensaje aparezca con caracteres escapados.
3. **Discrepancia de documentación/UX**: README habla de "Tipo de turno" pero la interfaz muestra la etiqueta "Tipo de día" para el mismo campo (`turn_type`), lo que puede confundir a quien lea la documentación y luego use el panel.
4. **Cobertura de pruebas insuficiente**: no hay pruebas automáticas para validación de horas (`time_to_minutes`, `minutes_to_time`) ni para el cálculo del resumen mensual.

## Backlog mínimo (4 tareas)

### Tarea 1 — Corregir error tipográfico/terminológico
- **Objetivo**: homogeneizar el término de la acción de borrado en README.
- **Cambio propuesto**: reemplazar "Alta, edición y borrado de registros diarios" por "Alta, edición y eliminación de registros diarios".
- **Criterio de aceptación**: README usa terminología consistente para acciones CRUD en todas sus secciones.

### Tarea 2 — Solucionar fallo de codificación en redirecciones
- **Objetivo**: evitar doble codificación de `mcch_message`.
- **Cambio propuesto**: eliminar `rawurlencode()` en `redirect_with_notice()` y delegar el encoding a `add_query_arg()`.
- **Criterio de aceptación**: al guardar/eliminar un registro, el mensaje se renderiza correctamente en `render_notice()` sin secuencias `%XX` visibles.

### Tarea 3 — Corregir discrepancia de comentario/documentación
- **Objetivo**: alinear documentación e interfaz sobre el campo `turn_type`.
- **Cambio propuesto**: elegir un único término ("Tipo de turno" o "Tipo de día") y aplicarlo en README y etiquetas de UI.
- **Criterio de aceptación**: el mismo concepto aparece con la misma terminología en README y en el formulario del panel admin.

### Tarea 4 — Mejorar pruebas automáticas
- **Objetivo**: cubrir conversiones de tiempo y resumen mensual.
- **Cambio propuesto**:
  - añadir tests unitarios para `time_to_minutes()` (casos válidos e inválidos),
  - tests para `minutes_to_time()` (límite superior de horas),
  - tests de `calculate_summary()` con combinaciones de días normales, vacaciones y asuntos propios.
- **Criterio de aceptación**: suite ejecutable localmente con al menos 1 archivo de pruebas y cobertura de escenarios positivos/negativos para lógica horaria.
