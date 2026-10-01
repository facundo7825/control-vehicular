# Demo local — Vehículos Oficiales

Guía para mostrar el sistema funcionando en una sola compu y un celular Android, en la misma red Wi-Fi:

- **Celular = chofer** (app `host_prueba`, perfil "Carlos Chofer").
- **Compu = solicitante** (la misma app de prueba en Chrome, perfil "Ana Pérez" o "Jorge Juez").
- **Compu = admin** (panel web) y **base de datos** a la vista.

> Todo esto es solo para desarrollo/demostración: identidad simulada (`IDENTIDAD_DRIVER=simulada`), mapas de distancia simulados para elegir chofer (`MAPAS_DRIVER=falso`), push solo al log y base SQLite local.

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

1. **Panel** (`/admin`): mostrar usuarios y roles, vehículos, cargos prioritarios, parámetros y el **mapa en vivo**.
2. **Celular — inicio de turno:** abrir host_prueba → "Carlos Chofer" → Herramientas → *Vehículos oficiales* → elegir un vehículo → aceptar el permiso de ubicación. Aparece la notificación fija "Turno activo – compartiendo ubicación". En el panel, Carlos aparece en el mapa como **libre**.
3. **Compu — pedido inmediato:** en http://localhost:5000 entrar como "Ana Pérez" → *Vehículos oficiales* → tocar el mapa para marcar origen y destino (cerca de donde está el celular) → *Pedir el más cercano*.
4. **Celular — oferta:** aparece la oferta a pantalla completa con la cuenta regresiva → *Aceptar* → *Voy en camino* → *Llegué* → *Iniciar viaje* → *Finalizar*. Mientras tanto, en la compu Ana ve el estado, el auto acercándose y el **tiempo estimado de llegada**.
5. **Viaje obligatorio:** en la compu salir y entrar como "Jorge Juez" → pedir un viaje. El celular muestra **"Viaje asignado"** sin opción de rechazar ni cancelar.
6. **Reserva:** como Ana → *Reservar para más tarde* → mañana a las 10:00 → elegir a Carlos → confirmar. En el celular: *Agenda* → la solicitud → *Aceptar*. En el mapa del chofer aparece la próxima reserva.
7. **Panel — gestión:** en *Viajes* abrir uno y mostrar la línea de tiempo, las ofertas y el recorrido; probar **Reasignar** y **Cancelar**; mostrar *Alertas*.
8. **Base de datos:** en DB Browser (*Navegar datos*), tablas `viajes`, `ofertas_viaje`, `recorrido_viaje`, `ubicaciones_chofer`, `turnos`, `usuarios`. Refrescar (F5) para ver los cambios en vivo.
9. **Fin de turno:** en el celular *Finalizar turno*: desaparece la notificación y la fila de `ubicaciones_chofer` se borra (privacidad).

## Problemas comunes

- **El celular no conecta** ("Error de conexión"): misma red Wi-Fi, firewall abierto (paso 0.1), y que la compu no haya cambiado de IP (si cambió, volver a compilar).
- **"Sin señal de GPS por ahora"** en el celular: activar la ubicación y salir a un lugar con señal; el aviso se va solo cuando llegan posiciones.
- **La oferta no llega:** el chofer tiene que estar **libre** (turno abierto y ubicación reciente). Revisarlo en el mapa del panel.
- **Reserva rechazada por anticipación:** se pide con al menos 60 minutos de anticipación (parámetro editable en el panel).
