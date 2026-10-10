# K Gastos

## Actualización del saldo y sesión persistente

En Estados de cuenta, «Asociar mi tarjeta de débito» guarda qué cuenta financia cada tarjeta y moneda. Las compras posteriores a la base se restan y los ingresos se suman automáticamente; la asociación se conserva al importar nuevos estados. El desglose muestra base, gastos descontados e ingresos aplicados.

«Ajustar mi saldo real» permite elegir saldo disponible ahora o saldo antes de los gastos de un mes. El segundo aplica los movimientos desde el primer día de ese mes en Costa Rica. La última corrección manual sustituye las correcciones anteriores de esa cuenta; un estado con corte posterior sigue sustituyendo una base antigua.

La sesión se recuerda durante 30 días en el mismo navegador. Las sesiones ya abiertas reciben la cookie al consultar `/api/session`; OAuth también la establece. Cerrar sesión invalida el acceso recordado. La sincronización conserva su frecuencia de cinco minutos y se ejecuta al abrir la app o volver a ella.

Ejecuta `php artisan migrate` para crear `debit_card_links`. Las asociaciones se guardan por usuario y no se infieren de las compras.

React 19 + TypeScript + Laravel 12, con migraciones compatibles con PostgreSQL. Dashboard mensual, historial de gastos e ingresos, saldos explícitos detectados en correo y PWA de consulta sin conexión.

## Configuración

1. Activa `extension=pdo_pgsql` en el `php.ini` de tu PHP (en este equipo: `C:\xampp\php\php.ini`). Crea una base PostgreSQL vacía llamada `k_gastos` o usa el nombre que prefieras.
2. Copia `k-ba/.env.example` a `k-ba/.env`. Configura `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD`; deja `DB_CONNECTION=pgsql`.
3. Configura al menos un proveedor de correo como se explica abajo.
4. Desde `k-ba`, ejecuta:

```powershell
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

5. Desde `k-fa`, ejecuta:

```powershell
npm install
npm run dev
```

Abre `http://localhost:5173`. Escribe tu correo, selecciona el proveedor y autoriza su lectura. El frontend usa el proxy de Vite hacia Laravel (`localhost:8000`). Mantén ambos en `localhost`, sin mezclar con `127.0.0.1`, para compartir la sesión. No hay que configurar CORS.

## Credenciales del correo

Escribir una dirección no concede acceso al buzón: es necesario OAuth. Las credenciales son de tu aplicación; nunca se solicita la contraseña del correo.

### Gmail / Google Workspace

En Google Cloud habilita Gmail API, configura la pantalla de consentimiento y crea un cliente OAuth de tipo aplicación web. En pruebas agrega tu correo como usuario de prueba. Registra exactamente `http://localhost:8000/auth/gmail/callback` como URI de redirección. Completa:

```dotenv
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=http://localhost:8000/auth/gmail/callback
```

Se solicita `gmail.readonly`. Google clasifica ese alcance como restringido; una publicación para otros usuarios puede requerir verificación. [Documentación de permisos](https://developers.google.com/workspace/gmail/api/auth/scopes).

### Outlook / Microsoft 365

Registra una aplicación en Microsoft Entra con acceso a cuentas organizacionales y personales, y una redirección de tipo web `http://localhost:8000/auth/outlook/callback`. Configura permisos delegados `User.Read`, `Mail.Read` y `offline_access`; crea un secreto y copia su valor en:

```dotenv
MICROSOFT_CLIENT_ID=
MICROSOFT_CLIENT_SECRET=
MICROSOFT_REDIRECT_URI=http://localhost:8000/auth/outlook/callback
```

[Documentación de autorización](https://learn.microsoft.com/en-us/graph/auth-v2-user).

## Consulta sin internet

El service worker se genera en el build de producción, con todos los archivos de la interfaz. Los datos del último dashboard se guardan por separado en el almacenamiento local del navegador. Para probar la PWA localmente, ejecuta `npm run build` y `npm run preview`; el preview también dispone del proxy de Laravel. Cambia `FRONTEND_URL=http://localhost:4173` en Laravel y ejecuta `php artisan config:clear` antes de autorizar el correo. Abre una vez con internet, espera la instalación de la PWA y carga los datos; después puedes recargar sin conexión.

La app se actualiza al abrirse, al recuperar conexión, al volver a primer plano y cada cinco minutos mientras está abierta. La importación inicial recorre todo el buzón por páginas; las siguientes consultan mensajes recientes con solapamiento y deduplicación por ID. Cada página se guarda de forma atómica; una importación interrumpida continúa desde su cursor. La actualización requiere que Laravel y el proveedor sean accesibles. Una app cerrada no sincroniza en segundo plano.

Para producción sirve `k-fa/dist` y las rutas `/api/*` y `/auth/*` de Laravel bajo el mismo origen con HTTPS; configura `APP_URL`, `FRONTEND_URL`, ambas redirecciones OAuth y `SESSION_SECURE_COOKIE=true`, `APP_DEBUG=false`. El host debe devolver `index.html` para la navegación de la PWA. No se ha publicado ni configurado un servidor externo.

## Interpretación de datos

- El parser reconoce avisos con una señal de operación y una etiqueta explícita de importe (`Monto`, `Importe`, `Total`, `Amount`, etc.). Admite GTQ/Q, USD/$ y EUR/€, separadores decimales y fechas `dd/mm/aaaa` o `aaaa-mm-dd`. Los mensajes rechazados, pendientes o revertidos no se cuentan como gastos.
- El comercio se toma de una etiqueta identificable. Si no hay fecha u hora explícita, se marca el dato como aproximado o del correo; la interfaz muestra zona `America/Costa_Rica`.
- No se mezclan monedas ni se convierten divisas. El dashboard filtra por mes y moneda.
- El saldo es una estimación basada en el último `saldo disponible` o `saldo actual` explícito por cuenta y moneda, con últimos cuatro dígitos identificables. No se calcula un saldo real a partir de gastos sin una base. Puede estar desactualizado; cuentas con los mismos últimos cuatro dígitos pueden ser ambiguas.
- Se importan PDFs adjuntos a correos de estados de cuenta en Gmail (hasta 10 MB). Los estados se muestran en tarjetas con fecha de corte, moneda, cuenta y saldo. La extracción es preliminar y debe confirmarse contra el original. Los PDF protegidos o escaneados quedan pendientes; no se adivinan los datos. No se procesan otros formatos bancarios desconocidos. Distintos correos sobre la misma operación pueden representar duplicados económicos, aunque el mismo mensaje nunca se importa dos veces. La extracción no garantiza cubrir todos los bancos; no se utiliza un servicio externo de IA ni se envía el correo a terceros adicionales.
- Se conservan IDs, asunto y datos extraídos; el cuerpo completo del correo no se almacena. Los tokens OAuth se cifran con `APP_KEY`; no cambies esa clave una vez conectado un correo. La sesión usa cookies HTTP-only y los POST llevan protección CSRF.
- Cerrar sesión borra la copia financiera de ese dispositivo. Los registros del servidor y la autorización del proveedor se conservan para volver a entrar. La copia local permite consultar datos sin autenticación mientras no cierres sesión: úsala en un dispositivo personal.

## Verificación

```powershell
# k-fa
npm run build
npm run lint
# k-ba (SQLite en memoria; no modifica PostgreSQL)
php artisan test
```

Las pruebas cubren extracción de importes y saldos, rechazo de operaciones ambiguas, aislamiento entre usuarios, OAuth state, cifrado, deduplicación e importación por páginas. La conexión real requiere tus credenciales y autorización; no se ha utilizado tu buzón durante el desarrollo.

## Estados de cuenta y saldo ajustable

Ejecuta `php artisan migrate` al actualizar esta versión. Las tablas nuevas son `bank_statements` y `balance_adjustments`. En esta sesión ya se aplicaron a la base PostgreSQL configurada.

Estados de cuenta muestra primero el documento con el corte más reciente, resumido automáticamente: saldo al cierre, disponible, entradas, salidas, retenciones y movimientos del período. No requiere confirmar datos. Los glosarios se reconocen como referencia y sus conceptos aparecen en una ayuda. El texto y el resumen se cifran con APP_KEY; el PDF se conserva en `storage/app/private/statements` y solo lo descarga su propietario autenticado. No uses `storage:link`. El resumen está disponible sin conexión; el PDF y los ajustes requieren conexión.

El saldo proyectado elige la base más reciente entre los estados legibles de cuentas bancarias y los ajustes manuales. Al corte se usa el final de ese día en Costa Rica. Solo se aplican movimientos posteriores con moneda y cuenta identificables, sin fecha aproximada. Los estados de tarjeta de crédito nunca se suman al dinero disponible. Una compra con tarjeta requiere una asociación explícita guardada; no se infiere la cuenta a partir de una tarjeta desconocida. Los movimientos del PDF ya forman parte del saldo al corte y no se agregan otra vez al historial.

«Ajustar mi saldo real» establece un saldo absoluto desde el momento del ajuste: los movimientos anteriores ya están incluidos y solo los posteriores pueden modificarlo. Un estado confirmado con fecha de corte posterior sustituye esa base; uno antiguo no la sustituye. Los SINPE recibidos sin aviso por correo no pueden importarse: el usuario corrige su efecto mediante ese ajuste. Un formato real de correo de SINPE enviado todavía requiere un ejemplo para verificar su extracción.

El comprobante de compra BN proporcionado por el usuario está cubierto por pruebas: comercio en «Compra realizada en…», tarjeta VISA/MASTERCARD, TOTAL CRC con coma decimal y fecha española con hora a.m./p.m. Al pulsar «Revisar correos anteriores», los registros ya existentes se enriquecen sin duplicarlos.

El lector está adaptado a Cuenta Electrónica Colones/Dólares del BN: tablas de saldos, movimientos, número de cuenta y “Fecha éste estado”. Se comprobó con los PDF guardados y pruebas con datos ficticios. Para actualizar documentos antiguos tras instalar este cambio, ejecuta `php artisan expenses:reparse-statements` después de las migraciones. Conserva las ediciones anteriores.

Modo oscuro/Modo claro guarda la preferencia en el dispositivo y respeta inicialmente el tema del sistema. Gmail renueva el token al vencer y ante un 401. Si el permiso fue revocado, muestra el formulario de autorización y conserva los datos y el punto de sincronización.
## Ventana de importación

La lectura está limitada al 1 de septiembre de 2026 a las 00:00 en Costa Rica (`expenses.import_since`). Gmail filtra las búsquedas con un timestamp y mantiene el filtro en cada página; Outlook usa el equivalente en su consulta. La primera actualización tras este cambio descarta el cursor del recorrido anterior y comienza de nuevo dentro de esa ventana. No se borran registros anteriores, pero el dashboard y los contadores excluyen los correos anteriores al límite. También se excluyen del historial visible las operaciones con fecha anterior al límite aunque su aviso se haya recibido después.

Si ya hay movimientos pero el mes o la moneda seleccionados no tienen resultados, la interfaz ofrece «Ver movimientos disponibles». El saldo real puede ajustarse sin esperar a terminar la importación.

## Dashboard y edición de movimientos

La navegación separa Resumen, Movimientos, Estados de cuenta y Mi correo. El resumen muestra saldo, gastos, ingresos, indicadores, gráficos y cinco movimientos recientes; no incluye los documentos del estado. El comercio se muestra completo, con la nota y el origen del movimiento.

«Agregar movimiento» permite registrar un gasto o ingreso con importe, moneda, comercio/persona, fecha y hora en Costa Rica, medio, últimos cuatro dígitos de cuenta/tarjeta y nota opcional. «Editar» abre los mismos campos para corregir cada operación. Los cambios manuales se protegen frente a la sincronización del correo. Una corrección de comercio no convierte una fecha aproximada en cierta: la confirmación de fecha/hora es explícita.

Los movimientos manuales se incluyen en el historial y en el cálculo del saldo cuando cumplen las reglas de cuenta, moneda y fecha posterior a la base. Los reintentos de creación usan un identificador para evitar duplicados. Agregar y editar requieren conexión; los movimientos guardados pueden consultarse offline.

La migración `2026_10_03_000005_add_editable_movements` ya se aplicó a PostgreSQL en esta sesión. La verificación del frontend puede ejecutarse con `npm test` en `k-fa` y la del backend con `php artisan test` en `k-ba`.
