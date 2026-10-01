import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Costura sobre `flutter_local_notifications`: el aviso con la app en segundo plano. Ningún método lanza.
/// En web no hace nada (ahí solo hay sonido).
abstract class NotificacionesLocales {
  /// Android 13+ (y iOS): pide permiso para mostrar notificaciones. Si ya se respondió, no molesta.
  Future<void> pedirPermiso();

  /// Notificación con sonido en el canal de alta prioridad. Tocarla abre la app.
  Future<void> mostrar({required String titulo, required String texto});
}

final notificacionesLocalesProvider = Provider<NotificacionesLocales>((ref) => NotificacionesPlugin());

/// [NotificacionesLocales] real. El plugin se inicializa recién al primer uso.
class NotificacionesPlugin implements NotificacionesLocales {
  /// Ícono chico de la notificación: un recurso de la app principal (ver INTEGRACION.md).
  static const icono = '@mipmap/ic_launcher';

  static const _canal = AndroidNotificationDetails(
    'vehiculos_oficiales_avisos',
    'Avisos de viajes',
    channelDescription: 'Ofertas, aceptación, llegada del chofer y cancelaciones.',
    importance: Importance.high,
    priority: Priority.high,
    category: AndroidNotificationCategory.message,
  );

  FlutterLocalNotificationsPlugin? _plugin;
  Future<bool>? _iniciando;
  bool _permisoPedido = false;
  int _siguienteId = 0;

  Future<FlutterLocalNotificationsPlugin?> _iniciado() async {
    if (kIsWeb) return null;
    final plugin = _plugin ??= FlutterLocalNotificationsPlugin();
    final listo = await (_iniciando ??= _iniciar(plugin));
    return listo ? plugin : null;
  }

  static Future<bool> _iniciar(FlutterLocalNotificationsPlugin plugin) async {
    const ajustes = InitializationSettings(
      android: AndroidInitializationSettings(icono),
      // El permiso se pide aparte, en el momento justo (pedirPermiso).
      iOS: DarwinInitializationSettings(
        requestAlertPermission: false,
        requestBadgePermission: false,
        requestSoundPermission: false,
      ),
    );
    return await plugin.initialize(settings: ajustes) ?? false;
  }

  @override
  Future<void> pedirPermiso() => _intentar(() async {
    if (_permisoPedido) return;
    final plugin = await _iniciado();
    if (plugin == null) return;
    _permisoPedido = true;
    await plugin
        .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
        ?.requestNotificationsPermission();
    await plugin.resolvePlatformSpecificImplementation<IOSFlutterLocalNotificationsPlugin>()?.requestPermissions(
      alert: true,
      sound: true,
    );
  });

  @override
  Future<void> mostrar({required String titulo, required String texto}) => _intentar(() async {
    final plugin = await _iniciado();
    await plugin?.show(
      id: _siguienteId++,
      title: titulo,
      body: texto,
      notificationDetails: const NotificationDetails(
        android: _canal,
        iOS: DarwinNotificationDetails(presentAlert: true, presentSound: true),
      ),
    );
  });

  Future<void> _intentar(Future<void> Function() accion) async {
    try {
      await accion();
    } catch (e) {
      // Plataforma sin notificaciones, plugin sin configurar en la app principal, permiso denegado…
      debugPrint('vehiculos_oficiales: no se pudo mostrar el aviso (${e.runtimeType}).');
    }
  }
}
