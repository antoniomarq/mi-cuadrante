# Revisión de la base de código y backlog propuesto

## Problemas identificados

1. **Error tipográfico en documentación técnica**
   - En `IMPLEMENTACION_MULTIUSUARIO.md` aparece "recalculo" sin tilde; en español corresponde "recálculo".

2. **Fallo funcional en notificaciones tras redirección**
   - `redirect_with_notice()` aplica `rawurlencode()` a `mcch_message` antes de llamar a `add_query_arg()`.
   - `add_query_arg()` vuelve a codificar el valor, y en la interfaz pueden aparecer secuencias `%20`, `%C3%A1`, etc.

3. **Discrepancia entre documentación y UI**
   - El `README.md` describe el campo como **"Tipo de turno"**.
   - En el formulario de administración (`mi-cuadrante-control-horas.php`) se muestra **"Tipo de día"** para el mismo campo (`turn_type`).

4. **Cobertura de pruebas insuficiente en lógica crítica**
   - No hay una suite automatizada para validar de forma unitaria `time_to_minutes()`, `minutes_to_time()` y `calculate_summary()`.

## Conjunto de tareas propuesto

### 1) Corregir error tipográfico
- **Objetivo**: normalizar ortografía en documentación técnica.
- **Cambio propuesto**: sustituir "recalculo" por "recálculo" en `IMPLEMENTACION_MULTIUSUARIO.md`.
- **Criterio de aceptación**: no quedan ocurrencias de "recalculo" en documentación mantenida.

### 2) Solucionar fallo de codificación en redirecciones
- **Objetivo**: evitar doble codificación de `mcch_message`.
- **Cambio propuesto**: eliminar `rawurlencode()` en `redirect_with_notice()` y dejar que `add_query_arg()` gestione el encoding.
- **Criterio de aceptación**: al guardar o eliminar un registro, `render_notice()` muestra el texto legible (sin `%XX`).

### 3) Corregir discrepancia de documentación/UI
- **Objetivo**: usar terminología coherente para `turn_type`.
- **Cambio propuesto**: escoger un único término (por ejemplo, "Tipo de turno") y aplicarlo de forma consistente en `README.md` y etiquetas del admin.
- **Criterio de aceptación**: documentación y pantalla de edición muestran exactamente el mismo texto para ese campo.

### 4) Mejorar pruebas de la lógica horaria
- **Objetivo**: elevar la confianza en reglas de negocio.
- **Cambio propuesto**:
  - añadir pruebas unitarias para `time_to_minutes()` (entradas válidas, inválidas y casos borde),
  - añadir pruebas para `minutes_to_time()` (0, valores intermedios, límite superior aceptado),
  - añadir pruebas para `calculate_summary()` con mezcla de días normales, vacaciones y asuntos propios.
- **Criterio de aceptación**: existe suite ejecutable localmente que cubre escenarios positivos y negativos de las tres funciones.

