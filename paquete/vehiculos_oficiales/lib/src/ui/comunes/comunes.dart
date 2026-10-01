import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../api/errores_api.dart';
import '../../tiempo_real/tiempo_real.dart';
import '../../tiempo_real/tiempo_real_provider.dart';

export '../../comunes/formato.dart';

/// Abre una URL externa (teléfono, Google Maps, Waze). Costura para los tests.
typedef LanzadorUrl = Future<bool> Function(Uri uri);

final lanzadorUrlProvider = Provider<LanzadorUrl>(
  (ref) =>
      (uri) => launchUrl(uri, mode: LaunchMode.externalApplication),
);

/// Teléfono para `tel:`: solo dígitos y un `+` inicial (llega como texto libre, p. ej. "+54 (381) 555-0000").
/// Nulo si no queda ningún dígito.
String? telefonoMarcable(String? telefono) {
  if (telefono == null) return null;
  final digitos = telefono.replaceAll(RegExp(r'\D'), '');
  if (digitos.isEmpty) return null;
  return telefono.trimLeft().startsWith('+') ? '+$digitos' : digitos;
}

/// Fecha y hora en la zona del dispositivo, p. ej. "vie 2/10 10:00".
String formatearFechaHora(DateTime d) => DateFormat('EEE d/M HH:mm', 'es').format(d.toLocal());

String mensajeDeError(Object error) => error is ErrorApi ? error.mensaje : 'Ocurrió un error inesperado.';

void mostrarError(BuildContext context, Object error) =>
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(mensajeDeError(error))));

/// Aviso visible mientras el WebSocket no está conectado (spec 6: se actualiza por sondeo).
class BannerConexion extends ConsumerWidget {
  const BannerConexion({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (ref.watch(estadoConexionProvider) == EstadoConexion.conectado) return const SizedBox.shrink();
    return MaterialBanner(
      leading: const Icon(Icons.sync_problem),
      content: const Text('Sin conexión en tiempo real. Actualizando cada 10 s.'),
      actions: const [SizedBox.shrink()],
    );
  }
}
