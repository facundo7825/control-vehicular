import 'package:vehiculos_oficiales/src/avisos/notificaciones_locales.dart';
import 'package:vehiculos_oficiales/src/avisos/reproductor_sonidos.dart';

/// [ReproductorSonidos] sin audioplayers: registra lo que sonó.
class ReproductorFalso implements ReproductorSonidos {
  /// Sonidos de una vez, en orden.
  final sonados = <Sonido>[];

  /// Lo que suena en bucle ahora (nulo si nada).
  Sonido? enBucle;

  /// Cuántas veces arrancó un bucle.
  int bucles = 0;
  int vibraciones = 0;

  @override
  Future<void> reproducir(Sonido sonido) async => sonados.add(sonido);

  @override
  Future<void> repetir(Sonido sonido) async {
    enBucle = sonido;
    bucles++;
  }

  @override
  Future<void> detenerBucle() async => enBucle = null;

  @override
  Future<void> vibrar() async => vibraciones++;

  @override
  Future<void> liberar() async => enBucle = null;
}

/// [NotificacionesLocales] sin el plugin: registra los pedidos de permiso y las notificaciones.
class NotificacionesFalsas implements NotificacionesLocales {
  int permisos = 0;
  final mostradas = <(String, String)>[];

  @override
  Future<void> pedirPermiso() async => permisos++;

  @override
  Future<void> mostrar({required String titulo, required String texto}) async => mostradas.add((titulo, texto));
}
