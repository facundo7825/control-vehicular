# Demo local — Vehículos Oficiales

Guía para mostrar el sistema funcionando en una sola compu y un celular Android, en la misma red Wi-Fi:

- **Celular = chofer** (app `host_prueba`, perfil "Carlos Chofer").
- **Compu = solicitante** (la misma app de prueba en Chrome, perfil "Ana Pérez" o "Jorge Juez").
- **Compu = admin** (panel web) y **base de datos** a la vista.

> Todo esto es solo para desarrollo/demostración: identidad simulada (`IDENTIDAD_DRIVER=simulada`), mapas de distancia simulados para elegir chofer (`MAPAS_DRIVER=falso`), push solo al log y base SQLite local. Para producción, los mapas sin Google ni servidores públicos (OSRM, Nominatim y teselas propios) están en [`PRODUCCION-MAPAS.md`](PRODUCCION-MAPAS.md).

## 0. Una sola vez

1. **Firewall de Windows** (PowerShell como administrador): abrir los puertos 8000 (backend), 8080 (Reverb) y 5000 (app web, opcional para abrirla desde otra compu):
   ```powershell
   New-NetFirewallRule -DisplayName "Vehiculos backend 8000" -Direction Inbound -Protocol TCP -LocalPort 8000 -Action Allow -Profile Private
   New-NetFirewallRule -DisplayName "Vehiculos Reverb 8080" -Direction Inbound -Protocol TCP -LocalPort 8080 -Action Allow -Profile Private
   ```
   Si la red Wi-Fi figura como pública en Windows, usar `-Profile Any`.
2. **Visor de la base:** instalar DB Browser for SQLite:
   ```powershell
   winget install DBBrowserForSQLite.DBBrowserForSQLite --source winget
   ```
3. **Mapa:** sin clave de Google Maps el módulo usa **OpenStreetMap** (gratis, sin cuenta ni tarjeta; pensado para desarrollo y demos). Si tenés una clave con *Maps JavaScript API* y *Maps SDK for Android* habilitadas, podés usar el mapa de Google pasándola al compilar.
4. **Celular:** Opciones de desarrollador → *Depuración inalámbrica* activada (o depuración USB con cable de datos). Vincular una vez:
   ```powershell
   & "$env:LOCALAPPDATA\Android\Sdk\platform-tools\adb.exe" pair <IP:PUERTO de vinculación> <código>
   ```

## 1. Antes de cada demo

1. **Conectar el celular** (el puerto de conexión cambia cada vez que se reactiva la depuración inalámbrica; está en la pantalla principal de *Depuración inalámbrica*, debajo de "Dirección IP y puerto"):
   ```powershell
   & "$env:LOCALAPPDATA\Android\Sdk\platform-tools\adb.exe" connect <IP:PUERTO>
   ```
2. **Compilar e instalar** (solo la primera vez o si cambió el código o la IP de la compu). Desde la raíz del repo:
   ```powershell
   powershell -ExecutionPolicy Bypass -File .\scripts\compilar-demo.ps1
   ```
   Compila la app web, compila el APK y, si el celular está conectado, lo instala. Para usar el mapa de Google en vez de OpenStreetMap: agregar `-MapsKey "TU_CLAVE"`.
3. **Levantar todo** con la base limpia:
   ```powershell
   powershell -ExecutionPolicy Bypass -File .\scripts\iniciar-demo.ps1 -Reiniciar
   ```
   Abre cinco ventanas (backend, Reverb, cola, scheduler, app web) y en el navegador la app del solicitante y el panel. Para cortar todo, cerrar esas ventanas. Sin `-Reiniciar` conserva los datos de la demo anterior.

| Qué | Dónde |
|---|---|
| Solicitante | http://localhost:5000 |
| Panel de administración | http://localhost:8000/admin — `admin@demo.local` / `demo1234` |
| Base de datos | `backend\database\database.sqlite` (abrir con DB Browser **en solo lectura**, así no bloquea las escrituras del backend) |

Datos de demo: vehículos AB123CD, AC456EF y AD789GH; "Juez" marcado como cargo obligatorio; Carlos ya es chofer; Ana y Jorge son solicitantes.

## 2. Guion sugerido

1. **Panel** (`/admin`): mostrar usuarios y roles, vehículos, cargos prioritarios, parámetros y el **mapa en vivo** (OpenStreetMap sin clave): todos los choferes con turno abierto, con color por estado (libre verde, en viaje azul, reservado pronto ámbar, sin señal gris), y los viajes activos con origen y destino unidos por una línea. Se actualiza cada 10 s.
2. **Celular — inicio de turno:** abrir host_prueba → "Carlos Chofer" → Herramientas → *Vehículos oficiales* → elegir un vehículo → aceptar el permiso de ubicación y el de **notificaciones**. Subir el volumen del **timbre** y de las **notificaciones** (la alarma de la oferta usa el volumen del timbre, no el de multimedia). Aparece la notificación fija "Turno activo – compartiendo ubicación". En el panel, Carlos aparece en el mapa como **libre**.
3. **Compu — pedido inmediato:** en http://localhost:5000 entrar como "Ana Pérez" → *Vehículos oficiales* → permitir la ubicación en Chrome: el mapa se centra ahí y el **origen es la ubicación actual**. Escribir el destino (p. ej. una calle de la ciudad) y tocar la **lupa** o Enter → elegir una sugerencia (también se puede tocar el mapa). Se dibuja el **recorrido** con el tiempo y la distancia ("≈ 12 min · 5,3 km") → *Pedir el más cercano*. Antes de pedir, hacer al menos un clic en la página: Chrome no reproduce sonidos hasta que se interactúa con ella. Con "Cambiar origen" se puede marcar otro origen.
4. **Celular — oferta:** suena la **alarma** (se repite hasta responder) y aparece la oferta a pantalla completa con la cuenta regresiva. Con la app en segundo plano llega además una notificación con sonido. → *Aceptar* (en la compu suena el aviso de **aceptado**) → *Voy en camino*: el chofer ve el **recorrido** hasta el origen y arriba el cartel con la **próxima indicación** ("En 200 m, doblá a la derecha por …"); si se desvía dice "Recalculando…". *Navegar* abre Google Maps o Waze para guiar con voz. → *Llegué* (en la compu suena **el chofer llegó**) → *Iniciar viaje* (el recorrido pasa a ser hasta el destino) → *Finalizar*. Mientras tanto, en la compu Ana ve el estado, el auto acercándose, el recorrido y el **tiempo estimado de llegada**.
5. **Viaje obligatorio:** en la compu salir y entrar como "Jorge Juez" → pedir un viaje. El celular muestra **"Viaje asignado"** sin opción de rechazar ni cancelar.
6. **Reserva:** como Ana → *Reservar para más tarde* → mañana a las 10:00 → elegir a Carlos → confirmar. En el celular: *Agenda* → la solicitud → *Aceptar*. En el mapa del chofer aparece la próxima reserva.
7. **Panel — gestión:** en *Viajes* abrir uno y mostrar la línea de tiempo, las ofertas y el recorrido; probar **Reasignar** y **Cancelar**; mostrar *Alertas*.
8. **Base de datos:** en DB Browser (*Navegar datos*), tablas `viajes`, `ofertas_viaje`, `recorrido_viaje`, `ubicaciones_chofer`, `turnos`, `usuarios`. Refrescar (F5) para ver los cambios en vivo.
9. **Simular fichaje (turno por asistencia):** en el panel → *Usuarios* → "Carlos Chofer", cargar primero su **Vehículo habitual** y guardar. Con el turno cerrado, tocar **Simular fichaje** → *Entrada* → *Fichar*: se abre su turno con ese vehículo y al celular le llega el aviso "Tu turno empezó". Después **Simular fichaje** → *Salida* cierra el turno (con un viaje activo queda como *cierre pendiente* y se cierra al terminar el viaje). Sin vehículo habitual la entrada da *sin vehículo*: aparece una alerta y el chofer elige el vehículo en la app. Los fichajes se ven en *Fichajes*. El contrato real con el sistema de asistencia del PJ está en [`ASISTENCIA.md`](ASISTENCIA.md).
10. **Fin de turno:** en el celular *Finalizar turno*: desaparece la notificación y la fila de `ubicaciones_chofer` se borra (privacidad).

## Problemas comunes

- **El celular no conecta** ("Error de conexión"): misma red Wi-Fi, firewall abierto (paso 0.1), y que la compu no haya cambiado de IP (si cambió, volver a compilar).
- **"Sin señal de GPS por ahora"** en el celular: activar la ubicación y salir a un lugar con señal; el aviso se va solo cuando llegan posiciones.
- **La búsqueda de destino dice "Sin resultados":** usa el buscador gratuito de OpenStreetMap (Nominatim), que no autocompleta: hay que tocar la lupa o Enter. Si no responde (sin internet o bloqueado), el backend deja de consultarlo por un minuto; mientras tanto se puede tocar el mapa para marcar el destino.
- **Chrome no toma la ubicación:** solo la pide en `http://localhost`. Si se abrió por IP, el origen se marca a mano.
- **No se dibuja el recorrido:** lo calcula el servidor público gratuito de OSRM (solo para demos). Probar desde la compu: `curl "https://router.project-osrm.org/route/v1/driving/-65.2,-26.8;-65.21,-26.81?overview=false"`. Si no responde, poner `RUTAS_DRIVER=falso` en `backend\.env` (dibuja una línea recta) y reiniciar el backend. Si OSRM falla, el backend deja de consultarlo por un minuto.
- **No suena nada:** en el celular, volumen del timbre y de notificaciones arriba y el permiso de notificaciones aceptado; en Chrome, hacer un clic en la página antes de que llegue el aviso.
- **La oferta no llega:** el chofer tiene que estar **libre** (turno abierto y ubicación reciente). Revisarlo en el mapa del panel.
- **Reserva rechazada por anticipación:** se pide con al menos 60 minutos de anticipación (parámetro editable en el panel).
