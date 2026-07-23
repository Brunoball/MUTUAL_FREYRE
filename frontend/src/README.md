# Estructura del frontend

La estructura fue simplificada sin cambiar la lógica del sistema.

- `app/`: arranque, router, autenticación y protección de rutas.
- `config/`: configuración general, navegación y catálogo de módulos.
- `Global/`: componentes, variables y estilos reutilizables en todo el sistema.
- `modules/`: una carpeta por módulo; dentro quedan juntos la pantalla, su CSS, API y permisos.
- `shared/`: layout, componentes técnicos y utilidades compartidas; cada componente visual conserva su CSS al lado.

Regla práctica: si un estilo pertenece a una sola pantalla, queda junto a esa pantalla. Si forma parte del sistema visual reutilizable, queda en `Global/Global_css`.
