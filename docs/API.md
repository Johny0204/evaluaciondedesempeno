# API REST

Base: `{base_url}/api/index.php` (rutas como `/api/index.php/empresas` o `/api/index.php?r=empresas`).
Formato JSON. Autenticación con `Authorization: Bearer <token>`.

Tokens: se generan en *Sistema → Tokens API* o con `POST /auth/login`.
Los usuarios de consulta sólo ven su empresa; las operaciones de escritura son sólo para la consultora.

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/auth/login` | `{"email","password"}` → `{"token"}` (vence en 30 días) |
| GET | `/me` | Usuario del token |
| GET | `/empresas` | Lista paginada (`?pagina=1&por_pagina=100`) |
| POST | `/empresas` | Crear: `{"nombre","nit","tipo_empresa_id","sector","contactos":[{"nombre","email","telefono","puesto"}]}` |
| GET | `/empresas/{id}` | Detalle con contactos |
| GET | `/empresas/{id}/empleados` | Padrón paginado |
| POST | `/empresas/{id}/empleados` | Alta/actualización masiva: `{"empleados":[{"nombre","apellido","email","codigo","area","puesto","nivel","email_jefe"}]}` |
| GET | `/tipos-formulario` | Lista de formularios |
| GET | `/tipos-formulario/{id}` | Estructura completa (secciones, preguntas, escala) |
| GET | `/preguntas` | Banco de preguntas |
| GET | `/evaluaciones` | Lista (`?empresa_id=`) |
| GET | `/evaluaciones/{id}` | Detalle + enlace público |
| GET | `/evaluaciones/{id}/avance` | Avance de respuestas |
| GET | `/evaluaciones/{id}/resultados` | Estadísticas (`?area_id=`) |
| GET | `/evaluaciones/{id}/participantes` | Participantes, calificación y `evaluacion_completada` |

Ejemplo:
```
curl -X POST http://localhost/evaluaciondedesempeno/api/index.php/auth/login -H "Content-Type: application/json" -d "{\"email\":\"admin@consultora.com\",\"password\":\"...\"}"
curl http://localhost/evaluaciondedesempeno/api/index.php/evaluaciones -H "Authorization: Bearer TOKEN"
```
Errores: `{"error":"mensaje"}` con código HTTP 401, 403, 404, 409, 422, 429 o 500.
