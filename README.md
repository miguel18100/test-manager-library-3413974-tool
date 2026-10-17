# Manager Library · Tool

Sembrador de datos de demostración. Usa **solo la API** (nunca SQL directo) y deja el sistema listo
para mostrarse: 1 bibliotecario, 40 libros, 10 socios y 15 préstamos, 3 de ellos vencidos
(el reporte de más prestados y la lista de vencidos tienen datos).

## Requisitos

- PHP 8.3+ con la extensión `curl`.
- La API en marcha sobre una base **recién creada**.
- La API iniciada con `ALLOW_SIMULATED_TIME=true`: los tres vencidos se registran con la cabecera
  `X-Simulated-Time` (hace 20 días). Terminado el sembrado, vuelve a `false`.

## Uso

```
export ADMIN_USERNAME=admin
export ADMIN_PASSWORD='la-misma-con-que-arrancó-la-API'
# opcional: export API_URL=http://localhost:8000/api/v1
# opcional: export SEED_LIBRARIAN_PASSWORD='...'   (si no, se genera y se imprime una sola vez)
php seed.php
```

El script termina con error si algo no coincide: estado HTTP inesperado, base con datos previos o
un número de vencidos distinto de 3. Las credenciales se leen del entorno; no hay ninguna en el código.

## Qué siembra

| Dato | Cantidad | Nota |
|---|---|---|
| Libros | 40 | repartidos en las 5 categorías, de 2 a 6 ejemplares |
| Socios | 10 | documentos `1098000001` a `1098000010` |
| Préstamos vencidos | 3 | socios 1 a 3, un libro cada uno |
| Otros préstamos | 12 | socios 4 a 10; nadie supera 3 libros sin devolver |
| Devoluciones | 2 | un préstamo cerrado y otro parcial |
