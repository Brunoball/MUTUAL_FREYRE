# Mutual Freyre API

Backend PHP 8.2+ organizado como **monolito modular simple**. La lógica existente no fue cambiada; solamente se redujo la profundidad de carpetas.

## Estructura

```text
backend/
├── app/
│   ├── Core/                 # HTTP, base de datos, entorno, auditoría y soporte común
│   └── Modules/
│       ├── Auth/             # Controller, Service, Repository y routes.php
│       ├── Personas/         # Controller, Service, Policy y routes.php
│       └── ...               # Un directorio simple por módulo
├── bootstrap/                # Autoload
├── routes/                   # Registro general de rutas
├── public/                   # Punto de entrada HTTP
├── storage/                  # Logs y archivos privados
├── bin/                      # Scripts de consola
└── tests/                    # Pruebas generales
```

## Regla práctica

- Código exclusivo de un módulo: `app/Modules/NombreModulo/`.
- Código compartido por varios módulos: `app/Core/`.
- Cada módulo conserva separados sus roles por nombre de archivo (`Controller`, `Service`, `Policy`, `Repository`) sin crear una carpeta adicional para cada uno.

## Desarrollo local

1. Copiar `.env.example` como `.env`.
2. Configurar la base de datos.
3. Ejecutar `php -S localhost:3001 server.php`.
4. Consultar `GET /api/public/v1/health`.
