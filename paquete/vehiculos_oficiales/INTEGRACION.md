# Integrar Vehículos Oficiales en la app del PJ

Qué tiene que hacer la app principal para embeber el módulo. `host_prueba/` (en este repo) es una app mínima que ya lo hace: ante la duda, copiar de ahí.

## 1. Versiones

- Flutter **3.41** o más nuevo, Dart **3.11** o más nuevo (`environment` de `pubspec.yaml` del paquete).
- El módulo usa **Riverpod 3** (`flutter_riverpod ^3.3.2`), `go_router ^17.5.0`, `dio ^5.11.1`, `geolocator ^14.1.1`, `google_maps_flutter ^2.18.1`, `flutter_map ^8.3.2` y `latlong2 ^0.10.1` (mapa de OpenStreetMap sin clave, ver 2), `url_launcher ^6.3.2`, `flutter_secure_storage ^11.2.0`, `path_provider ^2.1.6`, `dart_pusher_channels ^1.3.1`, `audioplayers ^6.7.1` (sonidos de los avisos) y `flutter_local_notifications ^22.3.1` (avisos con la app en segundo plano, ver 4). Varios son plugins de Flutter (con código nativo, como `path_provider`): tienen que resolverse en el `pubspec.lock` de la app principal. Si la app principal usa alguno, tiene que poder resolver esas versiones (en particular, no puede seguir en Riverpod 2).
- El módulo arma su propio `ProviderScope` y su propio router: no hace falta envolverlo en nada.

Dependencia (ruta o git, según cómo se distribuya):

```yaml
dependencies:
  vehiculos_oficiales:
    path: ../paquete/vehiculos_oficiales
```

## 2. Abrir el módulo

Único punto de entrada: `VehiculosOficiales.abrir` (lo exporta `package:vehiculos_oficiales/vehiculos_oficiales.dart`). Abre el módulo encima de la navegación de la app y el `Future` termina cuando el usuario lo cierra.

```dart
VehiculosOficiales.abrir(
  context,
  sesion: SesionPJ(tokenDeSesionDelPJ),
  push: puenteFcm, // una sola instancia para toda la app (ver 3)
  onSesionInvalida: () => volverAlLogin(aviso: 'Tu sesión venció. Volvé a ingresar.'),
  config: const VehiculosOficialesConfig(
    apiBaseUrl: 'https://vehiculos.pj.gob.ar', // sin /api
    reverbHost: 'vehiculos.pj.gob.ar',
    reverbKey: '<REVERB_APP_KEY>',
    reverbPort: 443,
    reverbScheme: 'https',
    centroMapaLat: -26.8241, // donde se centra el mapa si todavía no hay posición
    centroMapaLng: -65.2226,
  ),
);
```

- `sesion`: el token de sesión del PJ; el backend lo valida contra el servicio de identidad.
- `onSesionInvalida`: se llama **una sola vez** si el backend responde 401 (token vencido o inválido). La app decide qué hacer (normalmente, cerrar sesión y volver a su login).
- `googleMapsApiKey` elige el mapa:
  - **Con clave**, el módulo usa **Google Maps**. La clave tiene que estar además en el manifiesto de Android y en el `AppDelegate` de iOS (ver 4 y 5); en web, la página tiene que cargar el script de Maps JavaScript con esa clave.
  - **Vacía** (el valor por defecto), usa **OpenStreetMap** (`flutter_map`, teselas de `tile.openstreetmap.org`, con la atribución "© OpenStreetMap contributors" que pide la licencia). Es **solo para desarrollo y demos**: los servidores públicos de teselas de OpenStreetMap no admiten tráfico de producción (ver su [política de uso](https://operations.osmfoundation.org/policies/tiles/)). En producción hay que configurar la clave de Google.

## 3. Notificaciones push (FCM)

La app principal es dueña de Firebase. El módulo solo necesita un `PuenteNotificaciones`:

```dart
class PuenteFcm implements PuenteNotificaciones {
  // Tiene que ser broadcast: el módulo lo escucha en cada apertura.
  final _mensajes = StreamController<Map<String, dynamic>>.broadcast();

  PuenteFcm() {
    FirebaseMessaging.onMessage.listen((m) => _mensajes.add(m.data));
    FirebaseMessaging.onMessageOpenedApp.listen((m) => _mensajes.add(m.data));
  }

  @override
  Future<String?> token() => FirebaseMessaging.instance.getToken();

  @override
  Stream<Map<String, dynamic>> get mensajes => _mensajes.stream;
}
```

- `token()`: token FCM del dispositivo (o `null`). El módulo lo registra en el backend al abrirse. Si falla, sigue sin push.
- `mensajes`: el `data` de cada mensaje recibido, **como stream broadcast** (`StreamController.broadcast()`). Un stream de una sola escucha falla desde la segunda apertura del módulo.
- Los mensajes del módulo traen en `data` (todo string): `modulo = vehiculos_oficiales`, `tipo` (`oferta`, `oferta_reserva`, `viaje`, `recordatorio_reserva`, `alerta_reserva`, `turno`) y, según el tipo, `viaje_id`, `oferta_id` y `estado` (en `turno`: `abierto`, `cerrado`, `sin_vehiculo`, `cierre_pendiente` o `cierre_cancelado`; ver 7). Los que no tienen `modulo = vehiculos_oficiales` el módulo los ignora, así que se le pueden reenviar todos.
- El backend manda cada push con `notification` (título y texto) y prioridad alta en Android: con la app en segundo plano la notificación la muestra el sistema. Qué hacer al tocarla (por ejemplo, abrir el módulo) lo decide la app principal.
- En Android 13 o más nuevo hace falta el permiso `POST_NOTIFICATIONS` en tiempo de ejecución: sin él no se ven ni los push, ni la notificación fija del turno del chofer, ni los avisos locales del módulo. El módulo lo pide al **iniciar el turno** (chofer) y al **pedir un viaje o una reserva** (solicitante); si la app principal ya lo pidió antes (por ejemplo con `FirebaseMessaging.instance.requestPermission()`), el sistema no vuelve a preguntar.

## 4. Android

`android/app/src/main/AndroidManifest.xml`, dentro de `<manifest>`:

```xml
<uses-permission android:name="android.permission.INTERNET" />
<uses-permission android:name="android.permission.ACCESS_COARSE_LOCATION" />
<uses-permission android:name="android.permission.ACCESS_FINE_LOCATION" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE_LOCATION" />
<!-- El GPS del turno mantiene el procesador despierto (enableWakeLock): sin este permiso Android rechaza el stream. -->
<uses-permission android:name="android.permission.WAKE_LOCK" />
<uses-permission android:name="android.permission.POST_NOTIFICATIONS" />
<!-- Opcional, según la política de ubicación en segundo plano de Google Play que acepte el PJ: -->
<uses-permission android:name="android.permission.ACCESS_BACKGROUND_LOCATION" />
```

Dentro de `<application>`, la clave de Google Maps:

```xml
<meta-data
    android:name="com.google.android.geo.API_KEY"
    android:value="${MAPS_API_KEY}" />
```

(En `host_prueba` sale de `android/secretos.properties`, que no se versiona, con `manifestPlaceholders["MAPS_API_KEY"]` en `app/build.gradle.kts`.)

Dentro de `<queries>` (Android 11+), para "Llamar" y "Navegar" con Google Maps o Waze:

```xml
<intent>
    <action android:name="android.intent.action.DIAL" />
    <data android:scheme="tel" />
</intent>
<intent>
    <action android:name="android.intent.action.VIEW" />
    <data android:scheme="https" />
</intent>
<intent>
    <action android:name="android.intent.action.VIEW" />
    <data android:scheme="google.navigation" />
</intent>
<intent>
    <action android:name="android.intent.action.VIEW" />
    <data android:scheme="waze" />
</intent>
```

### Avisos locales (`flutter_local_notifications`)

El plugin necesita **core library desugaring** y `compileSdk` 35 o más (Flutter 3.41 usa 36 por defecto). En `android/app/build.gradle.kts`:

```kotlin
android {
    compileOptions {
        isCoreLibraryDesugaringEnabled = true
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}

dependencies {
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")
}
```

(Con `build.gradle` en Groovy: `coreLibraryDesugaringEnabled true` y `coreLibraryDesugaring 'com.android.tools:desugar_jdk_libs:2.1.4'`.) El permiso `POST_NOTIFICATIONS` ya está en la lista de arriba.

- **Ícono:** la notificación usa `@mipmap/ic_launcher` de la app. Android dibuja el ícono chico de la barra de estado solo con el canal alfa, así que un ícono a color se ve como un cuadrado blanco: si la app ya tiene un ícono monocromo para notificaciones, conviene ponerlo con ese nombre en el `mipmap` o avisar para cambiar `NotificacionesPlugin.icono`.
- **Canal:** "Avisos de viajes" (`vehiculos_oficiales_avisos`), de prioridad alta y con el sonido del sistema. Tocar la notificación abre la app (el intent de inicio por defecto).

`minSdk` **24** o más (lo exige `flutter_secure_storage`; es el valor por defecto de Flutter 3.41).

El GPS del turno corre en un **servicio en primer plano de tipo `location`** (geolocator) con la notificación fija "Turno activo – compartiendo ubicación" en el canal "Ubicación del turno". Se inicia con la app en primer plano, así que alcanza el permiso "mientras se usa la app"; `ACCESS_BACKGROUND_LOCATION` no hace falta para eso.

## 5. iOS

`ios/Runner/Info.plist`:

```xml
<key>NSLocationWhenInUseUsageDescription</key>
<string>Para marcar tu ubicación como origen del viaje y, si sos chofer, compartirla durante el turno.</string>
<key>NSLocationAlwaysAndWhenInUseUsageDescription</key>
<string>Mientras tu turno de chofer está abierto, la app comparte tu ubicación aunque esté en segundo plano.</string>
<key>UIBackgroundModes</key>
<array>
    <string>location</string>
</array>
<key>LSApplicationQueriesSchemes</key>
<array>
    <string>tel</string>
    <string>comgooglemaps</string>
    <string>waze</string>
</array>
<key>GMSApiKey</key>
<string>$(MAPS_API_KEY)</string>
```

(Si la app ya usa `UIBackgroundModes` para otra cosa, por ejemplo `remote-notification`, se agrega `location` al mismo arreglo.)

`ios/Runner/AppDelegate.swift`, antes de `super.application(...)`:

```swift
import GoogleMaps

if let clave = Bundle.main.object(forInfoDictionaryKey: "GMSApiKey") as? String, !clave.isEmpty {
  GMSServices.provideAPIKey(clave)
}
```

Para que `flutter_local_notifications` pueda mostrar avisos en iOS, en `AppDelegate.swift` (dentro de `application(_:didFinishLaunchingWithOptions:)`):

```swift
UNUserNotificationCenter.current().delegate = self as? UNUserNotificationCenterDelegate
```

## 6. Comportamiento que conviene saber

- **Avisos con sonido.** El módulo hace sonar (con `audioplayers`, assets propios del paquete) la oferta nueva del chofer —en bucle, con vibración cada 2 s, hasta que la acepta, la rechaza o vence—, el viaje asignado, y al solicitante el viaje aceptado (también una reserva), la llegada del chofer y la cancelación (salvo que cancele él mismo). La reserva aceptada se nota por el push o, sin push, al recargar "Mis viajes". El timbre de la oferta sigue el volumen del **tono de llamada** y los demás avisos el de **notificaciones** (no el multimedia); bajan la música mientras suenan. Con la app en **segundo plano** (`paused`/`hidden`; no `inactive`) y el módulo abierto, además muestra una notificación local; con el módulo cerrado los avisos son solo los push de FCM. En web solo hay sonido, y el navegador puede bloquearlo hasta que el usuario toque la página.
- **Limitaciones conocidas de los avisos:**
  - El backend no informa quién canceló: el módulo reconoce la cancelación propia porque la pidió desde ese teléfono. Si el mismo usuario cancela desde otro dispositivo, en este suena "cancelado".
  - Con la app en segundo plano pueden verse dos notificaciones del mismo hecho: la del push de FCM (la muestra el sistema) y la local del módulo.
  - La notificación local usa el ícono de la app (`@mipmap/ic_launcher`); a color, Android lo muestra como una silueta blanca. En producción conviene un ícono monocromo (ver 4).

- **El GPS del chofer vive con el módulo abierto.** Mientras el módulo está abierto sigue compartiendo la ubicación con la app en segundo plano; si el chofer **cierra el módulo** con el turno abierto, el GPS se corta hasta que lo vuelva a abrir (el turno sigue abierto y el backend lo marca "sin señal"). Por eso el módulo pide confirmación al cerrarlo con el turno abierto. Si el PJ necesita que siga con el módulo cerrado, el rastreo tiene que pasar a un servicio de la app principal (pendiente de definir con el equipo de la app).
- El GPS se abre una sola vez por turno y no se reinicia al empezar o terminar un viaje: reabrirlo con la app en segundo plano puede fallar en Android 12+ e iOS. Si el GPS falla (permiso revocado, ubicación apagada), el mapa del chofer lo avisa con "Abrir ajustes" y "Reintentar".
- Con permiso "mientras se usa la app" en iOS el sistema puede cortar la ubicación con la pantalla bloqueada; "Siempre" (`NSLocationAlwaysAndWhenInUseUsageDescription`) es lo que la garantiza.
- Los puntos que no se pudieron mandar (sin red) salen en orden al volver la conexión. Además de tenerlos en memoria, el módulo los guarda en un archivo (`vehiculos_oficiales/cola_ubicaciones.json`) en el **directorio de caché de la app** (`path_provider`), que iOS y Android no incluyen en las copias de seguridad; así sobreviven a que el sistema cierre la app a mitad del turno y se retoman al volver a abrir el módulo. El archivo se borra al terminar el turno, al vencer la sesión (401) y al entrar alguien que no es chofer. En web no se guarda nada.
- Toda la comunicación es con el backend de Vehículos Oficiales (`/api` y Reverb); el módulo no usa otros servicios de la app principal.

## 7. Turno por fichaje de asistencia

- El turno del chofer **se abre al fichar la entrada y se cierra al fichar la salida** (el sistema de asistencia del PJ le avisa al backend; ver `docs/ASISTENCIA.md`). Al abrirse usa el vehículo habitual del chofer, que se carga en el panel. Si ese día usa otro, en el mapa toca el vehículo → "Cambiar vehículo" (no durante un viaje).
- La app se entera por el push `tipo = turno` y, mientras el chofer está en "Iniciar turno" con la app en primer plano, preguntando cada 30 s (por si el push no llega); al volver a primer plano pregunta enseguida. Al abrirse el turno el GPS arranca solo, como al reabrir el módulo con un turno abierto; al cerrarse, se corta. Si fichó sin vehículo habitual libre, el push le pide elegir uno y el turno se inicia a mano como siempre.
- **El GPS arranca con la app en primer plano.** Si el turno se abre (push o fichaje) con la app en segundo plano, el módulo ya lo muestra abierto, pero el GPS arranca recién cuando el chofer vuelve a la app: Android 12+ no deja iniciar el servicio de ubicación en primer plano desde segundo plano. Un GPS que ya estaba corriendo sigue en segundo plano como siempre.
- Si ficha la salida durante un viaje, el turno se cierra solo al terminar ese viaje: llega el push `cierre_pendiente` ("Fichaste la salida" / "Tu turno se cierra al terminar el viaje.") y el mapa muestra "Fichaste la salida: se cierra al terminar el viaje.". Si vuelve a fichar la entrada antes, se anula el cierre (push `cierre_cancelado`, "Seguís de turno") y el aviso del mapa desaparece.
- Estados del push `turno`: `abierto`, `cerrado`, `sin_vehiculo`, `cierre_pendiente` y `cierre_cancelado`. Cualquiera (también uno desconocido) hace que el módulo vuelva a preguntar el turno; con la app en segundo plano, los conocidos muestran además una notificación local con el texto del push.
- Para que el GPS arranque, el módulo tiene que estar abierto (ver 6). Con el módulo cerrado el chofer ve el push "Tu turno empezó" y, al abrir el módulo, el turno ya está abierto.

### Extensión prevista (no implementada): fichar desde el celular

Cuando el fichaje se haga desde la app móvil, el módulo expondría una función pública (por ejemplo `VehiculosOficiales.fichar(context, tipo: entrada | salida, ...)`) que llame a un endpoint de fichaje del chofer en el backend. Ese endpoint usaría la misma lógica que los eventos del sistema de asistencia (`ServicioAsistencia`), así que el turno se abriría y cerraría igual. La app principal decidiría desde dónde se ficha (su propia pantalla de asistencia, por ejemplo) y el módulo no necesitaría cambiar su pantalla de turno.
