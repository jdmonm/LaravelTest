# Hallazgos de Auditoria

Fecha de auditoria: 2026-07-22

Este documento consolida los hallazgos confirmados por las revisiones de calidad Laravel, seguridad Laravel 13 y arquitectura Vue 3/Inertia. No incluye correcciones; sirve como backlog priorizado para el endurecimiento de Library Desk.

## Prioridad de Correccion

| Prioridad | Hallazgo | Estado | Area |
| --- | --- | --- | --- |
| P0 | Contrato de fechas de prestamos invalido en la vista Vue | Resuelto | Frontend / circulacion |
| P0 | Middleware `verified` inefectivo para usuarios no verificados | Resuelto | Autenticacion |
| P0 | Cuenta administradora demo con credenciales conocidas | Resuelto | Seguridad / seeders |
| P1 | Carrera entre actualizacion de inventario y check-out | Pendiente | Dominio / concurrencia |
| P1 | Registro depende de roles no sembrados y no es atomico | Pendiente | Provisionamiento / autenticacion |
| P1 | Eliminacion de perfil falla con prestamos y puede alterar roles | Pendiente | Datos / cuentas |
| P2 | Cambio de correo y sesiones despues de reset requieren endurecimiento | Pendiente | Seguridad de cuenta |
| P2 | Formularios y mutaciones Vue tienen errores o estados pendientes incompletos | Pendiente | Experiencia / frontend |
| P3 | Reutilizacion, accesibilidad y formato pendientes | Pendiente | Mantenibilidad |

## P0 - Corregir Antes de Entrega

### 1. Fechas de prestamos invalidas en Vue

- **Estado:** Resuelto el 2026-07-23.
- **Area:** [LoansIndex.vue](resources/js/pages/library/LoansIndex.vue), [LoanController.php](app/Http/Controllers/Library/LoanController.php)
- **Severidad:** Critica
- **Evidencia:** el backend serializa `due_at` como una fecha ISO completa, por ejemplo `2026-07-22T00:00:00.000000Z`. La vista concatena `T23:59:59` y `T12:00:00`, produciendo una fecha invalida.
- **Impacto:** la lista de prestamos puede fallar al renderizar o mostrar un estado de vencimiento incorrecto. El calculo de vencimiento tambien difiere del servidor.
- **Correccion aplicada:** la respuesta de prestamos transforma todas las fechas a `Y-m-d` y entrega `is_overdue` calculado por Laravel. La vista consume ese estado y crea fechas locales sin concatenar sufijos a valores ISO.
- **Validacion aplicada:** una prueba HTTP cubre un prestamo vencido y uno devuelto, comprobando las fechas normalizadas y ambos estados de vencimiento.

### 2. La verificacion de correo no protege las rutas `verified`

- **Estado:** Resuelto el 2026-07-23.
- **Area:** [User.php](app/Models/User.php), [web.php](routes/web.php), [fortify.php](config/fortify.php)
- **Severidad:** Alta
- **Evidencia:** `User` no implementa `Illuminate\Contracts\Auth\MustVerifyEmail`. Laravel solo bloquea usuarios no verificados mediante el middleware `verified` cuando el modelo implementa ese contrato.
- **Impacto:** un usuario nuevo sin correo verificado puede entrar a dashboard, catalogo y prestamos. Tampoco se dispara el flujo esperado de notificacion de verificacion.
- **Correccion aplicada:** `User` implementa `MustVerifyEmail`, activando el middleware `verified` y la notificacion de Fortify ya configurados.
- **Validacion aplicada:** una prueba cubre el bloqueo de dashboard, catalogo, prestamos y acceso para usuarios no verificados; el registro verifica el envio de `VerifyEmail`.

### 3. Seeder demo puede exponer o elevar una cuenta administradora

- **Estado:** Resuelto el 2026-07-23.
- **Area:** [DatabaseSeeder.php](database/seeders/DatabaseSeeder.php), [README.md](README.md)
- **Severidad:** Alta
- **Evidencia:** el seeder crea o reutiliza `admin@library.test`, le asigna el rol `admin` y usa una contrasena conocida (`password`). Si el email existe antes de ejecutar el seeder, `syncRoles(admin)` puede elevar esa cuenta.
- **Impacto:** ejecutar seeders en un entorno accesible puede dejar una cuenta administrativa predecible.
- **Correccion aplicada:** `LibraryDemoSeeder` contiene las cuentas y el catalogo de demostracion, y no realiza escrituras fuera de `local` o `testing`. `DatabaseSeeder` siempre conserva el seeding de roles y permisos.
- **Validacion aplicada:** una prueba ejecuta el seeder bajo `production` y confirma que no crea datos demo ni eleva una cuenta preexistente con el email de administrador demo.

## P1 - Corregir en el Siguiente Corte

### 4. Carrera entre editar inventario y hacer check-out

- **Area:** [BookController.php](app/Http/Controllers/Library/BookController.php), [CheckOutBook.php](app/Actions/Library/CheckOutBook.php)
- **Severidad:** Alta
- **Evidencia:** `CheckOutBook` usa transaccion y `lockForUpdate`, pero la actualizacion de libro cuenta prestamos activos y recalcula `available_copies` sin transaccion ni bloqueo de la fila.
- **Impacto:** una edicion concurrente puede volver a marcar como disponible una copia ya prestada y permitir sobreprestamos.
- **Correccion recomendada:** envolver la actualizacion de libro en `DB::transaction`, recargar con `lockForUpdate`, contar prestamos activos y actualizar el total y las copias disponibles bajo el mismo bloqueo.
- **Prueba requerida:** una prueba de intercalado entre `CheckOutBook` y edicion de `total_copies` debe preservar `available_copies = total_copies - prestamos_activos`.

### 5. Registro no atomico si los roles aun no existen

- **Area:** [CreateNewUser.php](app/Actions/Fortify/CreateNewUser.php), [LibraryAuthorizationSeeder.php](database/seeders/LibraryAuthorizationSeeder.php), [composer.json](composer.json)
- **Severidad:** Media
- **Evidencia:** el registro crea al usuario y luego asigna el rol `member`. Una instalacion que ejecute migraciones sin sembrar roles puede lanzar `RoleDoesNotExist` despues de crear la cuenta.
- **Impacto:** el registro falla y deja usuarios parciales sin rol; una instalacion limpia no queda lista para registrar usuarios.
- **Correccion recomendada:** garantizar el seeding de roles en el provisionamiento y usar una transaccion para crear la cuenta y asignar su rol.
- **Prueba requerida:** una base recien migrada sin roles no debe dejar una cuenta parcial; el flujo de setup debe dejar los roles disponibles antes del primer registro.

### 6. Eliminar un perfil con prestamos falla y puede dejar roles inconsistentes

- **Area:** [ProfileController.php](app/Http/Controllers/Settings/ProfileController.php), [create_loans_table.php](database/migrations/2026_07_22_220100_create_loans_table.php)
- **Severidad:** Alta
- **Evidencia:** `loans.borrower_id` usa `restrictOnDelete()`. Al intentar borrar un usuario con prestamos, la base rechaza el borrado; los listeners de roles pueden ejecutarse antes del fallo SQL.
- **Impacto:** se muestra un error de servidor, el usuario puede quedar desconectado y sus relaciones de rol pueden cambiar aunque la cuenta permanezca.
- **Correccion recomendada:** impedir de forma explicita la eliminacion con prestamos antes de cerrar sesion, o aplicar soft delete/anonymizacion compatible con retencion historica. La operacion compuesta debe ser transaccional.
- **Prueba requerida:** cubrir prestamos activos y devueltos, asegurando que la cuenta y roles se conservan si no se permite borrar.

## P2 - Seguridad y Experiencia de Cuenta

### 7. Cambio de correo sin reautenticacion y sesiones persistentes tras reset

- **Area:** [settings.php](routes/settings.php), [ProfileController.php](app/Http/Controllers/Settings/ProfileController.php), [ResetUserPassword.php](app/Actions/Fortify/ResetUserPassword.php), [app.php](bootstrap/app.php)
- **Severidad:** Media
- **Evidencia:** el correo se puede cambiar desde perfil sin solicitar contrasena actual o `password.confirm`. El reset actualiza la contrasena sin invalidar sesiones existentes.
- **Impacto:** una sesion robada puede convertirse en toma de cuenta persistente al cambiar correo, iniciar reset y mantener otras sesiones activas.
- **Correccion recomendada:** exigir `password.confirm` o `current_password` para cambiar correo; habilitar gestion de sesiones autenticadas e invalidar otras sesiones al reset o cambio de contrasena.
- **Prueba requerida:** dos sesiones del mismo usuario; despues de reset o cambio sensible, la sesion secundaria debe quedar invalidada.

### 8. Recuperacion, registro y confirmacion de contrasena requieren limites adicionales

- **Area:** [FortifyServiceProvider.php](app/Providers/FortifyServiceProvider.php), rutas de Fortify
- **Severidad:** Baja
- **Evidencia:** la recuperacion de contrasena revela respuestas diferentes para emails existentes e inexistentes, y no hay limites especificos por IP para recuperacion, registro y confirmacion de contrasena.
- **Impacto:** enumeracion de cuentas, abuso de correos de reset, creacion masiva de cuentas e intentos repetidos sobre una sesion comprometida.
- **Correccion recomendada:** usar respuestas indistinguibles para reset y registrar `RateLimiter` por IP/email segun el flujo.
- **Prueba requerida:** comprobar respuestas equivalentes y `429` despues del limite configurado.

### 9. Error de disponibilidad de libro no se muestra en checkout

- **Area:** [CheckOutBook.php](app/Actions/Library/CheckOutBook.php), [LoansIndex.vue](resources/js/pages/library/LoansIndex.vue)
- **Severidad:** Alta de experiencia
- **Evidencia:** la accion devuelve el error bajo la clave `book`, pero el formulario muestra solo `checkoutForm.errors.book_id`.
- **Impacto:** si otro usuario toma la ultima copia entre carga y envio, el dialogo falla sin explicacion visible.
- **Correccion recomendada:** normalizar la clave a `book_id` o mostrar tambien `errors.book`. Mantener la validacion de disponibilidad en el servidor.
- **Prueba requerida:** simular una copia agotada entre carga y check-out y verificar un mensaje visible.

### 10. Fecha limite seleccionable contradice la regla del servidor

- **Area:** [LoansIndex.vue](resources/js/pages/library/LoansIndex.vue), [StoreLoanRequest.php](app/Http/Requests/Library/StoreLoanRequest.php)
- **Severidad:** Media
- **Evidencia:** el cliente permite seleccionar hoy, pero Laravel exige `after:today`.
- **Impacto:** el usuario puede elegir una fecha que el servidor rechazara.
- **Correccion recomendada:** entregar `min_due_date` desde Laravel, calculada con la zona horaria de negocio como manana, y usarla como `min` en el input.

### 11. Mutaciones por fila no muestran estado pendiente ni error contextual

- **Area:** [LoansIndex.vue](resources/js/pages/library/LoansIndex.vue), [AccessIndex.vue](resources/js/pages/library/AccessIndex.vue)
- **Severidad:** Media
- **Evidencia:** check-in y cambio de rol usan `router.patch` directo; el control de fila no se deshabilita mientras se procesa la peticion.
- **Impacto:** doble clics o solicitudes simultaneas generan fallos ambiguos o silenciosos.
- **Correccion recomendada:** extraer un composable pequeno, por ejemplo `usePendingMutation`, con `pendingId`, manejo de error y limpieza en `onFinish`. Deshabilitar solo la fila activa.

## P3 - Mantenibilidad y Accesibilidad

### 12. Rutas literales en paginas Vue en lugar de Wayfinder

- **Area:** [BooksIndex.vue](resources/js/pages/library/BooksIndex.vue), [LoansIndex.vue](resources/js/pages/library/LoansIndex.vue), [AccessIndex.vue](resources/js/pages/library/AccessIndex.vue)
- **Severidad:** Media
- **Evidencia:** las paginas construyen URLs como `'/books'`, `'/loans'` y `'/access'`, aunque existen helpers Wayfinder generados.
- **Impacto:** cambios de rutas o parametros pueden desalinear frontend y backend sin proteccion de TypeScript.
- **Correccion recomendada:** usar los helpers generados y regenerar con `php artisan wayfinder:generate --with-form` cuando cambien rutas. No editar los archivos generados manualmente.

### 13. Paginacion repetida en tres paginas

- **Area:** [BooksIndex.vue](resources/js/pages/library/BooksIndex.vue), [LoansIndex.vue](resources/js/pages/library/LoansIndex.vue), [AccessIndex.vue](resources/js/pages/library/AccessIndex.vue)
- **Severidad:** Baja
- **Evidencia:** las tres paginas repiten el mismo contrato de enlaces, conversion de etiquetas y estructura de navegacion.
- **Impacto:** cambios de accesibilidad, localizacion o comportamiento se deben mantener en tres lugares.
- **Correccion recomendada:** extraer `LibraryPagination.vue` con `links` y `ariaLabel`, junto con un tipo compartido `PaginationLink`.

### 14. Jerarquia de encabezados incompleta

- **Area:** [Heading.vue](resources/js/components/Heading.vue)
- **Severidad:** Baja
- **Evidencia:** el componente siempre renderiza `h2`, incluso cuando representa el encabezado principal de dashboard, catalogo, prestamos o acceso.
- **Impacto:** los lectores de pantalla no encuentran un encabezado `h1` principal en las vistas operativas.
- **Correccion recomendada:** agregar una prop `as` con `h1` o `h2`, conservando `h2` como predeterminado, y usar `h1` en las cabeceras de pagina.

### 15. Errores de formulario con semantica accesible incompleta

- **Area:** [InputError.vue](resources/js/components/InputError.vue), formularios de libros y prestamos
- **Severidad:** Baja
- **Evidencia:** los mensajes no tienen `id`, `aria-live` ni `role="alert"`, y los controles no usan `aria-describedby` o `aria-invalid` para asociarlos.
- **Impacto:** los errores pueden no anunciarse correctamente a tecnologias asistivas.
- **Correccion recomendada:** mejorar `InputError` y enlazarlo con `Input` y `SelectTrigger` mediante atributos ARIA.

### 16. Formato PHP pendiente en la puerta de calidad

- **Area:** [pint.json](pint.json), archivos reportados por `composer lint:check`
- **Severidad:** Baja
- **Evidencia:** Pint reporto problemas de formato en 20 archivos; el chequeo forma parte de la ruta estandar de calidad.
- **Impacto:** el pipeline de CI no puede pasar de forma consistente.
- **Correccion recomendada:** ejecutar Pint sobre los archivos afectados y verificar con `composer lint:check`.

## Riesgos Residuales y Configuracion de Despliegue

- El esquema no impone por base de datos la invariancia `available_copies <= total_copies`; las escrituras directas o futuras integraciones podrian violarla.
- [LoanFactory.php](database/factories/LoanFactory.php) puede crear prestamos activos sin ajustar inventario. Las pruebas que dependan de existencias deben usar acciones de dominio o estados consistentes.
- Antes de produccion: usar `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, `SESSION_SECURE_COOKIE=true`, un `APP_KEY` unico, correo real y un secreto propio para passkeys.
- No ejecutar datos demo ni credenciales conocidas en ambientes accesibles publicamente.
- No se encontraron secretos versionados ni vulnerabilidades conocidas con `composer audit --locked` y `npm audit --omit=dev`.

## Estado de Validaciones Durante la Auditoria

| Comando | Resultado |
| --- | --- |
| `php artisan test` | Correcto: 53 pruebas, 223 aserciones. |
| `composer types:check` | Correcto: PHPStan/Larastan sin errores. |
| `npm run lint:check` | Correcto. |
| `npm run format:check` | Correcto. |
| `npm run types:check` | Correcto. |
| `npm run build` | Correcto; aviso opcional de `fontaine`. |
| `composer audit --locked` | Sin advisories conocidos. |
| `npm audit --omit=dev` | Sin vulnerabilidades conocidas. |
| `composer lint:check` | Pendiente: Pint reporta formato en 20 archivos. |

## Siguiente Orden de Trabajo

1. Hacer atomico el registro con rol y asegurar el aprovisionamiento inicial.
2. Corregir concurrencia de inventario y eliminacion de perfiles con prestamos.
3. Endurecer cambios sensibles de cuenta, sesiones y rate limits.
4. Resolver mensajes de error, fechas limite y estados pendientes de Vue.
5. Aplicar Wayfinder, paginacion reutilizable y mejoras ARIA.
6. Dejar `composer lint:check` en verde.