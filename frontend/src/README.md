# Estructura del frontend

La estructura fue simplificada sin cambiar la lógica del sistema.

- `app/`: arranque, router, autenticación y protección de rutas.
- `config/`: configuración general, navegación y catálogo de módulos.
- `modules/`: una carpeta por módulo; dentro quedan juntos la pantalla, API y permisos.
- `shared/`: componentes y utilidades reutilizables por varios módulos.
- `styles/`: estilos globales.

Regla práctica: si un archivo pertenece a un solo módulo, va en su carpeta; si lo usan varios módulos, va en `shared`.
