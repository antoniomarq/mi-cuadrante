# Auditoría rápida y tareas propuestas

## Hallazgos clave

1. **Error tipográfico en documentación técnica**: en `IMPLEMENTACION_MULTIUSUARIO.md` aparecen formas sin tilde como "recalculo", cuando en español normativo corresponde "recálculo".
2. **Fallo en redirección de mensajes flash**: `redirect_with_notice()` aplica `rawurlencode()` antes de `add_query_arg()`, lo que puede provocar doble codificación y mensajes con `%20`/`%C3%A1` visibles.
3. **Discrepancia entre documentación y UI**: el README usa "Tipo de turno", pero en el formulario del panel la etiqueta del campo `turn_type` muestra "Tipo de día".
4. **Pruebas automáticas inexistentes para lógica crítica**: no hay test suite para conversiones horarias ni para el cálculo del resumen mensual.

## Backlog propuesto (4 tareas)

### Tarea 1 — Corregir error tipográfico
- **Objetivo**: normalizar ortografía en documentación técnica.
- **Cambio propuesto**: eliminar `rawurlencode()` en `redirect_with_notice()` y pasar el texto limpio a `add_query_arg()`.
- **Criterio de aceptación**: tras guardar/eliminar, los avisos se muestran correctamente en `render_notice()` sin secuencias de escape visibles.

### Tarea 2 — Solucionar fallo de codificación en redirecciones
- **Objetivo**: evitar doble codificación de `mcch_message`.
- **Cambio propuesto**: eliminar `rawurlencode()` en `redirect_with_notice()` y delegar el encoding a `add_query_arg()`.
- **Criterio de aceptación**: al guardar/eliminar un registro, el mensaje se renderiza correctamente en `render_notice()` sin secuencias `%XX` visibles.

### Tarea 3 — Corregir discrepancia de documentación
- **Objetivo**: usar una única terminología para `turn_type`.
- **Cambio propuesto**: elegir "Tipo de turno" o "Tipo de día" y aplicarlo de forma consistente en README y formulario admin.
- **Criterio de aceptación**: documentación y pantalla de edición muestran el mismo término.

### Tarea 4 — Mejorar pruebas de lógica horaria
- **Objetivo**: elevar confianza en funciones de negocio.
- **Cambio propuesto**:
  - añadir pruebas unitarias para `time_to_minutes()` (válidos, inválidos y bordes),
  - pruebas para `minutes_to_time()` (0, intermedios y tope 23:59),
  - pruebas de `calculate_summary()` con días normales, vacaciones y asuntos propios.
- **Criterio de aceptación**: existe una suite ejecutable localmente que cubre al menos los escenarios positivos y negativos de las tres funciones.
