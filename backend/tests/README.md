# Pruebas backend

Desde la carpeta `backend`:

```bash
php tests/InformeRiesgoUnitTest.php
php tests/RepetScreeningUnitTest.php
```

Las pruebas cubren validación de CUIT, normalización BCRA, reglas LA/FT y
comparación RePET con respuestas simuladas. Antes de producción también deben
ejecutarse pruebas integrales contra una copia de la base y el dominio real.
